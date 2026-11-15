'use strict';

const { child, children, text } = require( './xml' );
const { ok, warning, failure } = require( './checks' );

const NS = {
	itunes: 'http://www.itunes.com/dtds/podcast-1.0.dtd',
	content: 'http://purl.org/rss/1.0/modules/content/',
	atom: 'http://www.w3.org/2005/Atom',
};

const RFC822_RE = /^(?:[A-Za-z]{3},\s+)?\d{1,2}\s+[A-Za-z]{3}\s+\d{2,4}\s+\d{2}:\d{2}(?::\d{2})?\s+(?:[+-]\d{4}|[A-Za-z]{1,5})$/;

/**
 * Indica si una fecha tiene formato RFC 822.
 *
 * @param {string} value Fecha.
 * @return {boolean}
 */
function isRfc822( value ) {
	return RFC822_RE.test( value ) && ! Number.isNaN( Date.parse( value ) );
}

/**
 * Valida la estructura del feed y extrae los datos del canal.
 *
 * @param {Object} root Nodo raíz del XML.
 * @return {{checks: Object[], channel: Object|null, items: Object[]}}
 */
function validateFeed( root ) {
	const checks = [];
	if ( root.local !== 'rss' || root.ns !== '' || root.attrs.version !== '2.0' ) {
		checks.push(
			failure( 'feed.root', 'feed', 'El elemento raíz debe ser <rss version="2.0">.', {
				expected: '<rss version="2.0">',
				actual: `<${ root.name }${ root.attrs.version ? ` version="${ root.attrs.version }"` : '' }>`,
			} )
		);
	} else {
		checks.push( ok( 'feed.root', 'feed', 'La raíz es <rss version="2.0">.' ) );
	}

	for ( const [ key, uri ] of Object.entries( NS ) ) {
		if ( root.declaredNamespaces && root.declaredNamespaces.has( uri ) ) {
			checks.push( ok( `feed.namespace.${ key }`, 'feed', `Namespace ${ key } declarado.` ) );
		} else {
			checks.push(
				failure( `feed.namespace.${ key }`, 'feed', `Falta declarar el namespace ${ key } (${ uri }).`, {
					expected: uri,
				} )
			);
		}
	}

	const channelNode = child( root, 'channel' );
	if ( ! channelNode ) {
		checks.push( failure( 'feed.channel', 'feed', 'El feed no contiene <channel>.' ) );
		return { checks, channel: null, items: [] };
	}
	const title = text( child( channelNode, 'title' ) );
	if ( title === '' ) {
		checks.push( failure( 'feed.channel.title', 'feed', 'El canal no tiene <title>.' ) );
	} else {
		checks.push( ok( 'feed.channel.title', 'feed', 'El canal tiene título.' ) );
	}

	const items = children( channelNode, 'item' );
	if ( items.length === 0 ) {
		checks.push( warning( 'feed.items', 'feed', 'El canal no contiene ningún <item>.' ) );
	}

	const generator = text( child( channelNode, 'generator' ) ) || null;
	const lastBuildDate = text( child( channelNode, 'lastBuildDate' ) ) || null;
	const itunesImage = child( channelNode, 'image', NS.itunes );
	const rssImage = child( channelNode, 'image', '' );
	const imageUrl = ( itunesImage && itunesImage.attrs.href ) || text( rssImage && child( rssImage, 'url' ) ) || null;

	const channelChecks = [];
	if ( generator === null ) {
		channelChecks.push( warning( 'channel.generator', 'channel', 'El canal no declara <generator>.' ) );
	}
	if ( lastBuildDate === null ) {
		channelChecks.push( warning( 'channel.lastBuildDate', 'channel', 'El canal no declara <lastBuildDate>.' ) );
	} else if ( ! isRfc822( lastBuildDate ) ) {
		channelChecks.push(
			warning( 'channel.lastBuildDate', 'channel', 'lastBuildDate no es una fecha RFC 822 válida.', {
				actual: lastBuildDate,
			} )
		);
	} else {
		channelChecks.push( ok( 'channel.lastBuildDate', 'channel', 'lastBuildDate es una fecha RFC 822 válida.' ) );
	}
	if ( imageUrl === null ) {
		channelChecks.push( warning( 'channel.image', 'channel', 'El canal no declara una imagen.' ) );
	}

	return {
		checks,
		channel: { title: title || null, generator, lastBuildDate, image: { url: imageUrl }, checks: channelChecks },
		items,
	};
}

module.exports = { validateFeed, isRfc822, NS };
