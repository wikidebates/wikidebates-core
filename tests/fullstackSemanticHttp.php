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
use MediaWiki\Extension\DebatePHP\SemanticReadResult;
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
	// The actual Argument renderer combines two parent properties with OR
	// and requests both values as printouts. Test that exact query shape.
	$parser->setHook('wd-http-combined', static function($input, $args, $parser, $frame): string {
		$context = new MediaWikiContext($parser, $frame, [], false);
		$rows = $context->readSemantic(Table::wrap([
			'([[WD HTTP Pro::' . trim($input) . ']] OR [[WD HTTP Con::' . trim($input) . ']] )',
			'?WD HTTP Pro=a',
			'?WD HTTP Con=b',
			'limit=200',
			'link=none',
		]), 'debate-parents');
		$a = $b = 0;
		foreach (SemanticReadResult::sequence($rows->rows ?? []) as $row) {
			if (!is_array($row)) { continue; }
			if (($row['a'] ?? null) !== null && ($row['a'] ?? null) !== '') { $a++; }
			if (($row['b'] ?? null) !== null && ($row['b'] ?? null) !== '') { $b++; }
		}
		return 'WD_HTTP_OR_A=' . $a . ';WD_HTTP_OR_B=' . $b .
			';WD_HTTP_OR_TOTAL=' . count($rows->rows ?? []);
	});
};
$services->getHookContainer()->register('ParserFirstCallInit', $register);
$register($services->getParser());

// Use argument titles retained from an actual Wikidebates debate fixture.
// The original full wikitext remains in the repository: no live wiki,
// online request or production database is involved in this HTTP lab.
$corpus = json_decode(file_get_contents(
	__DIR__ . '/../debatephp/tests/fixtures/pages.json'
), true, 512, JSON_THROW_ON_ERROR);
$corpusPage = null;
foreach ($corpus as $candidate) {
	if (($candidate['title'] ?? '') === 'Dieu existe-t-il ?') {
		$corpusPage = $candidate;
		break;
	}
}
check(is_array($corpusPage), 'Representative public debate is available in the retained corpus');
$extractCorpusArguments = static function(string $source, string $side): array {
	$pattern = '/\{\{Argument ' . preg_quote($side, '/') .
		'\s*\|\s*page\s*=\s*([^\r\n|}]+)/u';
	preg_match_all($pattern, $source, $matches);
	return array_values(array_map('trim', $matches[1] ?? []));
};
$corpusPro = $extractCorpusArguments($corpusPage['text'], 'pour');
$corpusCon = $extractCorpusArguments($corpusPage['text'], 'contre');
check(count($corpusPro) === 12 && count($corpusCon) === 13,
	'Real debate fixture contains 12 pro and 13 con arguments');
$corpusArgumentTitle = 'Dieu est une invention';
check(in_array($corpusArgumentTitle, $corpusCon, true),
	'The selected Argument really belongs to the retained debate corpus');
$encodeCorpusList = static function(array $titles): string {
	return implode('', array_map(static function(string $name): string {
		return str_replace('|', '{{!}}', $name) . '⟬' .
			str_replace('|', '{{!}}', $name) . '⟬⟭';
	}, $titles));
};
$corpusDebateName = 'WD HTTP corpus debate';
fsSave($corpusDebateName, 'Initial corpus debate stub');
fsSave($corpusArgumentTitle, 'Initial corpus Argument stub');

// A normal debate page links to argument pages but never renders them
// inline. The Argument page owns the semantic queries and reading HTML.
$props = \MediaWiki\Extension\DebatePHP\I18n::bundled();
$proProperty = $props->message('Argument.props', 'fr', 'arg_pro');
foreach ([$proProperty] as $property) {
	fsSave('Property:' . $property, '[[Has type::Page]]');
}
fsSave('WD HTTP reading debate', 'Initial debate stub');
fsSave('WD HTTP reading argument', 'Initial argument stub');
fsSave('WD HTTP reading parent', 'No semantic relation');
fsSave('Property:WD HTTP Parent', '[[Has type::Page]]');
fsSave('Property:WD HTTP Pro', '[[Has type::Page]]');
fsSave('Property:WD HTTP Con', '[[Has type::Page]]');
fsSave('WD HTTP OR parent', 'No semantic relation yet');
fsSave('WD HTTP OR target', 'Combined query target');
fsSave('WD HTTP OR query', 'Initial stub');
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
fsSave('WD HTTP OR query',
	"== Summary ==\n<wd-http-combined>WD HTTP OR target</wd-http-combined>\n== Parent debates ==\nOR");
fsSave($corpusDebateName, '{{#debate:render|lang=fr|topic=Dieu' .
	'|pro-arguments=' . $encodeCorpusList($corpusPro) .
	'|con-arguments=' . $encodeCorpusList($corpusCon) . '}}');
fsSave($corpusArgumentTitle,
	'{{#argument:render|lang=fr|summary=WD_HTTP_CORPUS_ARGUMENT_FIRST}}');
fsSave('WD HTTP reading argument',
	'{{#argument:render|lang=fr|summary=WD_HTTP_READING_SUMMARY_FIRST}}');
fsSave('WD HTTP reading debate',
	'{{#debate:render|lang=fr|topic=Reading flow debate' .
	'|pro-arguments=WD HTTP reading argument⟬WD HTTP displayed pro argument⟬⟭' .
	'|con-arguments=WD HTTP alternative argument⟬WD HTTP displayed con argument⟬⟭}}');
fsSave('WD HTTP redirect', '#REDIRECT [[WD HTTP query A]]');
fsFlush();
$subjectA = SemanticWikiPage::newFromTitle(Title::newFromText('WD HTTP query A'));
$store->clear();
check($store->getPropertyValues($subjectA, new Property('_ASK')) !== [],
	'The query page registered a genuine SMW _ASK profile before HTTP reads');
$orSubject = SemanticWikiPage::newFromTitle(Title::newFromText('WD HTTP OR query'));
check($store->getPropertyValues($orSubject, new Property('_ASK')) !== [],
	'Combined OR parent query persists genuine SMW _ASK profiles');
$readingArgTitle = Title::newFromText('WD HTTP reading argument');
$readingDebateTitle = Title::newFromText('WD HTTP reading debate');
$readingArgSubject = SemanticWikiPage::newFromTitle($readingArgTitle);
$readingDebateSubject = SemanticWikiPage::newFromTitle($readingDebateTitle);
$store->clear();
check($store->getPropertyValues($readingArgSubject, new Property('_ASK')) !== [],
	'Real Argument reader persists parent-query _ASK profiles');
check($store->getPropertyValues($readingDebateSubject, new Property('_ASK')) === [],
	'Real debate-only renderer has no semantic parent-query profile');
$corpusArgSubject = SemanticWikiPage::newFromTitle(Title::newFromText($corpusArgumentTitle));
$corpusDebateSubject = SemanticWikiPage::newFromTitle(Title::newFromText($corpusDebateName));
$store->clear();
check($store->getPropertyValues($corpusArgSubject, new Property('_ASK')) !== [],
	'The corpus Argument has native semantic parent-query profiles');
check($store->getPropertyValues($corpusDebateSubject, new Property('_ASK')) === [],
	'The 25-title debate has no Argument query profile');


$marker = $temporary . '/.semantic-http-disposable';
file_put_contents($marker, "Isolated CI semantic HTTP lab\n");
foreach (['semantic-http-full-pages', 'semantic-http-fragments', 'semantic-http-minerva-files'] as $dir) {
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
	$headers = ['Accept-Encoding: identity', 'Connection: close'];
	if (!array_filter($extraHeaders, static fn($h) => str_starts_with(strtolower($h), 'user-agent:'))) {
		$headers[] = 'User-Agent: Wikidebates-Semantic-HTTP-Lab/1';
	}
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

	// Real user flow: the cached debate contains clickable argument titles,
	// not their rendered content. Clicking a link requests the Argument
	// page fragment independently through the same reading API as the JS.
	$debateUrl = ['title' => 'WD_HTTP_reading_debate'];
	$debateFirst = $http('index.php', $debateUrl);
	check($debateFirst['status'] === 200 &&
		str_contains($debateFirst['body'], 'WD HTTP displayed pro argument') &&
		str_contains($debateFirst['body'], 'WD HTTP displayed con argument') &&
		str_contains($debateFirst['body'], 'WD_HTTP_reading_argument') &&
		str_contains($debateFirst['body'], 'argument-title'),
		'Actual #debate:render HTML contains argument title links, not inline Argument render');
	check(!str_contains($debateFirst['body'], 'WD_HTTP_READING_SUMMARY_FIRST'),
		'Debate page does not embed the Argument summary');
	$debateHeaders = strtolower(implode(', ', $debateFirst['headers']['cache-control'] ?? []));
	// Compare against the exact same MediaWiki HTTP pipeline without our
	// opt-in semantic tracking. HTTP Cache-Control may already be private
	// because of unrelated skin/extension conditions in this disposable
	// wiki. The new feature must not make a cacheable debate uncacheable.
	touch($temporary . '/semantic-http-disable-tracking');
	try {
		$debateWithoutTracking = $http('index.php', $debateUrl);
	} finally {
		unlink($temporary . '/semantic-http-disable-tracking');
	}
	$untrackedDebateHeaders = strtolower(implode(', ',
		$debateWithoutTracking['headers']['cache-control'] ?? []));
	$blocksPublic = static fn(string $value): bool =>
		str_contains($value, 'no-store') ||
		str_contains($value, 'no-cache') ||
		str_contains($value, 'private');
	check($debateWithoutTracking['status'] === 200 &&
		$debateFirst['body'] === $debateWithoutTracking['body'],
		'Debate HTML is unchanged with tracked versus untracked semantics');
	check($blocksPublic($debateHeaders) === $blocksPublic($untrackedDebateHeaders),
		'Opt-in tracking preserves baseline debate HTTP cache policy; tracked=' .
		$debateHeaders . '; baseline=' . $untrackedDebateHeaders);
	$debateAgain = $http('index.php', $debateUrl);
	check($debateAgain['status'] === 200 &&
		$debateAgain['body'] === $debateFirst['body'],
		'Second HTTP debate page view is byte-identical without a new Argument rendering');

	$readerFirst = $fragment('WD HTTP reading argument', ['X-Requested-With: XMLHttpRequest']);
	check(str_contains($readerFirst['fragment']['html'], 'WD_HTTP_READING_SUMMARY_FIRST'),
		'AJAX click on an argument gets the separate Argument page summary');
	$readerWarm = null;
	for ($attempt = 0; $attempt < 5; $attempt++) {
		$candidate = $fragment('WD HTTP reading argument', ['X-Requested-With: XMLHttpRequest']);
		if ($candidate['fragment']['source'] === 'fragment-cache') {
			$readerWarm = $candidate;
			break;
		}
	}
	check($readerWarm !== null && $readerWarm['fragment']['html'] ===
		$readerFirst['fragment']['html'],
		'Repeated argument clicks reuse a prepared server fragment without re-rendering the debate');

	$readerPrevious = SemanticRevisionStore::fingerprint($store, $readingArgSubject);
	fsSave('WD HTTP reading parent', '{{#set:' . $proProperty . '=WD HTTP reading argument}}');
	fsFlush(['wikidebatesSemanticCachePurge']);
	check($services->getJobQueueGroup()->queuesHaveJobs(),
		'Changing an Argument parent leaves the dependent purge job queued');
	check(SemanticRevisionStore::fingerprint($store, $readingArgSubject) !== $readerPrevious,
		'Argument semantic revision advances without any edit to the debate page');
	$readerAfterParent = $fragment('WD HTTP reading argument', ['X-Requested-With: XMLHttpRequest']);
	check($readerAfterParent['fragment']['source'] !== 'fragment-cache' &&
		str_contains($readerAfterParent['fragment']['html'], 'WD_HTTP_READING_SUMMARY_FIRST'),
		'New argument semantic relations invalidate only its cached fragment before purge');
	$parentInArgument = $http('index.php', ['title' => 'WD_HTTP_reading_argument']);
	check($parentInArgument['status'] === 200 &&
		(str_contains($parentInArgument['body'], 'WD_HTTP_reading_parent') ||
			str_contains($parentInArgument['body'], 'WD HTTP reading parent')),
		'Full Argument HTML reflects the parent edit before the purge');
	$debateAfterParent = $http('index.php', $debateUrl);
	check($debateAfterParent['status'] === 200 &&
		$debateAfterParent['body'] === $debateFirst['body'],
		'The debate page stays unchanged while the separately loaded argument updates');
	fsFlush();

	fsSave('WD HTTP reading argument',
		'{{#argument:render|lang=fr|summary=WD_HTTP_READING_SUMMARY_SECOND}}');
	fsFlush(['wikidebatesSemanticCachePurge']);
	$readerEdited = $fragment('WD HTTP reading argument', ['X-Requested-With: XMLHttpRequest']);
	check(str_contains($readerEdited['fragment']['html'], 'WD_HTTP_READING_SUMMARY_SECOND') &&
		!str_contains($readerEdited['fragment']['html'], 'WD_HTTP_READING_SUMMARY_FIRST'),
		'An Argument summary edit is visible on the next independent reading API fetch');
	$debateAfterArgumentEdit = $http('index.php', $debateUrl);
	check($debateAfterArgumentEdit['status'] === 200 &&
		$debateAfterArgumentEdit['body'] === $debateFirst['body'],
		'Editing an Argument does not embed content or alter the cached debate view');
	fsFlush();

	// Representative corpus size: 25 actual pro/con titles from the
	// retained "Dieu existe-t-il ?" fixture. A separate Argument page
	// supplies the summary, not the debate renderer.
	$corpusUrl = ['title' => 'WD_HTTP_corpus_debate'];
	$corpusDebate = $http('index.php', $corpusUrl);
	check($corpusDebate['status'] === 200 &&
		str_contains($corpusDebate['body'], 'Il existe des interventions divines') &&
		str_contains($corpusDebate['body'], 'Dieu est une invention') &&
		substr_count($corpusDebate['body'], 'argument-title') >= 25,
		'Corpus-sized native debate lists all 25 real argument titles');
	check(!str_contains($corpusDebate['body'], 'WD_HTTP_CORPUS_ARGUMENT_FIRST'),
		'Large debate HTML does not contain the selected Argument summary');
	$corpusFragment = $fragment($corpusArgumentTitle,
		['X-Requested-With: XMLHttpRequest']);
	check(str_contains($corpusFragment['fragment']['html'],
		'WD_HTTP_CORPUS_ARGUMENT_FIRST'),
		'Reading a real corpus argument title fetches only its separate HTML fragment');
	$corpusWarm = null;
	for ($attempt = 0; $attempt < 5; $attempt++) {
		$sample = $fragment($corpusArgumentTitle, ['X-Requested-With: XMLHttpRequest']);
		if ($sample['fragment']['source'] === 'fragment-cache') {
			$corpusWarm = $sample;
			break;
		}
	}
	check($corpusWarm !== null,
		'Real corpus Argument retains a reusable prepared fragment');
	fsSave($corpusArgumentTitle,
		'{{#argument:render|lang=fr|summary=WD_HTTP_CORPUS_ARGUMENT_SECOND}}');
	fsFlush(['wikidebatesSemanticCachePurge']);
	$corpusUpdated = $fragment($corpusArgumentTitle,
		['X-Requested-With: XMLHttpRequest']);
	check($corpusUpdated['fragment']['source'] !== 'fragment-cache' &&
		str_contains($corpusUpdated['fragment']['html'], 'WD_HTTP_CORPUS_ARGUMENT_SECOND') &&
		!str_contains($corpusUpdated['fragment']['html'], 'WD_HTTP_CORPUS_ARGUMENT_FIRST'),
		'Editing the corpus Argument invalidates its fragment even before queued purge');
	$corpusDebateAgain = $http('index.php', $corpusUrl);
	check($corpusDebateAgain['status'] === 200 &&
		$corpusDebateAgain['body'] === $corpusDebate['body'],
		'Corpus-sized debate remains byte-identical after its separate Argument changes');
	fsFlush();
	// A/B benchmark with crossed order: both configurations are warmed
	// before measurement, then eight pairs alternate on/off and off/on.
	// The tracking toggle is changed OUTSIDE the timed HTTP request.
	// A single on-then-off block would bias the second group through
	// progressively warmer MediaWiki/browser-independent server caches.
	$corpusDebateTimings = [];
	$corpusBaselineTimings = [];
	$toggle = $temporary . '/semantic-http-disable-tracking';
	$probeCorpus = static function(bool $tracking) use (
		$toggle, $http, $corpusUrl, $corpusDebate
	): float {
		if ($tracking) {
			if (is_file($toggle)) { unlink($toggle); }
		} else {
			touch($toggle);
		}
		$sample = $http('index.php', $corpusUrl);
		check($sample['status'] === 200 &&
			$sample['body'] === $corpusDebate['body'],
			'Debate HTML is byte-identical with or without semantic tracking');
		return $sample['elapsed_ms'];
	};
	try {
		for ($i = 0; $i < 3; $i++) {
			$probeCorpus(true);
			$probeCorpus(false);
		}
		for ($i = 0; $i < 8; $i++) {
			$order = $i % 2 === 0 ? [true, false] : [false, true];
			foreach ($order as $tracking) {
				$elapsed = $probeCorpus($tracking);
				if ($tracking) {
					$corpusDebateTimings[] = $elapsed;
				} else {
					$corpusBaselineTimings[] = $elapsed;
				}
			}
		}
	} finally {
		if (is_file($toggle)) { unlink($toggle); }
	}
	$trackedDebateMedian = $percentile($corpusDebateTimings, .5);
	$baselineDebateMedian = $percentile($corpusBaselineTimings, .5);
	$corpusMeasurements = [
		'source' => 'retained debatephp/tests/fixtures/pages.json, no production access',
		'corpus_debate' => $corpusPage['title'],
		'pro_titles' => count($corpusPro),
		'con_titles' => count($corpusCon),
		'html_bytes' => strlen($corpusDebate['body']),
		'http_samples_each' => count($corpusDebateTimings),
		'benchmark_design' => 'alternating paired requests, balanced AB/BA order, warmed before measurement',
		'debate_tracking_on_median_ms' => $trackedDebateMedian,
		'debate_tracking_off_median_ms' => $baselineDebateMedian,
		'debate_tracking_extra_median_ms' => round(
			$trackedDebateMedian - $baselineDebateMedian, 2
		)
	];

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

	// A genuinely mobile Article::view must also bypass the MinervaFileCache
	// full-page file and contain the updated semantic result before purge.
	$mobileUA = 'User-Agent: Mozilla/5.0 (Linux; Android 14; Pixel 8) ' .
		'AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36';
	$mobile = $http('index.php', ['title' => 'WD_HTTP_query_A'], [$mobileUA]);
	check($mobile['status'] === 200 && str_contains($mobile['body'], 'WD_HTTP_MATCHES=1'),
		'Real mobile HTML page is current with semantic purge still pending');
	$mobileCache = $mobile['headers']['x-minerva-file-cache'][0] ?? '';
	check(str_starts_with($mobileCache, 'BYPASS'),
		'Tracked mobile page bypasses Minerva full-page file cache');
	$mobileControl = strtolower(implode(', ', $mobile['headers']['cache-control'] ?? []));
	check(str_contains($mobileControl, 'no-cache') ||
		str_contains($mobileControl, 'no-store') || str_contains($mobileControl, 'private'),
		'Tracked mobile HTML cannot be stored in public HTTP caches');

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

	// Exercise the real Argument parent-query OR form via independent HTTP
	// workers, without clearing the workers' memory from this CLI process.
	// The semantics must remain correct while targeted purge jobs are queued.
	$orPage = 'WD HTTP OR query';
	$orEmpty = $fragment($orPage);
	check(str_contains($orEmpty['fragment']['html'],
		'WD_HTTP_OR_A=0;WD_HTTP_OR_B=0;WD_HTTP_OR_TOTAL=0'),
		'Independent HTTP combined-parent query starts empty');
	$orWarm = null;
	for ($attempt = 0; $attempt < 5; $attempt++) {
		$candidate = $fragment($orPage);
		if ($candidate['fragment']['source'] === 'fragment-cache') {
			$orWarm = $candidate;
			break;
		}
		check(str_contains($candidate['fragment']['html'], 'WD_HTTP_OR_TOTAL=0'),
			'Combined-parent warmup remains current');
	}
	check($orWarm !== null, 'Combined OR query reuses its prepared fragment');

	$beforeOr = SemanticRevisionStore::fingerprint($store, $orSubject);
	fsSave('WD HTTP OR parent', '{{#set:WD HTTP Pro=WD HTTP OR target}}');
	fsFlush(['wikidebatesSemanticCachePurge']);
	check($services->getJobQueueGroup()->queuesHaveJobs(),
		'Combined OR first-result purge is deliberately pending');
	check(SemanticRevisionStore::fingerprint($store, $orSubject) !== $beforeOr,
		'Combined OR first result changes the transaction revision');
	$orFirst = $fragment($orPage);
	check($orFirst['fragment']['source'] !== 'fragment-cache' &&
		str_contains($orFirst['fragment']['html'], 'WD_HTTP_OR_A=1;WD_HTTP_OR_B=0;WD_HTTP_OR_TOTAL=1'),
		'Combined OR first result appears on another PHP worker before purge: ' .
		($orFirst['fragment']['html'] ?? ''));
	fsFlush();

	$beforeOr = SemanticRevisionStore::fingerprint($store, $orSubject);
	fsSave('WD HTTP OR parent', '{{#set:WD HTTP Con=WD HTTP OR target}}');
	fsFlush(['wikidebatesSemanticCachePurge']);
	check($services->getJobQueueGroup()->queuesHaveJobs(),
		'Combined OR same-cardinality switch keeps the purge job waiting');
	check(SemanticRevisionStore::fingerprint($store, $orSubject) !== $beforeOr,
		'Combined OR same-cardinality switch changes the revision');
	$orSwitched = $fragment($orPage);
	check($orSwitched['fragment']['source'] !== 'fragment-cache' &&
		str_contains($orSwitched['fragment']['html'],
			'WD_HTTP_OR_A=0;WD_HTTP_OR_B=1;WD_HTTP_OR_TOTAL=1'),
		'Combined OR prints only the new parent after a real HTTP edit: ' .
		($orSwitched['fragment']['html'] ?? ''));

	$orDesktop = $http('index.php', ['title' => 'WD_HTTP_OR_query']);
	check($orDesktop['status'] === 200 &&
		str_contains($orDesktop['body'], 'WD_HTTP_OR_A=0;WD_HTTP_OR_B=1;WD_HTTP_OR_TOTAL=1'),
		'Full Vector page reflects the combined OR switch before purge');
	$orMobile = $http('index.php', ['title' => 'WD_HTTP_OR_query'], [$mobileUA]);
	check($orMobile['status'] === 200 &&
		str_contains($orMobile['body'], 'WD_HTTP_OR_A=0;WD_HTTP_OR_B=1;WD_HTTP_OR_TOTAL=1') &&
		str_starts_with($orMobile['headers']['x-minerva-file-cache'][0] ?? '', 'BYPASS'),
		'Minerva bypasses old full HTML while an OR parent switches before purge');
	fsFlush();

	$beforeOr = SemanticRevisionStore::fingerprint($store, $orSubject);
	fsSave('WD HTTP OR parent', 'No parent relation');
	fsFlush(['wikidebatesSemanticCachePurge']);
	check(SemanticRevisionStore::fingerprint($store, $orSubject) !== $beforeOr,
		'Removing the final OR parent changes its revision before purge');
	$orRemoved = $fragment($orPage);
	check($orRemoved['fragment']['source'] !== 'fragment-cache' &&
		str_contains($orRemoved['fragment']['html'],
			'WD_HTTP_OR_A=0;WD_HTTP_OR_B=0;WD_HTTP_OR_TOTAL=0'),
		'Combined OR query returns to empty immediately after removal: ' .
		($orRemoved['fragment']['html'] ?? ''));
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
	// Forced re-parses can change page_touched, so measuring HITs
	// immediately after each forced parse would deliberately manufacture
	// cache misses. Stabilize first; then measure each scenario separately.
	$stableHitCount = 0;
	for ($attempt = 0; $attempt < 12 && $stableHitCount < 3; $attempt++) {
		$probe = $fragment('WD HTTP query B');
		$stableHitCount = $probe['fragment']['source'] === 'fragment-cache' ?
			$stableHitCount + 1 : 0;
	}
	check($stableHitCount === 3, 'Semantic query has three stable consecutive fragment-cache hits');

	$hitSamples = [];
	$parseSamples = [];
	$workers = [];
	$benchmarkHtml = null;
	$parseStart = $parseCount();
	for ($i = 0; $i < 15; $i++) {
		$hit = $fragment('WD HTTP query B');
		check($hit['fragment']['source'] === 'fragment-cache',
			'Benchmark records a real, stable HTTP fragment-cache hit');
		$benchmarkHtml ??= $hit['fragment']['html'];
		check($hit['fragment']['html'] === $benchmarkHtml,
			'Benchmark warm HTML stays byte-for-byte stable');
		$hitSamples[] = $hit['elapsed_ms'];
		$workers[$hit['headers']['x-wd-semantic-lab-worker'][0] ?? 'unknown'] = true;
	}
	check($parseCount() === $parseStart, 'Fifteen HTTP cache hits avoid the semantic renderer');
	for ($i = 0; $i < 15; $i++) {
		$bypass = $fragment('WD HTTP query B', ['Cache-Control: no-cache']);
		check($bypass['fragment']['source'] === 'parser', 'Benchmark forced refresh uses parser');
		check($bypass['fragment']['html'] === $benchmarkHtml,
			'Benchmark compares exact same semantic HTML in both scenarios');
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

	// Approximate the per-HIT SQL tracking cost. The lab feature flag is read
	// afresh on every HTTP request, without any external wiki configuration.
	// No semantic edits occur in this comparison, and the fragment identity
	// explicitly separates tracked and untracked representations.
	$noTrackingSamples = [];
	touch($temporary . '/semantic-http-disable-tracking');
	try {
		$baselineStableHits = 0;
		for ($attempt = 0; $attempt < 12 && $baselineStableHits < 3; $attempt++) {
			$probe = $fragment('WD HTTP query B');
			$baselineStableHits = $probe['fragment']['source'] === 'fragment-cache' ?
				$baselineStableHits + 1 : 0;
		}
		check($baselineStableHits === 3,
			'Untracked control version also stabilizes as a shared HTTP fragment');
		for ($i = 0; $i < 15; $i++) {
			$control = $fragment('WD HTTP query B');
			check($control['fragment']['source'] === 'fragment-cache',
				'Untracked control request is a real shared fragment hit');
			check($control['fragment']['html'] === $benchmarkHtml,
				'Tracking on and off render byte-for-byte identical semantic HTML');
			$noTrackingSamples[] = $control['elapsed_ms'];
		}
	} finally {
		unlink($temporary . '/semantic-http-disable-tracking');
	}

	$report = [
		'backend' => $dbType,
		'mediawiki' => MW_VERSION,
		'smw' => SMW_VERSION,
		'semantic_scribunto' => SMW_SCRIBUNTO_VERSION,
		'transport' => 'real loopback HTTP, three PHP CLI server workers',
		'corpus_debate' => $corpusMeasurements,
		'benchmark' => [
			'samples_each' => count($hitSamples),
			'fragment_hit_median_ms' => $percentile($hitSamples, .5),
			'fragment_hit_p95_ms' => $percentile($hitSamples, .95),
			'forced_fresh_median_ms' => $percentile($parseSamples, .5),
			'forced_fresh_p95_ms' => $percentile($parseSamples, .95),
			'no_tracking_hit_median_ms' => $percentile($noTrackingSamples, .5),
			'no_tracking_hit_p95_ms' => $percentile($noTrackingSamples, .95),
			'tracking_hit_extra_median_ms' => round(
				$percentile($hitSamples, .5) - $percentile($noTrackingSamples, .5), 2),
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
