'use strict';

const { parse, XmlError } = require( './xml' );
const { validateFeed } = require( './feed-validator' );
const { validateItems } = require( './item-validator' );
const { checkEnclosure } = require( './enclosure-checker' );
const { buildResult } = require( './report' );
const { ok, warning, failure } = require( './checks' );

function fetchFailure( error ) {
	const message = error && error.message ? error.message : String( error );
	if ( error && ( error.name === 'BlockedHostError' || error.name === 'BlockedProtocolError' ) ) {
		return failure( 'feed.blocked', 'feed', message );
	}
	if ( error && error.code === 'feed-too-large' ) {
		return failure( 'feed.size', 'feed', message );
	}
	if ( error && error.code === 'too-many-redirects' ) {
		return failure( 'redirects.exceeded', 'feed', message );
	}
	return failure( 'feed.fetch', 'feed', message );
}

async function checkImage( client, channel, baseUrl ) {
	const image = channel.image;
	let target;
	try {
		target = new URL( image.url, baseUrl );
	} catch ( error ) {
		channel.checks.push( failure( 'channel.image.url', 'channel', `La URL de la imagen no es válida: ${ image.url }` ) );
		return;
	}
	image.url = target.href;
	try {
		const response = await client.request( target, { method: 'HEAD' } );
		image.status = response.status;
		image.contentType = response.headers[ 'content-type' ] ?? null;
		const type = ( image.contentType ?? '' ).split( ';' )[ 0 ].trim().toLowerCase();
		if ( response.status >= 200 && response.status < 300 && type.startsWith( 'image/' ) ) {
			channel.checks.push( ok( 'channel.image', 'channel', `La imagen responde ${ response.status } (${ type }).` ) );
		} else {
			channel.checks.push(
				failure(
					'channel.image',
					'channel',
					`La imagen responde ${ response.status } con Content-Type ${ type || 'ausente' }; se esperaba 2xx e image/*.`,
					{ actual: `${ response.status } ${ type || 'ausente' }` }
				)
			);
		}
	} catch ( error ) {
		channel.checks.push( failure( 'channel.image', 'channel', `No se pudo comprobar la imagen: ${ error.message }` ) );
	}
}

/**
 * Ejecuta el control completo del feed.
 *
 * @param {string} feedUrl URL del feed.
 * @param {Object} options Opciones de la CLI.
 * @param {{request: Function}} client Cliente HTTP.
 * @return {Promise<Object>} Resultado listo para renderizar.
 */
async function run( feedUrl, options, client ) {
	const startedAt = new Date();
	const base = { feedUrl, options, startedAt };
	const finish = ( extra ) => buildResult( { ...base, ...extra, finishedAt: new Date() } );

	let response;
	try {
		response = await client.request( feedUrl, { method: 'GET', keepBody: true, isFeed: true } );
	} catch ( error ) {
		return finish( { feedChecks: [ fetchFailure( error ) ] } );
	}
	const common = { finalUrl: response.finalUrl, status: response.status };
	if ( response.status !== 200 ) {
		return finish( {
			...common,
			feedChecks: [
				failure( 'feed.status', 'feed', `El feed respondió ${ response.status } y se esperaba 200.`, {
					expected: 200,
					actual: response.status,
				} ),
			],
		} );
	}

	let root;
	try {
		root = parse( response.body.toString( 'utf8' ) );
	} catch ( error ) {
		if ( ! ( error instanceof XmlError ) ) {
			throw error;
		}
		return finish( {
			...common,
			feedChecks: [ failure( 'feed.xml', 'feed', `El feed no es XML válido: ${ error.message }` ) ],
		} );
	}

	const { checks: feedChecks, channel, items: itemNodes } = validateFeed( root );
	if ( ! channel ) {
		return finish( { ...common, feedChecks } );
	}
	if ( channel.image.url ) {
		await checkImage( client, channel, response.finalUrl );
	}

	const parsedItems = validateItems( itemNodes );
	const limited = parsedItems.slice( 0, options.limit );
	if ( parsedItems.length > limited.length ) {
		feedChecks.push(
			warning( 'feed.limit', 'feed', `Se revisaron ${ limited.length } de ${ parsedItems.length } episodios (--limit ${ options.limit }).` )
		);
	}

	const items = [];
	for ( const item of limited ) {
		let enclosure = null;
		if ( item.enclosure ) {
			enclosure = { url: item.enclosure.url, length: item.enclosure.length, type: item.enclosure.type };
			if ( item.enclosure.url !== null ) {
				const result = await checkEnclosure( client, item, options );
				item.checks.push( ...result.checks );
				enclosure.head = result.head;
				enclosure.get = result.get;
				enclosure.range = result.range;
			}
		}
		items.push( { ...item, enclosure } );
	}

	return finish( { ...common, feedChecks, channel, items, episodesFound: parsedItems.length } );
}

module.exports = { run };
