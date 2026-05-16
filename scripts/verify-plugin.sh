#!/usr/bin/env bash
#
# Post-sync verification: plugin lifecycle and schema version (read-only).
#
set -euo pipefail

readonly WOOCOMMERCE_ROOT="/home/magpern/woocommerce"
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

echo "==> Verification complete."
