<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use DateTimeImmutable;
use DateTimeZone;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;

/**
 * MediaWiki 1.43 Title::invalidateCache() defers page_touched to PRESEND.
 * A dependent-page prewarm record must stay closed until those database writes
 * have happened and a final HTML purge has removed any intervening files.
 */
final class SemanticTouchBarrier {
	/**
	 * Force a strictly newer touch than the page row observed on PRIMARY.
	 * MediaWiki stores touched timestamps with second resolution: a same-second
	 * touch could otherwise be a no-op, so choose at least the next second.
	 */
	public static function targetAfter(string $touched, int $now): string {
		$date = DateTimeImmutable::createFromFormat('!YmdHis', $touched, new DateTimeZone('UTC'));
		if (!preg_match('/\A[0-9]{14}\z/D', $touched) || !$date ||
			$date->format('YmdHis') !== $touched || $now <= 0) {
			throw new \InvalidArgumentException('Invalid primary page_touched timestamp');
		}
		$target = max($now + 1, $date->getTimestamp() + 1);
		return gmdate('YmdHis', $target);
	}

	/**
	 * @param CachePreparationQueue $queue Persisted per-wiki generation queue.
	 * @param array<int,array{title:mixed,revisionId:int,generation:string,touchAfter:string,pageNamespace?:int,pageTitle?:string,requireAnonymousRead?:bool}> $pages
	 * @param mixed $db Primary database connection for rollback-aware deferral.
	 * @param mixed $htmlCache HtmlCacheUpdater, used only for a final safe purge.
	 * @param callable(array):void|null $onReady Optional safe post-purge receipt recording.
	 */
	public static function schedule(
		CachePreparationQueue $queue, array $pages, $db, $htmlCache, ?callable $onReady = null
	): void {
		if ($pages === []) {
			return;
		}
		// On the default JobRunner this callback runs AFTER commitPrimaryChanges
		// and AFTER the earlier Title::invalidateCache PRESEND updates.
		// Registration on the DB connection cancels it on transaction rollback.
		DeferredUpdates::addCallableUpdate(
			static function () use ($queue, $pages, $htmlCache, $onReady): void {
				$dbw = MediaWikiServices::getInstance()
					->getConnectionProvider()->getPrimaryDatabase();
				$failures = 0;
				$confirmed = [];
				foreach ($pages as $id => $details) {
					try {
						$current = $queue->current((int)$id);
						if (!$current || $current['schema'] !== 3 ||
							!hash_equals($details['generation'], $current['generation'])) {
							// A new edit or invalidation superseded this generation.
							continue;
						}
						$row = $dbw->selectRow('page',
							['page_latest', 'page_touched', 'page_namespace', 'page_title'],
							['page_id' => (int)$id], __METHOD__);
						if (!$row || (int)$row->page_latest !== $details['revisionId'] ||
							!is_string($row->page_touched) ||
							(string)$row->page_touched < $details['touchAfter']) {
							$failures++;
							continue; // Leave the existing record unready.
						}
						// Recovery must not certify a page that was moved while
						// its revision remained unchanged, nor a page that has
						// become unreadable to anonymous visitors.
						if ((isset($details['pageNamespace'], $details['pageTitle']) &&
								((int)$row->page_namespace !== $details['pageNamespace'] ||
									(string)$row->page_title !== $details['pageTitle'])) ||
							(($details['requireAnonymousRead'] ?? false) &&
								AnonymousCacheSafety::fingerprint((int)$id) === null)) {
							$failures++;
							continue;
						}
						// The first HTML purge preceded the deferred SQL write.
						// A second purge closes the gap for files published meanwhile.
						SemanticPurgeScope::run(static function () use ($htmlCache, $details): void {
							$htmlCache->purgeTitleUrls([$details['title']], $htmlCache::PURGE_NAIVE);
						});
						if ($queue->confirmTouched((int)$id, $details['revisionId'],
							$details['generation'], (string)$row->page_touched)) {
							$confirmed[(int)$id] = $details;
						} else {
							// Another request superseded it, or the touch barrier
							// is still not satisfied. Never force an acknowledgement.
							$pending = $queue->current((int)$id);
							if ($pending && $pending['schema'] === 3 &&
								$pending['generation'] === $details['generation']) {
								$failures++;
							}
						}
					} catch (\Throwable $e) {
						$failures++;
					}
				}
				if ($confirmed !== [] && $onReady !== null) {
					$onReady($confirmed);
				}
				if ($failures) {
					// The deferred runner logs this exception. Unready entries
					// remain durable; never mark them complete on error.
					throw new \RuntimeException('Deferred semantic cache touch barrier incomplete');
				}
			},
			DeferredUpdates::PRESEND,
			$db
		);
	}
}
