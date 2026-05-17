#!/usr/bin/env bash
#
# Release artifact validation for mp-commerce-promotions.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_SLUG="mp-commerce-promotions"
MAIN_FILE="${REPO_ROOT}/${PLUGIN_SLUG}.php"

echo "==> MP Commerce Promotions: release audit"
echo "    Root: ${REPO_ROOT}"

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

[[ -f "${MAIN_FILE}" ]] || fail "Missing main plugin file: ${MAIN_FILE}"

grep -q "Plugin Name:" "${MAIN_FILE}" || fail "Plugin header missing Plugin Name"
grep -q "Version:" "${MAIN_FILE}" || fail "Plugin header missing Version"

VERSION_CONST="$(grep -E "define\s*\(\s*'MP_COMMERCE_PROMOTIONS_VERSION'" "${MAIN_FILE}" | head -n1 | sed -E "s/.*'([^']+)'.*/\1/")"
HEADER_VERSION="$(grep -E '^\s*\*\s*Version:\s*' "${MAIN_FILE}" | head -n1 | sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//')"
[[ -n "${VERSION_CONST}" ]] || fail "MP_COMMERCE_PROMOTIONS_VERSION not found"
[[ "${VERSION_CONST}" == "${HEADER_VERSION}" ]] || fail "Version mismatch: header=${HEADER_VERSION} const=${VERSION_CONST}"

[[ -f "${REPO_ROOT}/readme.txt" ]] || fail "Missing readme.txt"
[[ -f "${REPO_ROOT}/README.md" ]] || fail "Missing README.md"
[[ -f "${REPO_ROOT}/CHANGELOG.md" ]] || fail "Missing CHANGELOG.md"
[[ -f "${REPO_ROOT}/docs/ARCHITECTURE.md" ]] || fail "Missing docs/ARCHITECTURE.md"
[[ -f "${REPO_ROOT}/docs/BROWSER_QA_MATRIX.md" ]] || fail "Missing docs/BROWSER_QA_MATRIX.md"
[[ -f "${REPO_ROOT}/docs/manual-performance-and-hardening-test.md" ]] || fail "Missing docs/manual-performance-and-hardening-test.md"

if [[ -d "${REPO_ROOT}/vendor" ]]; then
	echo "    WARN: vendor/ present in dev tree (excluded from release zip by build-zip.sh)"
fi

if [[ -d "${REPO_ROOT}/languages" ]]; then
	echo "    languages/: present"
else
	echo "    languages/: optional (not present)"
fi

SCHEMA_FILE="${REPO_ROOT}/src/Infrastructure/Database/Schema.php"
[[ -f "${SCHEMA_FILE}" ]] || fail "Missing Schema.php"
grep -q "SCHEMA_VERSION" "${SCHEMA_FILE}" || fail "Schema version constant missing"

echo "==> Required PHP entrypoints"
for path in \
	"src/Plugin.php" \
	"src/Service/PromotionPerformanceProfiler.php" \
	"src/Service/PromotionConcurrencyGuard.php" \
	"src/Service/PromotionCronScheduler.php" \
	"src/Service/PromotionDataRetentionService.php" \
	"scripts/performance-hardening-smoke.php"
do
	[[ -f "${REPO_ROOT}/${path}" ]] || fail "Missing ${path}"
done

echo "==> Release audit passed (version ${VERSION_CONST})"
