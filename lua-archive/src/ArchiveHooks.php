<?php

namespace MediaWiki\Extension\WikidebatesLuaArchive;

use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

final class ArchiveHooks {
	/** Register the same inert archive on historical wikis without a separate loader.
	 * The standalone extension remains usable during upgrades and in migration tests.
	 * ExtensionRegistry already knows all queued extensions at registration time.
	 */
	public static function registerWithCore(): void {
		global $wgContentHandlers, $wgNamespacesWithSubpages, $wgExtensionMessagesFiles, $wgHooks;

		$registry = ExtensionRegistry::getInstance();
		foreach ( [ 'WikidebatesLuaArchive', 'Scribunto', 'SemanticScribunto', 'VariablesLua' ] as $extension ) {
			if ( $registry->isLoaded( $extension ) ) {
				return;
			}
		}

		if ( isset( $wgContentHandlers['Scribunto'] ) &&
			$wgContentHandlers['Scribunto'] !== ArchiveContentHandler::class
		) {
			throw new \RuntimeException( 'Conflicting Scribunto content handler' );
		}

		// Preserve the exact namespace IDs used by the archived revisions.
		foreach ( [ 'NS_MODULE' => 828, 'NS_MODULE_TALK' => 829 ] as $name => $id ) {
			if ( !defined( $name ) ) {
				define( $name, $id );
			} elseif ( constant( $name ) !== $id ) {
				throw new \RuntimeException( 'Conflicting archived namespace ID: ' . $name );
			}
		}

		self::onRegistration();
		$wgContentHandlers['Scribunto'] = ArchiveContentHandler::class;
		$wgNamespacesWithSubpages += [ NS_MODULE => true, NS_MODULE_TALK => true ];
		$wgExtensionMessagesFiles['WikidebatesLuaArchiveNamespaces'] = dirname( __DIR__ ) . '/namespaces.php';
		$wgHooks['CanonicalNamespaces'][] = self::class . '::onCanonicalNamespaces';
		$wgHooks['ContentHandlerDefaultModelFor'][] = self::class . '::onContentHandlerDefaultModelFor';
	}

	public static function onCanonicalNamespaces( array &$namespaces ): void {
		$namespaces += [ NS_MODULE => 'Module', NS_MODULE_TALK => 'Module_talk' ];
	}

	public static function onRegistration(): void {
		$registry = ExtensionRegistry::getInstance();
		foreach (['Scribunto', 'SemanticScribunto', 'VariablesLua'] as $extension) {
			if ($registry->isLoaded($extension)) {
				throw new \RuntimeException('WikidebatesLuaArchive cannot coexist with ' . $extension);
			}
		}
		if (!defined('CONTENT_MODEL_SCRIBUNTO')) {
			define('CONTENT_MODEL_SCRIBUNTO', 'Scribunto');
		}
	}

	public static function onContentHandlerDefaultModelFor(Title $title, &$model): void {
		if ($title->getNamespace() !== NS_MODULE || $model === 'sanitized-css') {
			return;
		}
		// Match the default conventions of the French and English installations.
		// Persisted slot models remain authoritative for existing revisions.
		if (str_ends_with($title->getText(), '.json')) {
			$model = CONTENT_MODEL_JSON;
		} elseif (!str_ends_with($title->getText(), '/doc')) {
			$model = CONTENT_MODEL_SCRIBUNTO;
		}
	}
}
