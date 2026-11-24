<?php

namespace MediaWiki\Extension\Wikidebates\Seo;

use MediaWiki\Html\Html;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * Native SEO metadata for the two Wikidebates page types.
 *
 * Parser extension data travels with ParserCache; metadata is assembled when
 * the actual page is displayed, using the displayed revision's timestamp.
 * WikiSEO retains ownership while it is loaded, for a reversible cutover.
 */
final class Metadata {
	public const DATA_KEY = 'wikidebates-native-seo';

	public static function onOutputPageParserOutput( OutputPage $out, ParserOutput $parserOutput ): void {
		$data = $parserOutput->getExtensionData( self::DATA_KEY );
		if ( is_array( $data ) && in_array( $data['wd_kind'] ?? null, [ 'debate', 'argument' ], true ) ) {
			$out->setProperty( self::DATA_KEY, $data );
		}
	}

	public static function beforePageDisplay( OutputPage $out ): void {
		$data = $out->getProperty( self::DATA_KEY );
		if ( !is_array( $data ) || !$out->isArticle() || $out->getTitle() === null ) {
			return;
		}

		// Avoid conflicting titles, Open Graph tags and JSON-LD until WikiSEO
		// has been disabled. The legacy path remains active in that interval.
		if ( ExtensionRegistry::getInstance()->isLoaded( 'WikiSEO' ) ) {
			return;
		}

		$revisionTimestamp = $out->getRevisionTimestamp();
		$modified = $revisionTimestamp
			? wfTimestamp( TS_ISO_8601, $revisionTimestamp )
			: null;
		$values = self::build( $data, $out->getTitle()->getFullURL(), $modified );
		if ( $values === [] ) {
			return;
		}

		$out->setHTMLTitle( $values['title'] );
		if ( $values['robots'] !== '' ) {
			$parts = array_map( 'trim', explode( ',', $values['robots'], 2 ) );
			if ( in_array( $parts[0], [ 'index', 'noindex' ], true ) ) {
				$out->setIndexPolicy( $parts[0] );
			}
			if ( isset( $parts[1] ) && in_array( $parts[1], [ 'follow', 'nofollow' ], true ) ) {
				$out->setFollowPolicy( $parts[1] );
			}
		}

		$existingMeta = array_column( $out->getMetaTags(), 0 );
		foreach ( $values['meta'] as $name => $value ) {
			if ( !in_array( $name, $existingMeta, true ) ) {
				$out->addMeta( $name, $value );
			}
		}
		foreach ( $values['open_graph'] as $name => $valuesForProperty ) {
			// Open Graph article:tag is a repeatable property, not one CSV value.
			foreach ( (array)$valuesForProperty as $index => $value ) {
				$out->addHeadItem(
					'wikidebates-seo-' . $name . '-' . $index,
					Html::element( 'meta', [ 'property' => $name, 'content' => $value ] )
				);
			}
		}
	}

	/**
	 * Build only from existing editorial values. Never fetch, create or
	 * substitute a social image. No guessed publication or modification date.
	 *
	 * @return array{title:string,robots:string,meta:array,open_graph:array}|array
	 */
	public static function build( array $data, string $pageUrl, ?string $modified ): array {
		$kind = $data['wd_kind'] ?? '';
		if ( !in_array( $kind, [ 'debate', 'argument' ], true ) ) {
			return [];
		}

		$title = self::plainText( (string)( $data['title'] ?? '' ) );
		$description = self::plainText( (string)( $data['description'] ?? '' ) );
		if ( $kind === 'argument' ) {
			$summary = self::plainText( (string)( $data['wd_summary'] ?? '' ) );
			if ( $summary !== '' ) {
				$description = $summary;
			}
		}

		$meta = [];
		$openGraph = [];
		self::put( $meta, 'description', $description );
		self::put( $meta, 'keywords', (string)( $data['keywords'] ?? '' ) );
		self::put( $meta, 'googlebot', (string)( $data['googlebot'] ?? '' ) );
		self::put( $meta, 'twitter:card', 'summary_large_image' );
		self::put( $meta, 'twitter:site', (string)( $data['twitter_site'] ?? '' ) );
		self::put( $meta, 'twitter:title', $title );
		self::put( $meta, 'twitter:description', $description );

		self::put( $openGraph, 'og:title', $title );
		self::put( $openGraph, 'og:url', $pageUrl );
		self::put( $openGraph, 'og:description', $description );
		self::put( $openGraph, 'og:site_name', (string)( $data['site_name'] ?? '' ) );
		self::put( $openGraph, 'og:locale', (string)( $data['locale'] ?? '' ) );
		// The user-approved distinction applies to Open Graph only:
		// debates are website objects; argument pages are articles.
		self::put( $openGraph, 'og:type', $kind === 'debate' ? 'website' : 'article' );

		$image = trim( (string)( $data['image'] ?? '' ) );
		if ( $image !== '' ) {
			self::put( $openGraph, 'og:image', $image );
			self::put( $meta, 'twitter:image', $image );
			$alt = self::plainText( (string)( $data['image_alt'] ?? '' ) );
			self::put( $openGraph, 'og:image:alt', $alt );
			self::put( $meta, 'twitter:image:alt', $alt );
			if ( str_starts_with( $image, 'https://' ) ) {
				self::put( $openGraph, 'og:image:secure_url', $image );
			}
			$path = parse_url( $image, PHP_URL_PATH );
			$extension = is_string( $path ) ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
			$types = [
				'png' => 'image/png',
				'jpg' => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'gif' => 'image/gif',
				'webp' => 'image/webp',
			];
			self::put( $openGraph, 'og:image:type', $types[$extension] ?? '' );
			foreach ( [ 'width', 'height' ] as $dimension ) {
				$value = (string)( $data['image_' . $dimension] ?? '' );
				if ( ctype_digit( $value ) && (int)$value > 0 ) {
					self::put( $openGraph, 'og:image:' . $dimension, $value );
				}
			}
		}

		// Article namespace applies only to argument pages. article:author
		// expects an author profile URL, not the collective credit string.
		// Collective credits and debate dates belong in Wikidebates JSON-LD.
		if ( $kind === 'argument' ) {
			self::put( $openGraph, 'article:section', (string)( $data['section'] ?? '' ) );
			$tags = array_values( array_unique( array_filter(
				array_map( 'trim', explode( ',', (string)( $data['keywords'] ?? '' ) ) ),
				static fn ( string $tag ): bool => $tag !== ''
			) ) );
			if ( $tags !== [] ) {
				$openGraph['article:tag'] = $tags;
			}
			self::put( $openGraph, 'article:published_time', self::creationDate( $data['wd_creation_date'] ?? '' ) );
			self::put( $openGraph, 'article:modified_time', (string)( $modified ?? '' ) );
		}

		return [
			'title' => $title,
			'robots' => (string)( $data['robots'] ?? '' ),
			'meta' => $meta,
			'open_graph' => $openGraph,
		];
	}

	/**
	 * Complete the existing WebPage/QAPage graph rather than emitting a
	 * second JSON-LD document. The timestamp belongs to the parsed revision.
	 * Do not supply today's date or modify bibliographic references.
	 */
	public static function withModificationDate( array $schema, ?string $modified ): array {
		if ( !$modified || !isset( $schema['@graph'] ) || !is_array( $schema['@graph'] ) ) {
			return $schema;
		}
		foreach ( $schema['@graph'] as $index => $node ) {
			if (
				is_array( $node ) &&
				in_array( $node['@type'] ?? null, [ 'WebPage', 'QAPage' ], true ) &&
				isset( $node['url'], $node['@id'] )
			) {
				$schema['@graph'][$index]['dateModified'] = $modified;
			}
		}
		return $schema;
	}

	private static function put( array &$tags, string $key, string $value ): void {
		if ( $value !== '' ) {
			$tags[$key] = $value;
		}
	}

	private static function creationDate( mixed $raw ): string {
		$date = trim( (string)$raw );
		if (
			!preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $matches ) ||
			!checkdate( (int)$matches[2], (int)$matches[3], (int)$matches[1] )
		) {
			return '';
		}
		return $date . 'T00:00:00Z';
	}

	/** Plain text for HTML attribute content, without expanding wikitext. */
	private static function plainText( string $text ): string {
		$text = preg_replace( '/<!--.*?-->/su', ' ', $text ) ?? $text;
		$text = preg_replace( '/<ref\b[^>]*>.*?<\/ref>/isu', ' ', $text ) ?? $text;
		$text = preg_replace( '/<ref\b[^>]*\/>/isu', ' ', $text ) ?? $text;
		$text = preg_replace( '/\[\[(?:[^\]|]*\|)?([^\]]+)\]\]/u', '$1', $text ) ?? $text;
		$text = preg_replace( '/\[https?:\/\/[^\s\]]+\s+([^\]]+)\]/u', '$1', $text ) ?? $text;
		$text = str_replace( [ "'''", "''" ], '', $text );
		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
	}
}
