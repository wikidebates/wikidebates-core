<?php

/**
 * Isolated end-to-end test using the real MediaWiki 1.43 maintenance/run.php.
 * The child process reads only the temporary SQLite wiki created below.
 */
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\MediaWikiServices;

$services = MediaWikiServices::getInstance();
$jobType = 'wikidebatesSemanticCachePurge';
$classes = $services->getMainConfig()->get('JobClasses');
// Main does not register the experimental class, while the pilot does.
// In both modes the inspector must read SQL directly without instantiating it.
if ( $services->getMainConfig()->has( 'WikidebatesSemanticCacheTracking' ) ) {
	check( ( $classes[$jobType] ?? null ) ===
		\MediaWiki\Extension\Wikidebates\Reading\SemanticCachePurgeJob::class,
		'Pilot registers the expected purge class without executing it' );
} else {
	check( !isset( $classes[$jobType] ),
		'Main inspector must work without any registered purge JobClass' );
}

$db = $services->getConnectionProvider()->getPrimaryDatabase();
$stampNow = $db->timestamp();
$stampOld = $db->timestamp(time() - 7200);
$stampRecent = $db->timestamp(time() - 30);
$rows = [
	[
		'job_cmd' => $jobType, 'job_namespace' => NS_MAIN,
		'job_title' => 'WD_queue_probe_good',
		'job_params' => serialize( [ 'dependencyIds' => [ 101, 202 ], 'cursor' => 753 ] ),
		'job_timestamp' => $stampNow, 'job_token_timestamp' => null,
		'job_token' => '', 'job_attempts' => 0, 'job_sha1' => ''
	],
	[
		'job_cmd' => $jobType, 'job_namespace' => NS_MAIN,
		'job_title' => 'WD_queue_probe_bad',
		'job_params' => 'unexpected-serialized-input',
		'job_timestamp' => $stampNow, 'job_token_timestamp' => null,
		'job_token' => '', 'job_attempts' => 0, 'job_sha1' => ''
	],
	[
		'job_cmd' => $jobType, 'job_namespace' => NS_MAIN,
		'job_title' => 'WD_queue_probe_good',
		'job_params' => serialize( [ 'dependencyIds' => [ 202, 101 ], 'cursor' => 900 ] ),
		'job_timestamp' => $stampOld, 'job_token_timestamp' => $stampOld,
		'job_token' => 'disposable-claimed-token', 'job_attempts' => 2, 'job_sha1' => ''
	],
	[
		'job_cmd' => $jobType, 'job_namespace' => NS_MAIN,
		'job_title' => 'WD_queue_probe_good',
		'job_params' => serialize( [ 'dependencyIds' => [ 101, 202 ], 'cursor' => 900 ] ),
		'job_timestamp' => $stampNow, 'job_token_timestamp' => null,
		'job_token' => '', 'job_attempts' => 0, 'job_sha1' => ''
	],
	[
		'job_cmd' => $jobType, 'job_namespace' => NS_MAIN,
		'job_title' => 'WD_queue_probe_recent',
		'job_params' => serialize( [ 'dependencyIds' => [ 303 ], 'cursor' => 0 ] ),
		'job_timestamp' => $stampNow, 'job_token_timestamp' => $stampRecent,
		'job_token' => 'recent-disposable-token', 'job_attempts' => 1, 'job_sha1' => ''
	],
	[
		'job_cmd' => 'refreshLinks', 'job_namespace' => NS_MAIN,
		'job_title' => 'WD_queue_unrelated',
		'job_params' => serialize( [ 'cursor' => 9999 ] ),
		'job_timestamp' => $stampNow, 'job_token_timestamp' => null,
		'job_token' => '', 'job_attempts' => 0, 'job_sha1' => ''
	]
];
$db->newInsertQueryBuilder()->insertInto( 'job' )
	->rows( $rows )->caller( 'queueInspectorFixture' )->execute();
$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );

$snapshot = static function ( string $caller ) use ( $db ): array {
	$rows = $db->newSelectQueryBuilder()
		->select( [ 'job_id', 'job_cmd', 'job_params', 'job_token', 'job_token_timestamp', 'job_timestamp', 'job_attempts' ] )
		->from( 'job' )->orderBy( 'job_id' )
		->caller( $caller )->fetchResultSet();
	$values = [];
	foreach ( $rows as $row ) {
		$values[] = [
			(int)$row->job_id, (string)$row->job_cmd,
			(string)$row->job_params, (string)$row->job_token,
			(string)$row->job_token_timestamp, (string)$row->job_timestamp,
			(int)$row->job_attempts
		];
	}
	return $values;
};
$before = $snapshot( 'queueInspectorBefore' );

// A real MediaWiki CLI process must use the isolated DB and the code under test.
$extensionLink = MW_INSTALL_PATH . '/extensions/Wikidebates';
if ( !file_exists( $extensionLink ) && !is_link( $extensionLink ) ) {
	check( symlink( dirname( __DIR__ ), $extensionLink ),
		'Link only the disposable MediaWiki checkout to the tested extension' );
	register_shutdown_function( static function () use ( $extensionLink ): void {
		unlink( $extensionLink );
	} );
}
check( realpath( $extensionLink ) === realpath( dirname( __DIR__ ) ),
	'CLI extension link is restricted to this repository' );

$settingsFile = $temporary . '/queue-inspection-settings.php';
$settings = [
	'Server' => 'https://example.test',
	'ScriptPath' => '/w',
	'ArticlePath' => '/wiki/$1',
	'Sitename' => 'Disposable queue inspection',
	'LanguageCode' => 'en',
	'DBtype' => 'sqlite',
	'DBserver' => 'localhost',
	'DBname' => $testDatabase,
	'SQLiteDataDir' => $temporary,
	'SecretKey' => 'disposable-queue-inspection-secret',
	'CacheDirectory' => $temporary,
	'MainCacheType' => CACHE_NONE,
	'MessageCacheType' => CACHE_NONE,
	'ParserCacheType' => CACHE_NONE,
	'UseDatabaseMessages' => false,
	'JobRunRate' => 0,
];
$source = "<?php\n";
foreach ( $settings as $name => $value ) {
	$source .= '$wg' . $name . ' = ' . var_export( $value, true ) . ";\n";
}
$source .= "wfLoadExtension('Wikidebates');\n";
check( file_put_contents( $settingsFile, $source ) !== false,
	'Disposable settings written under the temporary directory' );
chmod( $settingsFile, 0600 );

$inspect = static function ( array $args = [] ) use ( $settingsFile ): array {
	$command = array_merge( [
		PHP_BINARY, MW_INSTALL_PATH . '/maintenance/run.php',
		'Wikidebates:InspectSemanticPurgeQueue', '--json', '--limit=20'
	], $args, [ '--conf=' . $settingsFile ] );
	$process = proc_open( $command, [
		0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ]
	], $pipes, MW_INSTALL_PATH );
	check( is_resource( $process ), 'Real isolated maintenance CLI starts' );
	fclose( $pipes[0] );
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $process );
	check( $code === 0, 'CLI inspection must succeed: ' . $out . $err );
	return array_map(
		static fn ( string $line ): array =>
			json_decode( $line, true, 512, JSON_THROW_ON_ERROR ),
		array_values( array_filter( explode( "\n", trim( $out ) ) ) )
	);
};

$records = $inspect();
$jobs = array_values( array_filter( $records,
	static fn ( array $record ): bool => $record['type'] === 'job' ) );
$totals = array_values( array_filter( $records,
	static fn ( array $record ): bool => $record['type'] === 'summary' ) );
check( count( $jobs ) === 5 && count( $totals ) === 1,
	'Only the semantic purge jobs are listed, without a JobFactory' );
check( $jobs[0]['cursor'] === 753 && $jobs[0]['dependency_count'] === 2 &&
	$jobs[0]['valid_payload'] === true && $jobs[0]['claimed'] === false,
	'Legacy cursor and dependencies are read correctly' );
check( $jobs[1]['valid_payload'] === false && $jobs[1]['cursor'] === null,
	'Malformed payload cannot be constructed as an object' );
check( $totals[0]['invalid_payloads'] === 1 && $totals[0]['rows_examined'] === 5 &&
	$totals[0]['total_jobs_in_sql_queue'] === 5 && !$totals[0]['has_more'],
	'Summary counts the malformed and valid jobs across the whole SQL queue' );
check( $jobs[2]['claimed'] && $jobs[2]['old_claim'] && $jobs[2]['attempts'] === 2 &&
	$jobs[2]['claim_age_seconds'] >= 7100 && $jobs[2]['queue_age_seconds'] >= 7100,
	'Actual claim timestamp detects an old reservation independently of queue age' );
check( $jobs[4]['claimed'] && !$jobs[4]['old_claim'] && $jobs[4]['claim_age_seconds'] < 3600,
	'Recent reservation does not trigger a false stalled-claim alert' );
check( $jobs[0]['continuation_group'] === $jobs[2]['continuation_group'] &&
	$jobs[2]['continuation_group'] === $jobs[3]['continuation_group'],
	'Dependency order does not change continuation-group identity' );
check( $totals[0]['claimed_jobs'] === 2 && $totals[0]['claims_older_than_threshold'] === 1 &&
	$totals[0]['jobs_with_multiple_attempts'] === 1 &&
	$totals[0]['multiple_cursor_groups'] === 1 &&
	$totals[0]['repeated_cursor_groups'] === 1 && $totals[0]['repeated_cursor_jobs'] === 2,
	'Multi-cursor continuations and exact cursor collisions are reported separately' );

$paginated = array_values( array_filter( $inspect( [
	'--after-id=' . $jobs[0]['job_id']
] ), static fn ( array $record ): bool => $record['type'] === 'job' ) );
check( count( $paginated ) === 4 &&
	$paginated[0]['job_id'] === $jobs[1]['job_id'],
	'Pagination resumes strictly after the stored job ID' );

$filtered = array_values( array_filter(
	$inspect( [ '--match=WD_queue_probe_good' ] ),
	static fn ( array $record ): bool => $record['type'] === 'job'
) );
check( count( $filtered ) === 3 && $filtered[0]['cursor'] === 753,
	'Literal title filter does not affect stored job data' );

$page = $inspect( [ '--limit=2' ] );
$pageSummary = array_values( array_filter( $page,
	static fn ( array $record ): bool => $record['type'] === 'summary' ) )[0];
check( $pageSummary['rows_examined'] === 2 && $pageSummary['has_more'] &&
	$pageSummary['resume_after_id'] === $jobs[1]['job_id'] &&
	$pageSummary['total_jobs_in_sql_queue'] === 5,
	'Bounded pagination reports a resumable cursor and the actual total' );
$after = $snapshot( 'queueInspectorAfter' );
check( $after === $before, 'No job, cursor, claim, payload or attempt was modified' );
echo "OK: real read-only maintenance CLI, claim ages, repeated cursors, pagination and unchanged SQL jobs\n";
