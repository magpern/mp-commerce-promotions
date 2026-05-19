#!/usr/bin/env bash
#
# Build a production release ZIP from the Git working tree.
# Does not run git commands. Excludes dev-only paths (see scripts/lib/verify-release-zip.py).
#
set -euo pipefail

readonly PLUGIN_SLUG="mp-commerce-promotions"
readonly VPS_SOURCE="/home/magpern/mp-commerce-promotions-staging/mp-commerce-promotions"
readonly VPS_BUILD_ROOT="/home/magpern/mp-commerce-promotions-staging/build"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
VERIFY_ZIP="${SCRIPT_DIR}/lib/verify-release-zip.py"

if [[ -n "${MP_CP_SOURCE:-}" ]]; then
	SOURCE="${MP_CP_SOURCE}"
elif [[ -f "${VPS_SOURCE}/${PLUGIN_SLUG}.php" ]]; then
	SOURCE="${VPS_SOURCE}"
else
	SOURCE="${REPO_ROOT}"
fi

if [[ -n "${MP_CP_BUILD_ROOT:-}" ]]; then
	BUILD_ROOT="${MP_CP_BUILD_ROOT}"
elif [[ "${SOURCE}" == "${VPS_SOURCE}" ]]; then
	BUILD_ROOT="${VPS_BUILD_ROOT}"
else
	BUILD_ROOT="$(cd "${SOURCE}/.." && pwd)/build"
fi

readonly SOURCE BUILD_ROOT
readonly MAIN_FILE="${SOURCE}/${PLUGIN_SLUG}.php"

echo "==> MP Commerce Promotions: build production release zip"
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

echo "==> Copying production files (excluding dev-only paths)"
tar -C "${SOURCE}" \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='vendor' \
	--exclude='node_modules' \
	--exclude='scripts' \
	--exclude='tests' \
	--exclude='docs' \
	--exclude='build' \
	--exclude='.phpcs-cache' \
	--exclude='.phpunit.result.cache' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='composer.phar' \
	--exclude='phpcs.xml.dist' \
	--exclude='phpunit.xml.dist' \
	--exclude='README.md' \
	--exclude='.gitignore' \
	--exclude='.editorconfig' \
	--exclude='.cursorignore' \
	--exclude='.env' \
	--exclude='.env.*' \
	--exclude='*.log' \
	--exclude='*.sql' \
	--exclude='*.sql.gz' \
	--exclude='*.dump' \
	--exclude='*.sqlite' \
	--exclude='.write-test' \
	--exclude='.DS_Store' \
	--exclude='Thumbs.db' \
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
            zf.write(full, full.relative_to(staging_dir).as_posix())
PY
fi

rm -rf "${STAGING_DIR}"

echo "==> Verifying production zip"
python3 "${VERIFY_ZIP}" "${ZIP_PATH}" "${PLUGIN_SLUG}"

echo "==> Zip summary"
python3 - "${ZIP_PATH}" "${PLUGIN_SLUG}" <<'PY'
import sys
import zipfile
from collections import Counter

zip_path, slug = sys.argv[1], sys.argv[2]
prefix = f"{slug}/"
with zipfile.ZipFile(zip_path) as zf:
    names = sorted(n for n in zf.namelist() if n.startswith(prefix))
    tops = Counter(n[len(prefix) :].split("/")[0] for n in names if n != prefix)
    print(f"    Path: {zip_path}")
    print(f"    Total entries: {len(names)}")
    print("    Top-level under plugin root:")
    for key in sorted(tops):
        print(f"      {key}/  ({tops[key]} paths)")
    print("    Sample paths:")
    for line in names[:12]:
        print(f"      {line}")
    if len(names) > 12:
        print(f"      ... ({len(names) - 12} more)")
PY

echo "==> ${ZIP_PATH}"
echo "==> Build complete."
