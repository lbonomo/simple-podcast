'use strict';

const dns = require( 'node:dns' );
const net = require( 'node:net' );

class BlockedHostError extends Error {
	constructor( message ) {
		super( message );
		this.name = 'BlockedHostError';
		this.code = 'blocked-host';
	}
}

class BlockedProtocolError extends Error {
	constructor( message ) {
		super( message );
		this.name = 'BlockedProtocolError';
		this.code = 'blocked-protocol';
	}
}

/**
 * Valida la URL recibida por parámetro.
 *
 * @param {string} text Texto recibido.
 * @return {{url: URL}|{error: string}}
 */
function validateInputUrl( text ) {
	let url;
	try {
		url = new URL( text );
	} catch ( error ) {
		return { error: `La URL no es válida: ${ text }` };
	}
	if ( url.protocol !== 'http:' && url.protocol !== 'https:' ) {
		return { error: `Solo se admiten URLs http o https (recibido: ${ url.protocol }).` };
	}
	if ( url.username !== '' || url.password !== '' ) {
		return { error: 'La URL no puede incluir credenciales (usuario:clave@host).' };
	}
	return { url };
}

/**
 * Lanza un error si el esquema no es http o https.
 *
 * @param {URL} url URL a comprobar.
 */
function assertSafeProtocol( url ) {
	if ( url.protocol !== 'http:' && url.protocol !== 'https:' ) {
		throw new BlockedProtocolError( `Esquema no permitido: ${ url.protocol }` );
	}
}

/**
 * Enmascara las credenciales de una URL para mostrarla.
 *
 * @param {URL} url URL a mostrar.
 * @return {string}
 */
function displayUrl( url ) {
	if ( url.username === '' && url.password === '' ) {
		return url.href;
	}
	const copy = new URL( url.href );
	copy.username = '***';
	copy.password = '';
	return copy.href;
}

function ipv4ToInt( ip ) {
	return ip.split( '.' ).reduce( ( acc, part ) => acc * 256 + Number( part ), 0 );
}

const IPV4_RANGES = [
	[ '0.0.0.0', 8 ],
	[ '10.0.0.0', 8 ],
	[ '100.64.0.0', 10 ],
	[ '127.0.0.0', 8 ],
	[ '169.254.0.0', 16 ],
	[ '172.16.0.0', 12 ],
	[ '192.0.0.0', 24 ],
	[ '192.0.2.0', 24 ],
	[ '192.168.0.0', 16 ],
	[ '198.18.0.0', 15 ],
	[ '198.51.100.0', 24 ],
	[ '203.0.113.0', 24 ],
	[ '224.0.0.0', 4 ],
	[ '240.0.0.0', 4 ],
].map( ( [ base, bits ] ) => ( { start: ipv4ToInt( base ), size: 2 ** ( 32 - bits ) } ) );

function isPrivateIpv4( ip ) {
	const value = ipv4ToInt( ip );
	return IPV4_RANGES.some( ( range ) => value >= range.start && value < range.start + range.size );
}

/**
 * Expande una dirección IPv6 a ocho grupos de 16 bits.
 *
 * @param {string} ip Dirección IPv6 válida.
 * @return {number[]}
 */
function expandIpv6( ip ) {
	let text = ip.split( '%' )[ 0 ].toLowerCase();
	const lastColon = text.lastIndexOf( ':' );
	const tail = text.slice( lastColon + 1 );
	if ( tail.includes( '.' ) ) {
		const parts = tail.split( '.' ).map( Number );
		const high = ( parts[ 0 ] << 8 ) | parts[ 1 ];
		const low = ( parts[ 2 ] << 8 ) | parts[ 3 ];
		text = `${ text.slice( 0, lastColon + 1 ) }${ high.toString( 16 ) }:${ low.toString( 16 ) }`;
	}
	const [ headText, tailText ] = text.split( '::' );
	const head = headText ? headText.split( ':' ) : [];
	const rest = tailText !== undefined && tailText !== '' ? tailText.split( ':' ) : [];
	const missing = text.includes( '::' ) ? 8 - head.length - rest.length : 0;
	return [ ...head, ...new Array( missing ).fill( '0' ), ...rest ].map( ( group ) => parseInt( group, 16 ) );
}

function isPrivateIpv6( ip ) {
	const groups = expandIpv6( ip );
	if ( groups.length !== 8 || groups.some( Number.isNaN ) ) {
		return true;
	}
	const [ g0, g1, g2, g3, g4, g5, g6, g7 ] = groups;
	const firstSix = [ g0, g1, g2, g3, g4 ].every( ( group ) => group === 0 );
	if ( firstSix && g5 === 0xffff ) {
		return isPrivateIpv4( `${ g6 >> 8 }.${ g6 & 255 }.${ g7 >> 8 }.${ g7 & 255 }` );
	}
	if ( firstSix && g5 === 0 && g6 === 0 && ( g7 === 0 || g7 === 1 ) ) {
		return true;
	}
	if ( ( g0 & 0xfe00 ) === 0xfc00 ) {
		return true;
	}
	if ( ( g0 & 0xffc0 ) === 0xfe80 ) {
		return true;
	}
	if ( ( g0 & 0xff00 ) === 0xff00 ) {
		return true;
	}
	return g0 === 0x2001 && g1 === 0x0db8;
}

/**
 * Indica si una dirección IP es privada, loopback, link-local o reservada.
 *
 * @param {string} ip Dirección IPv4 o IPv6.
 * @return {boolean}
 */
function isPrivateAddress( ip ) {
	const version = net.isIP( ip );
	if ( version === 4 ) {
		return isPrivateIpv4( ip );
	}
	if ( version === 6 ) {
		return isPrivateIpv6( ip );
	}
	return true;
}

/**
 * Resuelve un host y valida la dirección a la que se conectará el socket.
 *
 * @param {string} hostname Host (sin corchetes para IPv6).
 * @param {Object} options Opciones.
 * @param {boolean} options.allowPrivate Permite hosts privados.
 * @param {Function} options.lookup Función con la firma de dns.promises.lookup.
 * @return {Promise<{address: string, family: number}>}
 */
async function resolveSafe( hostname, { allowPrivate = false, lookup = dns.promises.lookup } = {} ) {
	const host = hostname.replace( /^\[|\]$/g, '' );
	const literal = net.isIP( host );
	let addresses;
	if ( literal ) {
		addresses = [ { address: host, family: literal } ];
	} else {
		try {
			addresses = await lookup( host, { all: true, verbatim: true } );
		} catch ( error ) {
			const err = new Error( `No se pudo resolver el host ${ host }` );
			err.code = 'dns';
			err.cause = error;
			throw err;
		}
	}
	if ( ! Array.isArray( addresses ) || addresses.length === 0 ) {
		const err = new Error( `No se pudo resolver el host ${ host }` );
		err.code = 'dns';
		throw err;
	}
	if ( allowPrivate ) {
		return { address: addresses[ 0 ].address, family: addresses[ 0 ].family };
	}
	const privateOne = addresses.find( ( entry ) => isPrivateAddress( entry.address ) );
	if ( privateOne ) {
		throw new BlockedHostError(
			`Host privado bloqueado: ${ host } resuelve a ${ privateOne.address } (use --allow-private para permitirlo)`
		);
	}
	return { address: addresses[ 0 ].address, family: addresses[ 0 ].family };
}

module.exports = {
	BlockedHostError,
	BlockedProtocolError,
	validateInputUrl,
	assertSafeProtocol,
	displayUrl,
	isPrivateAddress,
	resolveSafe,
};
