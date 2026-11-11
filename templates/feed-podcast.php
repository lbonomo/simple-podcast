<?php
/**
 * Plantilla del feed RSS de podcast (RSS 2.0 + iTunes).
 *
 * Variables disponibles: $channel (array) e $items (array de episodios).
 *
 * @package LbSimplePodcast
 *
 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Se escapa con Xml::text()/Xml::cdata().
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables locales de la plantilla.
 */

use LbSimplePodcast\Feed\Xml;

defined( 'ABSPATH' ) || exit;

/**
 * Datos que inyecta PodcastFeedRenderer::render().
 *
 * @var array<string, mixed>             $channel
 * @var array<int, array<string, mixed>> $items
 */

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
	xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
	<title><?php echo Xml::text( (string) $channel['title'] ); ?></title>
	<link><?php echo Xml::text( (string) $channel['link'] ); ?></link>
	<atom:link href="<?php echo Xml::text( (string) $channel['self'] ); ?>" rel="self" type="application/rss+xml" />
	<description><?php echo Xml::text( (string) $channel['description'] ); ?></description>
	<language><?php echo Xml::text( (string) $channel['language'] ); ?></language>
	<lastBuildDate><?php echo Xml::text( (string) $channel['last_build'] ); ?></lastBuildDate>
	<generator><?php echo Xml::text( (string) $channel['generator'] ); ?></generator>
<?php if ( '' !== $channel['copyright'] ) : ?>
	<copyright><?php echo Xml::text( (string) $channel['copyright'] ); ?></copyright>
<?php endif; ?>
<?php if ( '' !== $channel['image'] ) : ?>
	<image>
		<url><?php echo Xml::text( (string) $channel['image'] ); ?></url>
		<title><?php echo Xml::text( (string) $channel['title'] ); ?></title>
		<link><?php echo Xml::text( (string) $channel['link'] ); ?></link>
	</image>
	<itunes:image href="<?php echo Xml::text( (string) $channel['image'] ); ?>" />
<?php endif; ?>
	<itunes:type>episodic</itunes:type>
<?php if ( '' !== $channel['author'] ) : ?>
	<itunes:author><?php echo Xml::text( (string) $channel['author'] ); ?></itunes:author>
<?php endif; ?>
	<itunes:summary><?php echo Xml::text( (string) $channel['description'] ); ?></itunes:summary>
	<itunes:explicit><?php echo 'true' === $channel['explicit'] ? 'true' : 'false'; ?></itunes:explicit>
<?php if ( '' !== $channel['category'] ) : ?>
	<itunes:category text="<?php echo Xml::text( (string) $channel['category'] ); ?>" />
<?php endif; ?>
<?php if ( '' !== $channel['owner_name'] || '' !== $channel['owner_email'] ) : ?>
	<itunes:owner>
	<?php if ( '' !== $channel['owner_name'] ) : ?>
		<itunes:name><?php echo Xml::text( (string) $channel['owner_name'] ); ?></itunes:name>
<?php endif; ?>
	<?php if ( '' !== $channel['owner_email'] ) : ?>
		<itunes:email><?php echo Xml::text( (string) $channel['owner_email'] ); ?></itunes:email>
<?php endif; ?>
	</itunes:owner>
<?php endif; ?>
<?php foreach ( $items as $item ) : ?>
	<?php $audio = $item['audio']; ?>
	<item>
		<title><?php echo Xml::text( (string) $item['title'] ); ?></title>
		<itunes:title><?php echo Xml::text( (string) $item['title'] ); ?></itunes:title>
		<link><?php echo Xml::text( (string) $item['link'] ); ?></link>
		<guid isPermaLink="false"><?php echo Xml::text( (string) $item['guid'] ); ?></guid>
		<pubDate><?php echo Xml::text( (string) $item['pub_date'] ); ?></pubDate>
		<description><?php echo Xml::text( (string) $item['summary'] ); ?></description>
		<itunes:summary><?php echo Xml::text( (string) $item['summary'] ); ?></itunes:summary>
		<content:encoded><?php echo Xml::cdata( (string) $item['content_html'] ); ?></content:encoded>
		<enclosure url="<?php echo Xml::text( (string) $audio['url'] ); ?>" length="<?php echo (int) $audio['filesize']; ?>" type="<?php echo Xml::text( (string) $audio['mime_type'] ); ?>" />
	<?php if ( (int) $audio['duration'] > 0 ) : ?>
		<itunes:duration><?php echo Xml::duration( (int) $audio['duration'] ); ?></itunes:duration>
<?php endif; ?>
		<itunes:explicit><?php echo 'true' === $item['explicit'] ? 'true' : 'false'; ?></itunes:explicit>
		<itunes:episodeType><?php echo Xml::text( (string) $item['episode_type'] ); ?></itunes:episodeType>
	</item>
<?php endforeach; ?>
</channel>
</rss>
