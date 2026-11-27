<?php

namespace MediaWiki\Extension\Wikidebates;

use MediaWiki\Extension\WikidebatesLuaArchive\ArchiveHooks;

/** Resolve site defaults before MediaWiki initializes its services. */
final class Registration {
	public static function onRegistration(): void {
		global $wgLanguageCode, $wgWikidebatesArgumentMapApi;

		$historicalWiki = in_array( $wgLanguageCode, [ 'fr', 'en' ], true );
		$wgWikidebatesArgumentMapApi ??= $historicalWiki;

		if ( $historicalWiki ) {
			ArchiveHooks::registerWithCore();
		}
	}
}
