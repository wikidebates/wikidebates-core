<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\SQLStore\SQLStore;

/** Primary keyset scan; existing dependencyIds/cursor jobs remain executable. */
final class SemanticCachePurgeJob extends \Job {
	private array $metrics = [];

	public function __construct($title, array $params = []) {
		if (isset($params['dependencyIds'])) {
			$params['dependencyIds'] = SemanticCacheHooks::changedIds((array)$params['dependencyIds']);
			sort($params['dependencyIds'], SORT_NUMERIC);
		}
		if (isset($params['semanticRevision'])) {
			ksort($params, SORT_STRING);
		}
		parent::__construct('wikidebatesSemanticCachePurge', $title, $params);
		$this->removeDuplicates = true;
	}

	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		// Only new, revision-stamped jobs may coalesce across source titles.
		// Legacy jobs retain their original identity, including their cursor.
		if (isset($this->params['semanticRevision'])) {
			unset($info['params']['namespace'], $info['params']['title']);
		}
		return $info;
	}

	/** Diagnostic counters; also lets tests assert effects rather than only true. */
	public function getMetrics(): array {
		return $this->metrics;
	}

	public function run(): bool {
		$started = microtime(true);
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$ids = SemanticCacheHooks::changedIds((array)($this->params['dependencyIds'] ?? []));
		$cursor = max(0, (int)($this->params['cursor'] ?? 0));
		$batchSize = max(1, min(5000, (int)($config->has('WikidebatesSemanticCachePurgeBatchSize') ?
			$config->get('WikidebatesSemanticCachePurgeBatchSize') : 1000)));
		$log = $config->has('WikidebatesSemanticCachePurgeLog') &&
			$config->get('WikidebatesSemanticCachePurgeLog');
		$this->metrics = [
			'dependencies' => count($ids), 'cursor' => $cursor, 'batch_size' => $batchSize,
			'queries_examined' => 0, 'queries_processed' => 0, 'pages' => 0,
			'invalidations' => 0, 'coalesced_pages' => 0, 'continuations' => 0,
			'next_cursor' => null, 'generation_reads' => 0, 'logging_failures' => 0,
			'coalescing_available' => false, 'dependent_pages_queued_for_prewarm' => 0,
			'status' => 'completed'
		];
		try {
			if (!$config->get('WikidebatesSemanticCacheTracking') ||
				!$config->get('WikidebatesSemanticCacheEagerPurge')) {
				// A successful run() is ACKNOWLEDGED by MediaWiki's JobRunner.
				// Returning true here silently discards historic queued purges
				// while EagerPurge is disabled. Fail retryably instead.
				$this->metrics['status'] = 'disabled';
				$this->setLastError('Semantic cache tracking or eager purging disabled; job retained for retry');
				return false;
			}
			if ($ids === []) {
				return true;
			}
			$store = SemanticServices::getInstance()->getStore();
			if (!$store instanceof SQLStore) {
				throw new \RuntimeException('SMW SQLStore is required for targeted dependency invalidation');
			}
			$db = $store->getConnection('mw.db');
			$rows = $db->newPrimarySelectQueryBuilder()
				->select('s_id')->distinct()->from(SQLStore::QUERY_LINKS_TABLE)
				->where(['o_id' => $ids, 's_id > ' . $cursor])
				->orderBy('s_id')->limit($batchSize + 1)
				->caller(__METHOD__)->fetchResultSet();
			$queryIds = [];
			foreach ($rows as $row) {
				$queryIds[] = (int)$row->s_id;
			}
			$this->metrics['queries_examined'] = count($queryIds);
			$more = count($queryIds) > $batchSize;
			if ($more) {
				array_pop($queryIds);
			}
			$this->metrics['queries_processed'] = count($queryIds);
			if ($queryIds === []) {
				return true;
			}

			// Resolve owners in ONE primary query. SMW's object-ID cache and
			// Title::exists() can otherwise use stale request/replica state.
			$rows = $db->newPrimarySelectQueryBuilder()
				->select(['page.*', 'purge_query_id' => 'query.smw_id'])
				->from(SQLStore::ID_TABLE, 'query')
				->join('page', 'page', [
					'page.page_namespace=query.smw_namespace',
					'page.page_title=query.smw_title'
				])
				->where(['query.smw_id' => $queryIds, 'query.smw_iw' => ''])
				->caller(__METHOD__)->fetchResultSet();
			$pages = $titles = $requiredQueries = [];
			foreach ($rows as $row) {
				$id = (int)$row->page_id;
				if (!isset($titles[$id])) {
					// Several query subobjects can belong to the same page.
					// Hydrate its Title once while retaining EVERY query ID.
					$title = Title::newFromRow($row);
					if (!$title || !$title->canExist()) {
						continue;
					}
					$pages[$id] = $row;
					$titles[$id] = $title;
				}
				$requiredQueries[$id][] = (int)$row->purge_query_id;
			}
			unset($rows);
			$this->metrics['pages'] = count($titles);
			if ($titles !== []) {
				// The persistent journal is explicitly opt-in. Validate it before
				// mutating any caches so an invalid private path fails retryably.
				$queueDirectory = $config->has('WikidebatesCachePreparationDirectory') ?
					$config->get('WikidebatesCachePreparationDirectory') : false;
				$prewarmQueue = is_string($queueDirectory) && $queueDirectory !== '' ?
					new CachePreparationQueue($queueDirectory, WikiMap::getCurrentWikiId()) : null;
				$state = new SemanticPurgeState($store);
				$available = $state->available();
				$this->metrics['coalescing_available'] = $available;
				$before = $available ? $state->snapshots($pages, $requiredQueries) : [];
				$receipts = $available ? $state->receipts(array_keys($pages)) : [];
				$fragments = FragmentService::store();
				$pending = [];
				$touchTargets = [];
				foreach ($titles as $id => $title) {
					$receipt = $receipts[$id] ?? null;
					// A missing or semantically obsolete receipt cannot be reused.
					// Avoid a filesystem read and exclusive lock in that case.
					// A candidate still MUST pass the local generation check.
					if (isset($before[$id]) && $receipt && $receipt->fingerprint === $before[$id]) {
						$this->metrics['generation_reads']++;
						$generation = $fragments->read($title->getPrefixedDBkey(), 'semantic-purge', [])['generation'];
						if ($receipt->generation === $generation) {
							$this->metrics['coalesced_pages']++;
							continue;
						}
					}
					// Unlike FragmentService::invalidate(), this strict call must
					// propagate a filesystem failure: do not certify a failed purge.
					$fragments->invalidate($title->getPrefixedDBkey());
					$page = $services->getWikiPageFactory()->newFromTitle($title);
					$touchTarget = $prewarmQueue !== null
						? SemanticTouchBarrier::targetAfter((string)$pages[$id]->page_touched, time())
						: null;
					if ($touchTarget !== null) {
						if (!$title->invalidateCache($touchTarget)) {
							throw new \RuntimeException('Cannot defer dependent page_touched invalidation');
						}
						$touchTargets[$id] = $touchTarget;
					} else {
						// Preserve ordinary MediaWiki purge behavior when the opt-in
						// preparation queue is disabled (including standalone adapters).
						$title->invalidateCache();
					}
					$services->getParserCache()->deleteOptionsKey($page);
					$pending[$id] = $title;
				}
				if ($pending !== []) {
					// Immediate file/CDN dispatch, as in core HTMLCacheUpdateJob.
					// Still fires TitleSquidURLs (including Minerva), NOT ArticlePurge.
					$htmlCache = $services->getHtmlCacheUpdater();
					// Distinguish this dependent SMW refresh from a direct edit, deletion,
					// explicit purge or permission change. This provenance is advisory only:
					// every existing invalidation remains enabled until safety review.
					SemanticPurgeScope::run(static function () use ($htmlCache, $pending): void {
						$htmlCache->purgeTitleUrls(array_values($pending), $htmlCache::PURGE_NAIVE);
					});
					$this->metrics['invalidations'] = count($pending);
					// Queue only AFTER the real dependent HTML purge succeeds,
					// and BEFORE durable coalescing receipts are certified. If
					// any journal write fails, the purge job remains retryable;
					// successful writes are idempotent from the worker's view.
					// Use the page row from the PRIMARY SMW query. The queue
					// retains a still-newer direct edit if one raced this job.
					if ($prewarmQueue !== null) {
						$barriers = [];
						foreach ($pending as $id => $title) {
							$revisionId = (int)$pages[$id]->page_latest;
							if ($revisionId < 1) {
								throw new \RuntimeException('Cannot queue a semantic dependent without a revision');
							}
							$generation = $prewarmQueue->enqueueInvalidation(
								(int)$id, $revisionId, $touchTargets[$id]
							);
							$barriers[$id] = [
								'title' => $title, 'revisionId' => $revisionId,
								'generation' => $generation, 'touchAfter' => $touchTargets[$id]
							];
							$this->metrics['dependent_pages_queued_for_prewarm']++;
						}
						// PRESEND after all earlier Title::invalidateCache updates.
						// The callback re-purges HTML after the DB touch and only
						// then opens the exact queued generation for the worker.
						$recordAfterBarrier = static function (array $confirmed) use (
							$available, $state, $before, $requiredQueries, $fragments
						): void {
							if (!$available) {
								return;
							}
							$after = $state->snapshots(
								$state->pages(array_keys($confirmed)), $requiredQueries
							);
							$completed = [];
							foreach ($confirmed as $id => $details) {
								if (!isset($before[$id], $after[$id]) ||
									$before[$id] !== $after[$id]) {
									continue;
								}
								$completed[] = [
									'page_id' => $id, 'fingerprint' => $before[$id],
									'generation' => $fragments->read(
										$details['title']->getPrefixedDBkey(), 'semantic-purge', []
									)['generation']
								];
							}
							$state->remember($completed);
						};
						SemanticTouchBarrier::schedule(
							$prewarmQueue, $barriers,
							$services->getConnectionProvider()->getPrimaryDatabase(),
							$htmlCache, $recordAfterBarrier
						);
					}
					if ($available && $prewarmQueue === null) {
						$after = $state->snapshots($state->pages(array_keys($pending)), $requiredQueries);
						$completed = [];
						foreach ($pending as $id => $title) {
							if (isset($before[$id], $after[$id]) && $before[$id] === $after[$id]) {
								$this->metrics['generation_reads']++;
								$completed[] = [
									'page_id' => $id, 'fingerprint' => $before[$id],
									'generation' => $fragments->read(
										$title->getPrefixedDBkey(), 'semantic-purge', []
									)['generation']
								];
							}
						}
						$state->remember($completed);
					}
				}
			}
			// Only acknowledge a successor AFTER successful invalidation. A
			// retry preserves this cursor; completed pages can reuse receipts.
			if ($more) {
				$params = $this->params;
				$params['dependencyIds'] = $ids;
				$params['cursor'] = max($queryIds);
				$services->getJobQueueGroup()->push(new self($this->title, $params));
				$this->metrics['continuations'] = 1;
				$this->metrics['next_cursor'] = $params['cursor'];
			}
			return true;
		} catch (\Throwable $e) {
			$this->metrics['status'] = 'failed';
			$this->setLastError(defined('WD_FULLSTACK_TEST') ?
				get_class($e) . ': ' . $e->getMessage() : get_class($e));
			$this->log('error', 'Failed to purge semantic dependents ({type})', ['type' => get_class($e)]);
			return false;
		} finally {
			$this->metrics['duration_ms'] = round((microtime(true) - $started) * 1000, 3);
			if ($log) {
				$this->log('info', 'Semantic purge batch {metrics}', [
					'metrics' => json_encode($this->metrics, JSON_THROW_ON_ERROR)
				]);
			}
		}
	}

	/** Telemetry must neither turn success into failure nor mask the original failure. */
	private function log(string $level, string $message, array $context): void {
		try {
			LoggerFactory::getInstance('WikidebatesSemanticCache')->$level($message, $context);
		} catch (\Throwable $e) {
			// Do not try another logger here: that can recurse into the same sink.
			$this->metrics['logging_failures']++;
		}
	}
}
