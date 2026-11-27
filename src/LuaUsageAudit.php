<?php

namespace MediaWiki\Extension\Wikidebates;

use MediaWiki\Content\TextContent;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Conservative read-only inventory, not a wikitext interpreter.
 * A signal is a review candidate, not proof of runtime execution.
 */
final class LuaUsageAudit {
	public const MODULE_NAMESPACE = 828;
	public const MODULE_TALK_NAMESPACE = 829;

	/** All installed Wikidebates content languages, including English fallback. */
	public static function signals(string $source, string $model, int $namespace): array {
		$signals = [];
		$lua = in_array(strtolower($model), ['scribunto', 'lua'], true);
		if ($lua) {
			$signals[] = 'lua_content_model';
		}
		if (in_array($namespace, [self::MODULE_NAMESPACE, self::MODULE_TALK_NAMESPACE], true)) {
			$signals[] = 'module_namespace';
		}

		// Retain the raw source (including documentation), but also detect
		// magic words whose spelling is split by an HTML comment.
		$normalized = preg_replace('~<!--.*?-->~s', '', $source);
		if ($normalized === null) {
			throw new \RuntimeException('Cannot normalize source');
		}
		$text = $source . "\n" . html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$patterns = [
			'invoke' => '~#\s*(?:invoke|invoque|aufrufen|invocar)(?![\p{L}\p{N}_-])~iu',
			'dynamic_parser_name' => '~\{\{\s*(?:(?:safe)?subst\s*:\s*)?#\s*(?:[\p{L}_-]+\s*)?\{\{~iu',
			'module_reference' => '~(?:\[\[\s*:?\s*|\{\{\s*:?\s*)(?:Module|Modul|Módulo|Modulo)\s*:~iu',
			'native_argument' => '~#\s*argument\s*:\s*render\b~iu',
			'native_debate' => '~#\s*debate\s*:\s*render\b~iu',
			'native_argument_map' => '~#\s*argumentmap\s*:\s*render\b~iu',
			'semantic_lua_api' => '~\bmw\s*(?:\.\s*smw\b|\[\s*["\x27]smw["\x27]\s*\])~iu',
			'variables_lua_api' => '~\bmw\s*\.\s*ext\s*\.\s*(?:VariablesLua|variables)\b~iu',
			'seo_lua_api' => '~\bmw\s*\.\s*ext\s*\.\s*seo\b~iu'
		];
		if ($lua) {
			$patterns['lua_module_loader'] = '~\brequire\b|\bmw\s*\.\s*load(?:Json)?Data\b~u';
		}
		foreach ($patterns as $kind => $pattern) {
			$match = preg_match($pattern, $text);
			if ($match === false) {
				throw new \RuntimeException('Cannot scan source');
			}
			if ($match === 1) {
				$signals[] = $kind;
			}
		}
		return $signals;
	}

	/** Native parser calls are informational; everything else requires review. */
	public static function needsReview(array $signals): bool {
		return array_diff($signals, ['native_argument', 'native_debate', 'native_argument_map']) !== [];
	}

	/**
	 * Use the page_latest read from the replica, not a parser cache.
	 * Inspect all current slots without executing anything or reading hidden text.
	 * A bounded scan is never represented as a complete wiki-wide inventory.
	 */
	public static function scan(IReadableDatabase $db, RevisionLookup $revisions,
		int $after, int $limit, int $batch, callable $emit): array {
		if ($after < 0 || $limit < 1 || $batch < 1 || $batch > 1000) {
			throw new \InvalidArgumentException('Invalid cursor, limit or batch size');
		}
		$start = $after;
		$upper = (int)$db->newSelectQueryBuilder()->select('MAX(page_id)')
			->from('page')->caller(__METHOD__)->fetchField();
		$summary = [
			'type' => 'summary', 'start_after' => $start, 'upper_page_id' => $upper,
			'pages' => 0, 'slots' => 0, 'review_pages' => 0, 'module_pages' => 0,
			'lua_model_pages' => 0, 'unreadable' => 0, 'errors' => 0, 'signals' => []
		];
		$exhausted = false;
		while ($summary['pages'] < $limit && $after < $upper) {
			$rows = $db->newSelectQueryBuilder()
				->select(['page_id', 'page_latest', 'page_namespace', 'page_title'])
				->from('page')
				->where([$db->expr('page_id', '>', $after), $db->expr('page_id', '<=', $upper)])
				->orderBy('page_id')->limit(min($batch, $limit - $summary['pages']))
				->caller(__METHOD__)->fetchResultSet();
			$found = 0;
			foreach ($rows as $row) {
				$after = (int)$row->page_id;
				$namespace = (int)$row->page_namespace;
				$title = Title::makeTitle($namespace, $row->page_title);
				$base = [
					'page_id' => $after, 'revision_id' => (int)$row->page_latest,
					'namespace' => $namespace, 'title' => $title->getPrefixedText()
				];
				$found++;
				$summary['pages']++;
				$summary['module_pages'] += (int)($namespace === self::MODULE_NAMESPACE);
				$review = false;
				$luaPage = false;
				try {
					$revision = $revisions->getRevisionById($base['revision_id']);
					if (!$revision || (int)$revision->getPageId() !== $after ||
						$revision->isDeleted(RevisionRecord::DELETED_TEXT) || !$revision->getSlotRoles()) {
						$summary['unreadable']++;
						$emit(['type' => 'unreadable'] + $base);
						continue;
					}
					foreach ($revision->getSlotRoles() as $role) {
						try {
							$content = $revision->getContent($role, RevisionRecord::FOR_PUBLIC);
							if (!$content) {
								$summary['unreadable']++;
								$emit(['type' => 'unreadable', 'slot' => $role] + $base);
								continue;
							}
							$summary['slots']++;
							$model = $content->getModel();
							$source = $content instanceof TextContent ? $content->getText() : $content->serialize();
							if (!is_string($source)) {
								throw new \RuntimeException('Content is not serializable');
							}
							$signals = self::signals($source, $model, $namespace);
							foreach ($signals as $kind) {
								$summary['signals'][$kind] = ($summary['signals'][$kind] ?? 0) + 1;
							}
							$luaPage = $luaPage || in_array('lua_content_model', $signals, true);
							if (self::needsReview($signals)) {
								$review = true;
								$emit(['type' => 'candidate', 'slot' => $role, 'model' => $model,
									'signals' => $signals] + $base);
							}
						} catch (\Throwable $error) {
							$summary['errors']++;
							$emit(['type' => 'error', 'slot' => $role, 'exception' => get_class($error)] + $base);
						}
					}
				} catch (\Throwable $error) {
					$summary['errors']++;
					$emit(['type' => 'error', 'exception' => get_class($error)] + $base);
				}
				$summary['review_pages'] += (int)$review;
				$summary['lua_model_pages'] += (int)$luaPage;
			}
			if (!$found) {
				$exhausted = true;
				break;
			}
		}
		$summary['resume_after'] = $after;
		$summary['range_exhausted'] = $exhausted || $after >= $upper;
		$summary['full_current_scan'] = $start === 0 && $summary['range_exhausted'] &&
			$summary['unreadable'] === 0 && $summary['errors'] === 0;
		ksort($summary['signals']);
		return $summary;
	}

	/**
	 * Two independent metadata checks. Cached template links may be stale.
	 * Content rows are not revision counts: one row can be shared by many
	 * revisions, and older/deleted revisions may still require the handler.
	 */
	public static function storedEvidence(IReadableDatabase $db, callable $emit): array {
		$rows = $db->newSelectQueryBuilder()->select(['model_name', 'content_rows' => 'COUNT(*)'])
			->from('content')->join('content_models', null, 'model_id = content_model')
			->where(['model_name' => ['Scribunto', 'lua']])
			->groupBy('model_name')->caller(__METHOD__)->fetchResultSet();
		$models = [];
		foreach ($rows as $row) {
			$models[$row->model_name] = (int)$row->content_rows;
		}
		$links = $db->newSelectQueryBuilder()->select(['tl_from', 'lt_title'])
			->from('templatelinks')->join('linktarget', null, 'tl_target_id = lt_id')
			->where(['lt_namespace' => self::MODULE_NAMESPACE])
			->orderBy(['tl_from', 'lt_title'])->caller(__METHOD__)->fetchResultSet();
		$linkCount = 0;
		$sourceCount = 0;
		$previous = null;
		foreach ($links as $link) {
			$id = (int)$link->tl_from;
			$sourceCount += (int)($id !== $previous);
			$previous = $id;
			$linkCount++;
			$emit(['type' => 'stored_module_link', 'source_page_id' => $id,
				'target_dbkey' => $link->lt_title]);
		}
		return ['stored_lua_content_rows_by_model' => $models, 'stored_module_links' => $linkCount,
			'stored_module_link_sources' => $sourceCount];
	}
}
