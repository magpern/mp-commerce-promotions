#!/usr/bin/env bash
#
# Post-sync verification: plugin lifecycle and schema version (read-only).
#
# Release zip audit runs only from the staging/dev tree (see STAGING_ROOT below).
# When this script is executed against the live Docker-synced plugin copy, lifecycle
# checks still run; zip verification is skipped with a clear message.
#
set -euo pipefail

readonly WOOCOMMERCE_ROOT="/home/magpern/woocommerce"
readonly STAGING_ROOT="/home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions"
readonly PLUGIN_SLUG="mp-commerce-promotions"

echo "==> MP Commerce Promotions: verify plugin"
echo "    WooCommerce root: ${WOOCOMMERCE_ROOT}"

if [[ ! -d "${WOOCOMMERCE_ROOT}" ]]; then
	echo "ERROR: WooCommerce root does not exist: ${WOOCOMMERCE_ROOT}" >&2
	exit 1
fi

if [[ ! -x "${WOOCOMMERCE_ROOT}/wp" ]]; then
	echo "ERROR: WP-CLI helper not found: ${WOOCOMMERCE_ROOT}/wp" >&2
	exit 1
fi

cd "${WOOCOMMERCE_ROOT}"

echo "==> Plugin status (before)"
./wp plugin status "${PLUGIN_SLUG}"

echo "==> Deactivating plugin"
./wp plugin deactivate "${PLUGIN_SLUG}"

echo "==> Activating plugin"
./wp plugin activate "${PLUGIN_SLUG}"

echo "==> Plugin status (after)"
./wp plugin status "${PLUGIN_SLUG}"

echo "==> Schema version option"
SCHEMA_VERSION="$(./wp option get mp_cp_schema_version 2>/dev/null || true)"
if [[ -z "${SCHEMA_VERSION}" ]]; then
	echo "WARN: mp_cp_schema_version is empty or not set."
else
	echo "    mp_cp_schema_version = ${SCHEMA_VERSION}"
fi

PLUGIN_DIR="${WOOCOMMERCE_ROOT}/wp-content/plugins/${PLUGIN_SLUG}"
LIVE_CANONICAL="$(readlink -f "${PLUGIN_DIR}" 2>/dev/null || echo "${PLUGIN_DIR}")"
STAGING_CANONICAL="$(readlink -f "${STAGING_ROOT}" 2>/dev/null || echo "${STAGING_ROOT}")"

if [[ -f "${STAGING_ROOT}/scripts/release-audit.sh" ]]; then
	echo "==> Release audit (staging tree)"
	(
		cd "${STAGING_ROOT}"
		export MP_CP_BUILD_ROOT="${MP_CP_BUILD_ROOT:-$(dirname "${STAGING_ROOT}")/build}"
		bash "${STAGING_ROOT}/scripts/release-audit.sh"
	)
elif [[ -f "${PLUGIN_DIR}/scripts/release-audit.sh" ]] && [[ "${LIVE_CANONICAL}" == "${STAGING_CANONICAL}"* ]]; then
	echo "==> Release audit (plugin tree)"
	bash "${PLUGIN_DIR}/scripts/release-audit.sh"
else
	echo "==> Release audit skipped (live sync copy; run from staging: bash ${STAGING_ROOT}/scripts/release-audit.sh)"
fi

echo "==> Verification complete."
