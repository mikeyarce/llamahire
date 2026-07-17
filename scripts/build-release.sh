#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/llamahire"
VERSION="$(sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' "${ROOT_DIR}/llamahire.php" | head -n 1)"
ARCHIVE="${DIST_DIR}/llamahire-${VERSION}.zip"

rm -rf "${STAGE_DIR}"
mkdir -p "${STAGE_DIR}"

cp "${ROOT_DIR}/llamahire.php" "${STAGE_DIR}/"
cp "${ROOT_DIR}/readme.txt" "${STAGE_DIR}/"
cp "${ROOT_DIR}/uninstall.php" "${STAGE_DIR}/"
cp -R "${ROOT_DIR}/assets" "${STAGE_DIR}/"
cp -R "${ROOT_DIR}/blocks" "${STAGE_DIR}/"
cp -R "${ROOT_DIR}/includes" "${STAGE_DIR}/"
cp -R "${ROOT_DIR}/patterns" "${STAGE_DIR}/"

if [ -d "${ROOT_DIR}/languages" ]; then
	cp -R "${ROOT_DIR}/languages" "${STAGE_DIR}/"
fi

find "${STAGE_DIR}" -exec touch -t 198001010000 {} +

rm -f "${ARCHIVE}" "${ARCHIVE}.sha256"
(
	cd "${DIST_DIR}"
	LC_ALL=C find llamahire -print | LC_ALL=C sort | zip -X -q "$(basename "${ARCHIVE}")" -@
)

if command -v sha256sum >/dev/null 2>&1; then
	sha256sum "${ARCHIVE}" > "${ARCHIVE}.sha256"
else
	shasum -a 256 "${ARCHIVE}" > "${ARCHIVE}.sha256"
fi

echo "Built ${ARCHIVE}"
