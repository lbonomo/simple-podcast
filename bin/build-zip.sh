#!/usr/bin/env bash
# Genera lb-simple-podcast.zip limpio (sin tests, docs ni herramientas de desarrollo).
#
# Uso: bin/build-zip.sh
# Requisitos: composer, npm, rsync, zip.
set -euo pipefail

PLUGIN_SLUG="lb-simple-podcast"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build"
STAGE_DIR="${BUILD_DIR}/stage"
OUT_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${ROOT_DIR}/${PLUGIN_SLUG}.zip"

cd "${ROOT_DIR}"

# 1. Limpieza.
rm -rf "${BUILD_DIR}" "${ZIP_FILE}"
mkdir -p "${STAGE_DIR}" "${OUT_DIR}"

# 2. Compilar los assets de Gutenberg en el árbol de trabajo.
if [ ! -d node_modules ]; then
	npm ci --no-audit --no-fund
fi
npm run build

# 3. Copia de trabajo (sin dependencias de desarrollo ni artefactos) para instalar solo producción.
#    Así el entorno local conserva vendor/ con las herramientas de desarrollo.
rsync -rc \
	--exclude='/.git' --exclude='/node_modules' --exclude='/vendor' \
	--exclude='/build' --exclude='/*.zip' \
	./ "${STAGE_DIR}/"

(
	cd "${STAGE_DIR}"
	composer install --no-dev --optimize-autoloader --no-interaction --no-progress
)

# 4. Aplicar .distignore sobre la copia ya con vendor/ de producción.
rsync -rc --exclude-from="${ROOT_DIR}/.distignore" "${STAGE_DIR}/" "${OUT_DIR}/"

# 5. Archivo instalable.
(cd "${BUILD_DIR}" && zip -r -q "${ZIP_FILE}" "${PLUGIN_SLUG}")

rm -rf "${BUILD_DIR}"

# 6. Verificación del contenido.
"${ROOT_DIR}/bin/verify-zip.sh" "${ZIP_FILE}"
echo "Paquete creado: ${ZIP_FILE}"
