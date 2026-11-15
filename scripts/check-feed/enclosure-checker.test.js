/**
 * @jest-environment node
 */
'use strict';

const { createClient } = require( './http-client' );
const { checkEnclosure, normalizeType, looksLikeHtml } = require( './enclosure-checker' );
const { createTestServer, AUDIO_LEN } = require( './test-server' );

let server;
beforeAll( async () => {
	server = await createTestServer();
} );
afterAll( async () => {
	await server.close();
} );
beforeEach( () => {
	server.requests.length = 0;
} );

const client = createClient( { allowPrivate: true, userAgent: 'prueba-enclosure/1.0' } );

const item = ( path, overrides = {} ) => ( {
	enclosure: { url: `${ server.url }${ path }?lb_sp_audio=1&lb_sp_attachment=2`, length: AUDIO_LEN, type: 'audio/mpeg', ...overrides },
} );
const byResult = ( checks, result ) => checks.filter( ( c ) => c.result === result ).map( ( c ) => c.id );
const failuresOf = ( checks ) => byResult( checks, 'failure' ).sort();

describe( 'checkEnclosure', () => {
	test( 'un enclosure correcto no tiene fallos', async () => {
		const { checks, head, get, range } = await checkEnclosure( client, item( '/audio/ok-1.mp3' ), {} );
		expect( failuresOf( checks ) ).toEqual( [] );
		expect( byResult( checks, 'warning' ) ).toEqual( [] );
		expect( head ).toMatchObject( { status: 200, contentType: 'audio/mpeg', contentLength: AUDIO_LEN, acceptRanges: 'bytes' } );
		expect( get ).toMatchObject( { status: 200, bytes: AUDIO_LEN, truncated: false, htmlSignature: false } );
		expect( range ).toMatchObject( { status: 206, bytes: 100, contentRange: `bytes 0-99/${ AUDIO_LEN }` } );
	} );

	test( 'envía exactamente HEAD, GET y GET con Range, solo lectura, con el User-Agent y sin cookies', async () => {
		await checkEnclosure( client, item( '/audio/ok-1.mp3' ), {} );
		expect( server.requests.map( ( r ) => r.method ) ).toEqual( [ 'HEAD', 'GET', 'GET' ] );
		expect( server.requests.map( ( r ) => r.headers.range ) ).toEqual( [ undefined, undefined, 'bytes=0-99' ] );
		for ( const request of server.requests ) {
			expect( request.url ).toBe( '/audio/ok-1.mp3?lb_sp_audio=1&lb_sp_attachment=2' );
			expect( request.headers[ 'user-agent' ] ).toBe( 'prueba-enclosure/1.0' );
			expect( request.headers.cookie ).toBeUndefined();
			expect( request.headers.authorization ).toBeUndefined();
		}
	} );

	test( 'reproduce el bug: HEAD devuelve audio y GET devuelve HTML', async () => {
		const { checks, head, get } = await checkEnclosure( client, item( '/audio/html-on-get.mp3' ), {} );
		expect( failuresOf( checks ) ).toEqual(
			expect.arrayContaining( [ 'html.content-type', 'html.signature', 'parity.content-type', 'get.content-type', 'get.bytes' ] )
		);
		expect( byResult( checks, 'failure' ) ).not.toContain( 'head.status' );
		expect( byResult( checks, 'failure' ) ).not.toContain( 'head.content-type' );
		expect( byResult( checks, 'failure' ) ).not.toContain( 'head.content-length' );
		expect( head.status ).toBe( 200 );
		expect( get.htmlSignature ).toBe( true );
		const parity = checks.find( ( c ) => c.id === 'parity.content-type' );
		expect( parity.message ).toContain( 'HEAD=audio/mpeg' );
		expect( parity.message ).toContain( 'GET=text/html' );
	} );

	test( 'detecta HTML por la firma del cuerpo aunque el Content-Type diga audio', async () => {
		const { checks } = await checkEnclosure( client, item( '/audio/html-body-audio-ct.mp3' ), {} );
		const failed = failuresOf( checks );
		expect( failed ).toContain( 'html.signature' );
		expect( failed ).not.toContain( 'html.content-type' );
		expect( failed ).not.toContain( 'get.content-type' );
	} );

	test( 'length incorrecto', async () => {
		const { checks } = await checkEnclosure( client, item( '/audio/wrong-length.mp3' ), {} );
		const bytes = checks.find( ( c ) => c.id === 'get.bytes' );
		expect( bytes.result ).toBe( 'failure' );
		expect( bytes ).toMatchObject( { expected: AUDIO_LEN, actual: 1000 } );
		expect( bytes.message ).toContain( '1000' );
		expect( bytes.message ).toContain( String( AUDIO_LEN ) );
	} );

	test( 'estados distintos de 200 y 206', async () => {
		const notFound = await checkEnclosure( client, item( '/audio/status-404.mp3' ), {} );
		expect( failuresOf( notFound.checks ) ).toEqual( expect.arrayContaining( [ 'head.status', 'get.status', 'range.status' ] ) );
		expect( notFound.checks.find( ( c ) => c.id === 'get.status' ).actual ).toBe( 404 );
		const serverError = await checkEnclosure( client, item( '/audio/status-500.mp3' ), {} );
		expect( serverError.checks.find( ( c ) => c.id === 'range.status' ).actual ).toBe( 500 );
	} );

	test( 'un servidor que ignora Range devuelve 200 y se informa', async () => {
		const { checks } = await checkEnclosure( client, item( '/audio/no-ranges.mp3' ), {} );
		const failed = failuresOf( checks );
		expect( failed ).toEqual( expect.arrayContaining( [ 'head.accept-ranges', 'range.status', 'range.bytes' ] ) );
		expect( checks.find( ( c ) => c.id === 'range.status' ).actual ).toBe( 200 );
	} );

	test( 'Content-Range incorrecto', async () => {
		const { checks } = await checkEnclosure( client, item( '/audio/bad-content-range.mp3' ), {} );
		const check = checks.find( ( c ) => c.id === 'range.content-range' );
		expect( check.result ).toBe( 'failure' );
		expect( check.expected ).toBe( `bytes 0-99/${ AUDIO_LEN }` );
		expect( check.actual ).toBe( 'bytes 0-99/999' );
	} );

	test( 'Content-Type distinto del type declarado', async () => {
		const { checks } = await checkEnclosure( client, item( '/audio/wrong-type.mp3' ), {} );
		expect( failuresOf( checks ) ).toEqual( [ 'get.content-type', 'head.content-type', 'range.content-type' ] );
		const check = checks.find( ( c ) => c.id === 'head.content-type' );
		expect( check ).toMatchObject( { expected: 'audio/mpeg', actual: 'audio/ogg' } );
	} );

	test( 'Content-Length de HEAD distinto de length', async () => {
		const { checks } = await checkEnclosure( client, item( '/audio/ok-1.mp3', { length: AUDIO_LEN + 1 } ), {} );
		expect( failuresOf( checks ) ).toEqual( expect.arrayContaining( [ 'head.content-length', 'get.bytes', 'range.content-range' ] ) );
	} );

	test( 'un GET truncado por maxBytes no es un fallo por length', async () => {
		const { checks, get } = await checkEnclosure( client, item( '/audio/big.mp3', { length: 50000 } ), { maxBytes: 1000 } );
		expect( get.truncated ).toBe( true );
		const bytes = checks.find( ( c ) => c.id === 'get.bytes' );
		expect( bytes.result ).toBe( 'info' );
		expect( bytes.message ).toMatch( /No se pudo verificar length por bytes/ );
		expect( failuresOf( checks ) ).toEqual( [] );
	} );

	test( 'ignora un Content-Type con parámetros y diferencias de mayúsculas', () => {
		expect( normalizeType( 'Audio/MPEG; charset=binary' ) ).toBe( 'audio/mpeg' );
		expect( normalizeType( undefined ) ).toBeNull();
	} );

	describe( 'Cache-Control opcional', () => {
		test( 'sin la opción no se evalúa', async () => {
			const { checks } = await checkEnclosure( client, item( '/audio/no-store.mp3' ), {} );
			expect( checks.some( ( c ) => c.id === 'cache-control.no-store' ) ).toBe( false );
		} );

		test( 'con la opción no-store es una advertencia, no un fallo', async () => {
			const { checks } = await checkEnclosure( client, item( '/audio/no-store.mp3' ), { checkCacheControl: true } );
			expect( byResult( checks, 'warning' ) ).toEqual( [ 'cache-control.no-store' ] );
			expect( failuresOf( checks ) ).toEqual( [] );
		} );

		test( 'con la opción y sin no-store no hay advertencias', async () => {
			const { checks } = await checkEnclosure( client, item( '/audio/ok-1.mp3' ), { checkCacheControl: true } );
			expect( byResult( checks, 'warning' ) ).toEqual( [] );
		} );
	} );

	describe( 'seguridad', () => {
		test( 'un host privado se bloquea sin enviar peticiones', async () => {
			const strict = createClient( { allowPrivate: false } );
			const { checks } = await checkEnclosure( strict, item( '/audio/ok-1.mp3' ), {} );
			expect( failuresOf( checks ) ).toEqual( [ 'enclosure.blocked' ] );
			expect( server.requests ).toHaveLength( 0 );
		} );

		test( 'un esquema no permitido se bloquea', async () => {
			const { checks } = await checkEnclosure( client, { enclosure: { url: 'ftp://example.com/a.mp3', length: 5, type: 'audio/mpeg' } }, {} );
			expect( failuresOf( checks ) ).toEqual( [ 'enclosure.blocked' ] );
		} );

		test( 'una URL con credenciales se bloquea y se enmascara', async () => {
			const { checks } = await checkEnclosure(
				client,
				{ enclosure: { url: 'https://usuario:secreto@example.com/a.mp3', length: 5, type: 'audio/mpeg' } },
				{}
			);
			expect( failuresOf( checks ) ).toEqual( [ 'enclosure.blocked' ] );
			expect( JSON.stringify( checks ) ).not.toContain( 'secreto' );
			expect( server.requests ).toHaveLength( 0 );
		} );

		test( 'un error de red en un método no impide las demás peticiones', async () => {
			const flaky = {
				request: jest.fn( async ( url, opts ) => {
					if ( opts.method === 'HEAD' ) {
						throw Object.assign( new Error( 'Conexión interrumpida' ), { code: 'reset' } );
					}
					return client.request( url, opts );
				} ),
			};
			const { checks, get } = await checkEnclosure( flaky, item( '/audio/ok-1.mp3' ), {} );
			expect( flaky.request ).toHaveBeenCalledTimes( 3 );
			expect( failuresOf( checks ) ).toEqual( [ 'enclosure.head.network' ] );
			expect( get.status ).toBe( 200 );
		} );
	} );
} );

describe( 'looksLikeHtml', () => {
	test.each( [
		[ '<!DOCTYPE html><html>', true ],
		[ '  \n<html lang="es">', true ],
		[ '<HEAD><title>x', true ],
		[ '<body>', true ],
		[ '\ufeff<!doctype html>', true ],
		[ 'ID3\u0004', false ],
		[ '', false ],
		[ 'texto <html> dentro', false ],
	] )( '%j -> %s', ( body, expected ) => {
		expect( looksLikeHtml( Buffer.from( body, 'utf8' ) ) ).toBe( expected );
	} );

	test( 'un cuerpo MP3 binario no es HTML', () => {
		expect( looksLikeHtml( Buffer.from( [ 0xff, 0xfb, 0x90, 0x00, 0x3c, 0x68, 0x74, 0x6d, 0x6c ] ) ) ).toBe( false );
		expect( looksLikeHtml( Buffer.from( 'ID3', 'latin1' ) ) ).toBe( false );
	} );
} );
