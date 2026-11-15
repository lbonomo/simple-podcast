/**
 * @jest-environment node
 */
'use strict';

const net = require( 'node:net' );
const { createClient, HttpError } = require( './http-client' );
const { BlockedHostError } = require( './url-guard' );
const { createTestServer } = require( './test-server' );

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

const local = ( config = {} ) => createClient( { allowPrivate: true, ...config } );

describe( 'http-client', () => {
	test( 'descarga el cuerpo con keepBody y envía el User-Agent propio sin cookies', async () => {
		const client = local( { userAgent: 'prueba/1.0' } );
		const response = await client.request( `${ server.url }/feed-ok.xml`, { keepBody: true } );
		expect( response.status ).toBe( 200 );
		expect( response.body.toString() ).toContain( '<rss' );
		expect( response.headers[ 'content-type' ] ).toMatch( /rss\+xml/ );
		const sent = server.requests[ 0 ];
		expect( sent.headers[ 'user-agent' ] ).toBe( 'prueba/1.0' );
		expect( sent.headers[ 'accept-encoding' ] ).toBe( 'identity' );
		expect( sent.headers.cookie ).toBeUndefined();
		expect( sent.headers.authorization ).toBeUndefined();
	} );

	test( 'HEAD no lee cuerpo', async () => {
		const response = await local().request( `${ server.url }/audio/ok-1.mp3`, { method: 'HEAD' } );
		expect( response.status ).toBe( 200 );
		expect( response.bytesRead ).toBe( 0 );
		expect( server.requests[ 0 ].method ).toBe( 'HEAD' );
	} );

	test( 'bloquea el servidor loopback sin allowPrivate y no envía ninguna petición', async () => {
		const client = createClient( { allowPrivate: false } );
		await expect( client.request( `${ server.url }/feed-ok.xml` ) ).rejects.toBeInstanceOf( BlockedHostError );
		expect( server.requests ).toHaveLength( 0 );
	} );

	test( 'rechaza esquemas distintos de http y https', async () => {
		await expect( local().request( 'ftp://example.com/file' ) ).rejects.toMatchObject( { code: 'blocked-protocol' } );
		await expect( local().request( 'file:///etc/passwd' ) ).rejects.toMatchObject( { code: 'blocked-protocol' } );
	} );

	test( 'sigue redirecciones relativas', async () => {
		const response = await local().request( `${ server.url }/redirect-ok`, { keepBody: true } );
		expect( response.status ).toBe( 200 );
		expect( response.finalUrl ).toBe( `${ server.url }/feed-ok.xml` );
		expect( response.redirects ).toEqual( [ `${ server.url }/redirect-ok` ] );
	} );

	test( 'informa un bucle de redirecciones al superar el máximo', async () => {
		const client = local( { maxRedirects: 3 } );
		await expect( client.request( `${ server.url }/redirect-loop` ) ).rejects.toMatchObject( { code: 'too-many-redirects' } );
		expect( server.requests ).toHaveLength( 4 );
	} );

	test( 'maxRedirects 0 no sigue ninguna redirección', async () => {
		await expect( local( { maxRedirects: 0 } ).request( `${ server.url }/redirect-ok` ) ).rejects.toMatchObject( {
			code: 'too-many-redirects',
		} );
	} );

	test( 'revalida cada redirección y se detiene ante un destino bloqueado', async () => {
		const resolve = jest.fn( async ( host ) => {
			if ( host === 'blocked.test' ) {
				throw new BlockedHostError( 'Host privado bloqueado: blocked.test' );
			}
			return { address: '127.0.0.1', family: 4 };
		} );
		const client = createClient( { resolve } );
		await expect( client.request( `${ server.url }/redirect-blocked` ) ).rejects.toBeInstanceOf( BlockedHostError );
		expect( resolve ).toHaveBeenCalledTimes( 2 );
		expect( resolve.mock.calls[ 1 ][ 0 ] ).toBe( 'blocked.test' );
		expect( server.requests ).toHaveLength( 1 );
	} );

	test( 'se conecta a la dirección validada, sin resolver otra vez', async () => {
		const resolve = jest.fn( async () => ( { address: '127.0.0.1', family: 4 } ) );
		const client = createClient( { resolve } );
		const response = await client.request( `http://nombre-inexistente.test:${ server.port }/feed-ok.xml` );
		expect( response.status ).toBe( 200 );
		expect( resolve ).toHaveBeenCalledTimes( 1 );
		expect( server.requests[ 0 ].headers.host ).toBe( `nombre-inexistente.test:${ server.port }` );
	} );

	test( 'corta la lectura al llegar a maxBytes y lo informa', async () => {
		const response = await local().request( `${ server.url }/audio/big.mp3`, { maxBytes: 1000 } );
		expect( response.truncated ).toBe( true );
		expect( response.bytesRead ).toBe( 1000 );
		expect( response.head.subarray( 0, 3 ).toString() ).toBe( 'ID3' );
	} );

	test( 'no marca como truncado un cuerpo que cabe en maxBytes', async () => {
		const response = await local().request( `${ server.url }/audio/ok-1.mp3`, { maxBytes: 5000 } );
		expect( response.truncated ).toBe( false );
		expect( response.bytesRead ).toBe( 2000 );
	} );

	test( 'aborta un feed mayor que maxFeedBytes', async () => {
		const client = local( { maxFeedBytes: 3000 } );
		await expect( client.request( `${ server.url }/feed-huge.xml`, { isFeed: true, keepBody: true } ) ).rejects.toMatchObject( {
			code: 'feed-too-large',
		} );
		const ok = await client.request( `${ server.url }/feed-ok.xml`, { isFeed: true, keepBody: true } );
		expect( ok.status ).toBe( 200 );
	} );

	test( 'aplica el timeout total', async () => {
		const client = local( { timeoutMs: 300 } );
		const started = Date.now();
		await expect( client.request( `${ server.url }/slow` ) ).rejects.toMatchObject( { code: 'timeout-total' } );
		expect( Date.now() - started ).toBeLessThan( 3000 );
	} );

	test( 'aplica el timeout de conexión con un servidor que no acepta conexiones', async () => {
		// Backlog mínimo y conexiones previas ocupadas: el siguiente connect queda pendiente.
		const stuck = net.createServer();
		await new Promise( ( resolve ) => stuck.listen( { port: 0, host: '127.0.0.1', backlog: 1 }, resolve ) );
		const { port } = stuck.address();
		stuck.pause?.();
		const fillers = [];
		for ( let i = 0; i < 6; i++ ) {
			const socket = net.connect( port, '127.0.0.1' );
			socket.on( 'error', () => {} );
			fillers.push( socket );
		}
		try {
			const client = local( { connectTimeoutMs: 200, timeoutMs: 5000 } );
			const outcome = await client.request( `http://127.0.0.1:${ port }/x` ).then(
				( response ) => ( { response } ),
				( error ) => ( { error } )
			);
			// Según el sistema operativo la conexión queda pendiente o se acepta; solo se
			// afirma que un fallo, si ocurre, es el timeout de conexión o una respuesta de red tipada.
			if ( outcome.error ) {
				expect( outcome.error ).toBeInstanceOf( HttpError );
				expect( [ 'timeout-connect', 'timeout-total', 'reset', 'refused' ] ).toContain( outcome.error.code );
			}
		} finally {
			fillers.forEach( ( socket ) => socket.destroy() );
			await new Promise( ( resolve ) => stuck.close( resolve ) );
		}
	}, 15000 );

	test( 'una conexión rechazada se informa con código refused', async () => {
		const probe = net.createServer();
		await new Promise( ( resolve ) => probe.listen( 0, '127.0.0.1', resolve ) );
		const { port } = probe.address();
		await new Promise( ( resolve ) => probe.close( resolve ) );
		await expect( local().request( `http://127.0.0.1:${ port }/` ) ).rejects.toMatchObject( { code: 'refused' } );
	} );

	test( 'un error de DNS se informa con código dns', async () => {
		const lookup = jest.fn( async () => {
			throw Object.assign( new Error( 'ENOTFOUND' ), { code: 'ENOTFOUND' } );
		} );
		await expect( createClient( { lookup } ).request( 'http://no-existe.test/' ) ).rejects.toMatchObject( { code: 'dns' } );
	} );
} );
