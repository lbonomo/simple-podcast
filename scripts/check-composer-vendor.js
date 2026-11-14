const fs = require('node:fs');
const path = require('node:path');

const autoloadPath = path.join(__dirname, '..', 'vendor', 'autoload.php');

if (!fs.existsSync(autoloadPath)) {
	console.error(
		'Faltan las dependencias de Composer: no se encontró vendor/autoload.php. Ejecuta composer install antes de npm run start.'
	);
	process.exit(1);
}
