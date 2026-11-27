<?php

namespace MediaWiki\Extension\WikidebatesLuaArchive;

use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

final class ArchiveHooks {
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
