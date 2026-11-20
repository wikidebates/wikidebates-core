<?php

use MediaWiki\Content\TextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage as SemanticWikiPage;
use SMW\Services\ServicesFactory as SemanticServices;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * Read-only inventory for staging semantic-cache rollout. Reports flags,
 * lengths and SMW profile counts, never any page content or private values.
 *
 * No edits, parsing, maintenance jobs, cache purges or schema updates.
 */
class AuditSemanticCacheSources extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Read-only scan of NOCACHE, Argument/Debate readers and SMW _ASK profiles.');
		$this->requireExtension('Wikidebates');
		$this->requireExtension('SemanticMediaWiki');
		$this->addOption('after', 'Resume after page ID (default 0)', false, true);
		$this->addOption('limit', 'Max pages to examine (default 200; max 5000)', false, true);
		$this->addOption('batch-size', 'Pages per SELECT (default 100; max 500)', false, true);
		$this->addOption('namespaces', 'Comma-separated numeric namespace IDs (default 0,10)', false, true);
		$this->addOption('ids', 'Optional comma-separated page IDs to inspect', false, true);
	}

	public function execute() {
		$after = (int)$this->getOption('after', 0);
		$limit = (int)$this->getOption('limit', 200);
		$batchSize = (int)$this->getOption('batch-size', 100);
		if ($after < 0 || $limit < 1 || $limit > 5000 ||
			$batchSize < 1 || $batchSize > 500) {
			$this->fatalError('Invalid --after, --limit (1-5000) or --batch-size (1-500).');
		}
		$parseIds = static function(string $value, bool $allowZero): array {
			if (!preg_match('/^[0-9]+(?:,[0-9]+)*$/D', $value)) {
				throw new InvalidArgumentException('Expected comma-separated numeric IDs.');
			}
			$result = array_values(array_unique(array_map('intval', explode(',', $value))));
			if (!$allowZero && in_array(0, $result, true)) {
				throw new InvalidArgumentException('Page IDs must be positive.');
			}
			return $result;
		};
		try {
			$namespaces = $parseIds(trim((string)$this->getOption('namespaces', '0,10')), true);
			$idText = trim((string)$this->getOption('ids', ''));
			$ids = $idText === '' ? [] : $parseIds($idText, false);
		} catch (InvalidArgumentException $e) {
			$this->fatalError($e->getMessage());
		}

		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getReplicaDatabase();
		$revisions = $services->getRevisionLookup();
		$store = SemanticServices::getInstance()->getStore();
		$scanned = 0;
		$matched = 0;
		$nocacheCount = 0;
		$profileCount = 0;
		$unprofiledArgument = 0;
		$debateOnlyWithoutProfiles = 0;
		$nocacheDebateOnlySources = 0;
		$indirectProfiles = 0;
		$nontext = 0;
		$missing = 0;
		$errors = 0;

		do {
			$where = [
				'page_namespace' => $namespaces,
				$db->expr('page_id', '>', $after),
			];
			if ($ids !== []) {
				$where['page_id'] = $ids;
			}
			$rows = $db->newSelectQueryBuilder()
				->select(['page_id', 'page_namespace', 'page_title'])
				->from('page')
				->where($where)
				->orderBy('page_id')
				->limit(min($batchSize, $limit - $scanned))
				->caller(__METHOD__)
				->fetchResultSet();
			$found = 0;
			foreach ($rows as $row) {
				$after = (int)$row->page_id;
				$found++;
				$scanned++;
				$title = Title::makeTitle((int)$row->page_namespace, $row->page_title);
				try {
					$revision = $revisions->getRevisionByTitle($title);
					if (!$revision) {
						$missing++;
						continue;
					}
					$content = $revision->getContent(SlotRecord::MAIN);
					if (!$content) {
						$missing++;
						continue;
					}
					if (!$content instanceof TextContent) {
						$nontext++;
						continue;
					}
					$source = $content->getText();
					$nocache = preg_match('/__NOCACHE__/i', $source) === 1;
					$argumentTemplate = preg_match('/\{\{\s*Argument\s*(?:[|}])/iu', $source) === 1;
					$debateTemplate = preg_match('/\{\{\s*(?:D[eé]bat|Debate)\s*(?:[|}])/iu', $source) === 1;
					$nativeArgument = preg_match('/\{\{\s*#argument\s*:\s*render\s*(?:[|}])/iu', $source) === 1;
					$nativeDebate = preg_match('/\{\{\s*#debate\s*:\s*render\s*(?:[|}])/iu', $source) === 1;
					$legacyArgument = preg_match('/\{\{\s*#invoke\s*:\s*Argument\s*\|\s*render\s*(?:[|}])/iu', $source) === 1;
					$legacyDebate = preg_match('/\{\{\s*#invoke\s*:\s*Debate\s*\|\s*render\s*(?:[|}])/iu', $source) === 1;
					$argumentReader = $argumentTemplate || $nativeArgument || $legacyArgument;
					$debateRenderer = $debateTemplate || $nativeDebate || $legacyDebate;
					$direct = $argumentReader || $debateRenderer;
					$profiles = $store->getPropertyValues(
						SemanticWikiPage::newFromTitle($title), new Property('_ASK')
					);
					$hasProfiles = $profiles !== [];
					$debateOnly = $debateRenderer && !$argumentReader;
					$nocacheCount += (int)$nocache;
					$profileCount += (int)$hasProfiles;
					$unprofiledArgument += (int)($argumentReader && !$hasProfiles);
					$debateOnlyWithoutProfiles += (int)($debateOnly && !$hasProfiles);
					$nocacheDebateOnlySources += (int)($debateOnly && $nocache);
					$indirectProfiles += (int)($hasProfiles && !$direct);
					if ($nocache || $direct || $hasProfiles) {
						$matched++;
						$this->emit([
							'type' => 'page',
							'page_id' => $after,
							'namespace' => $title->getNamespace(),
							'title' => $title->getPrefixedText(),
							'bytes' => strlen($source),
							'nocache' => $nocache,
							// Keep older grouped keys for existing JSONL consumers,
							// but expose the important Argument/Debate distinction.
							'argument_or_debate_template' => $argumentTemplate || $debateTemplate,
							'native_php_reader' => $nativeArgument || $nativeDebate,
							'legacy_lua_reader' => $legacyArgument || $legacyDebate,
							'argument_template' => $argumentTemplate,
							'debate_template' => $debateTemplate,
							'native_argument_reader' => $nativeArgument,
							'native_debate_renderer' => $nativeDebate,
							'legacy_argument_reader' => $legacyArgument,
							'legacy_debate_renderer' => $legacyDebate,
							'argument_reader' => $argumentReader,
							'debate_renderer' => $debateRenderer,
							'ask_profile_count' => count($profiles),
							'unprofiled_direct_reader' => $argumentReader && !$hasProfiles,
							'unprofiled_argument_reader' => $argumentReader && !$hasProfiles,
							'debate_only_without_profiles' => $debateOnly && !$hasProfiles,
							'nocache_in_debate_only_source' => $debateOnly && $nocache,
							'profile_without_direct_reader' => $hasProfiles && !$direct,
						]);
					}
					if ($scanned % 100 === 0) {
						$store->clear();
					}
				} catch (\Throwable $e) {
					$errors++;
					$this->emit([
						'type' => 'error', 'page_id' => $after,
						'title' => $title->getPrefixedText(),
						'exception_class' => get_class($e),
					]);
				}
			}
			$this->emit([
				'type' => 'progress', 'scanned' => $scanned,
				'matched' => $matched, 'resume_after' => $after,
			]);
		} while ($found > 0 && $scanned < $limit);

		$this->emit([
			'type' => 'summary',
			'scanned' => $scanned, 'matched' => $matched,
			'nocache_pages' => $nocacheCount,
			'pages_with_ask_profiles' => $profileCount,
			'direct_readers_without_profiles' => $unprofiledArgument,
			'argument_readers_without_profiles' => $unprofiledArgument,
			'debate_only_without_profiles' => $debateOnlyWithoutProfiles,
			'nocache_in_debate_only_sources' => $nocacheDebateOnlySources,
			'profiles_without_direct_readers' => $indirectProfiles,
			'nontext' => $nontext, 'missing' => $missing,
			'errors' => $errors, 'resume_after' => $after,
		]);
		if ($errors || $missing) {
			$this->fatalError('Inventory incomplete: some pages could not be inspected.');
		}
	}

	private function emit(array $record): void {
		$this->output(json_encode(
			$record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		) . "\n");
	}
}

$maintClass = AuditSemanticCacheSources::class;
require_once RUN_MAINTENANCE_IF_MAIN;
