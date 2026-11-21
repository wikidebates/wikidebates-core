<?php

use MediaWiki\MediaWikiServices;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * Read-only inspection of SQL-backed semantic purge jobs. Unlike showJobs
 * --list, never instantiates a Job, so an unregistered JobFactory cannot hide
 * stored cursor progress. Intended for the site's standard 'job' table.
 */
class InspectSemanticPurgeQueue extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Inspect stored semantic purge jobs without executing them or using JobFactory.');
		$this->requireExtension('Wikidebates');
		$this->addOption('after-id', 'Only inspect job IDs greater than this value (default 0).', false, true);
		$this->addOption('limit', 'Maximum rows read (default 200, maximum 1000).', false, true);
		$this->addOption('match', 'Optional literal substring of the stored title.', false, true);
		$this->addOption('json', 'Emit one JSON record per job, followed by a summary.');
	}

	public function execute() {
		$after = filter_var($this->getOption('after-id', 0), FILTER_VALIDATE_INT);
		$limit = filter_var($this->getOption('limit', 200), FILTER_VALIDATE_INT);
		$match = (string)$this->getOption('match', '');
		if ($after === false || $after < 0 || $limit === false || $limit < 1 ||
			$limit > 1000 || strlen($match) > 200) {
			$this->fatalError('Expected --after-id >= 0, --limit in 1..1000 and --match up to 200 bytes.');
		}
		$db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$rows = $db->newSelectQueryBuilder()
			->select(['job_id', 'job_title', 'job_params', 'job_token', 'job_attempts'])
			->from('job')
			->where([
				'job_cmd' => 'wikidebatesSemanticCachePurge',
				$db->expr('job_id', '>', $after)
			])
			->orderBy('job_id')
			->limit($limit)
			->caller(__METHOD__)
			->fetchResultSet();
		$scanned = $matched = $invalid = 0;
		$lastId = $after;
		foreach ($rows as $row) {
			$scanned++;
			$lastId = (int)$row->job_id;
			if ($match !== '' && !str_contains((string)$row->job_title, $match)) {
				continue;
			}
			// JobQueueDB::makeBlob() writes PHP-serialized arrays. Never allow
			// objects to be constructed from potentially malformed job data.
			try {
				$params = @unserialize((string)$row->job_params, ['allowed_classes' => false]);
			} catch (\Throwable $e) {
				$params = false;
			}
			if (!is_array($params)) {
				$invalid++;
			}
			$record = [
				'type' => 'job',
				'job_id' => (int)$row->job_id,
				'title' => (string)$row->job_title,
				'cursor' => is_array($params) && isset($params['cursor']) &&
					(is_int($params['cursor']) || ctype_digit((string)$params['cursor']))
						? (int)$params['cursor'] : null,
				'dependency_count' => is_array($params) &&
					is_array($params['dependencyIds'] ?? null)
						? count($params['dependencyIds']) : null,
				'claimed' => (string)$row->job_token !== '',
				'attempts' => (int)$row->job_attempts,
				'valid_payload' => is_array($params)
			];
			$matched++;
			if ($this->hasOption('json')) {
				$this->emit($record);
			} else {
				$this->output('id=' . $record['job_id'] .
					' cursor=' . ($record['cursor'] ?? '?') .
					' dependencies=' . ($record['dependency_count'] ?? '?') .
					' claimed=' . ($record['claimed'] ? 'yes' : 'no') .
					' attempts=' . $record['attempts'] .
					' title=' . $record['title'] . "\n");
			}
		}
		$summary = [
			'type' => 'summary', 'rows_examined' => $scanned,
			'jobs_matching_title' => $matched, 'invalid_payloads' => $invalid,
			'resume_after_id' => $lastId, 'limit' => $limit,
			'scope' => 'Primary SQL job table only; no JobFactory, execution, claims or acknowledgements.'
		];
		if ($this->hasOption('json')) {
			$this->emit($summary);
		} else {
			$this->output('Summary: ' . json_encode($summary, JSON_THROW_ON_ERROR) . "\n");
		}
	}

	private function emit(array $record): void {
		$this->output(json_encode(
			$record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		) . "\n");
	}
}

$maintClass = InspectSemanticPurgeQueue::class;
require_once RUN_MAINTENANCE_IF_MAIN;
