#!/usr/bin/env bash
#
# Release artifact validation for mp-commerce-promotions.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_SLUG="mp-commerce-promotions"
MAIN_FILE="${REPO_ROOT}/${PLUGIN_SLUG}.php"
BUILD_ROOT="${MP_CP_BUILD_ROOT:-$(cd "${REPO_ROOT}/.." && pwd)/build}"

echo "==> MP Commerce Promotions: release audit"
echo "    Root: ${REPO_ROOT}"

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

warn() {
	echo "    WARN: $*"
}

[[ -f "${MAIN_FILE}" ]] || fail "Missing main plugin file: ${MAIN_FILE}"

grep -q "Plugin Name:" "${MAIN_FILE}" || fail "Plugin header missing Plugin Name"
grep -q "Version:" "${MAIN_FILE}" || fail "Plugin header missing Version"

VERSION_CONST="$(grep -E "define\s*\(\s*'MP_COMMERCE_PROMOTIONS_VERSION'" "${MAIN_FILE}" | head -n1 | sed -E "s/.*'([^']+)'.*/\1/")"
HEADER_VERSION="$(grep -E '^\s*\*\s*Version:\s*' "${MAIN_FILE}" | head -n1 | sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//')"
[[ -n "${VERSION_CONST}" ]] || fail "MP_COMMERCE_PROMOTIONS_VERSION not found"
[[ "${VERSION_CONST}" == "${HEADER_VERSION}" ]] || fail "Version mismatch: header=${HEADER_VERSION} const=${VERSION_CONST}"

for doc in \
	readme.txt \
	CHANGELOG.md \
	README.md \
	docs/ARCHITECTURE.md \
	docs/COMMERCIAL_READINESS.md \
	docs/BROWSER_QA_MATRIX.md \
	docs/manual-performance-and-hardening-test.md \
	docs/manual-automation-and-observability-test.md
do
	[[ -f "${REPO_ROOT}/${doc}" ]] || fail "Missing required doc: ${doc}"
done

if [[ -d "${REPO_ROOT}/vendor" ]]; then
	warn "vendor/ present in dev tree (excluded from release zip by build-zip.sh)"
fi

if [[ -d "${REPO_ROOT}/languages" ]]; then
	echo "    languages/: present"
else
	warn "languages/: optional (not present)"
fi

SCHEMA_FILE="${REPO_ROOT}/src/Infrastructure/Database/Schema.php"
[[ -f "${SCHEMA_FILE}" ]] || fail "Missing Schema.php"
SCHEMA_VERSION="$(grep -E "const SCHEMA_VERSION" "${SCHEMA_FILE}" | head -n1 | sed -E "s/.*'([^']+)'.*/\1/")"
[[ -n "${SCHEMA_VERSION}" ]] || fail "Schema version constant missing"

if ! grep -q "${SCHEMA_VERSION}" "${REPO_ROOT}/docs/ARCHITECTURE.md" && ! grep -q "${SCHEMA_VERSION}" "${REPO_ROOT}/README.md"; then
	fail "Schema version ${SCHEMA_VERSION} not documented in ARCHITECTURE.md or README.md"
fi

echo "==> Required PHP entrypoints"
for path in \
	"src/Plugin.php" \
	"src/Service/PromotionPerformanceProfiler.php" \
	"src/Service/PromotionConcurrencyGuard.php" \
	"src/Service/PromotionCronScheduler.php" \
	"src/Service/PromotionDataRetentionService.php" \
	"scripts/performance-hardening-smoke.php" \
	"scripts/production-hardening-closure-smoke.php" \
	"scripts/beta-readiness-smoke.php" \
	"scripts/beta-release-prep-smoke.php"
do
	[[ -f "${REPO_ROOT}/${path}" ]] || fail "Missing ${path}"
done

POT_FILE="${REPO_ROOT}/languages/mp-commerce-promotions.pot"
[[ -f "${POT_FILE}" ]] || fail "Missing languages/mp-commerce-promotions.pot"
POT_LINES="$(wc -l < "${POT_FILE}")"
if [[ "${POT_LINES}" -lt 100 ]]; then
	fail "POT file looks like a placeholder (${POT_LINES} lines); run wp i18n make-pot"
fi
echo "    POT: ${POT_LINES} lines"

[[ -f "${REPO_ROOT}/docs/BETA_READINESS.md" ]] || fail "Missing docs/BETA_READINESS.md"
[[ -f "${REPO_ROOT}/docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md" ]] || fail "Missing docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md"
[[ -f "${REPO_ROOT}/docs/BROWSER_QA_RUNBOOK.md" ]] || fail "Missing docs/BROWSER_QA_RUNBOOK.md"
[[ -f "${REPO_ROOT}/docs/CLASSIC_CHECKOUT_CERTIFICATION.md" ]] || fail "Missing docs/CLASSIC_CHECKOUT_CERTIFICATION.md"
[[ -f "${REPO_ROOT}/docs/BLOCK_CHECKOUT_INVESTIGATION.md" ]] || fail "Missing docs/BLOCK_CHECKOUT_INVESTIGATION.md"
[[ -f "${REPO_ROOT}/docs/RELEASE_EVIDENCE_0.2.0_BETA1.md" ]] || fail "Missing docs/RELEASE_EVIDENCE_0.2.0_BETA1.md"
[[ -f "${REPO_ROOT}/docs/PILOT_RELEASE_0.3.0_PILOT1.md" ]] || fail "Missing docs/PILOT_RELEASE_0.3.0_PILOT1.md"
[[ -f "${REPO_ROOT}/docs/PILOT_RELEASE_0.3.0_PILOT2.md" ]] || fail "Missing docs/PILOT_RELEASE_0.3.0_PILOT2.md"
[[ -f "${REPO_ROOT}/docs/PILOT_RELEASE_0.3.0_PILOT3.md" ]] || fail "Missing docs/PILOT_RELEASE_0.3.0_PILOT3.md"
[[ -f "${REPO_ROOT}/docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT1.md" ]] || fail "Missing docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT1.md"
[[ -f "${REPO_ROOT}/docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT2.md" ]] || fail "Missing docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT2.md"
[[ -f "${REPO_ROOT}/docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT3.md" ]] || fail "Missing docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT3.md"
[[ -f "${REPO_ROOT}/scripts/commerce-growth-navigation-smoke.php" ]] || fail "Missing scripts/commerce-growth-navigation-smoke.php"
[[ -f "${REPO_ROOT}/scripts/pilot-release-smoke.php" ]] || fail "Missing scripts/pilot-release-smoke.php"
[[ -f "${REPO_ROOT}/.github/workflows/release.yml" ]] || fail "Missing .github/workflows/release.yml"

ZIP_PATH="${BUILD_ROOT}/${PLUGIN_SLUG}-${VERSION_CONST}.zip"
if [[ ! -f "${ZIP_PATH}" ]]; then
	echo "==> Building release zip for artifact checks"
	bash "${SCRIPT_DIR}/build-zip.sh"
fi

[[ -f "${ZIP_PATH}" ]] || fail "Release zip not found: ${ZIP_PATH}"

echo "==> Verifying release zip contents"
python3 - "${ZIP_PATH}" "${PLUGIN_SLUG}" <<'PY'
import sys
import zipfile

zip_path = sys.argv[1]
plugin_slug = sys.argv[2]
forbidden = {".git", "vendor", "node_modules", ".phpcs-cache"}
with zipfile.ZipFile(zip_path) as zf:
    for name in zf.namelist():
        parts = name.split("/")
        if any(part in forbidden for part in parts):
            print(f"ERROR: zip contains forbidden segment: {name}", file=sys.stderr)
            sys.exit(1)
    main = f"{plugin_slug}/{plugin_slug}.php"
    if main not in zf.namelist():
        print(f"ERROR: missing {main}", file=sys.stderr)
        sys.exit(1)
    root_prefix = f"{plugin_slug}/"
    if any(n and not n.startswith(root_prefix) for n in zf.namelist()):
        print(f"ERROR: zip entries must be under {root_prefix}", file=sys.stderr)
        sys.exit(1)
print(f"OK: zip artifact clean ({len(zf.namelist())} entries)")
PY

echo "==> Release audit passed (version ${VERSION_CONST}, schema ${SCHEMA_VERSION})"
