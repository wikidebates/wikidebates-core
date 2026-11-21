<?php

/**
 * Read-only SQL job queue inspector integration. An isolated MediaWiki SQLite
 * database is created by mediawikiEnvironment.php; no production job is used.
 */
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\MediaWikiServices;

require_once __DIR__ . '/../maintenance/InspectSemanticPurgeQueue.php';

$services = MediaWikiServices::getInstance();
$jobType = 'wikidebatesSemanticCachePurge';
$jobClasses = $services->getMainConfig()->get('JobClasses');
check(!isset($jobClasses[$jobType]),
	'Inspection does not require an installed semantic purge JobClass');

$db = $services->getConnectionProvider()->getPrimaryDatabase();
$rows = [
	[
		'job_cmd' => $jobType, 'job_namespace' => NS_MAIN,
		'job_title' => 'WD_queue_probe_good',
		'job_params' => serialize(['dependencyIds' => [101, 202], 'cursor' => 753]),
		'job_timestamp' => $db->timestamp(), 'job_sha1' => ''
	],
	[
		'job_cmd' => $jobType, 'job_namespace' => NS_MAIN,
		'job_title' => 'WD_queue_probe_bad',
		'job_params' => 'unexpected-serialized-input',
		'job_timestamp' => $db->timestamp(), 'job_sha1' => ''
	],
	[
		'job_cmd' => 'refreshLinks', 'job_namespace' => NS_MAIN,
		'job_title' => 'WD_queue_unrelated',
		'job_params' => serialize(['cursor' => 9999]),
		'job_timestamp' => $db->timestamp(), 'job_sha1' => ''
	]
];
$db->newInsertQueryBuilder()
	->insertInto('job')->rows($rows)
	->caller('queueInspectorIsolatedFixture')->execute();

$inspect = static function(array $options = []): array {
	$probe = new class extends InspectSemanticPurgeQueue {
		public array $outputRecords = [];

		protected function output($value, $channel = null) {
			$this->outputRecords[] = $value;
		}
	};
	$probe->loadParamsAndArgs('Wikidebates:InspectSemanticPurgeQueue',
		['json' => true, 'limit' => 20] + $options);
	$probe->execute();
	return array_map(
		static fn(string $record): array =>
			json_decode(trim($record), true, 512, JSON_THROW_ON_ERROR),
		$probe->outputRecords
	);
};

$before = $db->newSelectQueryBuilder()
	->select(['job_id', 'job_cmd', 'job_params', 'job_token', 'job_attempts'])
	->from('job')->orderBy('job_id')
	->caller('queueInspectorBefore')->fetchResultSet();
$baseline = [];
foreach ($before as $row) {
	$baseline[] = [(int)$row->job_id, (string)$row->job_cmd,
		(string)$row->job_params, (string)$row->job_token, (int)$row->job_attempts];
}

$records = $inspect();
$jobs = array_values(array_filter($records,
	static fn(array $record): bool => $record['type'] === 'job'));
$summaries = array_values(array_filter($records,
	static fn(array $record): bool => $record['type'] === 'summary'));
check(count($jobs) === 2 && count($summaries) === 1,
	'Inspector selects only the semantic purge type, without JobFactory');
check($jobs[0]['cursor'] === 753 && $jobs[0]['dependency_count'] === 2 &&
	$jobs[0]['valid_payload'] === true && $jobs[0]['claimed'] === false,
	'Legacy serialized cursor, dependency count and claim state are readable');
check($jobs[1]['valid_payload'] === false && $jobs[1]['cursor'] === null &&
	$jobs[1]['dependency_count'] === null,
	'Malformed serialized payload is recorded without instantiating objects');
check($summaries[0]['invalid_payloads'] === 1 &&
	$summaries[0]['rows_examined'] === 2 && $summaries[0]['jobs_matching_title'] === 2,
	'Summary accurately counts malformed and valid jobs');

$afterId = $inspect(['after-id' => $jobs[0]['job_id']]);
$continuations = array_values(array_filter($afterId,
	static fn(array $record): bool => $record['type'] === 'job'));
check(count($continuations) === 1 &&
	$continuations[0]['job_id'] === $jobs[1]['job_id'],
	'Resume pagination observes the exact stored job_id');
$filtered = $inspect(['match' => 'WD_queue_probe_good']);
$matched = array_values(array_filter($filtered,
	static fn(array $record): bool => $record['type'] === 'job'));
check(count($matched) === 1 && $matched[0]['cursor'] === 753,
	'Literal title filtering does not change the stored queue');

$after = $db->newSelectQueryBuilder()
	->select(['job_id', 'job_cmd', 'job_params', 'job_token', 'job_attempts'])
	->from('job')->orderBy('job_id')
	->caller('queueInspectorAfter')->fetchResultSet();
$remaining = [];
foreach ($after as $row) {
	$remaining[] = [(int)$row->job_id, (string)$row->job_cmd,
		(string)$row->job_params, (string)$row->job_token, (int)$row->job_attempts];
}
check($remaining === $baseline,
	'Inspector never changes job IDs, payloads, claims or attempts');

echo "OK: read-only semantic purge SQL inspection, malformed jobs, cursor pagination and no writes\n";
