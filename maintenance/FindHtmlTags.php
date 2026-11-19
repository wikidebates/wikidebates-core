<?php

use MediaWiki\Content\TextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * Read-only inventory of legacy HTML Tags invocations in the CURRENT main slot
 * of wiki pages. Does not render, update, purge or index anything.
 */
class FindHtmlTags extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Find HTML Tags invocations in current wiki text, read only.');
		$this->requireExtension('Wikidebates');
		$this->addOption('after', 'Resume after this page ID (default 0)', false, true);
		$this->addOption('limit', 'Maximum number of pages to scan (default: all)', false, true);
		$this->addOption('batch-size', 'Pages per database batch (1-1000, default 200)', false, true);
	}

	public function execute() {
		$after = (int)$this->getOption('after', 0);
		$limit = (int)$this->getOption('limit', PHP_INT_MAX);
		$batch = (int)$this->getOption('batch-size', 200);
		if ($after < 0 || $limit < 1 || $batch < 1 || $batch > 1000) {
			$this->fatalError('Invalid cursor, limit or batch-size.');
		}
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getReplicaDatabase();
		$revisions = $services->getRevisionLookup();
		$scanned = 0;
		$hits = 0;
		$nontext = 0;
		$missing = 0;
		$errors = 0;

		do {
			$rows = $db->newSelectQueryBuilder()
				->select(['page_id', 'page_namespace', 'page_title'])
				->from('page')
				->where([$db->expr('page_id', '>', $after)])
				->orderBy('page_id')
				->limit(min($batch, $limit - $scanned))
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
					if (!$revision) { $missing++; continue; }
					$content = $revision->getContent(SlotRecord::MAIN);
					if (!$content) { $missing++; continue; }
					if (!$content instanceof TextContent) { $nontext++; continue; }
					$text = $content->getText();
					if (preg_match('~<\s*/?\s*htmltag\b|\#\s*tag\s*:\s*htmltag\b~i', $text)) {
						$hits++;
						$this->output('MATCH page_id=' . $after . ' namespace=' . $row->page_namespace .
							' title=' . $title->getPrefixedText() . "\n");
					}
				} catch (\Throwable $e) {
					$errors++;
					$this->output('ERROR page_id=' . $after . ' title=' . $title->getPrefixedText() .
						' exception=' . get_class($e) . "\n");
				}
			}
			$this->output("Checked=$scanned matches=$hits nontext=$nontext missing=$missing errors=$errors resume_after=$after\n");
		} while ($found > 0 && $scanned < $limit);
		if ($errors || $missing) {
			$this->fatalError('Inventory incomplete: some revisions could not be read; do not disable HTML Tags.');
		}
		$this->output("DONE. Pages checked=$scanned, matches=$hits, nontext=$nontext; last_page_id=$after\n");
	}
}

$maintClass = FindHtmlTags::class;
require_once RUN_MAINTENANCE_IF_MAIN;
