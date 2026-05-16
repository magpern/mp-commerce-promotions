#!/usr/bin/env bash
#
# Sync staging Git working tree to the live WordPress plugin directory in Docker.
# Does not run git commands. Excludes dev-only paths (.git, vendor, caches).
#
set -euo pipefail

readonly SOURCE="/home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions"
readonly CONTAINER="woocommerce-wordpress-1"
readonly TARGET="/var/www/html/wp-content/plugins/mp-commerce-promotions"
readonly MAIN_PLUGIN_FILE="${TARGET}/mp-commerce-promotions.php"

echo "==> MP Commerce Promotions: sync staging → live"
echo "    Source:    ${SOURCE}"
echo "    Container: ${CONTAINER}"
echo "    Target:    ${TARGET}"

if [[ ! -d "${SOURCE}" ]]; then
	echo "ERROR: Source directory does not exist: ${SOURCE}" >&2
	exit 1
fi

if [[ ! -f "${SOURCE}/mp-commerce-promotions.php" ]]; then
	echo "ERROR: Source is missing main plugin file." >&2
	exit 1
fi

if ! docker inspect "${CONTAINER}" >/dev/null 2>&1; then
	echo "ERROR: Docker container not found: ${CONTAINER}" >&2
	exit 1
fi

echo "==> Ensuring target directory exists in container"
docker exec "${CONTAINER}" mkdir -p "${TARGET}"

echo "==> Streaming files (excluding .git, vendor, node_modules, caches)"
tar -C "${SOURCE}" \
	--exclude='.git' \
	--exclude='vendor' \
	--exclude='node_modules' \
	--exclude='.phpcs-cache' \
	--exclude='.phpunit.result.cache' \
	-cf - . \
	| docker exec -i "${CONTAINER}" tar -C "${TARGET}" -xf -

echo "==> Removing excluded paths if present on target"
docker exec "${CONTAINER}" rm -rf \
	"${TARGET}/.git" \
	"${TARGET}/vendor" \
	"${TARGET}/node_modules" \
	"${TARGET}/.phpcs-cache" \
	"${TARGET}/.phpunit.result.cache"

echo "==> Setting ownership (www-data:www-data)"
docker exec "${CONTAINER}" chown -R www-data:www-data "${TARGET}"

echo "==> Verifying target"
if docker exec "${CONTAINER}" test -d "${TARGET}/.git"; then
	echo "ERROR: .git still exists at target." >&2
	exit 1
fi

if docker exec "${CONTAINER}" test -d "${TARGET}/vendor"; then
	echo "ERROR: vendor still exists at target." >&2
	exit 1
fi

if ! docker exec "${CONTAINER}" test -f "${MAIN_PLUGIN_FILE}"; then
	echo "ERROR: Main plugin file missing: ${MAIN_PLUGIN_FILE}" >&2
	exit 1
fi

echo "OK: No .git or vendor on target; ${MAIN_PLUGIN_FILE} present."
echo "==> Sync complete."
