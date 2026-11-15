#!/usr/bin/env node
'use strict';

/**
 * Controla un feed de podcast: valida el XML y comprueba cada enclosure.
 *
 * Uso: npm run check:feed -- <url-del-feed> [opciones]
 */

const { parseCli, HELP } = require( './check-feed/cli' );
const { createClient } = require( './check-feed/http-client' );
const { run } = require( './check-feed/runner' );
const { renderText, renderJson } = require( './check-feed/report' );
const { version } = require( '../package.json' );

async function main() {
	const parsed = parseCli( process.argv.slice( 2 ), process.env, Boolean( process.stdout.isTTY ) );
	if ( ! parsed.ok ) {
		process.stderr.write( `Error: ${ parsed.message }\n\n${ HELP }` );
		return 2;
	}
	if ( parsed.help ) {
		process.stdout.write( HELP );
		return 0;
	}
	const { options } = parsed;
	const client = createClient( {
		timeoutMs: options.timeout * 1000,
		maxRedirects: options.maxRedirects,
		allowPrivate: options.allowPrivate,
		userAgent: `lb-simple-podcast-check-feed/${ version }`,
	} );
	process.once( 'SIGINT', () => {
		process.stderr.write( '\nInterrumpido.\n' );
		process.exit( 130 );
	} );
	const result = await run( options.url, options, client );
	process.stdout.write( options.json ? renderJson( result ) : renderText( result, { color: options.color } ) );
	return result.exitCode;
}

main().then(
	( code ) => {
		process.exitCode = code;
	},
	( error ) => {
		const detail = process.env.DEBUG === 'check-feed' ? `\n${ error.stack }` : '';
		process.stderr.write( `Error interno: ${ error.message }${ detail }\n` );
		process.exitCode = 2;
	}
);
