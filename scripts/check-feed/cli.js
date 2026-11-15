'use strict';

const { parseArgs } = require( 'node:util' );
const { validateInputUrl } = require( './url-guard' );

const DEFAULTS = {
	limit: 50,
	timeout: 30,
	maxRedirects: 5,
	maxBytes: 104857600,
};

const HELP = `Uso: npm run check:feed -- <url-del-feed> [opciones]
     node scripts/check-feed.js <url-del-feed> [opciones]

Controla un feed de podcast: valida el XML RSS 2.0 y comprueba cada
<enclosure> con HEAD, GET y GET con Range. Solo lee; no modifica nada.

Opciones:
  --json                  Imprime un único documento JSON en stdout.
  --allow-private         Permite hosts privados, loopback y reservados.
  --limit <n>             Máximo de episodios a revisar (defecto ${ DEFAULTS.limit }).
  --timeout <segundos>    Timeout total por petición (defecto ${ DEFAULTS.timeout }).
  --max-redirects <n>     Máximo de redirecciones (defecto ${ DEFAULTS.maxRedirects }).
  --max-bytes <n>         Máximo de bytes descargados por enclosure
                          (defecto ${ DEFAULTS.maxBytes }, 100 MiB).
  --check-cache-control   Advierte si una respuesta de audio trae no-store.
  --no-color              Salida sin colores.
  -h, --help              Muestra esta ayuda.

Valores fijos: timeout de conexión 10 s, tamaño máximo del feed 10 MiB.

Códigos de salida:
  0  Sin fallos (puede haber advertencias).
  1  Al menos un fallo en las comprobaciones.
  2  Error de uso o de entrada.
`;

/**
 * Convierte un valor de texto en entero dentro de un rango.
 *
 * @param {string} name Nombre de la opción.
 * @param {string|undefined} value Valor recibido.
 * @param {number} fallback Valor por defecto.
 * @param {number} min Mínimo permitido.
 * @return {{value: number}|{error: string}}
 */
function toInt( name, value, fallback, min ) {
	if ( value === undefined ) {
		return { value: fallback };
	}
	if ( ! /^\d+$/.test( value ) || Number( value ) < min || ! Number.isSafeInteger( Number( value ) ) ) {
		return { error: `El valor de --${ name } debe ser un entero mayor o igual a ${ min }.` };
	}
	return { value: Number( value ) };
}

/**
 * Interpreta los argumentos de la línea de comandos.
 *
 * @param {string[]} argv Argumentos (sin node ni el script).
 * @param {Object} env Variables de entorno.
 * @param {boolean} isTTY Si stdout es una terminal.
 * @return {{ok: true, options: Object}|{ok: true, help: true}|{ok: false, message: string}}
 */
function parseCli( argv, env = {}, isTTY = false ) {
	let parsed;
	try {
		parsed = parseArgs( {
			args: argv,
			allowPositionals: true,
			strict: true,
			options: {
				json: { type: 'boolean' },
				'allow-private': { type: 'boolean' },
				limit: { type: 'string' },
				timeout: { type: 'string' },
				'max-redirects': { type: 'string' },
				'max-bytes': { type: 'string' },
				'check-cache-control': { type: 'boolean' },
				'no-color': { type: 'boolean' },
				help: { type: 'boolean', short: 'h' },
			},
		} );
	} catch ( error ) {
		return { ok: false, message: `Opción no válida: ${ error.message }` };
	}
	const { values, positionals } = parsed;
	if ( values.help ) {
		return { ok: true, help: true };
	}
	if ( positionals.length === 0 ) {
		return { ok: false, message: 'Falta la URL del feed.' };
	}
	if ( positionals.length > 1 ) {
		return { ok: false, message: 'Se esperaba una única URL de feed.' };
	}
	const input = validateInputUrl( positionals[ 0 ] );
	if ( input.error ) {
		return { ok: false, message: input.error };
	}
	const numbers = {};
	const specs = [
		[ 'limit', 'limit', 1 ],
		[ 'timeout', 'timeout', 1 ],
		[ 'max-redirects', 'maxRedirects', 0 ],
		[ 'max-bytes', 'maxBytes', 1 ],
	];
	for ( const [ flag, key, min ] of specs ) {
		const result = toInt( flag, values[ flag ], DEFAULTS[ key ], min );
		if ( result.error ) {
			return { ok: false, message: result.error };
		}
		numbers[ key ] = result.value;
	}
	const noColor = Boolean( values[ 'no-color' ] ) || env.NO_COLOR !== undefined || ! isTTY;
	return {
		ok: true,
		options: {
			url: input.url.href,
			json: Boolean( values.json ),
			allowPrivate: Boolean( values[ 'allow-private' ] ),
			checkCacheControl: Boolean( values[ 'check-cache-control' ] ),
			color: ! noColor,
			...numbers,
		},
	};
}

module.exports = { parseCli, HELP, DEFAULTS };
