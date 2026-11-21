<?php

/** Genuine maintenance/run.php smoke tests, sharing only the disposable lab database. */
require __DIR__ . '/fullstackSemanticPurgeFollowup.php';

$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
$extensionLink = MW_INSTALL_PATH . '/extensions/Wikidebates';
if (!file_exists($extensionLink) && !is_link($extensionLink)) {
	check(symlink(dirname(__DIR__), $extensionLink), 'Register extension path in disposable MW checkout');
	register_shutdown_function(static function() use ($extensionLink): void { unlink($extensionLink); });
}
check(realpath($extensionLink) === realpath(dirname(__DIR__)), 'CLI extension path targets only the tested checkout');

$settingsFile = $temporary . '/cli-settings.php';
$settingsSource = "<?php\n";
$cliSettings = [
	'Server' => 'https://example.test',
	'ScriptPath' => '/w',
	'ArticlePath' => '/wiki/$1',
	'Sitename' => 'Isolated purge CLI tests',
	'LanguageCode' => 'en',
	'DBtype' => $testSocket === null ? 'sqlite' : 'mysql',
	'DBserver' => $testSocket === null ? 'localhost' : 'localhost:' . $testSocket,
	'DBuser' => $testSocket === null ? '' : 'root',
	'DBpassword' => '',
	'DBname' => $testDatabase,
	'SQLiteDataDir' => $temporary,
	'SecretKey' => 'isolated-purge-cli',
	'CacheDirectory' => $temporary,
	'MainCacheType' => CACHE_NONE,
	'MessageCacheType' => CACHE_NONE,
	'ParserCacheType' => CACHE_DB,
	'UseDatabaseMessages' => false,
	'JobRunRate' => 0,
	'JobTypesExcludedFromDefaultQueue' => ['wikidebatesSemanticCachePurge'],
	'WikidebatesReadingCacheDirectory' => $temporary,
	'WikidebatesSemanticCacheTracking' => true,
	'WikidebatesSemanticCachePurgeBatchSize' => 1000,
	'WikidebatesSemanticCachePurgeLog' => false
];
foreach ($cliSettings as $name => $value) {
	$settingsSource .= '$wg' . $name . ' = ' . var_export($value, true) . ";\n";
}
$settingsSource .= '$smwgConfigFileDir = ' . var_export($temporary, true) . ";\n";
$settingsSource .= <<<'PHP'
$smwgEnabledQueryDependencyLinksStore = true;
$smwgQueryProfiler = 0;
$smwgQueryResultCacheType = 0;
$wgWikidebatesSemanticCacheEagerPurge = getenv('WD_TEST_CLI_EAGER') === '1';
wfLoadExtension('Scribunto');
wfLoadExtension('SemanticMediaWiki');
wfLoadExtension('SemanticScribunto');
wfLoadExtension('Wikidebates');
wfLoadSkin('Vector');
PHP;
check(file_put_contents($settingsFile, $settingsSource . "\n") !== false, 'Isolated CLI settings written');
chmod($settingsFile, 0600);

$cli = static function(array $args, bool $eager, string $input = '') use ($settingsFile): array {
	$command = array_merge([PHP_BINARY, MW_INSTALL_PATH . '/maintenance/run.php'], $args,
		['--conf=' . $settingsFile]);
	$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
		$pipes, MW_INSTALL_PATH, array_merge(getenv(), ['WD_TEST_CLI_EAGER' => $eager ? '1' : '0']));
	check(is_resource($process), 'Isolated CLI process started');
	fwrite($pipes[0], $input);
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return [proc_close($process), $out, $err];
};

[$exit, $out, $err] = $cli(['Wikidebates:CheckSemanticCache', '--json', '--require-purge-ready'], true);
check($exit === 0, 'Actual CLI preflight passes with enabled prerequisites: ' . $out . $err);
$cliReport = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
check($cliReport['job_class_configured'] && $cliReport['job_factory_ready'],
	'Actual maintenance CLI resolves the semantic purge job in JobFactory');
check($cliReport['purge_preconditions_met'] && $cliReport['coalescing_table_available'],
	'Actual CLI emits a valid JSON report');
[$exit, $out, $err] = $cli(['Wikidebates:CheckSemanticCache', '--json', '--require-purge-ready'], false);
check($exit !== 0 && str_contains($out . $err, 'No jobs were consumed'),
	'Actual CLI refuses disabled eager purges with a nonzero exit code');
[$exit, $out, $err] = $cli(['Wikidebates:CheckSemanticCache', '--json'], false);
check($exit === 0 && !json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR)['purge_preconditions_met'],
	'Read-only inventory still works when eager purging is deliberately disabled');

// Execute the exact fallback snippet in the deployment documentation, not a
// second hand-maintained copy. Multi-line blocks would reproduce the reported bug.
$doc = file_get_contents(__DIR__ . '/../docs/smw-semantic-purge-optimization.md');
check(preg_match("/eval --wiki=en <<'PHP'\n(.*?)\nPHP/s", $doc, $matches) === 1,
	'Documented fallback eval snippet found');
[$exit, $out, $err] = $cli(['eval'], true, $matches[1] . "\n");
check($exit === 0 && !str_contains($out . $err, 'ParseError'), 'Documented eval input executes: ' . $out . $err);
foreach (['Tracking = true', 'EagerPurge = true', 'BatchSize = 1000',
	'wd_smw_entity_revisions = OK', 'wd_smw_purge_state = OK'] as $expected) {
	check(str_contains($out, $expected), 'Documented eval output contains ' . $expected);
}
same($queueBefore, $queue->getSize(), 'CLI checks leave the lab job queue unchanged');
same($ledgerBefore, \MediaWiki\Extension\Wikidebates\Reading\SemanticPurgeState::revisions($store, [$depId]),
	'CLI checks do not modify semantic revisions');
// Populate only the disposable DB, then inspect serialized rows via a
// separate maintenance process. Neither JobFactory nor job execution is used.
$sample = new \MediaWiki\Extension\Wikidebates\Reading\SemanticCachePurgeJob(
	$owners[0], ['dependencyIds' => [$depId], 'cursor' => 30773]
);
$services->getJobQueueGroup()->push($sample);
$services->getDBLoadBalancerFactory()->commitPrimaryChanges(__METHOD__);
[$exit, $out, $err] = $cli([
	'Wikidebates:InspectSemanticPurgeQueue', '--json', '--limit=200',
	'--match=WD_Purge_Owner_A'
], false);
check($exit === 0, 'Read-only CLI queue inspection succeeds even when eager purges are disabled: ' . $err);
$records = array_map(static fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
	array_filter(explode("\n", trim($out))));
$found = false;
foreach ($records as $row) {
	if (($row['type'] ?? '') === 'job' && ($row['cursor'] ?? null) === 30773 &&
		($row['dependency_count'] ?? null) === 1) {
		$found = true;
	}
}
check($found, 'A stored legacy cursor is visible without instantiating its job type');
same(1, count(array_filter($records, static fn($row) => $row['type'] === 'summary')),
	'Queue inspector reports a single resume cursor');
echo "OK: actual run.php preflight, serialized job inspection and documented line-by-line eval\n";
