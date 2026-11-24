<?php

namespace MediaWiki\Extension\Wikidebates\Seo;

use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

/**
 * Native SEO metadata for the two Wikidebates page types.
 *
 * Parser extension data travels with ParserCache; metadata is assembled when
 * the actual page is displayed, using the displayed revision's timestamp.
 * When WikiSEO is loaded, its unrelated-page and verification metadata are
 * preserved, while competing page SEO output is replaced at the final head
 * assembly boundary. No patches to the upstream WikiSEO submodule are needed.
 */
final class Metadata {
	public const DATA_KEY = 'wikidebates-native-seo';

	/**
	 * Prevent WikiSEO from adding competing page metadata for native Debate
	 * and Argument pages. Noncompeting site-wide verification, hreflang and
	 * robot safety tags remain available in WikiSEO's global-only mode.
	 *
	 * This hook is optional: Wikidebates remains usable without WikiSEO.
	 */
	public static function onWikiSEOPageMetadataMode( OutputPage $out, string &$mode ): void {
		$data = $out->getProperty( self::DATA_KEY );
		if ( $out->isArticle() && self::isNativeSeoData( $data ) ) {
			$mode = 'global-only';
		}
	}

	/**
	 * Skip WikiSEO's deferred description/page-image updates when the parser
	 * output already contains Wikidebates' native SEO payload.
	 */
	public static function onWikiSEOPageRevisionMode(
		Title $title, ParserOutput $parserOutput, string &$mode
	): void {
		if ( self::isNativeSeoData( $parserOutput->getExtensionData( self::DATA_KEY ) ) ) {
			$mode = 'global-only';
		}
	}

	private static function isNativeSeoData( mixed $data ): bool {
		return is_array( $data ) && in_array( $data['wd_kind'] ?? null, [ 'debate', 'argument' ], true );
	}

	public static function onOutputPageParserOutput( OutputPage $out, ParserOutput $parserOutput ): void {
		$data = $parserOutput->getExtensionData( self::DATA_KEY );
		if ( self::isNativeSeoData( $data ) ) {
			$out->setProperty( self::DATA_KEY, $data );
		}
	}

	public static function beforePageDisplay( OutputPage $out ): void {
		$values = self::valuesForPage( $out );
		if ( $values === [] ) {
			return;
		}

		$out->setHTMLTitle( $values['title'] );
		if ( $values['robots'] !== '' ) {
			$parts = array_map( 'trim', explode( ',', $values['robots'], 2 ) );
			// Never override a noindex/nofollow restriction already imposed
			// by MediaWiki, a visibility policy or another extension.
			if (
				in_array( $parts[0], [ 'index', 'noindex' ], true ) &&
				( $parts[0] === 'noindex' || $out->getIndexPolicy() !== 'noindex' )
			) {
				$out->setIndexPolicy( $parts[0] );
			}
			if (
				isset( $parts[1] ) &&
				in_array( $parts[1], [ 'follow', 'nofollow' ], true ) &&
				( $parts[1] === 'nofollow' || $out->getFollowPolicy() !== 'nofollow' )
			) {
				$out->setFollowPolicy( $parts[1] );
			}
		}

		// Without WikiSEO the regular OutputPage APIs are all that is needed.
		// When WikiSEO is loaded it still runs its site-wide hook; filtering
		// conflicting tags here would depend on extension load order. Instead,
		// perform the selective handoff in OutputPageAfterGetHeadLinksArray.
		if ( ExtensionRegistry::getInstance()->isLoaded( 'WikiSEO' ) ) {
			return;
		}

		$existingMeta = array_column( $out->getMetaTags(), 0 );
		foreach ( $values['meta'] as $name => $value ) {
			if ( !in_array( $name, $existingMeta, true ) ) {
				$out->addMeta( $name, $value );
			}
		}
		self::addOpenGraph( $out, $values['open_graph'] );
	}

	/**
	 * WikiSEO may set the HTML title after Wikidebates' BeforePageDisplay
	 * handler, depending on extension registration order. Restore the title
	 * at WikiSEO's own post-title hook. Never affect an ordinary wiki page.
	 *
	 * @param array &$metadata WikiSEO data (unmodified except obsolete robots)
	 */
	public static function onWikiSEOPreAddMetadata( array &$metadata ): void {
		$out = RequestContext::getMain()->getOutput();
		if ( !$out instanceof OutputPage ) {
			return;
		}
		$values = self::valuesForPage( $out );
		if ( $values === [] ) {
			return;
		}
		$out->setHTMLTitle( $values['title'] );
		// Legacy page_props from an old ParserCache must not override a
		// noindex policy that MediaWiki has already established.
		unset( $metadata['robots'] );
	}

	/**
	 * Runs after all BeforePageDisplay handlers, irrespective of extension
	 * loading order. The $tags array contains addMeta() tags while head
	 * items (Open Graph and WikiSEO JSON-LD) are appended immediately after.
	 */
	public static function onOutputPageAfterGetHeadLinksArray( array &$tags, OutputPage $out ): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'WikiSEO' ) ) {
			return;
		}
		$values = self::valuesForPage( $out );
		if ( $values !== [] ) {
			self::replaceCompetingMetadata( $tags, $out, $values );
		}
	}

	/**
	 * @internal Public to permit deterministic MediaWiki integration tests
	 * without relying on hook load order. Only specialized pages call this.
	 */
	public static function replaceCompetingMetadata( array &$tags, OutputPage $out, array $values ): void {
		// WikiSEO and other generators may have added SEO meta tags under
		// arbitrary keys, including duplicate name+value suffixes.
		foreach ( $tags as $key => $html ) {
			if ( is_string( $html ) && self::isManagedSeoTag( $html ) ) {
				unset( $tags[$key] );
			}
		}

		// Overwrite only conflicting head items by their own keys; a blank
		// replacement is valid in OutputPage 1.43, which has no removeHeadItem.
		// Leave site-verification, canonical, hreflang and other metadata alone.
		foreach ( $out->getHeadItemsArray() as $key => $html ) {
			if (
				in_array( $key, [ 'jsonld-metadata', 'jsonld-metadata-citation' ], true ) ||
				( is_string( $html ) && self::isManagedSeoTag( $html ) )
			) {
				$out->addHeadItem( $key, '' );
			}
		}

		foreach ( $values['meta'] as $name => $value ) {
			$tags['wikidebates-seo-meta-' . $name] = Html::element( 'meta', [
				'name' => $name,
				'content' => $value,
			] );
		}
		self::addOpenGraph( $out, $values['open_graph'] );
	}

	private static function isManagedSeoTag( string $html ): bool {
		if (
			!preg_match(
				'~<meta\b[^>]*\b(?:name|property)\s*=\s*([\'"])([^\'"]+)\1~i',
				$html,
				$matches
			)
		) {
			return false;
		}
		$name = strtolower( $matches[2] );
		return in_array( $name, [ 'description', 'keywords', 'googlebot' ], true ) ||
			str_starts_with( $name, 'og:' ) ||
			str_starts_with( $name, 'article:' ) ||
			str_starts_with( $name, 'twitter:' );
	}

	private static function addOpenGraph( OutputPage $out, array $openGraph ): void {
		foreach ( $openGraph as $name => $values ) {
			foreach ( (array)$values as $index => $value ) {
				$out->addHeadItem(
					'wikidebates-seo-' . $name . '-' . $index,
					Html::element( 'meta', [ 'property' => $name, 'content' => $value ] )
				);
			}
		}
	}

	/** @return array{title:string,robots:string,meta:array,open_graph:array}|array */
	private static function valuesForPage( OutputPage $out ): array {
		$data = $out->getProperty( self::DATA_KEY );
		if (
			!self::isNativeSeoData( $data ) ||
			!$out->isArticle() ||
			$out->getTitle() === null
		) {
			return [];
		}
		$revisionTimestamp = $out->getRevisionTimestamp();
		$modified = $revisionTimestamp
			? wfTimestamp( TS_ISO_8601, $revisionTimestamp )
			: null;
		return self::build( $data, $out->getTitle()->getFullURL(), $modified );
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
