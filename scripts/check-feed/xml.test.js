/**
 * @jest-environment node
 */
'use strict';

const { parse, child, children, text, XmlError, MAX_DEPTH } = require( './xml' );

describe( 'xml', () => {
	test( 'analiza un documento con namespaces, CDATA y comentarios', () => {
		const root = parse(
			'\ufeff<?xml version="1.0" encoding="UTF-8"?>\n<!-- c -->\n<rss version="2.0" xmlns:itunes="urn:it"><channel><title>Hola</title><itunes:author>Ana</itunes:author><desc><![CDATA[<b>negrita</b>]]></desc></channel></rss>\n'
		);
		expect( root.local ).toBe( 'rss' );
		expect( root.attrs.version ).toBe( '2.0' );
		const channel = child( root, 'channel' );
		expect( text( child( channel, 'title' ) ) ).toBe( 'Hola' );
		expect( text( child( channel, 'author', 'urn:it' ) ) ).toBe( 'Ana' );
		expect( text( child( channel, 'desc' ) ) ).toBe( '<b>negrita</b>' );
		expect( root.declaredNamespaces.has( 'urn:it' ) ).toBe( true );
	} );

	test( 'resuelve namespaces por URI aunque cambie el prefijo', () => {
		const root = parse( '<a xmlns:x="urn:it" xmlns="urn:def"><x:b/><c/></a>' );
		expect( children( root, 'b', 'urn:it' ) ).toHaveLength( 1 );
		expect( child( root, 'c' ).ns ).toBe( 'urn:def' );
	} );

	test( 'decodifica las entidades predefinidas y numéricas', () => {
		const root = parse( '<a href="https://e.com/?x=1&amp;y=2">&lt;t&gt; &quot;q&quot; &apos;a&apos; &#65;&#x42;</a>' );
		expect( root.attrs.href ).toBe( 'https://e.com/?x=1&y=2' );
		expect( text( root ) ).toBe( '<t> "q" \'a\' AB' );
	} );

	test( 'acepta comillas simples y elementos vacíos', () => {
		const root = parse( "<a b='1'><c d=\"2\" /></a>" );
		expect( root.attrs.b ).toBe( '1' );
		expect( child( root, 'c' ).attrs.d ).toBe( '2' );
	} );

	test.each( [
		[ 'DOCTYPE', '<!DOCTYPE a><a/>' ],
		[ 'DOCTYPE con entidad externa', '<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a>&x;</a>' ],
		[ 'billion laughs', '<!DOCTYPE l [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]><l>&b;</l>' ],
		[ 'ENTITY suelta', '<a><!ENTITY x "y"></a>' ],
		[ 'entidad no predefinida', '<a>&nbsp;</a>' ],
		[ 'ampersand suelto', '<a>a & b</a>' ],
		[ 'entidad sin cerrar', '<a>&amp</a>' ],
		[ 'entidad numérica inválida', '<a>&#0;</a>' ],
		[ 'etiqueta sin cerrar', '<a><b></a>' ],
		[ 'etiquetas mal anidadas', '<a><b></a></b>' ],
		[ 'cierre sin apertura', '</a>' ],
		[ 'dos raíces', '<a/><b/>' ],
		[ 'texto fuera de la raíz', 'hola <a/>' ],
		[ 'atributo duplicado', '<a x="1" x="2"/>' ],
		[ 'atributo sin comillas', '<a x=1/>' ],
		[ 'atributo con <', '<a x="<"/>' ],
		[ 'carácter de control', '<a>\u0001</a>' ],
		[ 'prefijo no declarado', '<a><x:b/></a>' ],
		[ 'comentario sin cerrar', '<a><!-- x</a>' ],
		[ 'CDATA sin cerrar', '<a><![CDATA[x</a>' ],
		[ 'vacío', '' ],
		[ 'solo espacios', '   \n ' ],
		[ 'sin raíz', '<!-- solo comentario -->' ],
	] )( 'rechaza: %s', ( name, input ) => {
		expect( () => parse( input ) ).toThrow( XmlError );
	} );

	test( 'los errores informan línea y columna', () => {
		try {
			parse( '<a>\n<b>\n</a>' );
			throw new Error( 'debió fallar' );
		} catch ( error ) {
			expect( error ).toBeInstanceOf( XmlError );
			expect( error.line ).toBe( 3 );
			expect( error.message ).toMatch( /línea 3/ );
		}
	} );

	test( 'no expande entidades: el DOCTYPE se rechaza antes de interpretar nada', () => {
		const start = Date.now();
		const bomb = `<!DOCTYPE l [${ Array.from( { length: 20 }, ( _, i ) => `<!ENTITY e${ i } "${ i ? `&e${ i - 1 };`.repeat( 10 ) : 'x' }">` ).join( '' ) }]><l>&e19;</l>`;
		expect( () => parse( bomb ) ).toThrow( XmlError );
		expect( Date.now() - start ).toBeLessThan( 500 );
	} );

	test( 'limita la profundidad sin recursión', () => {
		const ok = `${ '<a>'.repeat( MAX_DEPTH ) }${ '</a>'.repeat( MAX_DEPTH ) }`;
		expect( () => parse( ok ) ).not.toThrow();
		const deep = `${ '<a>'.repeat( MAX_DEPTH + 1 ) }${ '</a>'.repeat( MAX_DEPTH + 1 ) }`;
		expect( () => parse( deep ) ).toThrow( /Profundidad/ );
		const huge = `${ '<a>'.repeat( 100000 ) }`;
		expect( () => parse( huge ) ).toThrow( XmlError );
	} );
} );
