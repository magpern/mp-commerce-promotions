#!/usr/bin/env bash
#
# Release validation for mp-commerce-promotions.
# Repo checks: dev tree completeness (scripts, tests, docs allowed).
# Artifact checks: production ZIP must not contain dev-only paths.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
VERIFY_ZIP="${SCRIPT_DIR}/lib/verify-release-zip.py"
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

# --- Repository checks (dev tree; scripts/tests/docs expected here) ---

echo ""
echo "==> Repository checks"

[[ -f "${MAIN_FILE}" ]] || fail "Missing main plugin file: ${MAIN_FILE}"

grep -q "Plugin Name:" "${MAIN_FILE}" || fail "Plugin header missing Plugin Name"
grep -q "Version:" "${MAIN_FILE}" || fail "Plugin header missing Version"

VERSION_CONST="$(grep -E "define\s*\(\s*'MP_COMMERCE_PROMOTIONS_VERSION'" "${MAIN_FILE}" | head -n1 | sed -E "s/.*'([^']+)'.*/\1/")"
HEADER_VERSION="$(grep -E '^\s*\*\s*Version:\s*' "${MAIN_FILE}" | head -n1 | sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//')"
[[ -n "${VERSION_CONST}" ]] || fail "MP_COMMERCE_PROMOTIONS_VERSION not found"
[[ "${VERSION_CONST}" == "${HEADER_VERSION}" ]] || fail "Version mismatch: header=${HEADER_VERSION} const=${VERSION_CONST}"
echo "    Version: header and MP_COMMERCE_PROMOTIONS_VERSION = ${VERSION_CONST}"

for doc in \
	readme.txt \
	CHANGELOG.md \
	LICENSE \
	README.md \
	docs/ARCHITECTURE.md \
	docs/COMMERCIAL_READINESS.md \
	docs/BROWSER_QA_MATRIX.md \
	docs/manual-performance-and-hardening-test.md \
	docs/manual-automation-and-observability-test.md
do
	[[ -f "${REPO_ROOT}/${doc}" ]] || fail "Missing required repo file: ${doc}"
done

[[ -d "${REPO_ROOT}/scripts" ]] || fail "Missing scripts/ (dev tree)"
[[ -d "${REPO_ROOT}/tests" ]] || fail "Missing tests/ (dev tree)"
[[ -d "${REPO_ROOT}/docs" ]] || fail "Missing docs/ (dev tree)"
[[ -f "${REPO_ROOT}/.github/workflows/release.yml" ]] || fail "Missing .github/workflows/release.yml"
echo "    Dev tree: scripts/, tests/, docs/, .github/ present (expected in repo)"

if [[ -d "${REPO_ROOT}/vendor" ]]; then
	warn "vendor/ present in dev tree (excluded from production ZIP)"
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
echo "    Schema version: ${SCHEMA_VERSION}"

echo "==> Required PHP entrypoints (repository)"
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

for doc in \
	docs/BETA_READINESS.md \
	docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md \
	docs/BROWSER_QA_RUNBOOK.md \
	docs/CLASSIC_CHECKOUT_CERTIFICATION.md \
	docs/BLOCK_CHECKOUT_INVESTIGATION.md \
	docs/RELEASE_EVIDENCE_0.2.0_BETA1.md \
	docs/PILOT_RELEASE_0.3.0_PILOT1.md \
	docs/PILOT_RELEASE_0.3.0_PILOT2.md \
	docs/PILOT_RELEASE_0.3.0_PILOT3.md \
	docs/PILOT_RELEASE_0.3.0_PILOT4.md \
	docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT1.md \
	docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT2.md \
	docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT3.md \
	docs/GITHUB_RELEASE_NOTES_0.3.0_PILOT4.md \
	docs/GITHUB_RELEASE_NOTES_0.4.0.md
do
	[[ -f "${REPO_ROOT}/${doc}" ]] || fail "Missing ${doc}"
done

[[ -f "${REPO_ROOT}/src/Infrastructure/GithubUpdater.php" ]] || fail "Missing src/Infrastructure/GithubUpdater.php"

[[ -f "${REPO_ROOT}/scripts/commerce-growth-navigation-smoke.php" ]] || fail "Missing scripts/commerce-growth-navigation-smoke.php"
[[ -f "${REPO_ROOT}/scripts/pilot-release-smoke.php" ]] || fail "Missing scripts/pilot-release-smoke.php"
[[ -f "${REPO_ROOT}/uninstall.php" ]] || fail "Missing uninstall.php"

echo "    Repository checks passed"

# --- Artifact checks (production ZIP) ---

echo ""
echo "==> Release artifact checks"

ZIP_PATH="${BUILD_ROOT}/${PLUGIN_SLUG}-${VERSION_CONST}.zip"
if [[ ! -f "${ZIP_PATH}" ]]; then
	echo "    Building release zip (not found: ${ZIP_PATH})"
	bash "${SCRIPT_DIR}/build-zip.sh"
fi

[[ -f "${ZIP_PATH}" ]] || fail "Release zip not found: ${ZIP_PATH}"
echo "    Zip: ${ZIP_PATH}"

[[ -f "${VERIFY_ZIP}" ]] || fail "Missing verifier: ${VERIFY_ZIP}"
python3 "${VERIFY_ZIP}" "${ZIP_PATH}" "${PLUGIN_SLUG}"

echo ""
echo "==> Forbidden-path spot check (artifact must be empty)"
if command -v unzip >/dev/null 2>&1; then
	HITS="$(unzip -l "${ZIP_PATH}" | grep -E 'scripts/|tests/|docs/|\.github/' || true)"
	if [[ -n "${HITS}" ]]; then
		echo "${HITS}" >&2
		fail "Release zip contains forbidden dev paths (see above)"
	fi
	echo "    OK: no scripts/, tests/, docs/, or .github/ in zip listing"
	if ! unzip -l "${ZIP_PATH}" | grep -q 'src/Infrastructure/GithubUpdater.php'; then
		fail "Release zip missing src/Infrastructure/GithubUpdater.php"
	fi
	echo "    OK: GithubUpdater.php present in zip"
else
	warn "unzip not installed; skipped grep spot check (verify-release-zip.py already passed)"
	if command -v python3 >/dev/null 2>&1; then
		if ! python3 -c "import sys,zipfile; z=zipfile.ZipFile(sys.argv[1]); sys.exit(0 if any('GithubUpdater.php' in n for n in z.namelist()) else 1)" "${ZIP_PATH}"; then
			fail "Release zip missing GithubUpdater.php (python check)"
		fi
		echo "    OK: GithubUpdater.php present in zip (python check)"
	fi
fi

echo ""
echo "==> Release audit passed (version ${VERSION_CONST}, schema ${SCHEMA_VERSION})"
