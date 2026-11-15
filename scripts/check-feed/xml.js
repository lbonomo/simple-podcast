'use strict';

/**
 * Parser XML mínimo y estricto para feeds RSS.
 *
 * No implementa DTD ni entidades definidas por el usuario: DOCTYPE y ENTITY se
 * rechazan, de modo que no hay expansión de entidades ni entidades externas.
 */

const MAX_DEPTH = 100;
const NAME_RE = /[\p{L}_:][\p{L}\p{N}_:.\-]*/uy;
const CONTROL_RE = /[\x00-\x08\x0B\x0C\x0E-\x1F]/; // eslint-disable-line no-control-regex
const PREDEFINED = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };

class XmlError extends Error {
	constructor( message, line = 1, column = 1 ) {
		super( `${ message } (línea ${ line }, columna ${ column })` );
		this.name = 'XmlError';
		this.line = line;
		this.column = column;
	}
}

function position( text, index ) {
	let line = 1;
	let last = -1;
	for ( let i = text.indexOf( '\n' ); i !== -1 && i < index; i = text.indexOf( '\n', i + 1 ) ) {
		line++;
		last = i;
	}
	return { line, column: index - last };
}

function decode( text, fail, offset ) {
	if ( ! text.includes( '&' ) ) {
		return text;
	}
	return text.replace( /&([^;\s&<]*);?/g, ( match, body, index ) => {
		if ( ! match.endsWith( ';' ) ) {
			fail( 'Referencia de entidad sin cerrar', offset + index );
		}
		if ( Object.hasOwn( PREDEFINED, body ) ) {
			return PREDEFINED[ body ];
		}
		let code = null;
		if ( /^#\d+$/.test( body ) ) {
			code = Number( body.slice( 1 ) );
		} else if ( /^#x[0-9a-fA-F]+$/.test( body ) ) {
			code = parseInt( body.slice( 2 ), 16 );
		}
		if ( code === null || code > 0x10ffff || code === 0 || ( code >= 0xd800 && code <= 0xdfff ) ) {
			fail( `Entidad no permitida: &${ body };`, offset + index );
		}
		return String.fromCodePoint( code );
	} );
}

/**
 * Analiza un documento XML.
 *
 * @param {string} input Texto XML.
 * @return {Object} Nodo raíz con name, prefix, local, ns, attrs, children, text y declaredNamespaces.
 */
function parse( input ) {
	let text = String( input );
	if ( text.charCodeAt( 0 ) === 0xfeff ) {
		text = text.slice( 1 );
	}
	const fail = ( message, index ) => {
		const { line, column } = position( text, index );
		throw new XmlError( message, line, column );
	};
	if ( text.trim() === '' ) {
		fail( 'Documento vacío', 0 );
	}
	const control = CONTROL_RE.exec( text );
	if ( control ) {
		fail( 'Carácter de control no permitido', control.index );
	}

	const declared = new Set();
	const stack = [];
	let root = null;
	let i = 0;
	const length = text.length;

	const readName = ( at ) => {
		NAME_RE.lastIndex = at;
		const match = NAME_RE.exec( text );
		if ( ! match ) {
			fail( 'Nombre de etiqueta o atributo no válido', at );
		}
		return match[ 0 ];
	};

	const addText = ( chunk, at ) => {
		if ( stack.length === 0 ) {
			if ( chunk.trim() !== '' ) {
				fail( 'Texto fuera del elemento raíz', at );
			}
			return;
		}
		stack[ stack.length - 1 ].text += decode( chunk, fail, at );
	};

	while ( i < length ) {
		const lt = text.indexOf( '<', i );
		if ( lt === -1 ) {
			addText( text.slice( i ), i );
			i = length;
			break;
		}
		if ( lt > i ) {
			addText( text.slice( i, lt ), i );
		}
		i = lt;

		if ( text.startsWith( '<!--', i ) ) {
			const end = text.indexOf( '-->', i + 4 );
			if ( end === -1 ) {
				fail( 'Comentario sin cerrar', i );
			}
			i = end + 3;
			continue;
		}
		if ( text.startsWith( '<![CDATA[', i ) ) {
			const end = text.indexOf( ']]>', i + 9 );
			if ( end === -1 ) {
				fail( 'Sección CDATA sin cerrar', i );
			}
			if ( stack.length === 0 ) {
				fail( 'CDATA fuera del elemento raíz', i );
			}
			stack[ stack.length - 1 ].text += text.slice( i + 9, end );
			i = end + 3;
			continue;
		}
		if ( text.startsWith( '<!', i ) ) {
			const upper = text.slice( i, i + 10 ).toUpperCase();
			if ( upper.startsWith( '<!DOCTYPE' ) ) {
				fail( 'Se rechaza la declaración DOCTYPE', i );
			}
			if ( upper.startsWith( '<!ENTITY' ) ) {
				fail( 'Se rechazan las declaraciones de entidades', i );
			}
			fail( 'Declaración no soportada', i );
		}
		if ( text.startsWith( '<?', i ) ) {
			const end = text.indexOf( '?>', i + 2 );
			if ( end === -1 ) {
				fail( 'Instrucción de procesamiento sin cerrar', i );
			}
			i = end + 2;
			continue;
		}
		if ( text.startsWith( '</', i ) ) {
			const name = readName( i + 2 );
			let j = i + 2 + name.length;
			while ( /\s/.test( text[ j ] ?? '' ) ) {
				j++;
			}
			if ( text[ j ] !== '>' ) {
				fail( 'Etiqueta de cierre mal formada', i );
			}
			const open = stack.pop();
			if ( ! open ) {
				fail( `Etiqueta de cierre sin apertura: </${ name }>`, i );
			}
			if ( open.name !== name ) {
				fail( `Etiqueta mal anidada: se esperaba </${ open.name }> y se encontró </${ name }>`, i );
			}
			i = j + 1;
			continue;
		}

		// Etiqueta de apertura.
		const name = readName( i + 1 );
		let j = i + 1 + name.length;
		const rawAttrs = [];
		let selfClosing = false;
		for ( ;; ) {
			const before = j;
			while ( /\s/.test( text[ j ] ?? '' ) ) {
				j++;
			}
			const ch = text[ j ];
			if ( ch === undefined ) {
				fail( 'Etiqueta sin cerrar', i );
			}
			if ( ch === '>' ) {
				j++;
				break;
			}
			if ( ch === '/' && text[ j + 1 ] === '>' ) {
				selfClosing = true;
				j += 2;
				break;
			}
			if ( j === before ) {
				fail( 'Falta un espacio entre atributos', j );
			}
			const attrName = readName( j );
			j += attrName.length;
			while ( /\s/.test( text[ j ] ?? '' ) ) {
				j++;
			}
			if ( text[ j ] !== '=' ) {
				fail( `El atributo ${ attrName } no tiene valor`, j );
			}
			j++;
			while ( /\s/.test( text[ j ] ?? '' ) ) {
				j++;
			}
			const quote = text[ j ];
			if ( quote !== '"' && quote !== "'" ) {
				fail( `El valor del atributo ${ attrName } debe ir entre comillas`, j );
			}
			const end = text.indexOf( quote, j + 1 );
			if ( end === -1 ) {
				fail( 'Valor de atributo sin cerrar', j );
			}
			const rawValue = text.slice( j + 1, end );
			if ( rawValue.includes( '<' ) ) {
				fail( 'Carácter "<" no permitido en un atributo', j );
			}
			if ( rawAttrs.some( ( attr ) => attr.name === attrName ) ) {
				fail( `Atributo duplicado: ${ attrName }`, j );
			}
			rawAttrs.push( { name: attrName, value: decode( rawValue, fail, j + 1 ) } );
			j = end + 1;
		}

		if ( stack.length === 0 && root !== null ) {
			fail( 'El documento tiene más de un elemento raíz', i );
		}
		if ( stack.length >= MAX_DEPTH ) {
			fail( `Profundidad máxima de ${ MAX_DEPTH } niveles superada`, i );
		}

		const parent = stack[ stack.length - 1 ];
		const nsMap = Object.create( parent ? parent.nsMap : { xml: 'http://www.w3.org/XML/1998/namespace' } );
		const attrs = {};
		for ( const attr of rawAttrs ) {
			attrs[ attr.name ] = attr.value;
			if ( attr.name === 'xmlns' ) {
				nsMap[ '' ] = attr.value;
				declared.add( attr.value );
			} else if ( attr.name.startsWith( 'xmlns:' ) ) {
				nsMap[ attr.name.slice( 6 ) ] = attr.value;
				declared.add( attr.value );
			}
		}
		const colon = name.indexOf( ':' );
		const prefix = colon === -1 ? '' : name.slice( 0, colon );
		const local = colon === -1 ? name : name.slice( colon + 1 );
		const ns = nsMap[ prefix ];
		if ( prefix !== '' && ns === undefined ) {
			fail( `Prefijo de namespace no declarado: ${ prefix }`, i );
		}
		const node = { name, prefix, local, ns: ns ?? '', attrs, children: [], text: '', nsMap };
		if ( parent ) {
			parent.children.push( node );
		} else {
			root = node;
		}
		if ( ! selfClosing ) {
			stack.push( node );
		}
		i = j;
	}

	if ( stack.length > 0 ) {
		fail( `Etiqueta sin cerrar: <${ stack[ stack.length - 1 ].name }>`, length );
	}
	if ( root === null ) {
		fail( 'No hay elemento raíz', 0 );
	}
	root.declaredNamespaces = declared;
	return root;
}

/**
 * Elementos hijos con un nombre local (y un namespace opcional).
 *
 * @param {Object} node Nodo.
 * @param {string} local Nombre local.
 * @param {string} [nsUri] URI del namespace; si se omite no se filtra.
 * @return {Object[]}
 */
function children( node, local, nsUri ) {
	return node.children.filter( ( child ) => child.local === local && ( nsUri === undefined || child.ns === nsUri ) );
}

function child( node, local, nsUri ) {
	return children( node, local, nsUri )[ 0 ] ?? null;
}

function text( node ) {
	return node ? node.text.trim() : '';
}

module.exports = { parse, children, child, text, XmlError, MAX_DEPTH };
