/**
 * @jest-environment node
 */
'use strict';

const { parse } = require( './xml' );
const { validateFeed, isRfc822 } = require( './feed-validator' );
const { validateItems } = require( './item-validator' );
const { buildFeed } = require( './test-server' );

const BASE = 'http://feed.test';
const failures = ( checks ) => checks.filter( ( c ) => c.result === 'failure' ).map( ( c ) => c.id );
const warnings = ( checks ) => checks.filter( ( c ) => c.result === 'warning' ).map( ( c ) => c.id );

describe( 'feed-validator', () => {
	test( 'un feed válido no tiene fallos y expone los datos del canal', () => {
		const { checks, channel, items } = validateFeed( parse( buildFeed( BASE ) ) );
		expect( failures( checks ) ).toEqual( [] );
		expect( warnings( checks ) ).toEqual( [] );
		expect( channel ).toMatchObject( {
			title: 'Canal de prueba',
			generator: 'LB Simple Podcast 0.8.0',
			lastBuildDate: 'Sat, 14 Nov 2026 17:32:51 +0000',
			image: { url: `${ BASE }/image.jpg` },
		} );
		expect( channel.checks.filter( ( c ) => c.result !== 'ok' ) ).toEqual( [] );
		expect( items ).toHaveLength( 2 );
	} );

	test.each( [
		[ 'raíz que no es rss', '<feed><channel><title>x</title></channel></feed>', 'feed.root' ],
		[ 'versión distinta de 2.0', '<rss version="0.91"><channel><title>x</title></channel></rss>', 'feed.root' ],
	] )( 'raíz inválida: %s', ( name, xml, id ) => {
		expect( failures( validateFeed( parse( xml ) ).checks ) ).toContain( id );
	} );

	test( 'informa un fallo por cada namespace ausente', () => {
		const xml = '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel><title>x</title><item/></channel></rss>';
		expect( failures( validateFeed( parse( xml ) ).checks ).sort() ).toEqual( [ 'feed.namespace.atom', 'feed.namespace.content' ] );
		const none = validateFeed( parse( buildFeed( BASE, { omitNamespaces: true } ) ) );
		expect( failures( none.checks ).sort() ).toEqual( [
			'feed.namespace.atom',
			'feed.namespace.content',
			'feed.namespace.itunes',
		] );
	} );

	test( 'reconoce los namespaces por URI aunque cambie el prefijo', () => {
		const xml =
			'<rss version="2.0" xmlns:it="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:c="http://purl.org/rss/1.0/modules/content/" xmlns:a="http://www.w3.org/2005/Atom"><channel><title>x</title><it:image href="http://i/x.jpg"/></channel></rss>';
		const { checks, channel } = validateFeed( parse( xml ) );
		expect( failures( checks ) ).toEqual( [] );
		expect( channel.image.url ).toBe( 'http://i/x.jpg' );
	} );

	test( 'sin canal o sin título es un fallo', () => {
		const ns =
			'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom"';
		const noChannel = validateFeed( parse( `<rss version="2.0" ${ ns }></rss>` ) );
		expect( failures( noChannel.checks ) ).toContain( 'feed.channel' );
		expect( noChannel.channel ).toBeNull();
		const noTitle = validateFeed( parse( `<rss version="2.0" ${ ns }><channel><item/></channel></rss>` ) );
		expect( failures( noTitle.checks ) ).toContain( 'feed.channel.title' );
	} );

	test( 'un feed sin items solo genera una advertencia', () => {
		const { checks } = validateFeed( parse( buildFeed( BASE, { items: [] } ) ) );
		expect( failures( checks ) ).toEqual( [] );
		expect( warnings( checks ) ).toEqual( [ 'feed.items' ] );
	} );

	test( 'campos del canal ausentes o con fecha inválida son advertencias', () => {
		const ns =
			'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom"';
		const bare = validateFeed( parse( `<rss version="2.0" ${ ns }><channel><title>x</title><item/></channel></rss>` ) );
		expect( warnings( bare.channel.checks ).sort() ).toEqual( [ 'channel.generator', 'channel.image', 'channel.lastBuildDate' ] );
		const badDate = validateFeed(
			parse( `<rss version="2.0" ${ ns }><channel><title>x</title><lastBuildDate>ayer</lastBuildDate><item/></channel></rss>` )
		);
		expect( warnings( badDate.channel.checks ) ).toContain( 'channel.lastBuildDate' );
	} );

	test( 'isRfc822', () => {
		expect( isRfc822( 'Sat, 14 Nov 2026 17:32:51 +0000' ) ).toBe( true );
		expect( isRfc822( '14 Nov 2026 17:32 GMT' ) ).toBe( true );
		expect( isRfc822( '2026-11-14T17:32:51Z' ) ).toBe( false );
		expect( isRfc822( 'Foo, 99 Zzz 2026 99:99:99 +0000' ) ).toBe( false );
	} );
} );

describe( 'item-validator', () => {
	const itemsOf = ( xml ) => validateItems( validateFeed( parse( xml ) ).items );

	test( 'extrae los datos del episodio y del enclosure con &amp; decodificado', () => {
		const [ first ] = itemsOf( buildFeed( BASE ) );
		expect( first ).toMatchObject( {
			index: 1,
			title: 'Episodio 1',
			guid: 'ep-1',
			pubDate: 'Fri, 13 Nov 2026 07:11:39 +0000',
			duration: '00:02:06',
			enclosure: { url: `${ BASE }/audio/ok-1.mp3?lb_sp_audio=1&lb_sp_attachment=2`, length: 2000, type: 'audio/mpeg' },
		} );
		expect( failures( first.checks ) ).toEqual( [] );
		expect( warnings( first.checks ) ).toEqual( [] );
	} );

	const ns =
		'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom"';
	const wrap = ( item ) => `<rss version="2.0" ${ ns }><channel><title>x</title>${ item }</channel></rss>`;

	test.each( [
		[ 'sin title', '<item><guid>g</guid><pubDate>d</pubDate><enclosure url="http://a/b" length="5" type="audio/mpeg"/></item>', 'item.title' ],
		[ 'sin guid', '<item><title>t</title><enclosure url="http://a/b" length="5" type="audio/mpeg"/></item>', 'item.guid' ],
		[ 'sin enclosure', '<item><title>t</title><guid>g</guid></item>', 'item.enclosure' ],
		[ 'enclosure sin url', '<item><title>t</title><guid>g</guid><enclosure length="5" type="audio/mpeg"/></item>', 'item.enclosure.url' ],
		[ 'length cero', '<item><title>t</title><guid>g</guid><enclosure url="http://a" length="0" type="audio/mpeg"/></item>', 'item.enclosure.length' ],
		[ 'length no numérico', '<item><title>t</title><guid>g</guid><enclosure url="http://a" length="abc" type="audio/mpeg"/></item>', 'item.enclosure.length' ],
		[ 'length negativo', '<item><title>t</title><guid>g</guid><enclosure url="http://a" length="-5" type="audio/mpeg"/></item>', 'item.enclosure.length' ],
		[ 'length ausente', '<item><title>t</title><guid>g</guid><enclosure url="http://a" type="audio/mpeg"/></item>', 'item.enclosure.length' ],
		[ 'type que no es audio', '<item><title>t</title><guid>g</guid><enclosure url="http://a" length="5" type="video/mp4"/></item>', 'item.enclosure.type' ],
		[ 'type ausente', '<item><title>t</title><guid>g</guid><enclosure url="http://a" length="5"/></item>', 'item.enclosure.type' ],
	] )( 'fallo: %s', ( name, item, id ) => {
		const [ result ] = itemsOf( wrap( item ) );
		expect( failures( result.checks ) ).toContain( id );
	} );

	test( 'sin pubDate o sin duración son advertencias, no fallos', () => {
		const [ result ] = itemsOf( wrap( '<item><title>t</title><guid>g</guid><enclosure url="http://a" length="5" type="audio/mpeg"/></item>' ) );
		expect( failures( result.checks ) ).toEqual( [] );
		expect( warnings( result.checks ).sort() ).toEqual( [ 'item.duration', 'item.pubDate' ] );
	} );

	test( 'un guid duplicado es un fallo en cada episodio involucrado', () => {
		const dup = '<item><title>t</title><guid>x</guid><enclosure url="http://a" length="5" type="audio/mpeg"/></item>';
		const other = '<item><title>u</title><guid>y</guid><enclosure url="http://a" length="5" type="audio/mpeg"/></item>';
		const results = itemsOf( wrap( dup + other + dup ) );
		expect( failures( results[ 0 ].checks ) ).toContain( 'item.guid.duplicate' );
		expect( failures( results[ 1 ].checks ) ).not.toContain( 'item.guid.duplicate' );
		expect( failures( results[ 2 ].checks ) ).toContain( 'item.guid.duplicate' );
	} );
} );
