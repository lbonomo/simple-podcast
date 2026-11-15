/**
 * @jest-environment node
 */
'use strict';

const path = require( 'node:path' );
const { spawn } = require( 'node:child_process' );
const { createTestServer } = require( './test-server' );

const SCRIPT = path.join( __dirname, '..', 'check-feed.js' );

let server;
beforeAll( async () => {
	server = await createTestServer();
} );
afterAll( async () => {
	await server.close();
} );

// El servidor de prueba vive en este proceso, por eso se usa spawn asíncrono
// (spawnSync bloquearía el bucle de eventos y el servidor no respondería).
function cli( args ) {
	return new Promise( ( resolve ) => {
		const child = spawn( process.execPath, [ SCRIPT, ...args ], { env: { ...process.env, NO_COLOR: '1' } } );
		let stdout = '';
		let stderr = '';
		child.stdout.on( 'data', ( chunk ) => ( stdout += chunk ) );
		child.stderr.on( 'data', ( chunk ) => ( stderr += chunk ) );
		child.on( 'close', ( status ) => resolve( { status, stdout, stderr } ) );
	} );
}

describe( 'proceso real', () => {
	test( 'exit 0 con un feed válido', async () => {
		const result = await cli( [ `${ server.url }/feed-ok.xml`, '--allow-private' ] );
		expect( result.status ).toBe( 0 );
		expect( result.stdout ).toContain( 'Canal de prueba' );
		expect( result.stdout ).toMatch( /Fallos: 0/ );
		expect( result.stderr ).toBe( '' );
	} );

	test( 'exit 1 con un fallo de comprobación', async () => {
		const result = await cli( [ `${ server.url }/feed-html-get.xml`, '--allow-private' ] );
		expect( result.status ).toBe( 1 );
		expect( result.stdout ).toMatch( /FALLO html\.signature/ );
	} );

	test( 'exit 1 sin --allow-private contra un host privado, con resultado completo', async () => {
		const result = await cli( [ `${ server.url }/feed-ok.xml`, '--json' ] );
		expect( result.status ).toBe( 1 );
		expect( JSON.parse( result.stdout ).feed.checks[ 0 ].id ).toBe( 'feed.blocked' );
	} );

	test.each( [
		[ 'sin URL', [] ],
		[ 'URL inválida', [ 'no-es-una-url' ] ],
		[ 'esquema no permitido', [ 'ftp://example.com/feed' ] ],
		[ 'opción desconocida', [ 'https://example.com/f', '--nope' ] ],
	] )( 'exit 2 ante error de uso (%s): stdout vacío y mensaje por stderr', async ( name, args ) => {
		const result = await cli( args );
		expect( result.status ).toBe( 2 );
		expect( result.stdout ).toBe( '' );
		expect( result.stderr ).toMatch( /Error:/ );
	} );

	test( '--json imprime solo un documento JSON en stdout', async () => {
		const result = await cli( [ `${ server.url }/feed-ok.xml`, '--allow-private', '--json' ] );
		expect( result.status ).toBe( 0 );
		const parsed = JSON.parse( result.stdout );
		expect( parsed.totals.failures ).toBe( 0 );
		expect( result.stdout.trim().startsWith( '{' ) ).toBe( true );
		expect( result.stdout ).not.toMatch( /\u001b\[/ );
		expect( result.stderr ).toBe( '' );
	} );

	test( '--help y -h salen con 0 sin hacer peticiones', async () => {
		const before = server.requests.length;
		for ( const flag of [ '--help', '-h' ] ) {
			const result = await cli( [ flag ] );
			expect( result.status ).toBe( 0 );
			expect( result.stdout ).toMatch( /Uso:/ );
			expect( result.stdout ).toMatch( /Códigos de salida/ );
		}
		expect( server.requests.length ).toBe( before );
	} );

	test( 'una URL inválida no realiza ninguna petición', async () => {
		const before = server.requests.length;
		await cli( [ 'ftp://127.0.0.1/feed' ] );
		expect( server.requests.length ).toBe( before );
	} );
} );
