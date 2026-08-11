#!/usr/bin/env bash
# Build a WordPress-installable zip: anhora-{version}.zip containing anhora/
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="${ROOT}/anhora"
DIST_DIR="${ROOT}/dist"

if [[ ! -f "${PLUGIN_DIR}/anhora.php" ]]; then
  echo "error: missing ${PLUGIN_DIR}/anhora.php" >&2
  exit 1
fi

VERSION="$(
  grep -E "^\s*\*\s*Version:" "${PLUGIN_DIR}/anhora.php" \
    | head -1 \
    | sed -E 's/.*Version:[[:space:]]*//' \
    | tr -d '[:space:]'
)"

if [[ -z "${VERSION}" ]]; then
  echo "error: could not parse Version from anhora.php" >&2
  exit 1
fi

STABLE="$(
  grep -E "^Stable tag:" "${PLUGIN_DIR}/readme.txt" \
    | head -1 \
    | sed -E 's/^Stable tag:[[:space:]]*//' \
    | tr -d '[:space:]'
)"

if [[ -n "${STABLE}" && "${STABLE}" != "${VERSION}" ]]; then
  echo "error: Version ${VERSION} in anhora.php != Stable tag ${STABLE} in readme.txt" >&2
  exit 1
fi

CONST="$(
  grep -E "define\(\s*'ANHORA_VERSION'" "${PLUGIN_DIR}/anhora.php" \
    | head -1 \
    | sed -E "s/.*ANHORA_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'.*/\1/"
)"

if [[ -n "${CONST}" && "${CONST}" != "${VERSION}" ]]; then
  echo "error: Version ${VERSION} != ANHORA_VERSION constant ${CONST}" >&2
  exit 1
fi

mkdir -p "${DIST_DIR}"
ZIP_NAME="anhora-${VERSION}.zip"
ZIP_PATH="${DIST_DIR}/${ZIP_NAME}"
rm -f "${ZIP_PATH}"

# Zip from repo root so the archive root is the `anhora/` plugin folder.
(
  cd "${ROOT}"
  zip -r "${ZIP_PATH}" anhora \
    -x "anhora/**/.DS_Store" \
    -x "anhora/**/*.zip"
)

echo "Built ${ZIP_PATH}"
echo "version=${VERSION}"
echo "artifact=${ZIP_NAME}"
