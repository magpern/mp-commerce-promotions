#!/usr/bin/env bash
#
# Build a distributable plugin zip from the staging Git working tree.
# Does not run git commands. Excludes dev-only paths.
#
set -euo pipefail

readonly SOURCE="/home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions"
readonly BUILD_ROOT="/home/magpern/mp-commerce-promotions-staging/build"
readonly PLUGIN_SLUG="mp-commerce-promotions"
readonly MAIN_FILE="${SOURCE}/${PLUGIN_SLUG}.php"

echo "==> MP Commerce Promotions: build release zip"
echo "    Source: ${SOURCE}"

if [[ ! -f "${MAIN_FILE}" ]]; then
	echo "ERROR: Main plugin file not found: ${MAIN_FILE}" >&2
	exit 1
fi

VERSION="$(
	grep -E "define\s*\(\s*'MP_COMMERCE_PROMOTIONS_VERSION'" "${MAIN_FILE}" \
		| head -n 1 \
		| sed -E "s/.*'([^']+)'.*/\1/"
)"

if [[ -z "${VERSION}" ]]; then
	echo "ERROR: Could not read MP_COMMERCE_PROMOTIONS_VERSION from ${MAIN_FILE}" >&2
	exit 1
fi

HEADER_VERSION="$(
	grep -E '^\s*\*\s*Version:\s*' "${MAIN_FILE}" \
		| head -n 1 \
		| sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//'
)"

if [[ "${HEADER_VERSION}" != "${VERSION}" ]]; then
	echo "ERROR: Plugin header Version (${HEADER_VERSION}) does not match MP_COMMERCE_PROMOTIONS_VERSION (${VERSION})." >&2
	exit 1
fi

readonly ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
readonly ZIP_PATH="${BUILD_ROOT}/${ZIP_NAME}"
readonly STAGING_DIR="${BUILD_ROOT}/.package-${PLUGIN_SLUG}"
readonly PACKAGE_DIR="${STAGING_DIR}/${PLUGIN_SLUG}"

echo "    Version: ${VERSION}"
echo "    Output:  ${ZIP_PATH}"

rm -rf "${STAGING_DIR}"
mkdir -p "${PACKAGE_DIR}" "${BUILD_ROOT}"

echo "==> Copying plugin files (excluding dev paths)"
tar -C "${SOURCE}" \
	--exclude='.git' \
	--exclude='vendor' \
	--exclude='node_modules' \
	--exclude='.phpcs-cache' \
	--exclude='.phpunit.result.cache' \
	--exclude='build' \
	-cf - . \
	| tar -C "${PACKAGE_DIR}" -xf -

echo "==> Creating zip archive"
rm -f "${ZIP_PATH}"
if command -v zip >/dev/null 2>&1; then
	(
		cd "${STAGING_DIR}"
		zip -rq "${ZIP_PATH}" "${PLUGIN_SLUG}"
	)
else
	echo "    (zip not found; using python3)"
	python3 - "${STAGING_DIR}" "${ZIP_PATH}" "${PLUGIN_SLUG}" <<'PY'
import os
import sys
import zipfile
from pathlib import Path

staging_dir = Path(sys.argv[1])
zip_path = Path(sys.argv[2])
plugin_slug = sys.argv[3]
root = staging_dir / plugin_slug

with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
    for dirpath, _dirnames, filenames in os.walk(root):
        for name in filenames:
            full = Path(dirpath) / name
            zf.write(full, full.relative_to(staging_dir))
PY
fi

rm -rf "${STAGING_DIR}"

echo "==> Verifying zip exclusions"
python3 - "${ZIP_PATH}" "${PLUGIN_SLUG}" <<'PY'
import sys
import zipfile

zip_path = sys.argv[1]
plugin_slug = sys.argv[2]
excluded_segments = {
    ".git",
    "vendor",
    "node_modules",
    ".phpcs-cache",
    ".phpunit.result.cache",
}


def has_excluded_segment(path: str) -> bool:
    return any(part in excluded_segments for part in path.split("/"))


with zipfile.ZipFile(zip_path) as zf:
    names = zf.namelist()
    bad = [n for n in names if has_excluded_segment(n)]
    if bad:
        print("ERROR: Zip contains excluded paths:", bad[:5], file=sys.stderr)
        sys.exit(1)
    main = f"{plugin_slug}/{plugin_slug}.php"
    if main not in names:
        print(f"ERROR: Zip is missing {main}", file=sys.stderr)
        sys.exit(1)
    print(f"OK: {len(names)} entries; {main} present; no .git/vendor/caches.")
PY

echo "==> ${ZIP_PATH}"
echo "==> Build complete."
