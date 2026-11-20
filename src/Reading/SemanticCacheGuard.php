<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\MediaWikiServices;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\SQLStore\QueryDependencyLinksStoreFactory;

/**
 * Cache-hit guard: the SMW 7.3.1 ArticleViewHeader hook is skipped by
 * action=wikidebatesreading and by direct fragment-cache hits. The durable
 * semantic revision fingerprint, not smw_touched, validates these hits.
 */
final class SemanticCacheGuard {
	public static function safeForRead($title, $page): bool {
		try {
			if (!class_exists(QueryDependencyLinksStoreFactory::class)) {
				return false;
			}
			$services = SemanticServices::getInstance();
			$settings = $services->getSettings();
			if (!$settings->get('smwgEnabledQueryDependencyLinksStore') ||
				$settings->get('smwgQueryProfiler') === false) {
				self::testTrace('settings', ['links' => $settings->get('smwgEnabledQueryDependencyLinksStore'),
					'profiler' => $settings->get('smwgQueryProfiler')]);
				return false;
			}

			$subject = WikiPage::newFromTitle($title);
			$store = $services->getStore();

			// On a newly migrated page, the saved _ASK profile may not
			// exist yet. This includes both arguments and debate pages, not
			// only the former. Never trust an empty dependency graph for a
			// page whose source invokes a native semantic renderer.
			if (SemanticPageCacheHooks::isQueryPage($title, $page) &&
				$store->getPropertyValues($subject, new Property('_ASK')) === []) {
				self::testTrace('missing-ask');
				return false;
			}

			$validator = (new QueryDependencyLinksStoreFactory())->newDependencyLinksValidator();
			if (!$validator->canCheckDependencies()) {
				self::testTrace('validator-disabled');
				return false;
			}
			// Do not gate fragment hits on SMW's smw_touched comparator.
			// After a fresh render SMW can advance dependency timestamps
			// without changing the semantic values or query links, making
			// hasArchaicDependencies() reject the very next cache hit.
			// The transactional revision fingerprint is our real freshness
			// boundary: it captures every linked entity revision, and its
			// verification fails closed if any tracked query has no links.
			// FragmentService puts this fingerprint in the cache identity
			// and checks it again immediately before publishing HTML.
			SemanticRevisionStore::fingerprint($store, $subject);
			self::testTrace('safe-fingerprint');
			return true;
		} catch (\Throwable $e) {
			self::testTrace('exception', ['type' => get_class($e), 'message' => $e->getMessage()]);
			FragmentService::log($e);
			// Never return a prebuilt fragment when dependency checks fail.
			return false;
		}
	}

	/** Test-only diagnostic, never active on production requests. */
	private static function testTrace(string $event, array $details = []): void {
		if (getenv('WD_SEMANTIC_HTTP_DISPOSABLE') !== '1') {
			return;
		}
		$root = (string)getenv('WD_SEMANTIC_HTTP_ROOT');
		@file_put_contents($root . '/semantic-http-guard.jsonl',
			json_encode(['pid' => getmypid(), 'event' => $event] + $details) . "\n",
			FILE_APPEND | LOCK_EX);
	}

}
