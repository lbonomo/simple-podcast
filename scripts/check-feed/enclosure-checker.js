'use strict';

const { ok, warning, failure, info } = require( './checks' );
const { displayUrl } = require( './url-guard' );

const RANGE_HEADER = 'bytes=0-99';
const RANGE_BYTES = 100;
const RANGE_READ_LIMIT = 4096;
const HTML_TYPES = [ 'text/html', 'application/xhtml+xml' ];
const HTML_MARKERS = [ '<!doctype html', '<html', '<head', '<body' ];

/**
 * Normaliza un Content-Type: sin parámetros, recortado y en minúsculas.
 *
 * @param {string|undefined|null} value Valor del encabezado.
 * @return {string|null}
 */
function normalizeType( value ) {
	if ( value === undefined || value === null || value === '' ) {
		return null;
	}
	return String( value ).split( ';' )[ 0 ].trim().toLowerCase();
}

/**
 * Indica si el inicio del cuerpo parece HTML.
 *
 * @param {Buffer} head Primeros bytes del cuerpo.
 * @return {boolean}
 */
function looksLikeHtml( head ) {
	if ( ! head || head.length === 0 ) {
		return false;
	}
	let start = 0;
	if ( head[ 0 ] === 0xef && head[ 1 ] === 0xbb && head[ 2 ] === 0xbf ) {
		start = 3;
	}
	const sample = head.subarray( start, start + 128 ).toString( 'latin1' ).replace( /^\s+/, '' ).toLowerCase();
	return HTML_MARKERS.some( ( marker ) => sample.startsWith( marker ) );
}

function toNumber( value ) {
	if ( value === undefined || value === null || value === '' || ! /^\d+$/.test( String( value ) ) ) {
		return null;
	}
	return Number( value );
}

/**
 * Comprueba un enclosure con HEAD, GET y GET con Range.
 *
 * @param {{request: Function}} client Cliente HTTP.
 * @param {Object} item Episodio con enclosure.
 * @param {Object} options Opciones (maxBytes, checkCacheControl).
 * @return {Promise<{checks: Object[], head: Object, get: Object, range: Object}>}
 */
async function checkEnclosure( client, item, options = {} ) {
	const { maxBytes = 104857600, checkCacheControl = false } = options;
	const enclosure = item.enclosure;
	const checks = [];
	const empty = { status: null, contentType: null, contentLength: null, error: null };
	const result = {
		checks,
		head: { ...empty, acceptRanges: null },
		get: { ...empty, bytes: null, truncated: false, htmlSignature: false },
		range: { status: null, contentType: null, contentRange: null, bytes: null, error: null },
	};

	let url;
	try {
		url = new URL( enclosure.url );
	} catch ( error ) {
		checks.push( failure( 'enclosure.url', 'enclosure', `La URL del enclosure no es válida: ${ enclosure.url }` ) );
		return result;
	}
	if ( url.username !== '' || url.password !== '' ) {
		checks.push(
			failure(
				'enclosure.blocked',
				'enclosure',
				`URL de enclosure con credenciales no permitida: ${ displayUrl( url ) }`
			)
		);
		return result;
	}

	const declaredType = normalizeType( enclosure.type );
	const declaredLength = enclosure.length;

	const requests = [
		[ 'head', 'HEAD', { method: 'HEAD' } ],
		[ 'get', 'GET', { method: 'GET', maxBytes } ],
		[ 'range', 'RANGE', { method: 'GET', headers: { Range: RANGE_HEADER }, maxBytes: RANGE_READ_LIMIT } ],
	];
	const responses = {};
	for ( const [ key, label, opts ] of requests ) {
		try {
			responses[ key ] = await client.request( url, opts );
		} catch ( error ) {
			if ( error && ( error.name === 'BlockedHostError' || error.name === 'BlockedProtocolError' ) ) {
				checks.push( failure( 'enclosure.blocked', 'enclosure', `${ error.message }`, { method: label } ) );
				return result;
			}
			const message = error && error.message ? error.message : String( error );
			result[ key ].error = message;
			checks.push(
				failure( `enclosure.${ key }.network`, 'enclosure', `${ label }: ${ message }`, { method: label } )
			);
		}
	}
	const { head, get, range } = responses;

	if ( head ) {
		Object.assign( result.head, {
			status: head.status,
			contentType: head.headers[ 'content-type' ] ?? null,
			contentLength: toNumber( head.headers[ 'content-length' ] ),
			acceptRanges: head.headers[ 'accept-ranges' ] ?? null,
		} );
	}
	if ( get ) {
		Object.assign( result.get, {
			status: get.status,
			contentType: get.headers[ 'content-type' ] ?? null,
			contentLength: toNumber( get.headers[ 'content-length' ] ),
			bytes: get.bytesRead,
			truncated: get.truncated,
			htmlSignature: looksLikeHtml( get.head ),
		} );
	}
	if ( range ) {
		Object.assign( result.range, {
			status: range.status,
			contentType: range.headers[ 'content-type' ] ?? null,
			contentRange: range.headers[ 'content-range' ] ?? null,
			bytes: range.truncated ? `${ range.bytesRead }+` : range.bytesRead,
		} );
	}

	const expectStatus = ( id, response, expected, label ) => {
		if ( ! response ) {
			return;
		}
		if ( response.status === expected ) {
			checks.push( ok( id, 'enclosure', `${ label } respondió ${ expected }.`, { method: label } ) );
		} else {
			checks.push(
				failure( id, 'enclosure', `${ label } respondió ${ response.status } y se esperaba ${ expected }.`, {
					expected,
					actual: response.status,
					method: label,
				} )
			);
		}
	};
	expectStatus( 'head.status', head, 200, 'HEAD' );
	expectStatus( 'get.status', get, 200, 'GET' );
	expectStatus( 'range.status', range, 206, 'RANGE' );

	for ( const [ id, response, label ] of [
		[ 'head.content-type', head, 'HEAD' ],
		[ 'get.content-type', get, 'GET' ],
		[ 'range.content-type', range, 'RANGE' ],
	] ) {
		if ( ! response ) {
			continue;
		}
		const actual = normalizeType( response.headers[ 'content-type' ] );
		if ( actual === declaredType ) {
			checks.push( ok( id, 'enclosure', `${ label }: Content-Type coincide con el feed (${ actual }).`, { method: label } ) );
		} else {
			checks.push(
				failure(
					id,
					'enclosure',
					`${ label }: Content-Type ${ actual ?? 'ausente' } difiere del type declarado ${ declaredType ?? 'ausente' }.`,
					{ expected: declaredType ?? 'ausente', actual: actual ?? 'ausente', method: label }
				)
			);
		}
	}

	// Detección de HTML por Content-Type y por firma del cuerpo.
	const htmlTypes = [];
	for ( const [ response, label ] of [ [ head, 'HEAD' ], [ get, 'GET' ], [ range, 'RANGE' ] ] ) {
		const actual = response ? normalizeType( response.headers[ 'content-type' ] ) : null;
		if ( actual && HTML_TYPES.includes( actual ) ) {
			htmlTypes.push( `${ label }=${ actual }` );
		}
	}
	if ( htmlTypes.length > 0 ) {
		checks.push(
			failure( 'html.content-type', 'enclosure', `El enclosure devuelve HTML (${ htmlTypes.join( ', ' ) }).`, {
				actual: htmlTypes.join( ', ' ),
			} )
		);
	}
	const htmlBodies = [];
	if ( get && looksLikeHtml( get.head ) ) {
		htmlBodies.push( 'GET' );
	}
	if ( range && looksLikeHtml( range.head ) ) {
		htmlBodies.push( 'RANGE' );
	}
	if ( htmlBodies.length > 0 ) {
		checks.push(
			failure(
				'html.signature',
				'enclosure',
				`El cuerpo de ${ htmlBodies.join( ' y ' ) } empieza con un marcador HTML en lugar de audio.`,
				{ actual: htmlBodies.join( ', ' ) }
			)
		);
	}
	if ( htmlTypes.length === 0 && htmlBodies.length === 0 && ( get || range ) ) {
		checks.push( ok( 'html.none', 'enclosure', 'Ninguna respuesta parece HTML.' ) );
	}

	// Longitud del GET completo.
	if ( get && declaredLength !== null ) {
		if ( get.truncated ) {
			checks.push(
				info(
					'get.bytes',
					'enclosure',
					`No se pudo verificar length por bytes: la descarga superó --max-bytes (${ maxBytes }).`,
					{ method: 'GET' }
				)
			);
		} else if ( get.bytesRead === declaredLength ) {
			checks.push( ok( 'get.bytes', 'enclosure', `GET descargó ${ get.bytesRead } bytes, igual a length.`, { method: 'GET' } ) );
		} else {
			checks.push(
				failure(
					'get.bytes',
					'enclosure',
					`GET descargó ${ get.bytesRead } bytes y length declara ${ declaredLength }.`,
					{ expected: declaredLength, actual: get.bytesRead, method: 'GET' }
				)
			);
		}
	}

	if ( head && declaredLength !== null ) {
		const actual = toNumber( head.headers[ 'content-length' ] );
		if ( actual === declaredLength ) {
			checks.push( ok( 'head.content-length', 'enclosure', 'HEAD: Content-Length coincide con length.', { method: 'HEAD' } ) );
		} else {
			checks.push(
				failure(
					'head.content-length',
					'enclosure',
					`HEAD: Content-Length ${ actual ?? 'ausente' } difiere de length ${ declaredLength }.`,
					{ expected: declaredLength, actual: actual ?? 'ausente', method: 'HEAD' }
				)
			);
		}
	}

	if ( head ) {
		const acceptRanges = ( head.headers[ 'accept-ranges' ] ?? '' ).trim().toLowerCase();
		if ( acceptRanges === 'bytes' ) {
			checks.push( ok( 'head.accept-ranges', 'enclosure', 'HEAD: Accept-Ranges es bytes.', { method: 'HEAD' } ) );
		} else {
			checks.push(
				failure( 'head.accept-ranges', 'enclosure', 'HEAD: falta Accept-Ranges: bytes.', {
					expected: 'bytes',
					actual: head.headers[ 'accept-ranges' ] ?? 'ausente',
					method: 'HEAD',
				} )
			);
		}
	}

	if ( range ) {
		if ( range.bytesRead === RANGE_BYTES && ! range.truncated ) {
			checks.push( ok( 'range.bytes', 'enclosure', 'RANGE: el cuerpo tiene 100 bytes.', { method: 'RANGE' } ) );
		} else {
			checks.push(
				failure(
					'range.bytes',
					'enclosure',
					`RANGE: el cuerpo tiene ${ result.range.bytes } bytes y se esperaban ${ RANGE_BYTES }.`,
					{ expected: RANGE_BYTES, actual: result.range.bytes, method: 'RANGE' }
				)
			);
		}
		if ( declaredLength !== null ) {
			const expected = `bytes 0-99/${ declaredLength }`;
			const actual = ( range.headers[ 'content-range' ] ?? '' ).trim();
			if ( actual === expected ) {
				checks.push( ok( 'range.content-range', 'enclosure', 'RANGE: Content-Range es correcto.', { method: 'RANGE' } ) );
			} else {
				checks.push(
					failure(
						'range.content-range',
						'enclosure',
						`RANGE: Content-Range ${ actual || 'ausente' } difiere de ${ expected }.`,
						{ expected, actual: actual || 'ausente', method: 'RANGE' }
					)
				);
			}
		}
	}

	// Paridad GET vs HEAD.
	if ( head && get ) {
		const parity = [];
		if ( head.status !== get.status ) {
			parity.push( [ 'parity.status', 'estado', head.status, get.status ] );
		}
		const headType = normalizeType( head.headers[ 'content-type' ] );
		const getType = normalizeType( get.headers[ 'content-type' ] );
		if ( headType !== getType ) {
			parity.push( [ 'parity.content-type', 'Content-Type', headType ?? 'ausente', getType ?? 'ausente' ] );
		}
		const getLength = toNumber( get.headers[ 'content-length' ] );
		const headLength = toNumber( head.headers[ 'content-length' ] );
		if ( getLength !== null && getLength !== headLength ) {
			parity.push( [ 'parity.content-length', 'Content-Length', headLength ?? 'ausente', getLength ] );
		}
		for ( const [ id, label, headValue, getValue ] of parity ) {
			checks.push(
				failure( id, 'enclosure', `GET difiere de HEAD en ${ label }: HEAD=${ headValue }, GET=${ getValue }.`, {
					expected: headValue,
					actual: getValue,
				} )
			);
		}
		if ( parity.length === 0 ) {
			checks.push( ok( 'parity', 'enclosure', 'GET y HEAD coinciden en estado, Content-Type y Content-Length.' ) );
		}
	}

	if ( checkCacheControl ) {
		const noStore = [];
		for ( const [ response, label ] of [ [ head, 'HEAD' ], [ get, 'GET' ], [ range, 'RANGE' ] ] ) {
			if ( response && /(^|[\s,])no-store([\s,;]|$)/i.test( response.headers[ 'cache-control' ] ?? '' ) ) {
				noStore.push( label );
			}
		}
		if ( noStore.length > 0 ) {
			checks.push(
				warning( 'cache-control.no-store', 'enclosure', `Cache-Control incluye no-store (${ noStore.join( ', ' ) }).`, {
					actual: noStore.join( ', ' ),
				} )
			);
		}
	}

	return result;
}

module.exports = { checkEnclosure, normalizeType, looksLikeHtml, RANGE_HEADER };
