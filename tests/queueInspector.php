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
		\\MediaWiki\\Extension\\Wikidebates\\Reading\\SemanticCachePurgeJob::class,
		'Pilot registers the expected purge class without executing it' );
} else {
	check( !isset( $classes[$jobType] ),
		'Main inspector must work without any registered purge JobClass' );
}

$db = $services->getConnectionProvider()->getPrimaryDatabase();
$rows = [
	[
		'job_cmd' => $jobType, 'job_namespace' => NS_MAIN,
		'job_title' => 'WD_queue_probe_good',
		'job_params' => serialize( [ 'dependencyIds' => [ 101, 202 ], 'cursor' => 753 ] ),
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
		'job_params' => serialize( [ 'cursor' => 9999 ] ),
		'job_timestamp' => $db->timestamp(), 'job_sha1' => ''
	]
];
$db->newInsertQueryBuilder()->insertInto( 'job' )
	->rows( $rows )->caller( 'queueInspectorFixture' )->execute();
$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __METHOD__ );

$snapshot = static function ( string $caller ) use ( $db ): array {
	$rows = $db->newSelectQueryBuilder()
		->select( [ 'job_id', 'job_cmd', 'job_params', 'job_token', 'job_attempts' ] )
		->from( 'job' )->orderBy( 'job_id' )
		->caller( $caller )->fetchResultSet();
	$values = [];
	foreach ( $rows as $row ) {
		$values[] = [
			(int)$row->job_id, (string)$row->job_cmd,
			(string)$row->job_params, (string)$row->job_token,
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
check( count( $jobs ) === 2 && count( $totals ) === 1,
	'Only the semantic purge jobs are listed, without a JobFactory' );
check( $jobs[0]['cursor'] === 753 && $jobs[0]['dependency_count'] === 2 &&
	$jobs[0]['valid_payload'] === true && $jobs[0]['claimed'] === false,
	'Legacy cursor and dependencies are read correctly' );
check( $jobs[1]['valid_payload'] === false && $jobs[1]['cursor'] === null,
	'Malformed payload cannot be constructed as an object' );
check( $totals[0]['invalid_payloads'] === 1 && $totals[0]['rows_examined'] === 2,
	'Summary counts the malformed and valid jobs' );

$paginated = array_values( array_filter( $inspect( [
	'--after-id=' . $jobs[0]['job_id']
] ), static fn ( array $record ): bool => $record['type'] === 'job' ) );
check( count( $paginated ) === 1 &&
	$paginated[0]['job_id'] === $jobs[1]['job_id'],
	'Pagination resumes strictly after the stored job ID' );

$filtered = array_values( array_filter(
	$inspect( [ '--match=WD_queue_probe_good' ] ),
	static fn ( array $record ): bool => $record['type'] === 'job'
) );
check( count( $filtered ) === 1 && $filtered[0]['cursor'] === 753,
	'Literal title filter does not affect stored job data' );

$after = $snapshot( 'queueInspectorAfter' );
check( $after === $before, 'No job, cursor, claim, payload or attempt was modified' );
echo "OK: real read-only MediaWiki maintenance CLI, pagination and unchanged SQL job table\n";
