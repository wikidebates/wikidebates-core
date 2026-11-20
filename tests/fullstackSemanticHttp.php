<?php

/**
 * Real HTTP regression and comparative benchmark for MediaWiki 1.43.11,
 * SMW 7.3.1 and SemanticScribunto 2.3.3.
 *
 * This process creates a temporary database. Independent PHP HTTP workers
 * read it while this CLI process commits semantic edits and holds purge jobs.
 * The lab is only reachable via 127.0.0.1; no production settings are loaded.
 */
define('WD_FULLSTACK_TEST', true);
putenv('WD_TEST_LANGUAGE=en');
putenv('WD_TEST_SEMANTIC_TRACKING=1');
putenv('WD_TEST_EXTENSIONS=Scribunto,SemanticMediaWiki,SemanticScribunto');
require __DIR__ . '/mediawikiEnvironment.php';
require __DIR__ . '/fullstackSupport.php';

use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\Extension\DebatePHP\Table;
use MediaWiki\Extension\Wikidebates\Reading\SemanticRevisionStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage as SemanticWikiPage;
use SMW\Services\ServicesFactory;

check(str_starts_with(MW_VERSION, '1.43.'), 'HTTP lab requires MediaWiki 1.43');
check(SMW_VERSION === '7.3.1', 'HTTP lab requires SMW 7.3.1');
check(SMW_SCRIBUNTO_VERSION === '2.3.3', 'HTTP lab requires SemanticScribunto 2.3.3');

$services = MediaWikiServices::getInstance();
$store = ServicesFactory::getInstance()->getStore();
check($store instanceof \SMW\SQLStore\SQLStore && $store->setup(false),
	'Real SMW SQL tables initialized on a disposable database');

$db = $store->getConnection('mw.db');
$dbType = $db->getType();
check(in_array($dbType, ['sqlite', 'mysql'], true), 'Only isolated SQLite or MariaDB allowed');
$ddl = file_get_contents(__DIR__ . '/../sql/' . $dbType . '/wd_smw_entity_revisions.sql');
check(is_string($ddl) && $ddl !== '', 'Exact semantic revision SQL schema found');
$db->query($ddl, 'semanticHttpInstallSchema');
check($db->tableExists(SemanticRevisionStore::TABLE), 'Revision table exists in HTTP lab');
$schema = $GLOBALS;
(new \SMW\SetupFile())->loadSchema($schema);
\SMW\Globals::replace($schema);
check(\SMW\Site::isReady(), 'SMW is ready for HTTP lab');

$fsUser = \MediaWiki\User\User::newSystemUser('Wikidebates HTTP test writer', ['steal' => false]);
check($fsUser !== null, 'Disposable writer initialized');

// Register the same diagnostic semantic reader as the independent web workers.
$register = static function ($parser): void {
	$parser->setHook('wd-http-parents', static function ($input, $args, $parser, $frame): string {
		$context = new MediaWikiContext($parser, $frame, [], false);
		$rows = $context->readSemantic(Table::wrap([
			'[[WD HTTP Parent::' . trim($input) . ']]',
			'limit=200',
			'link=none'
		]), 'cache-tracking');
		return 'WD_HTTP_MATCHES=' . count($rows->rows ?? []);
	});
};
$services->getHookContainer()->register('ParserFirstCallInit', $register);
$register($services->getParser());

fsSave('Property:WD HTTP Parent', '[[Has type::Page]]');
fsSave('WD HTTP parent A', 'Parent A');
fsSave('WD HTTP parent B', 'Parent B');
fsSave('WD HTTP child', 'No parent');
fsSave('WD HTTP query A', 'Initial stub');
fsSave('WD HTTP query B', 'Initial stub');
fsSave('WD HTTP public page', str_repeat('This ordinary page contains no semantic query. ', 30));
fsFlush();

// SMW profiles must belong to already-created query pages.
// Give initial semantic subjects an earlier touched second than the _ASK
// profiles, matching the stable-profile baseline of fullstackSemanticCache.
sleep(2);
fsSave('WD HTTP query A',
	"== Summary ==\n<wd-http-parents>WD HTTP parent A</wd-http-parents>\n== Parent debates ==\nA");
fsSave('WD HTTP query B',
	"== Summary ==\n<wd-http-parents>WD HTTP parent B</wd-http-parents>\n== Parent debates ==\nB");
fsSave('WD HTTP redirect', '#REDIRECT [[WD HTTP query A]]');
fsFlush();
$subjectA = SemanticWikiPage::newFromTitle(Title::newFromText('WD HTTP query A'));
$store->clear();
check($store->getPropertyValues($subjectA, new Property('_ASK')) !== [],
	'The query page registered a genuine SMW _ASK profile before HTTP reads');

$marker = $temporary . '/.semantic-http-disposable';
file_put_contents($marker, "Isolated CI semantic HTTP lab\n");
foreach (['semantic-http-full-pages', 'semantic-http-fragments'] as $dir) {
	check(mkdir($temporary . '/' . $dir, 0700), 'Private HTTP cache directory: ' . $dir);
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $socketErr, $socketMessage);
check($socket !== false, 'Acquire an unused loopback port');
$address = stream_socket_get_name($socket, false);
$port = (int)substr($address, strrpos($address, ':') + 1);
fclose($socket);
check($port >= 1024, 'Valid isolated HTTP port');

$env = getenv();
$env['WD_SEMANTIC_HTTP_ROOT'] = $temporary;
$env['WD_SEMANTIC_HTTP_DB'] = $testDatabase;
$env['WD_SEMANTIC_HTTP_PORT'] = (string)$port;
$env['WD_SEMANTIC_HTTP_DISPOSABLE'] = '1';
$env['PHP_CLI_SERVER_WORKERS'] = '3';
$serverLog = $temporary . '/semantic-http-server.log';

$process = proc_open([
	PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1',
	'-d', 'output_buffering=0', '-d',
	'auto_prepend_file=' . __DIR__ . '/semanticHttpBootstrap.php',
	'-S', '127.0.0.1:' . $port, '-t', MW_INSTALL_PATH
], [
	['pipe', 'r'],
	['file', $serverLog, 'a'],
	['file', $serverLog, 'a']
], $pipes, null, $env);
check(is_resource($process), 'Starts isolated HTTP server');
fclose($pipes[0]);

$http = static function (
	string $path, array $params = [], array $extraHeaders = [], ?array $post = null
) use ($port): array {
	$url = 'http://127.0.0.1:' . $port . '/' . $path . ($params !== [] ?
		'?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
	$headers = ['Accept-Encoding: identity', 'Connection: close', 'User-Agent: Wikidebates-Semantic-HTTP-Lab/1'];
	$headers = array_merge($headers, $extraHeaders);
	$settings = ['method' => $post === null ? 'GET' : 'POST', 'ignore_errors' => true,
		'follow_location' => 0, 'timeout' => 18, 'header' => $headers];
	if ($post !== null) {
		$settings['content'] = http_build_query($post);
		$settings['header'][] = 'Content-Type: application/x-www-form-urlencoded';
	}
	$start = hrtime(true);
	$body = @file_get_contents($url, false, stream_context_create(['http' => $settings]));
	$elapsed = (hrtime(true) - $start) / 1e6;
	$received = $http_response_header ?? [];
	$parsed = [];
	foreach ($received as $header) {
		if (str_contains($header, ':')) {
			[$name, $value] = explode(':', $header, 2);
			$parsed[strtolower($name)][] = trim($value);
		}
	}
	preg_match('/^HTTP\/\S+\s+(\d+)/', $received[0] ?? '', $status);
	if (!is_string($body)) {
		throw new RuntimeException('Cannot request ' . $url . ': ' . json_encode(error_get_last()));
	}
	return ['status' => (int)($status[1] ?? 0), 'headers' => $parsed,
		'body' => $body, 'elapsed_ms' => $elapsed];
};
$fragment = static function(
	string $page = 'WD HTTP query A', array $headers = [], array $options = []
) use ($http): array {
	$parameters = array_merge([
		'action' => 'wikidebatesreading',
		'page' => $page,
		'useskin' => 'vector',
		'format' => 'json',
		'formatversion' => 2
	], $options);
	$response = $http('api.php', $parameters, $headers);
	$decoded = json_decode($response['body'], true);
	if ($response['status'] !== 200 || !isset($decoded['wikidebatesreading'])) {
		throw new RuntimeException('API HTTP regression: ' . $response['status'] . ' ' .
			substr($response['body'], 0, 1700));
	}
	$response['fragment'] = $decoded['wikidebatesreading'];
	return $response;
};
$parseCount = static function () use ($temporary): int {
	$log = $temporary . '/semantic-http-parses.log';
	return is_file($log) ? count(file($log, FILE_IGNORE_NEW_LINES)) : 0;
};
$percentile = static function (array $samples, float $fraction): float {
	sort($samples, SORT_NUMERIC);
	$index = max(0, min(count($samples) - 1, (int)ceil(count($samples) * $fraction) - 1));
	return round($samples[$index], 2);
};

try {
	$ready = false;
	for ($i = 0; $i < 100; $i++) {
		$socket = @fsockopen('127.0.0.1', $port, $err, $message, .1);
		if (is_resource($socket)) {
			fclose($socket);
			$ready = true;
			break;
		}
		usleep(100000);
	}
	check($ready, 'Real HTTP server is accepting loopback connections');

	$fingerprintBefore = SemanticRevisionStore::fingerprint($store, $subjectA);
	$cold = $fragment();
	$fingerprintAfterCold = SemanticRevisionStore::fingerprint($store, $subjectA);
	check(str_contains($cold['fragment']['html'], 'WD_HTTP_MATCHES=0'),
		'First independent HTTP read renders empty query');
	check($cold['fragment']['source'] === 'parser', 'Initial HTTP read uses parser');

	// The first real HTTP parse may advance page_touched through SMW
	// deferred updates. Its fragment identity is then conservatively
	// rejected on the next request. Require a genuinely stable cache HIT
	// after at most four independent requests, not a stale one-second key.
	$warm = null;
	$warmCountBefore = null;
	$sourceHistory = [];
	for ($attempt = 0; $attempt < 4; $attempt++) {
		$beforeCount = $parseCount();
		$candidate = $fragment();
		$sourceHistory[] = $candidate['fragment']['source'] ?? 'unknown';
		if ($candidate['fragment']['source'] === 'fragment-cache') {
			$warm = $candidate;
			$warmCountBefore = $beforeCount;
			break;
		}
		check($candidate['fragment']['source'] === 'parser' &&
			str_contains($candidate['fragment']['html'], 'WD_HTTP_MATCHES=0'),
			'An initial semantic-touched normalization still parses current HTML');
	}
	$fingerprintAfterWarm = SemanticRevisionStore::fingerprint($store, $subjectA);
	$observedOutputLog = $temporary . '/semantic-http-parser-outputs.jsonl';
	$diagnostics = [
		'cold_source' => $cold['fragment']['source'],
		'warm_source' => $warm['fragment']['source'] ?? null,
		'sources_until_warm' => $sourceHistory,
		'cold_expires' => $cold['fragment']['_expires'] ?? null,
		'warm_expires' => $warm['fragment']['_expires'] ?? null,
		'fingerprint_before' => $fingerprintBefore,
		'fingerprint_after_cold' => $fingerprintAfterCold,
		'fingerprint_after_warm' => $fingerprintAfterWarm,
		'cache_files' => count(glob($temporary . '/semantic-http-fragments/*/*/*/*.cache') ?: []),
		'disk_identities' => array_map(static function ($path) {
			$encoded = @file_get_contents($path);
			$data = $encoded === false ? [] : json_decode((string)@gzdecode($encoded), true);
			return ['path' => basename($path), 'identity' => $data['identity'] ?? null,
				'generation' => $data['generation'] ?? null];
		}, array_slice(glob($temporary . '/semantic-http-fragments/*/*/*/*.cache') ?: [], -3)),
		'parser_output_events' => is_file($observedOutputLog) ?
			array_slice(file($observedOutputLog, FILE_IGNORE_NEW_LINES), -4) : [],
		'eligibility' => is_file($temporary . '/semantic-http-eligibility.jsonl') ?
			array_slice(file($temporary . '/semantic-http-eligibility.jsonl', FILE_IGNORE_NEW_LINES), -4) : [],
		'guard' => is_file($temporary . '/semantic-http-guard.jsonl') ?
			array_slice(file($temporary . '/semantic-http-guard.jsonl', FILE_IGNORE_NEW_LINES), -6) : [],
		'publication' => is_file($temporary . '/semantic-http-publication.jsonl') ?
			array_slice(file($temporary . '/semantic-http-publication.jsonl', FILE_IGNORE_NEW_LINES), -8) : [],
	];
	check($warm !== null && $warm['fragment']['source'] === 'fragment-cache',
		'At least one stable independent HTTP request reuses the prepared fragment: ' .
		json_encode($diagnostics, JSON_UNESCAPED_SLASHES));
	check($warmCountBefore === $parseCount(), 'Fragment hit does not run the query renderer again');
	check($cold['fragment']['html'] === $warm['fragment']['html'],
		'Cold and warm HTTP results have identical bytes');

	$ajax = $fragment('WD HTTP query A', ['X-Requested-With: XMLHttpRequest']);
	check($ajax['fragment']['source'] === 'fragment-cache',
		'Independent mw.Api AJAX request keeps a public fragment hit');

	$cookie = $fragment('WD HTTP query A', ['Cookie: preference=test']);
	check($cookie['fragment']['source'] === 'parser',
		'Cookie-bearing HTTP request bypasses shared fragments');
	check($fragment('WD HTTP redirect')['fragment']['html'] === $warm['fragment']['html'],
		'HTTP redirect resolves to its readable target');
	$variant = $fragment('WD HTTP query A', [], ['uselang' => 'fr']);
	check($variant['fragment']['html'] !== '',
		'Foreign interface language remains separately rendered');

	// Commit the semantic update, but deliberately leave its dependent purge
	// job pending. A prebuilt fragment from another PHP worker must be refused.
	fsSave('WD HTTP child', '{{#set:WD HTTP Parent=WD HTTP parent A}}');
	fsFlush(['wikidebatesSemanticCachePurge']);
	check($services->getJobQueueGroup()->queuesHaveJobs(),
		'Targeted semantic purge remains deliberately queued');
	$beforePurge = $fragment();
	check($beforePurge['fragment']['source'] !== 'fragment-cache',
		'Real HTTP refuses old fragment before queued purge');
	check(str_contains($beforePurge['fragment']['html'], 'WD_HTTP_MATCHES=1'),
		'Real HTTP reads new relationship with purge job still pending');

	$full = $http('index.php', ['title' => 'WD_HTTP_query_A']);
	check($full['status'] === 200 &&
		str_contains($full['body'], 'WD_HTTP_MATCHES=1'),
		'Full Vector HTML page reflects the semantic edit before purge');
	$cacheControl = strtolower(implode(', ', $full['headers']['cache-control'] ?? []));
	check(str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store') ||
		str_contains($cacheControl, 'private'),
		'Semantic full-page HTML is not publicly cacheable through HTTP');
	check($services->getJobQueueGroup()->queuesHaveJobs(),
		'Neither the HTTP fragment nor the full-page view drains queued purge jobs');
	fsFlush();

	$stable = $fragment();
	check(str_contains($stable['fragment']['html'], 'WD_HTTP_MATCHES=1'),
		'HTTP remains current after deferred jobs complete');
	check($fragment()['fragment']['source'] === 'fragment-cache',
		'Stable HTML is reusable by the shared fragment cache');

	fsSave('WD HTTP child', '{{#set:WD HTTP Parent=WD HTTP parent B}}');
	fsFlush(['wikidebatesSemanticCachePurge']);
	check(str_contains($fragment()['fragment']['html'], 'WD_HTTP_MATCHES=0'),
		'Removing a parent updates HTTP while target purge waits');
	check(str_contains($fragment('WD HTTP query B')['fragment']['html'], 'WD_HTTP_MATCHES=1'),
		'Adding a different parent updates HTTP while target purge waits');
	fsFlush();

	// Content authorization must remain ahead of any prepared cache.
	touch($temporary . '/semantic-http-deny-read');
	try {
		$denied = $http('api.php', ['action' => 'wikidebatesreading',
			'page' => 'WD HTTP query A', 'format' => 'json']);
		check(!str_contains($denied['body'], 'WD_HTTP_MATCHES=') &&
			isset(json_decode($denied['body'], true)['error']),
			'Anonymous HTTP read authorization rejects existing prepared HTML');
	} finally {
		unlink($temporary . '/semantic-http-deny-read');
	}

	$public = $http('index.php', ['title' => 'WD_HTTP_public_page']);
	check($public['status'] === 200, 'Unrelated public HTTP page still loads');

	// Characterize, do not impose a speed threshold on noisy CI hosts.
	for ($i = 0; $i < 3; $i++) {
		$fragment('WD HTTP query B');
	}
	$hitSamples = [];
	$parseSamples = [];
	$workers = [];
	$parseStart = $parseCount();
	for ($i = 0; $i < 15; $i++) {
		$hit = $fragment('WD HTTP query B');
		check($hit['fragment']['source'] === 'fragment-cache', 'Benchmark warm fragment remains a hit');
		$hitSamples[] = $hit['elapsed_ms'];
		$workers[$hit['headers']['x-wd-semantic-lab-worker'][0] ?? 'unknown'] = true;

		$bypass = $fragment('WD HTTP query B', ['Cache-Control: no-cache']);
		check($bypass['fragment']['source'] === 'parser', 'Benchmark forced refresh uses parser');
		check($bypass['fragment']['html'] === $hit['fragment']['html'],
			'Benchmark compares the exact same semantic HTML');
		$parseSamples[] = $bypass['elapsed_ms'];
		$workers[$bypass['headers']['x-wd-semantic-lab-worker'][0] ?? 'unknown'] = true;
	}
	check($parseCount() >= $parseStart + 15,
		'Forced HTTP reads execute the native semantic query at least once each');
	// Serial calls may all land on the same idle worker. Open multiple TCP
	// requests BEFORE reading any responses; the lab-only hold header keeps
	// the first workers busy so the others must process their own requests.
	$parallel = [];
	for ($i = 0; $i < 6; $i++) {
		$socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 8);
		check(is_resource($socket), 'Open independent concurrent loopback request');
		stream_set_timeout($socket, 20);
		$query = http_build_query(['action' => 'wikidebatesreading',
			'page' => 'WD HTTP query B', 'useskin' => 'vector',
			'format' => 'json', 'formatversion' => 2], '', '&', PHP_QUERY_RFC3986);
		$written = fwrite($socket,
			"GET /api.php?$query HTTP/1.1\r\n" .
			"Host: 127.0.0.1:$port\r\n" .
			"Connection: close\r\n" .
			"Accept-Encoding: identity\r\n" .
			"X-WD-Semantic-Lab-Hold: 1\r\n\r\n");
		check(is_int($written) && $written > 0,
			'Concurrent HTTP request was sent to the test worker');
		$parallel[] = $socket;
	}
	foreach ($parallel as $socket) {
		$raw = stream_get_contents($socket);
		fclose($socket);
		check(is_string($raw) && preg_match('/^HTTP\/\d(?:\.\d)? 200\b/', $raw) === 1,
			'Concurrent HTTP worker responds successfully');
		check(preg_match('/^X-WD-Semantic-Lab-Worker:\s*(\d+)/mi', $raw, $matches) === 1,
			'Concurrent HTTP request identifies its separate worker');
		$workers[$matches[1]] = true;
	}
	$workers = array_keys($workers);
	check(count(array_filter($workers, static fn($id) => $id !== 'unknown')) >= 2,
		'At least two distinct PHP worker processes served concurrent HTTP reads');

	$report = [
		'backend' => $dbType,
		'mediawiki' => MW_VERSION,
		'smw' => SMW_VERSION,
		'semantic_scribunto' => SMW_SCRIBUNTO_VERSION,
		'transport' => 'real loopback HTTP, three PHP CLI server workers',
		'benchmark' => [
			'samples_each' => count($hitSamples),
			'fragment_hit_median_ms' => $percentile($hitSamples, .5),
			'fragment_hit_p95_ms' => $percentile($hitSamples, .95),
			'forced_fresh_median_ms' => $percentile($parseSamples, .5),
			'forced_fresh_p95_ms' => $percentile($parseSamples, .95),
		],
		'php_worker_pids' => $workers,
		'assertions' => 'PASS'
	];
	$reportPath = __DIR__ . '/results/semantic-http-' . $dbType . '.json';
	file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (\Throwable $e) {
	$serverLogText = @file_get_contents($serverLog);
	fwrite(STDERR, "Semantic HTTP regression failed: " . $e->getMessage() .
		"\nServer log tail:\n" . substr((string)$serverLogText, -5000) . "\n");
	throw $e;
} finally {
	proc_terminate($process);
	proc_close($process);
}
