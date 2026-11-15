'use strict';

const { countChecks } = require( './checks' );
const { displayUrl } = require( './url-guard' );

const TOOL_VERSION = require( '../../package.json' ).version;

/**
 * Arma el objeto resultado con totales y código de salida.
 *
 * @param {Object} data Datos de la ejecución.
 * @return {Object}
 */
function buildResult( data ) {
	const { feedUrl, finalUrl = null, status = null, options, feedChecks, channel = null, items = [] } = data;
	const startedAt = data.startedAt ?? new Date();
	const finishedAt = data.finishedAt ?? new Date();
	const episodesFound = data.episodesFound ?? items.length;

	const items2 = items.map( ( item ) => ( { ...item, ...countChecks( item.checks ) } ) );
	const all = [ ...feedChecks, ...( channel ? channel.checks : [] ), ...items2.flatMap( ( item ) => item.checks ) ];
	const { failures, warnings } = countChecks( all );

	return {
		tool: 'check-feed',
		version: TOOL_VERSION,
		feedUrl,
		finalUrl,
		startedAt: startedAt.toISOString(),
		durationMs: finishedAt.getTime() - startedAt.getTime(),
		options: {
			limit: options.limit,
			timeout: options.timeout,
			maxRedirects: options.maxRedirects,
			maxBytes: options.maxBytes,
			allowPrivate: options.allowPrivate,
			checkCacheControl: options.checkCacheControl,
		},
		feed: { status, checks: feedChecks },
		channel,
		items: items2,
		totals: {
			episodesFound,
			episodesChecked: items2.length,
			episodesSkipped: Math.max( 0, episodesFound - items2.length ),
			failures,
			warnings,
		},
		exitCode: failures > 0 ? 1 : 0,
	};
}

function renderJson( result ) {
	return `${ JSON.stringify( result, null, 2 ) }\n`;
}

const ANSI = { red: '31', green: '32', yellow: '33', bold: '1', dim: '2' };

function painter( color ) {
	return ( name, text ) => ( color ? `\u001b[${ ANSI[ name ] }m${ text }\u001b[0m` : text );
}

function value( input ) {
	return input === null || input === undefined ? 'ausente' : String( input );
}

function listChecks( checks, paint, indent ) {
	const lines = [];
	const okCount = checks.filter( ( c ) => c.result === 'ok' ).length;
	for ( const check of checks ) {
		if ( check.result === 'failure' ) {
			lines.push( `${ indent }${ paint( 'red', 'FALLO' ) } ${ check.id }: ${ check.message }` );
		} else if ( check.result === 'warning' ) {
			lines.push( `${ indent }${ paint( 'yellow', 'ADVERTENCIA' ) } ${ check.id }: ${ check.message }` );
		} else if ( check.result === 'info' ) {
			lines.push( `${ indent }${ paint( 'dim', 'INFO' ) } ${ check.id }: ${ check.message }` );
		}
	}
	if ( okCount > 0 ) {
		lines.push( `${ indent }${ paint( 'green', 'OK' ) } ${ okCount } comprobaciones correctas` );
	}
	return lines;
}

function statusLabel( item, paint ) {
	if ( item.failures > 0 ) {
		return paint( 'red', 'FALLO' );
	}
	if ( item.warnings > 0 ) {
		return paint( 'yellow', 'ADVERTENCIA' );
	}
	return paint( 'green', 'OK' );
}

/**
 * Genera el informe en texto legible.
 *
 * @param {Object} result Resultado de buildResult.
 * @param {{color: boolean}} format Formato.
 * @return {string}
 */
function renderText( result, { color = false } = {} ) {
	const paint = painter( color );
	const lines = [];
	lines.push( paint( 'bold', `Feed: ${ result.feedUrl }` ) );
	if ( result.finalUrl && result.finalUrl !== result.feedUrl ) {
		lines.push( `URL final: ${ result.finalUrl }` );
	}
	lines.push( `Estado HTTP: ${ value( result.feed.status ) }` );
	lines.push( '' );

	if ( result.channel ) {
		const channel = result.channel;
		lines.push( paint( 'bold', 'Canal' ) );
		lines.push( `  Título: ${ value( channel.title ) }` );
		lines.push( `  Generator: ${ value( channel.generator ) }` );
		lines.push( `  lastBuildDate: ${ value( channel.lastBuildDate ) }` );
		const image = channel.image;
		const imageInfo = image.url
			? `${ image.url }${ image.status !== undefined && image.status !== null ? ` (${ image.status } ${ value( image.contentType ) })` : '' }`
			: 'ausente';
		lines.push( `  Imagen: ${ imageInfo }` );
		lines.push( ...listChecks( channel.checks, paint, '  ' ) );
		lines.push( '' );
	}

	lines.push( paint( 'bold', 'Feed' ) );
	lines.push( ...listChecks( result.feed.checks, paint, '  ' ) );
	lines.push( '' );

	if ( result.items.length > 0 ) {
		lines.push( paint( 'bold', `Episodios (${ result.totals.episodesChecked } de ${ result.totals.episodesFound })` ) );
		for ( const item of result.items ) {
			lines.push( `${ item.index }. ${ value( item.title ) } [${ statusLabel( item, paint ) }]` );
			lines.push(
				`   guid: ${ value( item.guid ) } | pubDate: ${ value( item.pubDate ) } | duración: ${ value( item.duration ) }`
			);
			const enclosure = item.enclosure;
			if ( enclosure ) {
				lines.push( `   enclosure: ${ value( enclosure.url ) }` );
				lines.push( `   length: ${ value( enclosure.length ) } | type: ${ value( enclosure.type ) }` );
				if ( enclosure.head ) {
					const { head, get, range } = enclosure;
					lines.push(
						`   HEAD ${ value( head.status ) } ${ value( head.contentType ) } | GET ${ value( get.status ) } ${ value( get.contentType ) } ${ value( get.bytes ) } B | RANGE ${ value( range.status ) } ${ value( range.bytes ) } B`
					);
				}
			}
			lines.push( ...listChecks( item.checks, paint, '   ' ) );
		}
		lines.push( '' );
	}

	const { totals } = result;
	lines.push( paint( 'bold', 'Resumen' ) );
	lines.push( `  Episodios revisados: ${ totals.episodesChecked } de ${ totals.episodesFound }` );
	lines.push( `  Fallos: ${ totals.failures }` );
	lines.push( `  Advertencias: ${ totals.warnings }` );
	lines.push( `  Resultado: ${ result.exitCode === 0 ? paint( 'green', 'sin fallos' ) : paint( 'red', 'con fallos' ) }` );
	return `${ lines.join( '\n' ) }\n`;
}

module.exports = { buildResult, renderText, renderJson, displayUrl };
