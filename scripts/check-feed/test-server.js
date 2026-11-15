'use strict';

/**
 * Servidor HTTP local para las pruebas de check-feed. Solo escucha en loopback.
 */

const http = require( 'node:http' );

const AUDIO_LEN = 2000;
const HTML = '<!DOCTYPE html><html><head><title>Home</title></head><body>Inicio</body></html>';

function audioBody( length ) {
	const body = Buffer.alloc( length, 0x41 );
	body.write( 'ID3', 0, 'latin1' );
	return body;
}

/**
 * Comportamiento de cada ruta de audio.
 * len: bytes reales; type: Content-Type; ranges: soporta Range; getHtml: GET devuelve HTML.
 */
const AUDIO_ROUTES = {
	'/audio/ok-1.mp3': {},
	'/audio/ok-2.mp3': {},
	'/audio/html-on-get.mp3': { getHtml: true },
	'/audio/html-body-audio-ct.mp3': { getHtml: true, getType: 'audio/mpeg' },
	'/audio/wrong-length.mp3': { len: 1000, headLen: AUDIO_LEN },
	'/audio/status-404.mp3': { status: 404 },
	'/audio/status-500.mp3': { status: 500 },
	'/audio/no-ranges.mp3': { ranges: false },
	'/audio/bad-content-range.mp3': { contentRange: 'bytes 0-99/999' },
	'/audio/wrong-type.mp3': { type: 'audio/ogg' },
	'/audio/big.mp3': { len: 50000, headLen: 50000 },
	'/audio/no-store.mp3': { noStore: true },
};

function handleAudio( req, res, spec ) {
	const len = spec.len ?? AUDIO_LEN;
	const declared = spec.headLen ?? len;
	const type = spec.type ?? 'audio/mpeg';
	const base = {};
	if ( spec.noStore ) {
		base[ 'Cache-Control' ] = 'private, no-store';
	}
	if ( spec.status && spec.status !== 200 ) {
		res.writeHead( spec.status, { 'Content-Type': 'text/plain', ...base } );
		res.end( req.method === 'HEAD' ? undefined : 'error' );
		return;
	}
	if ( req.method === 'HEAD' ) {
		const headers = { 'Content-Type': type, 'Content-Length': String( declared ), ...base };
		if ( spec.ranges !== false ) {
			headers[ 'Accept-Ranges' ] = 'bytes';
		}
		res.writeHead( 200, headers );
		res.end();
		return;
	}
	if ( spec.getHtml ) {
		res.writeHead( 200, { 'Content-Type': spec.getType ?? 'text/html; charset=UTF-8', 'Content-Length': String( HTML.length ), ...base } );
		res.end( HTML );
		return;
	}
	if ( req.headers.range && spec.ranges !== false ) {
		const body = audioBody( 100 );
		res.writeHead( 206, {
			'Content-Type': type,
			'Content-Length': '100',
			'Content-Range': spec.contentRange ?? `bytes 0-99/${ declared }`,
			'Accept-Ranges': 'bytes',
			...base,
		} );
		res.end( body );
		return;
	}
	const headers = { 'Content-Type': type, 'Content-Length': String( len ), ...base };
	if ( spec.ranges !== false ) {
		headers[ 'Accept-Ranges' ] = 'bytes';
	}
	res.writeHead( 200, headers );
	res.end( audioBody( len ) );
}

/**
 * Arma un feed RSS de prueba.
 *
 * @param {string} base URL base del servidor.
 * @param {Object} options Opciones: items, omitNamespaces, generator.
 * @return {string}
 */
function buildFeed( base, options = {} ) {
	const items = options.items ?? [
		{ title: 'Episodio 1', guid: 'ep-1', path: '/audio/ok-1.mp3' },
		{ title: 'Episodio 2', guid: 'ep-2', path: '/audio/ok-2.mp3' },
	];
	const ns = options.omitNamespaces
		? ''
		: ' xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom"';
	const itemXml = items
		.map( ( item ) => {
			const length = item.length ?? AUDIO_LEN;
			const type = item.type ?? 'audio/mpeg';
			return `<item><title>${ item.title }</title><guid isPermaLink="false">${ item.guid }</guid><pubDate>Fri, 13 Nov 2026 07:11:39 +0000</pubDate><enclosure url="${ base }${ item.path }?lb_sp_audio=1&amp;lb_sp_attachment=2" length="${ length }" type="${ type }" />${ options.omitNamespaces ? '' : '<itunes:duration>00:02:06</itunes:duration>' }</item>`;
		} )
		.join( '' );
	return `<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"${ ns }><channel><title>Canal de prueba</title><link>${ base }/</link><generator>${ options.generator ?? 'LB Simple Podcast 0.8.0' }</generator><lastBuildDate>Sat, 14 Nov 2026 17:32:51 +0000</lastBuildDate>${ options.omitNamespaces ? '' : `<itunes:image href="${ base }/image.jpg" />` }${ itemXml }</channel></rss>`;
}

/**
 * Levanta el servidor en 127.0.0.1 con un puerto efímero.
 *
 * @return {Promise<{url: string, port: number, requests: Object[], close: Function}>}
 */
function createTestServer() {
	const requests = [];
	let base = '';
	const server = http.createServer( ( req, res ) => {
		requests.push( { method: req.method, url: req.url, headers: { ...req.headers } } );
		const path = req.url.split( '?' )[ 0 ];

		if ( AUDIO_ROUTES[ path ] ) {
			handleAudio( req, res, AUDIO_ROUTES[ path ] );
			return;
		}
		const feeds = {
			'/feed-ok.xml': () => buildFeed( base ),
			'/feed-invalid.xml': () => '<rss version="2.0"><channel><title>Roto</channel></rss>',
			'/feed-doctype.xml': () =>
				'<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><title>&xxe;</title></channel></rss>',
			'/feed-no-namespaces.xml': () => buildFeed( base, { omitNamespaces: true } ),
			'/feed-no-items.xml': () => buildFeed( base, { items: [] } ),
			'/feed-dup-guid.xml': () =>
				buildFeed( base, {
					items: [
						{ title: 'A', guid: 'same', path: '/audio/ok-1.mp3' },
						{ title: 'B', guid: 'same', path: '/audio/ok-2.mp3' },
					],
				} ),
			'/feed-html-get.xml': () =>
				buildFeed( base, { items: [ { title: 'Roto', guid: 'h-1', path: '/audio/html-on-get.mp3' } ] } ),
			'/feed-wrong-length.xml': () =>
				buildFeed( base, { items: [ { title: 'Largo', guid: 'w-1', path: '/audio/wrong-length.mp3' } ] } ),
			'/feed-status.xml': () =>
				buildFeed( base, {
					items: [
						{ title: 'Cuatrocientos cuatro', guid: 's-1', path: '/audio/status-404.mp3' },
						{ title: 'Sin rangos', guid: 's-2', path: '/audio/no-ranges.mp3' },
					],
				} ),
			'/feed-no-store.xml': () => buildFeed( base, { items: [ { title: 'NS', guid: 'n-1', path: '/audio/no-store.mp3' } ] } ),
			'/feed-huge.xml': () => `${ buildFeed( base ) }${ ' '.repeat( 5000 ) }`,
		};
		if ( feeds[ path ] ) {
			const body = feeds[ path ]();
			res.writeHead( 200, { 'Content-Type': 'application/rss+xml; charset=UTF-8' } );
			res.end( req.method === 'HEAD' ? undefined : body );
			return;
		}
		switch ( path ) {
			case '/feed-404':
				res.writeHead( 404, { 'Content-Type': 'text/plain' } );
				res.end( 'no existe' );
				return;
			case '/image.jpg':
				res.writeHead( 200, { 'Content-Type': 'image/jpeg', 'Content-Length': '4' } );
				res.end( req.method === 'HEAD' ? undefined : 'JPEG' );
				return;
			case '/redirect-ok':
				res.writeHead( 302, { Location: '/feed-ok.xml' } );
				res.end();
				return;
			case '/redirect-blocked':
				res.writeHead( 302, { Location: 'http://blocked.test/secret' } );
				res.end();
				return;
			case '/redirect-loop':
				res.writeHead( 302, { Location: '/redirect-loop' } );
				res.end();
				return;
			case '/slow':
				// No responde: la prueba espera el timeout.
				return;
			default:
				res.writeHead( 404, { 'Content-Type': 'text/plain' } );
				res.end( 'no encontrado' );
		}
	} );

	return new Promise( ( resolve ) => {
		server.listen( 0, '127.0.0.1', () => {
			const { port } = server.address();
			base = `http://127.0.0.1:${ port }`;
			resolve( {
				url: base,
				port,
				requests,
				close: () =>
					new Promise( ( done ) => {
						server.closeAllConnections();
						server.close( () => done() );
					} ),
			} );
		} );
	} );
}

module.exports = { createTestServer, buildFeed, AUDIO_LEN };
