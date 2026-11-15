/**
 * @jest-environment node
 */
'use strict';

const { createClient } = require( './http-client' );
const { run } = require( './runner' );
const { renderText, renderJson } = require( './report' );
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

const baseOptions = { limit: 50, timeout: 30, maxRedirects: 5, maxBytes: 104857600, allowPrivate: true, checkCacheControl: false };
const execute = ( path, overrides = {}, clientConfig = {} ) => {
	const options = { ...baseOptions, ...overrides };
	const client = createClient( { allowPrivate: options.allowPrivate, maxRedirects: options.maxRedirects, ...clientConfig } );
	return run( `${ server.url }${ path }`, options, client );
};
const failureIds = ( result ) =>
	[ ...result.feed.checks, ...( result.channel ? result.channel.checks : [] ), ...result.items.flatMap( ( i ) => i.checks ) ]
		.filter( ( c ) => c.result === 'failure' )
		.map( ( c ) => c.id );

describe( 'runner', () => {
	test( 'feed válido: sin fallos, exit 0, canal y episodios extraídos', async () => {
		const result = await execute( '/feed-ok.xml' );
		expect( result.exitCode ).toBe( 0 );
		expect( result.totals ).toMatchObject( { episodesFound: 2, episodesChecked: 2, episodesSkipped: 0, failures: 0, warnings: 0 } );
		expect( result.channel ).toMatchObject( {
			title: 'Canal de prueba',
			generator: 'LB Simple Podcast 0.8.0',
			image: { status: 200, contentType: 'image/jpeg' },
		} );
		expect( result.items[ 0 ] ).toMatchObject( { title: 'Episodio 1', guid: 'ep-1' } );
		expect( result.items[ 0 ].enclosure ).toMatchObject( { length: 2000, type: 'audio/mpeg' } );
		expect( result.items[ 0 ].enclosure.head.status ).toBe( 200 );
		expect( result.items[ 0 ].enclosure.range.status ).toBe( 206 );
	} );

	test( 'solo envía GET y HEAD (solo lectura)', async () => {
		await execute( '/feed-ok.xml' );
		const methods = new Set( server.requests.map( ( r ) => r.method ) );
		expect( [ ...methods ].sort() ).toEqual( [ 'GET', 'HEAD' ] );
	} );

	test.each( [ '/feed-invalid.xml', '/feed-doctype.xml' ] )( 'XML inválido o con DOCTYPE (%s): Fallo feed.xml sin revisar episodios', async ( path ) => {
		const result = await execute( path );
		expect( failureIds( result ) ).toEqual( [ 'feed.xml' ] );
		expect( result.exitCode ).toBe( 1 );
		expect( result.items ).toEqual( [] );
		expect( server.requests ).toHaveLength( 1 );
	} );

	test( 'feed con estado 404: Fallo y no se revisan episodios', async () => {
		const result = await execute( '/feed-404' );
		expect( failureIds( result ) ).toEqual( [ 'feed.status' ] );
		expect( result.feed.status ).toBe( 404 );
		expect( result.exitCode ).toBe( 1 );
		expect( result.items ).toEqual( [] );
	} );

	test( 'feed mayor que el máximo', async () => {
		const result = await execute( '/feed-huge.xml', {}, { maxFeedBytes: 3000 } );
		expect( failureIds( result ) ).toEqual( [ 'feed.size' ] );
		expect( result.exitCode ).toBe( 1 );
	} );

	test( 'enclosure con GET=HTML y HEAD=audio: fallos y exit 1', async () => {
		const result = await execute( '/feed-html-get.xml' );
		expect( result.exitCode ).toBe( 1 );
		expect( failureIds( result ) ).toEqual( expect.arrayContaining( [ 'html.content-type', 'html.signature', 'parity.content-type' ] ) );
		expect( result.items[ 0 ].enclosure.head.status ).toBe( 200 );
		expect( result.items[ 0 ].enclosure.get.contentType ).toMatch( /text\/html/ );
	} );

	test( 'length incorrecto', async () => {
		const result = await execute( '/feed-wrong-length.xml' );
		expect( failureIds( result ) ).toContain( 'get.bytes' );
		expect( result.exitCode ).toBe( 1 );
	} );

	test( 'estados distintos de 200 y 206', async () => {
		const result = await execute( '/feed-status.xml' );
		expect( failureIds( result ) ).toEqual( expect.arrayContaining( [ 'head.status', 'get.status', 'range.status' ] ) );
		expect( result.items ).toHaveLength( 2 );
		expect( result.items.every( ( item ) => item.failures > 0 ) ).toBe( true );
	} );

	test( 'host privado bloqueado: el feed no se pide', async () => {
		const result = await execute( '/feed-ok.xml', { allowPrivate: false } );
		expect( failureIds( result ) ).toEqual( [ 'feed.blocked' ] );
		expect( result.exitCode ).toBe( 1 );
		expect( server.requests ).toHaveLength( 0 );
	} );

	test( 'host privado en enclosure e imagen: se bloquean sin peticiones a ellos', async () => {
		// Resolución simulada: el host del feed es público y los de enclosure e imagen son privados.
		const feed = `<?xml version="1.0"?><rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom"><channel><title>x</title><generator>g</generator><lastBuildDate>Sat, 14 Nov 2026 17:32:51 +0000</lastBuildDate><itunes:image href="http://interno.test/i.jpg"/><item><title>t</title><guid>g</guid><pubDate>Sat, 14 Nov 2026 17:32:51 +0000</pubDate><itunes:duration>1</itunes:duration><enclosure url="http://interno.test/a.mp3" length="5" type="audio/mpeg"/></item></channel></rss>`;
		const { BlockedHostError } = require( './url-guard' );
		const calls = [];
		const client = {
			request: async ( url, opts ) => {
				const target = new URL( url );
				calls.push( target.host );
				if ( target.host === 'feed.test' ) {
					return { status: 200, headers: {}, bytesRead: feed.length, truncated: false, head: Buffer.alloc( 0 ), body: Buffer.from( feed ), finalUrl: target.href, redirects: [] };
				}
				throw new BlockedHostError( `Host privado bloqueado: ${ target.hostname }` );
			},
		};
		const result = await run( 'http://feed.test/f.xml', baseOptions, client );
		expect( failureIds( result ) ).toEqual( expect.arrayContaining( [ 'channel.image', 'enclosure.blocked' ] ) );
		expect( result.exitCode ).toBe( 1 );
		expect( calls.filter( ( host ) => host === 'interno.test' ) ).toHaveLength( 2 );
	} );

	test( 'redirección del feed a un host privado: Fallo', async () => {
		const { BlockedHostError } = require( './url-guard' );
		const resolve = async ( host ) => {
			if ( host === 'blocked.test' ) {
				throw new BlockedHostError( 'Host privado bloqueado: blocked.test' );
			}
			return { address: '127.0.0.1', family: 4 };
		};
		const result = await execute( '/redirect-blocked', {}, { resolve } );
		expect( failureIds( result ) ).toEqual( [ 'feed.blocked' ] );
	} );

	test( 'redirecciones en exceso', async () => {
		const result = await execute( '/redirect-loop', { maxRedirects: 2 } );
		expect( failureIds( result ) ).toEqual( [ 'redirects.exceeded' ] );
	} );

	test( 'feed sin namespaces: un fallo por cada uno', async () => {
		const result = await execute( '/feed-no-namespaces.xml' );
		expect( failureIds( result ).filter( ( id ) => id.startsWith( 'feed.namespace' ) ).sort() ).toEqual( [
			'feed.namespace.atom',
			'feed.namespace.content',
			'feed.namespace.itunes',
		] );
	} );

	test( 'feed sin items: solo una advertencia, exit 0', async () => {
		const result = await execute( '/feed-no-items.xml' );
		expect( result.exitCode ).toBe( 0 );
		expect( result.totals ).toMatchObject( { episodesFound: 0, failures: 0 } );
		expect( result.totals.warnings ).toBeGreaterThan( 0 );
	} );

	test( 'guid duplicado', async () => {
		const result = await execute( '/feed-dup-guid.xml' );
		expect( failureIds( result ).filter( ( id ) => id === 'item.guid.duplicate' ) ).toHaveLength( 2 );
	} );

	test( 'tope de episodios: revisa hasta --limit, informa los omitidos y advierte', async () => {
		const result = await execute( '/feed-ok.xml', { limit: 1 } );
		expect( result.totals ).toMatchObject( { episodesFound: 2, episodesChecked: 1, episodesSkipped: 1 } );
		expect( result.feed.checks.some( ( c ) => c.id === 'feed.limit' && c.result === 'warning' ) ).toBe( true );
		expect( result.exitCode ).toBe( 0 );
		const audioRequests = server.requests.filter( ( r ) => r.url.startsWith( '/audio/' ) );
		expect( audioRequests ).toHaveLength( 3 );
	} );

	test( 'advertencias de Cache-Control no cambian el código de salida', async () => {
		const result = await execute( '/feed-no-store.xml', { checkCacheControl: true } );
		expect( result.totals.failures ).toBe( 0 );
		expect( result.totals.warnings ).toBe( 1 );
		expect( result.exitCode ).toBe( 0 );
	} );
} );

describe( 'report', () => {
	test( 'el JSON es un único documento válido con canal, episodios, comprobaciones y totales', async () => {
		const result = await execute( '/feed-html-get.xml' );
		const parsed = JSON.parse( renderJson( result ) );
		expect( parsed ).toMatchObject( { tool: 'check-feed', exitCode: 1 } );
		expect( parsed.channel.title ).toBe( 'Canal de prueba' );
		expect( parsed.items[ 0 ].enclosure ).toHaveProperty( 'head' );
		expect( parsed.items[ 0 ].checks.length ).toBeGreaterThan( 0 );
		expect( parsed.totals.failures ).toBe( parsed.items[ 0 ].failures + parsed.feed.checks.filter( ( c ) => c.result === 'failure' ).length );
		expect( renderJson( result ).endsWith( '\n' ) ).toBe( true );
	} );

	test( 'el texto muestra el canal, cada episodio, los motivos y los totales', async () => {
		const result = await execute( '/feed-html-get.xml' );
		const text = renderText( result, { color: false } );
		expect( text ).toContain( 'Canal de prueba' );
		expect( text ).toContain( 'LB Simple Podcast 0.8.0' );
		expect( text ).toContain( 'Roto' );
		expect( text ).toMatch( /FALLO html\.signature/ );
		expect( text ).toMatch( /HEAD=audio\/mpeg, GET=text\/html/ );
		expect( text ).toMatch( /Episodios revisados: 1 de 1/ );
		expect( text ).toMatch( /Fallos: \d+/ );
		expect( text ).toMatch( /Advertencias: \d+/ );
	} );

	test( 'sin color no hay secuencias de escape ANSI; con color sí', async () => {
		const result = await execute( '/feed-html-get.xml' );
		expect( renderText( result, { color: false } ) ).not.toMatch( /\u001b\[/ );
		expect( renderText( result, { color: true } ) ).toMatch( /\u001b\[/ );
	} );

	test( 'exitCode es 0 si y solo si no hay fallos, aunque haya advertencias', async () => {
		const warn = await execute( '/feed-no-items.xml' );
		expect( warn.totals.warnings ).toBeGreaterThan( 0 );
		expect( warn.exitCode ).toBe( 0 );
		const fail = await execute( '/feed-wrong-length.xml' );
		expect( fail.exitCode ).toBe( 1 );
	} );

	test( 'los errores de red del feed producen un resultado completo', async () => {
		const result = await run( 'http://no-existe.test/', baseOptions, createClient( { lookup: async () => { throw Object.assign( new Error( 'x' ), { code: 'ENOTFOUND' } ); } } ) );
		expect( failureIds( result ) ).toEqual( [ 'feed.fetch' ] );
		expect( result.exitCode ).toBe( 1 );
		expect( JSON.parse( renderJson( result ) ).feed.checks[ 0 ].message ).toMatch( /resolver/ );
	} );
} );
