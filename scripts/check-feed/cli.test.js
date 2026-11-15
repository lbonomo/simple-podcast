/**
 * @jest-environment node
 */
'use strict';

const { parseCli, HELP, DEFAULTS } = require( './cli' );

describe( 'cli', () => {
	test( 'acepta una URL y aplica los valores por defecto', () => {
		const result = parseCli( [ 'https://example.com/feed/' ], {}, true );
		expect( result.ok ).toBe( true );
		expect( result.options ).toMatchObject( {
			url: 'https://example.com/feed/',
			json: false,
			allowPrivate: false,
			checkCacheControl: false,
			color: true,
			...DEFAULTS,
		} );
	} );

	test( 'interpreta todas las opciones', () => {
		const result = parseCli(
			[
				'http://example.com/f',
				'--json',
				'--allow-private',
				'--limit',
				'3',
				'--timeout',
				'7',
				'--max-redirects',
				'0',
				'--max-bytes',
				'1000',
				'--check-cache-control',
				'--no-color',
			],
			{},
			true
		);
		expect( result.options ).toMatchObject( {
			json: true,
			allowPrivate: true,
			limit: 3,
			timeout: 7,
			maxRedirects: 0,
			maxBytes: 1000,
			checkCacheControl: true,
			color: false,
		} );
	} );

	test( 'desactiva el color sin TTY o con NO_COLOR', () => {
		expect( parseCli( [ 'https://e.com/f' ], {}, false ).options.color ).toBe( false );
		expect( parseCli( [ 'https://e.com/f' ], { NO_COLOR: '1' }, true ).options.color ).toBe( false );
	} );

	test.each( [
		[ 'sin URL', [] ],
		[ 'más de una URL', [ 'https://a.com/', 'https://b.com/' ] ],
		[ 'URL no válida', [ 'no-es-una-url' ] ],
		[ 'esquema ftp', [ 'ftp://example.com/feed' ] ],
		[ 'esquema file', [ 'file:///etc/passwd' ] ],
		[ 'credenciales', [ 'https://user:pass@example.com/feed' ] ],
		[ 'opción desconocida', [ 'https://example.com/f', '--nope' ] ],
		[ 'valor no numérico', [ 'https://example.com/f', '--limit', 'abc' ] ],
		[ 'limit cero', [ 'https://example.com/f', '--limit', '0' ] ],
		[ 'timeout negativo', [ 'https://example.com/f', '--timeout', '-1' ] ],
	] )( 'rechaza: %s', ( name, argv ) => {
		const result = parseCli( argv, {}, false );
		expect( result.ok ).toBe( false );
		expect( typeof result.message ).toBe( 'string' );
		expect( result.message.length ).toBeGreaterThan( 0 );
	} );

	test( '--help y -h devuelven la ayuda', () => {
		expect( parseCli( [ '--help' ], {}, false ) ).toEqual( { ok: true, help: true } );
		expect( parseCli( [ '-h' ], {}, false ) ).toEqual( { ok: true, help: true } );
		expect( parseCli( [ 'https://e.com/f', '-h' ], {}, false ).help ).toBe( true );
	} );

	test( 'la ayuda describe uso, opciones y códigos de salida', () => {
		expect( HELP ).toMatch( /Uso:/ );
		expect( HELP ).toMatch( /--allow-private/ );
		expect( HELP ).toMatch( /Códigos de salida/ );
		expect( HELP ).toMatch( /0 {2}Sin fallos/ );
		expect( HELP ).toMatch( /2 {2}Error de uso/ );
	} );
} );
