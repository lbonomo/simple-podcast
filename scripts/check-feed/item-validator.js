'use strict';

const { child, text } = require( './xml' );
const { NS } = require( './feed-validator' );
const { ok, warning, failure } = require( './checks' );

const LENGTH_RE = /^[1-9]\d*$/;
const AUDIO_TYPE_RE = /^audio\/[\w.+-]+$/i;

/**
 * Extrae y valida los datos de cada episodio.
 *
 * @param {Object[]} itemNodes Nodos <item>.
 * @return {Object[]} Episodios con sus comprobaciones.
 */
function validateItems( itemNodes ) {
	const items = itemNodes.map( ( node, index ) => {
		const checks = [];
		const title = text( child( node, 'title' ) ) || null;
		const guid = text( child( node, 'guid' ) ) || null;
		const pubDate = text( child( node, 'pubDate' ) ) || null;
		const duration = text( child( node, 'duration', NS.itunes ) ) || null;
		const enclosureNode = child( node, 'enclosure' );

		for ( const [ key, value ] of [
			[ 'title', title ],
			[ 'guid', guid ],
		] ) {
			if ( value === null ) {
				checks.push( failure( `item.${ key }`, 'item', `El episodio no tiene <${ key }>.` ) );
			} else {
				checks.push( ok( `item.${ key }`, 'item', `El episodio tiene <${ key }>.` ) );
			}
		}
		if ( pubDate === null ) {
			checks.push( warning( 'item.pubDate', 'item', 'El episodio no tiene <pubDate>.' ) );
		}
		if ( duration === null ) {
			checks.push( warning( 'item.duration', 'item', 'El episodio no tiene <itunes:duration>.' ) );
		}

		let enclosure = null;
		if ( ! enclosureNode ) {
			checks.push( failure( 'item.enclosure', 'item', 'El episodio no tiene <enclosure>.' ) );
		} else {
			const url = enclosureNode.attrs.url || null;
			const lengthRaw = enclosureNode.attrs.length ?? null;
			const type = enclosureNode.attrs.type ?? null;
			const lengthValid = lengthRaw !== null && LENGTH_RE.test( lengthRaw ) && Number.isSafeInteger( Number( lengthRaw ) );
			enclosure = { url, length: lengthValid ? Number( lengthRaw ) : null, lengthRaw, type };
			if ( url === null ) {
				checks.push( failure( 'item.enclosure.url', 'item', 'El <enclosure> no tiene el atributo url.' ) );
			}
			if ( ! lengthValid ) {
				checks.push(
					failure( 'item.enclosure.length', 'item', 'El atributo length del <enclosure> no es un entero positivo.', {
						actual: lengthRaw === null ? 'ausente' : lengthRaw,
					} )
				);
			}
			if ( type === null || ! AUDIO_TYPE_RE.test( type ) ) {
				checks.push(
					failure( 'item.enclosure.type', 'item', 'El atributo type del <enclosure> no es un tipo MIME audio/*.', {
						actual: type === null ? 'ausente' : type,
					} )
				);
			}
			if ( url !== null && lengthValid && type !== null && AUDIO_TYPE_RE.test( type ) ) {
				checks.push( ok( 'item.enclosure', 'item', 'El <enclosure> declara url, length y type válidos.' ) );
			}
		}

		return { index: index + 1, title, guid, pubDate, duration, enclosure, checks };
	} );

	const byGuid = new Map();
	for ( const item of items ) {
		if ( item.guid !== null ) {
			byGuid.set( item.guid, [ ...( byGuid.get( item.guid ) || [] ), item ] );
		}
	}
	for ( const [ guid, group ] of byGuid ) {
		if ( group.length > 1 ) {
			for ( const item of group ) {
				item.checks.push(
					failure( 'item.guid.duplicate', 'item', `El guid está duplicado en ${ group.length } episodios.`, {
						actual: guid,
					} )
				);
			}
		}
	}
	return items;
}

module.exports = { validateItems, LENGTH_RE, AUDIO_TYPE_RE };
