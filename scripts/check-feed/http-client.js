'use strict';

const http = require( 'node:http' );
const https = require( 'node:https' );
const { assertSafeProtocol, resolveSafe, displayUrl } = require( './url-guard' );

const CONNECT_TIMEOUT_MS = 10000;
const MAX_FEED_BYTES = 10 * 1024 * 1024;
const HEAD_BYTES = 512;
const REDIRECT_STATUSES = [ 301, 302, 303, 307, 308 ];
const TLS_CODES = /^(ERR_TLS|ERR_SSL|CERT_|DEPTH_ZERO|UNABLE_TO_|SELF_SIGNED|HOSTNAME_MISMATCH|ERR_OSSL)/;

class HttpError extends Error {
	constructor( code, message ) {
		super( message );
		this.name = 'HttpError';
		this.code = code;
	}
}

function mapNetworkError( error, url ) {
	if ( error instanceof HttpError ) {
		return error;
	}
	if ( error && ( error.name === 'BlockedHostError' || error.name === 'BlockedProtocolError' ) ) {
		return error;
	}
	const where = displayUrl( url );
	const code = error && error.code ? String( error.code ) : '';
	if ( code === 'dns' || code === 'ENOTFOUND' || code === 'EAI_AGAIN' ) {
		return new HttpError( 'dns', `No se pudo resolver el nombre del host de ${ where }` );
	}
	if ( code === 'ECONNREFUSED' ) {
		return new HttpError( 'refused', `Conexión rechazada por ${ url.host }` );
	}
	if ( code === 'ECONNRESET' || code === 'EPIPE' ) {
		return new HttpError( 'reset', `Conexión interrumpida con ${ url.host }` );
	}
	if ( TLS_CODES.test( code ) ) {
		return new HttpError( 'tls', `Error TLS con ${ url.host }: ${ error.message }` );
	}
	return new HttpError( 'other', `Error de red con ${ where }: ${ error && error.message ? error.message : error }` );
}

/**
 * Crea un cliente HTTP que valida cada destino y se conecta a la IP validada.
 *
 * @param {Object} config Configuración.
 * @return {{request: Function}}
 */
function createClient( config = {} ) {
	const {
		timeoutMs = 30000,
		connectTimeoutMs = CONNECT_TIMEOUT_MS,
		maxRedirects = 5,
		allowPrivate = false,
		userAgent = 'lb-simple-podcast-check-feed',
		lookup,
		maxFeedBytes = MAX_FEED_BYTES,
		resolve = resolveSafe,
	} = config;

	function once( url, address, family, opts, deadline ) {
		return new Promise( ( resolve, reject ) => {
			const remaining = deadline - Date.now();
			if ( remaining <= 0 ) {
				reject( new HttpError( 'timeout-total', `Se superó el timeout total de ${ timeoutMs / 1000 } s` ) );
				return;
			}
			const method = opts.method || 'GET';
			const isHttps = url.protocol === 'https:';
			const limit = opts.isFeed ? maxFeedBytes : opts.maxBytes ?? Infinity;
			let done = false;
			let connectTimer = null;
			let totalTimer = null;
			let req;

			const finish = ( error, value ) => {
				if ( done ) {
					return;
				}
				done = true;
				clearTimeout( connectTimer );
				clearTimeout( totalTimer );
				if ( req ) {
					req.destroy();
				}
				if ( error ) {
					reject( error );
				} else {
					resolve( value );
				}
			};

			totalTimer = setTimeout(
				() => finish( new HttpError( 'timeout-total', `Se superó el timeout total de ${ timeoutMs / 1000 } s` ) ),
				remaining
			);

			const lib = isHttps ? https : http;
			req = lib.request( {
				protocol: url.protocol,
				hostname: url.hostname.replace( /^\[|\]$/g, '' ),
				port: url.port || ( isHttps ? 443 : 80 ),
				path: url.pathname + url.search,
				method,
				agent: false,
				headers: {
					'User-Agent': userAgent,
					Accept: '*/*',
					'Accept-Encoding': 'identity',
					Host: url.host,
					...( opts.headers || {} ),
				},
				lookup: ( host, options, callback ) => {
					if ( options && options.all ) {
						callback( null, [ { address, family } ] );
					} else {
						callback( null, address, family );
					}
				},
			} );

			req.on( 'socket', ( socket ) => {
				if ( socket.connecting ) {
					connectTimer = setTimeout(
						() =>
							finish(
								new HttpError(
									'timeout-connect',
									`Se superó el timeout de conexión de ${ connectTimeoutMs / 1000 } s con ${ url.host }`
								)
							),
						connectTimeoutMs
					);
					socket.once( isHttps ? 'secureConnect' : 'connect', () => clearTimeout( connectTimer ) );
				}
			} );

			req.on( 'error', ( error ) => finish( mapNetworkError( error, url ) ) );

			req.on( 'response', ( res ) => {
				const headers = {};
				for ( const [ key, value ] of Object.entries( res.headers ) ) {
					headers[ key.toLowerCase() ] = Array.isArray( value ) ? value.join( ', ' ) : String( value );
				}
				const status = res.statusCode;
				const isRedirect = REDIRECT_STATUSES.includes( status ) && headers.location;
				if ( method === 'HEAD' || isRedirect ) {
					res.resume();
					finish( null, { status, headers, bytesRead: 0, truncated: false, head: Buffer.alloc( 0 ) } );
					return;
				}
				const chunks = [];
				let head = Buffer.alloc( 0 );
				let bytesRead = 0;
				res.on( 'data', ( chunk ) => {
					if ( done ) {
						return;
					}
					let piece = chunk;
					let truncated = false;
					if ( bytesRead + piece.length > limit ) {
						if ( opts.isFeed ) {
							finish( new HttpError( 'feed-too-large', `El feed supera el tamaño máximo de ${ limit } bytes` ) );
							return;
						}
						piece = piece.subarray( 0, Math.max( 0, limit - bytesRead ) );
						truncated = true;
					}
					if ( head.length < HEAD_BYTES ) {
						head = Buffer.concat( [ head, piece.subarray( 0, HEAD_BYTES - head.length ) ] );
					}
					bytesRead += piece.length;
					if ( opts.keepBody ) {
						chunks.push( piece );
					}
					if ( truncated ) {
						finish( null, {
							status,
							headers,
							bytesRead,
							truncated: true,
							head,
							body: opts.keepBody ? Buffer.concat( chunks ) : undefined,
						} );
					}
				} );
				res.on( 'end', () =>
					finish( null, {
						status,
						headers,
						bytesRead,
						truncated: false,
						head,
						body: opts.keepBody ? Buffer.concat( chunks ) : undefined,
					} )
				);
				res.on( 'error', ( error ) => finish( mapNetworkError( error, url ) ) );
				res.on( 'aborted', () => finish( new HttpError( 'reset', `Respuesta interrumpida por ${ url.host }` ) ) );
			} );

			req.end();
		} );
	}

	/**
	 * Realiza una petición siguiendo redirecciones y validando cada destino.
	 *
	 * @param {string|URL} target URL.
	 * @param {Object} opts method, headers, maxBytes, keepBody, isFeed.
	 * @return {Promise<Object>}
	 */
	async function request( target, opts = {} ) {
		const deadline = Date.now() + timeoutMs;
		let current = target instanceof URL ? target : new URL( target );
		const redirects = [];
		for ( let hop = 0; ; hop++ ) {
			assertSafeProtocol( current );
			const { address, family } = await resolve( current.hostname, { allowPrivate, lookup } );
			const response = await once( current, address, family, opts, deadline );
			if ( REDIRECT_STATUSES.includes( response.status ) && response.headers.location ) {
				if ( hop >= maxRedirects ) {
					throw new HttpError(
						'too-many-redirects',
						`Se superó el máximo de ${ maxRedirects } redirecciones: ${ [ ...redirects, current.href ].join( ' -> ' ) }`
					);
				}
				redirects.push( current.href );
				try {
					current = new URL( response.headers.location, current );
				} catch ( error ) {
					throw new HttpError( 'other', `Redirección con Location no válido: ${ response.headers.location }` );
				}
				continue;
			}
			return { ...response, finalUrl: current.href, redirects };
		}
	}

	return { request };
}

module.exports = { createClient, HttpError, CONNECT_TIMEOUT_MS, MAX_FEED_BYTES, HEAD_BYTES };
