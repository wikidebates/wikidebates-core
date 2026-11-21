<?php

use MediaWiki\MediaWikiServices;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * Read-only inspection of SQL-backed semantic purges. Do not instantiate,
 * claim, acknowledge or execute the job; SQL is the authority for cursors.
 */
class InspectSemanticPurgeQueue extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Read-only SQL inspection of semantic purge progress, claim ages and duplicate continuations.');
		$this->requireExtension('Wikidebates');
		$this->addOption('after-id', 'Only inspect job IDs greater than this value (default 0).', false, true);
		$this->addOption('limit', 'Maximum rows read (default 200, maximum 1000).', false, true);
		$this->addOption('match', 'Optional literal substring of the stored title.', false, true);
		$this->addOption('claim-age-seconds', 'Review claimed jobs older than this many seconds (default 3600).', false, true);
		$this->addOption('json', 'Emit one JSON record per job, followed by a summary.');
	}

	/** Parse MediaWiki's 14-digit UTC timestamp, not a locale-sensitive date. */
	private static function ageInSeconds($value, int $now): ?int {
		$stamp = trim((string)$value, "\0");
		if (!preg_match('/^[0-9]{14}$/D', $stamp)) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat('!YmdHis', $stamp, new DateTimeZone('UTC'));
		if ($date === false || $date->format('YmdHis') !== $stamp) {
			return null;
		}
		return max(0, $now - $date->getTimestamp());
	}

	/** A stable group ID for different job attempts sharing the same work. */
	private static function continuationGroup(string $title, array $params): ?string {
		$ids = $params['dependencyIds'] ?? null;
		if (!is_array($ids) || $ids === []) {
			return null;
		}
		$normalized = [];
		foreach ($ids as $id) {
			if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
				return null;
			}
			if ((int)$id <= 0) {
				return null;
			}
			$normalized[(int)$id] = (int)$id;
		}
		sort($normalized, SORT_NUMERIC);
		$revision = $params['semanticRevision'] ?? '';
		if (!is_scalar($revision)) {
			return null;
		}
		return substr(hash('sha256', json_encode(
			[$title, $normalized, (string)$revision], JSON_THROW_ON_ERROR
		)), 0, 16);
	}

	public function execute() {
		$after = filter_var($this->getOption('after-id', 0), FILTER_VALIDATE_INT);
		$limit = filter_var($this->getOption('limit', 200), FILTER_VALIDATE_INT);
		$threshold = filter_var($this->getOption('claim-age-seconds', 3600), FILTER_VALIDATE_INT);
		$match = (string)$this->getOption('match', '');
		if ($after === false || $after < 0 || $limit === false || $limit < 1 ||
			$limit > 1000 || $threshold === false || $threshold < 60 ||
			$threshold > 2592000 || strlen($match) > 200) {
			$this->fatalError('Expected --after-id >= 0, --limit 1..1000, --claim-age-seconds 60..2592000 and --match <= 200 bytes.');
		}

		$db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$command = 'wikidebatesSemanticCachePurge';
		// Independent of pagination and title filtering: distinguish zero
		// remaining jobs from two IDs merely disappearing from an old scan.
		$total = (int)$db->newSelectQueryBuilder()
			->select('COUNT(*)')->from('job')->where(['job_cmd' => $command])
			->caller(__METHOD__)->fetchField();
		$rows = $db->newSelectQueryBuilder()
			->select([
				'job_id', 'job_title', 'job_params', 'job_token',
				'job_token_timestamp', 'job_timestamp', 'job_attempts'
			])
			->from('job')
			->where(['job_cmd' => $command, $db->expr('job_id', '>', $after)])
			->orderBy('job_id')
			->limit($limit + 1)
			->caller(__METHOD__)->fetchResultSet();

		$scanned = $matched = $invalid = $claimed = $oldClaims = 0;
		$unknownClaims = $retried = $maxAttempts = 0;
		$oldestClaim = $oldestQueue = null;
		$lastId = $after;
		$hasMore = false;
		$groups = [];
		$now = time();
		foreach ($rows as $row) {
			if ($scanned >= $limit) {
				$hasMore = true;
				break;
			}
			$scanned++;
			$lastId = (int)$row->job_id;
			if ($match !== '' && !str_contains((string)$row->job_title, $match)) {
				continue;
			}
			// JobQueueDB stores PHP-serialized parameter arrays. No object
			// instantiation is permitted from the persisted job payload.
			try {
				$params = @unserialize((string)$row->job_params, ['allowed_classes' => false]);
			} catch (Throwable $e) {
				$params = false;
			}
			if (!is_array($params)) {
				$invalid++;
			}
			$cursor = is_array($params) && isset($params['cursor']) &&
				(is_int($params['cursor']) || (is_string($params['cursor']) && ctype_digit($params['cursor'])))
				? max(0, (int)$params['cursor']) : null;
			$group = is_array($params) ? self::continuationGroup((string)$row->job_title, $params) : null;
			if ($group !== null && $cursor !== null) {
				$groups[$group]['cursors'][$cursor] = ($groups[$group]['cursors'][$cursor] ?? 0) + 1;
			}
			$isClaimed = (string)$row->job_token !== '';
			$claimAge = $isClaimed ? self::ageInSeconds($row->job_token_timestamp, $now) : null;
			$queueAge = self::ageInSeconds($row->job_timestamp, $now);
			$oldClaim = $isClaimed && $claimAge !== null && $claimAge >= $threshold;
			$attempts = (int)$row->job_attempts;
			$claimed += (int)$isClaimed;
			$oldClaims += (int)$oldClaim;
			$unknownClaims += (int)($isClaimed && $claimAge === null);
			$retried += (int)($attempts > 1);
			$maxAttempts = max($maxAttempts, $attempts);
			if ($claimAge !== null) {
				$oldestClaim = max($oldestClaim ?? 0, $claimAge);
			}
			if ($queueAge !== null) {
				$oldestQueue = max($oldestQueue ?? 0, $queueAge);
			}
			$record = [
				'type' => 'job',
				'job_id' => (int)$row->job_id,
				'title' => (string)$row->job_title,
				'cursor' => $cursor,
				'dependency_count' => is_array($params) &&
					is_array($params['dependencyIds'] ?? null) ? count($params['dependencyIds']) : null,
				'continuation_group' => $group,
				'claimed' => $isClaimed,
				'claim_age_seconds' => $claimAge,
				'old_claim' => $oldClaim,
				'queue_age_seconds' => $queueAge,
				'attempts' => $attempts,
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
					' claim_age_s=' . ($claimAge ?? '?') .
					' attempts=' . $attempts .
					' title=' . $record['title'] . "\n");
			}
		}
		$multipleCursors = $repeatedCursorGroups = $repeatedCursorJobs = 0;
		foreach ($groups as $group) {
			$cursors = $group['cursors'];
			$multipleCursors += (int)(count($cursors) > 1);
			$colliding = array_filter($cursors, static fn(int $count): bool => $count > 1);
			$repeatedCursorGroups += (int)($colliding !== []);
			$repeatedCursorJobs += array_sum($colliding);
		}
		$summary = [
			'type' => 'summary', 'rows_examined' => $scanned,
			'total_jobs_in_sql_queue' => $total,
			'jobs_matching_title' => $matched, 'invalid_payloads' => $invalid,
			'claimed_jobs' => $claimed, 'claims_older_than_threshold' => $oldClaims,
			'claims_without_valid_timestamp' => $unknownClaims,
			'claim_age_threshold_seconds' => $threshold,
			'oldest_claim_age_seconds' => $oldestClaim,
			'oldest_queue_age_seconds' => $oldestQueue,
			'jobs_with_multiple_attempts' => $retried, 'max_attempts' => $maxAttempts,
			'multiple_cursor_groups' => $multipleCursors,
			'repeated_cursor_groups' => $repeatedCursorGroups,
			'repeated_cursor_jobs' => $repeatedCursorJobs,
			'has_more' => $hasMore,
			'resume_after_id' => $lastId, 'limit' => $limit,
			'scope' => 'Read-only primary SQL job table. Old claims need investigation, not automatic reset. Multiple cursors can be valid continuations; no automatic purge.'
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
