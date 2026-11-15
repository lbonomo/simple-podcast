#!/usr/bin/env bash
# Verifica que el zip de producción tenga lo necesario y nada de desarrollo.
#
# Uso: bin/verify-zip.sh [ruta/al/lb-simple-podcast.zip]
set -euo pipefail

SLUG="lb-simple-podcast"
ZIP_FILE="${1:-${SLUG}.zip}"

if [ ! -f "${ZIP_FILE}" ]; then
	echo "ERROR: no existe ${ZIP_FILE}" >&2
	exit 1
fi

LISTING="$(unzip -Z1 "${ZIP_FILE}")"
status=0

for required in \
	"${SLUG}/${SLUG}.php" \
	"${SLUG}/uninstall.php" \
	"${SLUG}/vendor/autoload.php" \
	"${SLUG}/assets/build/index.js" \
	"${SLUG}/assets/build/index.asset.php" \
	"${SLUG}/templates/feed-podcast.php" \
	"${SLUG}/inc/Plugin.php"; do
	if ! grep -qx "${required}" <<<"${LISTING}"; then
		echo "FALTA: ${required}" >&2
		status=1
	fi
done

# Nada de desarrollo (ni en la raíz ni dentro de vendor/).
forbidden='^'"${SLUG}"'/(tests|docs|node_modules|bin|build|scripts|\.github|assets/src)/|^'"${SLUG}"'/(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml\.dist|phpstan\.neon\.dist|phpcs\.xml\.dist|jest\.config\.js|\.gitignore|\.distignore|\.lando\.yml)$'
if grep -Eq "${forbidden}" <<<"${LISTING}"; then
	echo "SOBRA en el paquete:" >&2
	grep -E "${forbidden}" <<<"${LISTING}" >&2
	status=1
fi

if [ "${status}" -eq 0 ]; then
	echo "OK: ${ZIP_FILE} contiene lo esperado y nada de desarrollo."
fi
exit "${status}"
