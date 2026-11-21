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
// Reproduce the real French Argument/Debat template wrappers rather than only
// calling native parser functions directly. The lab runs in English, so set
// lang=fr on the bundled wrapper; production fr.wikidebates.org supplies it
// through its content language. Never insert __NOCACHE__ into these templates.
$argumentTemplate = file_get_contents(
	__DIR__ . '/../debatephp/resources/Template-Argument.php.wikitext'
);
$debateTemplate = file_get_contents(
	__DIR__ . '/../debatephp/resources/Template-Debat.php.wikitext'
);
check(is_string($argumentTemplate) && is_string($debateTemplate) &&
	!str_contains(strtoupper($argumentTemplate), '__NOCACHE__') &&
	!str_contains(strtoupper($debateTemplate), '__NOCACHE__'),
	'Actual bundled Argument/Debat templates contain no __NOCACHE__');
fsSave('Template:WD HTTP Argument FR',
	str_replace('{{#argument:render', '{{#argument:render|lang=fr', $argumentTemplate));
fsSave('Template:WD HTTP Argument EN',
	str_replace('{{#argument:render', '{{#argument:render|lang=en', $argumentTemplate));
fsSave('Template:WD HTTP Debate FR',
	str_replace('{{#debate:render', '{{#debate:render|lang=fr', $debateTemplate));
fsSave('WD HTTP template argument FR', 'Initial Argument template stub');
fsSave('WD HTTP template argument EN', 'Initial Argument template stub');
fsSave('WD HTTP template debate FR', 'Initial Debate template stub');
fsSave('WD HTTP template parent FR', 'No parent relation');
fsSave('WD HTTP template parent EN', 'No parent relation');

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
fsSave('WD HTTP template argument FR',
	'{{WD HTTP Argument FR|résumé=WD_HTTP_TEMPLATE_FR_SUMMARY}}');
fsSave('WD HTTP template argument EN',
	'{{WD HTTP Argument EN|résumé=WD_HTTP_TEMPLATE_EN_SUMMARY}}');
fsSave('WD HTTP template debate FR',
	'{{WD HTTP Debate FR|sujet=Template debate|arguments-pour=' .
	'WD HTTP template argument FR⟬Displayed template argument⟬⟭}}');
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


$templateArgFrSubject = SemanticWikiPage::newFromTitle(
	Title::newFromText('WD HTTP template argument FR'));
$templateArgEnSubject = SemanticWikiPage::newFromTitle(
	Title::newFromText('WD HTTP template argument EN'));
$templateDebateSubject = SemanticWikiPage::newFromTitle(
	Title::newFromText('WD HTTP template debate FR'));
$store->clear();
check($store->getPropertyValues($templateArgFrSubject, new Property('_ASK')) !== [],
	'Actual transcluded French Argument wrapper creates SMW _ASK profiles');
check($store->getPropertyValues($templateArgEnSubject, new Property('_ASK')) !== [],
	'Actual transcluded English Argument wrapper creates SMW _ASK profiles');
check($store->getPropertyValues($templateDebateSubject, new Property('_ASK')) === [],
	'Actual transcluded French Debat-only wrapper keeps ordinary cache eligibility');

// Recreate the production page-1220 state on disposable data: a native
// Argument saved while semantic tracking is OFF, with no _ASK profiles.
// Its mobile HTML will be warmed in the untracked HTTP configuration,
// then an external parent edit will occur before turning tracking ON.
$unprofiledArgumentName = 'WD HTTP unprofiled argument';
$unprofiledParentName = 'WD HTTP unprofiled parent';
$unprofiledAnchorName = 'WD HTTP unprofiled anchor';
$unprofiledDebateName = 'WD HTTP cacheable debate';
fsSave($unprofiledParentName, 'Initially no semantic relation');
fsSave($unprofiledAnchorName, 'Target of a parent query');
fsSave($unprofiledArgumentName, 'Initial argument stub');
fsSave($unprofiledDebateName,
	'{{#debate:render|lang=fr|topic=Unrelated cacheable debate}}');
fsFlush();
check($services->getMainConfig()->get('WikidebatesSemanticCacheTracking') === true,
	'Disposable HTTP writer starts with tracking enabled');
$GLOBALS['wgWikidebatesSemanticCacheTracking'] = false;
try {
	check($services->getMainConfig()->get('WikidebatesSemanticCacheTracking') === false,
		'Disposable writer can emulate a legacy untracked Argument revision');
	fsSave($unprofiledArgumentName,
		"{{#argument:render|lang=fr|summary=WD_HTTP_UNPROFILED_SUMMARY}}\n" .
		"== Parent debates ==\n" .
		"<wd-http-parents>$unprofiledAnchorName</wd-http-parents>");
	fsFlush();
} finally {
	$GLOBALS['wgWikidebatesSemanticCacheTracking'] = true;
}
$unprofiledTitle = Title::newFromText($unprofiledArgumentName);
$unprofiledSubject = SemanticWikiPage::newFromTitle($unprofiledTitle);
$unprofiledDebateSubject = SemanticWikiPage::newFromTitle(
	Title::newFromText($unprofiledDebateName));
$store->clear();
check($store->getPropertyValues($unprofiledSubject, new Property('_ASK')) === [],
	'Untracked native Argument has no _ASK, reproducing the production page-1220 state');
check($store->getPropertyValues($unprofiledDebateSubject, new Property('_ASK')) === [],
	'Unrelated native Debate needs no parent-query profile');

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

	// Exercise transcluded, bundled Argument/Debat templates on both languages,
	// with genuine HTTP workers and the semantic purge job intentionally held.
	$templateDebateUrl = ['title' => 'WD_HTTP_template_debate_FR'];
	$templatedDebate = $http('index.php', $templateDebateUrl);
	check($templatedDebate['status'] === 200 &&
		str_contains($templatedDebate['body'], 'Displayed template argument'),
		'Bundled French Debat template renders an argument link');
	$initialTemplate = [];
	foreach (['FR', 'EN'] as $lang) {
		$title = 'WD HTTP template argument ' . $lang;
		$marker = 'WD_HTTP_TEMPLATE_' . $lang . '_SUMMARY';
		$read = $fragment($title, ['X-Requested-With: XMLHttpRequest']);
		check(str_contains($read['fragment']['html'], $marker),
			'Bundled Argument wrapper renders the ' . $lang . ' summary');
		$warm = null;
		for ($attempt = 0; $attempt < 6; $attempt++) {
			$candidate = $fragment($title, ['X-Requested-With: XMLHttpRequest']);
			if ($candidate['fragment']['source'] === 'fragment-cache') {
				$warm = $candidate;
				break;
			}
		}
		check($warm !== null, 'Bundled Argument ' . $lang . ' reuses private fragments');
		$initialTemplate[$lang] = $warm['fragment']['html'];
	}

	$enProProperty = $props->message('Argument.props', 'en', 'arg_pro');
	foreach (['FR', 'EN'] as $lang) {
		$title = 'WD HTTP template argument ' . $lang;
		$parent = 'WD HTTP template parent ' . $lang;
		$property = $lang === 'FR' ? $proProperty : $enProProperty;
		$subject = $lang === 'FR' ? $templateArgFrSubject : $templateArgEnSubject;
		$revisionBefore = SemanticRevisionStore::fingerprint($store, $subject);
		fsSave($parent, '{{#set:' . $property . '=' . $title . '}}');
		fsFlush(['wikidebatesSemanticCachePurge']);
		check($services->getJobQueueGroup()->queuesHaveJobs(),
			'Real ' . $lang . ' template parent change leaves its purge job pending');
		check(SemanticRevisionStore::fingerprint($store, $subject) !== $revisionBefore,
			'Real ' . $lang . ' transcluded template has an advancing semantic fingerprint');
		$changed = $fragment($title, ['X-Requested-With: XMLHttpRequest']);
		$parentUnderscored = str_replace(' ', '_', $parent);
		// FragmentExtractor deliberately omits Parent_debates: the parent
		// appears in full HTML, while the separate reading fragment still
		// carries the summary and MUST bypass its old cache identity.
		check($changed['fragment']['source'] !== 'fragment-cache' &&
			str_contains($changed['fragment']['html'], 'WD_HTTP_TEMPLATE_' . $lang . '_SUMMARY'),
			'Real ' . $lang . ' transcluded Argument fragment is freshly rebuilt before purge');
		$article = $http('index.php', ['title' => str_replace(' ', '_', $title)]);
		check($article['status'] === 200 &&
			(str_contains($article['body'], $parent) ||
				str_contains($article['body'], $parentUnderscored)),
			'Real ' . $lang . ' transcluded Argument full article is fresh before purge');
		fsFlush();
	}
	$afterTemplate = $http('index.php', $templateDebateUrl);
	check($afterTemplate['status'] === 200 &&
		$afterTemplate['body'] === $templatedDebate['body'],
		'Bundled French Debat-only HTML stays unchanged when parent links change');

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
	$mobile = $http('index.php', ['title' => 'WD_HTTP_query_A'], [$mobileUA, 'X-WD-Semantic-Lab-File-Cache-Probe: 1']);
	check($mobile['status'] === 200 && str_contains($mobile['body'], 'WD_HTTP_MATCHES=1'),
		'Real mobile HTML page is current with semantic purge still pending');
	$mobileCache = $mobile['headers']['x-minerva-file-cache'][0] ?? '';
	check(str_starts_with($mobileCache, 'BYPASS; reason=extension-veto'),
		'Tracked mobile query is refused by the semantic file-cache veto, not by a lab header: ' .
		$mobileCache);
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
	$orMobile = $http('index.php', ['title' => 'WD_HTTP_OR_query'], [$mobileUA, 'X-WD-Semantic-Lab-File-Cache-Probe: 1']);
	check($orMobile['status'] === 200 &&
		str_contains($orMobile['body'], 'WD_HTTP_OR_A=0;WD_HTTP_OR_B=1;WD_HTTP_OR_TOTAL=1') &&
		str_starts_with($orMobile['headers']['x-minerva-file-cache'][0] ?? '',
			'BYPASS; reason=extension-veto'),
		'Semantic veto protects Minerva during an OR parent switch even without the lab debug header');
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

	// Exact transition observed on production page 1220, but on a completely
	// synthetic lab page: a native Argument with no _ASK can have an
	// already-warm Minerva HIT; an edit to another page then changes its
	// semantic parent query without editing the Argument revision.
	$unprofiledUrl = ['title' => str_replace(' ', '_', $unprofiledArgumentName)];
	$unprofiledHeaders = [$mobileUA, 'X-WD-Semantic-Lab-File-Cache-Probe: 1'];
	$unprofiledToggle = $temporary . '/semantic-http-disable-tracking';
	touch($unprofiledToggle);
	try {
		$unprofiledCold = $http('index.php', $unprofiledUrl, $unprofiledHeaders);
		$unprofiledWarm = $http('index.php', $unprofiledUrl, $unprofiledHeaders);
		check($unprofiledCold['status'] === 200 && $unprofiledWarm['status'] === 200 &&
			str_contains($unprofiledCold['body'], 'WD_HTTP_MATCHES=0') &&
			str_contains($unprofiledWarm['body'], 'WD_HTTP_MATCHES=0') &&
			str_contains($unprofiledWarm['body'], 'WD_HTTP_UNPROFILED_SUMMARY'),
			'Real untracked Argument starts with an empty parent query and genuine mobile HTML');
		check(str_starts_with($unprofiledWarm['headers']['x-minerva-file-cache'][0] ?? '', 'HIT'),
			'Unprofiled native Argument has a genuine warm Minerva HIT with tracking off');

		$writer = $services->getConnectionProvider()->getPrimaryDatabase();
		$unprofiledPageId = $unprofiledTitle->getArticleID();
		$unprofiledBefore = $writer->newSelectQueryBuilder()
			->select(['page_latest', 'page_touched'])
			->from('page')
			->where(['page_id' => $unprofiledPageId])
			->caller(__FILE__)
			->fetchRow();
		check($unprofiledBefore !== false, 'Unprofiled Argument row exists');

		// The real Argument renderer uses a parent property, and the
		// supplementary diagnostic marker makes the 0-to-1 transition
		// independently observable in the full HTML.
		fsSave($unprofiledParentName,
			'{{#set:WD HTTP Parent=' . $unprofiledAnchorName .
			'|' . $proProperty . '=' . $unprofiledArgumentName . '}}');
		fsFlush(['wikidebatesSemanticCachePurge']);
		$store->clear();
		$direct = (new \SMW\Scribunto\LibraryFactory($store))->newQueryResultFrom([
			'[[WD HTTP Parent::' . $unprofiledAnchorName . ']]',
			'limit=200', 'link=none'
		]);
		check(count($direct->getResults()) === 1,
			'Direct SMW parent query sees external 0-to-1 relation');

		$unprofiledAfter = $writer->newSelectQueryBuilder()
			->select(['page_latest', 'page_touched'])
			->from('page')
			->where(['page_id' => $unprofiledPageId])
			->caller(__FILE__)
			->fetchRow();
		check($unprofiledAfter !== false &&
			$unprofiledBefore->page_latest === $unprofiledAfter->page_latest &&
			$unprofiledBefore->page_touched === $unprofiledAfter->page_touched,
			'External SMW edit does not update the cached Argument revision or page_touched');
		check($store->getPropertyValues($unprofiledSubject, new Property('_ASK')) === [],
			'Query owner still has no _ASK when its unrelated parent changes');

		$unprofiledStale = $http('index.php', $unprofiledUrl, $unprofiledHeaders);
		check($unprofiledStale['status'] === 200 &&
			str_starts_with($unprofiledStale['headers']['x-minerva-file-cache'][0] ?? '', 'HIT') &&
			str_contains($unprofiledStale['body'], 'WD_HTTP_MATCHES=0') &&
			!str_contains($unprofiledStale['body'], 'WD_HTTP_MATCHES=1'),
			'Expected untracked control reproduces an actual stale Minerva HIT on an unprofiled Argument');
	} finally {
		if (is_file($unprofiledToggle)) {
			unlink($unprofiledToggle);
		}
	}
	// Enable the opt-in safeguards WITHOUT invalidating or deleting the old
	// untracked Minerva file. Its cached HTML is still present on disk.
	$unprofiledProtected = $http('index.php', $unprofiledUrl, $unprofiledHeaders);
	$protectedState = $unprofiledProtected['headers']['x-minerva-file-cache'][0] ?? '';
	check($unprofiledProtected['status'] === 200 &&
		str_starts_with($protectedState, 'BYPASS; reason=extension-veto') &&
		str_contains($unprofiledProtected['body'], 'WD_HTTP_MATCHES=1') &&
		!str_contains($unprofiledProtected['body'], 'WD_HTTP_MATCHES=0'),
		'Tracking veto rejects the old unprofiled mobile HIT and renders the new relation: ' .
		$protectedState);
	$expectedParentText = str_contains($unprofiledProtected['body'], $unprofiledParentName) ||
		str_contains($unprofiledProtected['body'], str_replace(' ', '_', $unprofiledParentName));
	check($expectedParentText,
		'Native Argument parent section displays the newly related page before any targeted purge');
	$unprofiledProtectedAgain = $http('index.php', $unprofiledUrl, $unprofiledHeaders);
	check($unprofiledProtectedAgain['status'] === 200 &&
		str_starts_with($unprofiledProtectedAgain['headers']['x-minerva-file-cache'][0] ?? '',
			'BYPASS; reason=extension-veto') &&
		str_contains($unprofiledProtectedAgain['body'], 'WD_HTTP_MATCHES=1'),
		'Repeated tracked Argument requests never reuse the stale unprofiled HTML file');
	$unprofiledFragment = $fragment($unprofiledArgumentName);
	check(str_contains($unprofiledFragment['fragment']['html'], 'WD_HTTP_UNPROFILED_SUMMARY') &&
		!str_contains($unprofiledFragment['fragment']['html'], 'WD_HTTP_MATCHES=0'),
		'Independent Argument reading API does not expose the former parent count');
	$store->clear();
	$profilesAfterTrackedRead = count($store->getPropertyValues(
		$unprofiledSubject, new Property('_ASK')));
	fsFlush();

	// The guard must remain selective: a normal Debate with no semantic
	// parent queries should still produce a genuine Minerva MISS then HIT
	// with tracking enabled, rather than a global cache shutdown.
	$pureDebateUrl = ['title' => str_replace(' ', '_', $unprofiledDebateName)];
	$debateMobileFirst = $http('index.php', $pureDebateUrl, $unprofiledHeaders);
	$debateMobileSecond = $http('index.php', $pureDebateUrl, $unprofiledHeaders);
	check($debateMobileFirst['status'] === 200 && $debateMobileSecond['status'] === 200 &&
		str_starts_with($debateMobileFirst['headers']['x-minerva-file-cache'][0] ?? '', 'MISS') &&
		str_starts_with($debateMobileSecond['headers']['x-minerva-file-cache'][0] ?? '', 'HIT'),
		'Debate-only mobile HTML remains genuinely file-cacheable with semantic tracking enabled');

	$unprofiledArgumentObservation = [
		'initial_ask_profiles' => 0,
		'old_mobile_file_state' => $unprofiledWarm['headers']['x-minerva-file-cache'][0] ?? '',
		'after_external_edit_untracked_file_state' => $unprofiledStale['headers']['x-minerva-file-cache'][0] ?? '',
		'external_edit_changed_argument_page_touched' => false,
		'protected_mobile_file_state' => $protectedState,
		'protected_full_html_has_new_parent' => true,
		'ask_profiles_after_protected_read' => $profilesAfterTrackedRead,
		'debate_mobile_first_state' => $debateMobileFirst['headers']['x-minerva-file-cache'][0] ?? '',
		'debate_mobile_second_state' => $debateMobileSecond['headers']['x-minerva-file-cache'][0] ?? '',
	];

	// Model a direct native Argument with no registered _ASK and a warm
	// untracked mobile cache. Ordinary refreshLinks reparses the current
	// revision, but SMW can skip persisting the annotations when no
	// template-triggered forced-update marker is present. The explicit
	// SMW forced UpdateJob must backfill a durable graph WITHOUT adding a
	// revision. Then verify 0 -> 1 -> 0 semantic parent changes over real
	// independent HTTP workers with eager purge disabled.
	$originalEager = $GLOBALS['wgWikidebatesSemanticCacheEagerPurge'];
	$disableEagerFile = $temporary . '/semantic-http-disable-eager-purge';
	check(file_put_contents($disableEagerFile, 'disposable only') !== false,
		'Isolated HTTP workers switch off semantic eager purges for migration test');
	$GLOBALS['wgWikidebatesSemanticCacheEagerPurge'] = false;
	try {
		check($services->getMainConfig()->get('WikidebatesSemanticCacheTracking') === true &&
			$services->getMainConfig()->get('WikidebatesSemanticCacheEagerPurge') === false,
			'Backfill runs with tracking on and eager semantic purges off');
		// Remove the existing parent before registering _ASK so the SQL
		// dependency graph must include initially EMPTY query results.
		fsSave($unprofiledParentName, 'No semantic parent relation before _ASK backfill');
		fsFlush();
		$store->clear();
		check($store->getPropertyValues($unprofiledSubject, new Property('_ASK')) === [],
			'No _ASK profile exists immediately before the isolated backfill');

		$oldRevision = $services->getWikiPageFactory()
			->newFromTitle($unprofiledTitle)->getLatest();
		$oldContent = $services->getWikiPageFactory()
			->newFromTitle($unprofiledTitle)->getContent()->getText();

		$refreshParseEvents = [];
		$refreshObserver = $services->getHookContainer()->scopedRegister(
			'ParserAfterParse',
			static function($parser, &$text, $stripState) use (&$refreshParseEvents, $unprofiledTitle): bool {
				$title = $parser->getTitle();
				if ($title && $title->getPrefixedDBkey() === $unprofiledTitle->getPrefixedDBkey()) {
					$output = $parser->getOutput();
					$semanticData = $output->getExtensionData(\SMW\ParserData::DATA_ID);
					$refreshParseEvents[] = [
						'title' => $title->getPrefixedText(),
						'tracked_query_marker' => $output->getExtensionData(
							\MediaWiki\Extension\DebatePHP\TrackedSemanticQuery::OUTPUT_MARKER) === true,
						'output_profile_count' => $semanticData instanceof \SMW\SemanticData ?
							count($semanticData->getPropertyValues(new Property('_ASK'))) : -1,
					];
				}
				return true;
			}
		);
		$refresh = new \RefreshLinksJob($unprofiledTitle, [
			'causeAction' => 'WikidebatesSemanticHTTPBackfillTest',
			'causeAgent' => 'Isolated MediaWiki 1.43 SMW regression',
		]);
		check($refresh->run() !== false,
			'MediaWiki refreshLinks job executes for one published Argument without editing');
		$refreshObserver = null;
		fsFlush();
		$store->clear();

		$backfilledPage = $services->getWikiPageFactory()->newFromTitle($unprofiledTitle);
		check($backfilledPage->getLatest() === $oldRevision &&
			$backfilledPage->getContent()->getText() === $oldContent,
			'Targeted _ASK backfill preserves both the Argument revision and its source');

		$profilesAfterRefreshLinks = $store->getPropertyValues(
			$unprofiledSubject, new Property('_ASK'));
		// A successful refreshLinks is NOT a universal semantic backfill:
		// SMW 7.3.1 skips data already recorded for the current revision
		// unless it has a forced-update marker (e.g. a template link).
		// This direct native Argument contains no transcluded template.
		// Its parser output can carry four profiles while its stored _ASK
		// remains empty. Record the result, then use SMW's explicit forced
		// update, which is safe even for other direct native readers.
		check(count($refreshParseEvents) >= 1 &&
			end($refreshParseEvents)['tracked_query_marker'] === true &&
			end($refreshParseEvents)['output_profile_count'] > 0,
			'refreshLinks parsed native tracked queries even if SMW skipped its store update');

		$forcedUpdate = $services->getJobFactory()->newJob('smw.update', [
			'namespace' => $unprofiledTitle->getNamespace(),
			'title' => $unprofiledTitle->getDBkey(),
			\SMW\MediaWiki\Jobs\UpdateJob::FORCED_UPDATE => true,
			'origin' => 'WikidebatesSemanticHTTPForcedProfileBackfillTest',
		]);
		check($forcedUpdate->run() !== false,
			'SMW 7.3.1 forced single-page update executes without a content revision');
		fsFlush();
		$store->clear();
		$askProfiles = $store->getPropertyValues(
			$unprofiledSubject, new Property('_ASK'));
		check(count($askProfiles) > 0,
			'SMW forced update persists missing _ASK profiles even for an initially empty query; ' .
			json_encode([
				'profiles_after_refreshlinks' => count($profilesAfterRefreshLinks),
				'refresh_parser_events' => $refreshParseEvents,
				'profiles_after_forced_smw_update' => count($askProfiles),
			], JSON_UNESCAPED_SLASHES));
		$backfilledPage = $services->getWikiPageFactory()->newFromTitle($unprofiledTitle);
		check($backfilledPage->getLatest() === $oldRevision &&
			$backfilledPage->getContent()->getText() === $oldContent,
			'Forced SMW update preserves both the Argument revision and its source');

		$queryLinkStore = (new \SMW\SQLStore\QueryDependencyLinksStoreFactory())
			->newQueryDependencyLinksStore($store);
		$queryIds = array_values(array_unique(array_filter(array_map(
			'intval', array_values($queryLinkStore->findEmbeddedQueryIdListBySubject(
				$unprofiledSubject))
		), static fn(int $id): bool => $id > 0)));
		check($queryIds !== [],
			'Persisted _ASK profiles resolve to durable SMW query IDs');
		$edgeCounts = array_fill_keys($queryIds, 0);
		$edgeRows = $store->getConnection('mw.db')->newSelectQueryBuilder()
			->select(['s_id', 'edge_count' => 'COUNT(*)'])
			->from(\SMW\SQLStore\SQLStore::QUERY_LINKS_TABLE)
			->where(['s_id' => $queryIds])
			->groupBy('s_id')
			->caller(__FILE__)
			->fetchResultSet();
		foreach ($edgeRows as $edgeRow) {
			$edgeCounts[(int)$edgeRow->s_id] = (int)$edgeRow->edge_count;
		}
		check(count(array_filter($edgeCounts, static fn(int $count): bool => $count === 0)) === 0,
			'Every forced-updated query ID has real SQL dependencies, including empty parent queries');
		$store->clear();
		$beforeParentFingerprint = SemanticRevisionStore::fingerprint(
			$store, $unprofiledSubject);
		check(\MediaWiki\Extension\Wikidebates\Reading\SemanticCacheGuard::safeForRead(
			$unprofiledTitle, $backfilledPage),
			'The once-unprofiled Argument becomes eligible for fingerprint-guarded fragments');

		$backfillSources = [];
		$backfillWarm = null;
		for ($attempt = 0; $attempt < 10; $attempt++) {
			$sample = $fragment($unprofiledArgumentName);
			$backfillSources[] = $sample['fragment']['source'] ?? 'unknown';
			check(str_contains($sample['fragment']['html'], 'WD_HTTP_UNPROFILED_SUMMARY'),
				'After backfill the Argument summary is still rendered normally');
			if (($sample['fragment']['source'] ?? '') === 'fragment-cache') {
				$backfillWarm = $sample;
				break;
			}
		}
		check($backfillWarm !== null,
			'Real HTTP fragment reaches a verified cache HIT after targeted backfill: ' .
			json_encode($backfillSources));

		// No owner-page edit, and no eager purge: the transactionally updated
		// semantic revision must invalidate an existing cached fragment.
		fsSave($unprofiledParentName,
			'{{#set:WD HTTP Parent=' . $unprofiledAnchorName .
			'|' . $proProperty . '=' . $unprofiledArgumentName . '}}');
		fsFlush();
		$store->clear();
		check($backfilledPage->getLatest() === $oldRevision,
			'Adding the external parent leaves the backfilled Argument revision unchanged');
		$afterAdditionFingerprint = SemanticRevisionStore::fingerprint(
			$store, $unprofiledSubject);
		check($afterAdditionFingerprint !== $beforeParentFingerprint,
			'Adding the first parent changes fingerprint even when the old query returned zero rows');
		$afterAddition = $fragment($unprofiledArgumentName);
		check(($afterAddition['fragment']['source'] ?? '') !== 'fragment-cache' &&
			str_contains($afterAddition['fragment']['html'], 'WD_HTTP_UNPROFILED_SUMMARY'),
			'Real HTTP refuses the pre-parent fragment after an external 0-to-1 semantic update');

		$afterAdditionMobile = $http('index.php', $unprofiledUrl, $unprofiledHeaders);
		check($afterAdditionMobile['status'] === 200 &&
			str_starts_with($afterAdditionMobile['headers']['x-minerva-file-cache'][0] ?? '',
				'BYPASS; reason=extension-veto') &&
			str_contains($afterAdditionMobile['body'], 'WD_HTTP_MATCHES=1') &&
			(str_contains($afterAdditionMobile['body'], $unprofiledParentName) ||
				str_contains($afterAdditionMobile['body'],
					str_replace(' ', '_', $unprofiledParentName))),
			'Full mobile Argument HTML and real parent link are fresh after the external addition');

		$rewarmedAfterAddition = false;
		for ($attempt = 0; $attempt < 10; $attempt++) {
			$sample = $fragment($unprofiledArgumentName);
			if (($sample['fragment']['source'] ?? '') === 'fragment-cache') {
				$rewarmedAfterAddition = true;
				break;
			}
		}
		check($rewarmedAfterAddition,
			'New parent-state fragment becomes cacheable again, not permanently bypassed');

		fsSave($unprofiledParentName, 'Removed the previously matching parent');
		fsFlush();
		$store->clear();
		check($backfilledPage->getLatest() === $oldRevision,
			'Removing the external parent also leaves the Argument revision unchanged');
		check(SemanticRevisionStore::fingerprint($store, $unprofiledSubject) !==
			$afterAdditionFingerprint,
			'Removing the final parent changes the durable semantic fingerprint');
		$afterRemoval = $fragment($unprofiledArgumentName);
		check(($afterRemoval['fragment']['source'] ?? '') !== 'fragment-cache',
			'Real HTTP rejects the fragment holding the removed parent');
		$afterRemovalMobile = $http('index.php', $unprofiledUrl, $unprofiledHeaders);
		check($afterRemovalMobile['status'] === 200 &&
			str_starts_with($afterRemovalMobile['headers']['x-minerva-file-cache'][0] ?? '',
				'BYPASS; reason=extension-veto') &&
			str_contains($afterRemovalMobile['body'], 'WD_HTTP_MATCHES=0') &&
			!str_contains($afterRemovalMobile['body'], $unprofiledParentName) &&
			!str_contains($afterRemovalMobile['body'],
				str_replace(' ', '_', $unprofiledParentName)),
			'Full mobile Argument HTML removes the former parent without waiting for a purge');

		// An unrelated Debate must keep its previously published Minerva
		// file across both semantic edits to the Argument parent.
		$unrelatedDebateAfter = $http('index.php', $pureDebateUrl, $unprofiledHeaders);
		check($unrelatedDebateAfter['status'] === 200 &&
			str_starts_with($unrelatedDebateAfter['headers']['x-minerva-file-cache'][0] ?? '',
				'HIT'),
			'Genuine debate-only Minerva HIT survives both external semantic changes');

		$backfillObservation = [
			'backfill_via_forced_smw_update' => true,
			'profiles_after_refreshlinks' => count($profilesAfterRefreshLinks),
			'refresh_parse_output_profiles' => end($refreshParseEvents)['output_profile_count'],
			'argument_revision_unchanged' => true,
			'ask_profiles' => count($askProfiles),
			'query_ids' => count($queryIds),
			'actual_sql_dependency_rows' => array_sum($edgeCounts),
			'zero_dependency_query_ids' => 0,
			'fragment_sources_until_first_hit' => $backfillSources,
			'fragment_hit_after_backfill' => true,
			'fragment_refused_after_first_external_parent' => true,
			'fragment_hit_after_rewarm' => true,
			'fragment_refused_after_parent_removed' => true,
			'debate_minerva_remains_hit' => true,
			'eager_purge_disabled' => true,
		];
	} finally {
		$GLOBALS['wgWikidebatesSemanticCacheEagerPurge'] = $originalEager;
		if (is_file($disableEagerFile)) {
			unlink($disableEagerFile);
		}
	}

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

	// Characterize the actual full-page Minerva behavior with semantic tracking
	// disabled, as on the current default configuration. Never label a green
	// CI run as a guarantee of freshness: report whether this specific cached
	// response survived an unrelated SMW update without a dependent purge.
	$baselineParent = 'WD HTTP untracked Minerva parent';
	$baselineChild = 'WD HTTP untracked Minerva child';
	$baselineQuery = 'WD HTTP untracked Minerva query';
	fsSave($baselineParent, 'Isolated parent for a mobile cache audit');
	fsSave($baselineChild, 'No semantic relationship yet');
	fsSave($baselineQuery, 'Initial untracked mobile query stub');
	fsFlush();
	sleep(2);
	fsSave($baselineQuery, "== Summary ==\n<wd-http-parents>$baselineParent</wd-http-parents>\n" .
		"== Parent debates ==\nBaseline");
	fsFlush();
	$baselineUrl = ['title' => str_replace(' ', '_', $baselineQuery)];
	$baselineToggle = $temporary . '/semantic-http-disable-tracking';
	touch($baselineToggle);
	try {
		$baselineCold = $http('index.php', $baselineUrl, [$mobileUA, 'X-WD-Semantic-Lab-File-Cache-Probe: 1']);
		$baselineWarm = $http('index.php', $baselineUrl, [$mobileUA, 'X-WD-Semantic-Lab-File-Cache-Probe: 1']);
		check($baselineCold['status'] === 200 && $baselineWarm['status'] === 200 &&
			str_contains($baselineCold['body'], 'WD_HTTP_MATCHES=0') &&
			str_contains($baselineWarm['body'], 'WD_HTTP_MATCHES=0'),
			'Untracked mobile query starts with zero matches on two genuine HTTP views');

		$baselineInitialStatus = $baselineWarm['headers']['x-minerva-file-cache'][0] ?? '';
		check(str_starts_with($baselineInitialStatus, 'HIT'),
			'Untracked mobile query must have a genuine full-page cache HIT before the external edit: ' .
			$baselineInitialStatus);

		// Keep experimental targeted purges waiting so only the normal
		// MediaWiki/SMW invalidation pipeline can refresh this baseline.
		fsSave($baselineChild, '{{#set:WD HTTP Parent=' . $baselineParent . '}}');
		fsFlush(['wikidebatesSemanticCachePurge']);
		$store->clear();
		$directAfter = (new \SMW\Scribunto\LibraryFactory($store))->newQueryResultFrom([
			'[[WD HTTP Parent::' . $baselineParent . ']]',
			'limit=200', 'link=none'
		]);
		check(count($directAfter->getResults()) === 1,
			'SMW direct query sees the new parent independently of HTML caches');
		$baselineAfter = $http('index.php', $baselineUrl, [$mobileUA, 'X-WD-Semantic-Lab-File-Cache-Probe: 1']);
		check($baselineAfter['status'] === 200,
			'Untracked Minerva full-page query still responds after external semantic edit');
		$coldState = $baselineCold['headers']['x-minerva-file-cache'][0] ?? '';
		$warmState = $baselineWarm['headers']['x-minerva-file-cache'][0] ?? '';
		$afterState = $baselineAfter['headers']['x-minerva-file-cache'][0] ?? '';
		$oldHtml = str_contains($baselineAfter['body'], 'WD_HTTP_MATCHES=0');
		$newHtml = str_contains($baselineAfter['body'], 'WD_HTTP_MATCHES=1');
		$untrackedMinervaObservation = [
			'tracking_in_http_workers' => false,
			'cli_semantic_purge_jobs_held' => true,
			'direct_smw_matches_after_edit' => 1,
			'initial_file_state' => $coldState,
			'second_file_state' => $warmState,
			'after_external_edit_file_state' => $afterState,
			'after_external_edit_old_html' => $oldHtml,
			'after_external_edit_new_html' => $newHtml,
			'stale_minerva_hit_observed' => str_starts_with($afterState, 'HIT') && $oldHtml,
			// A MISS or BYPASS is not an automatic freshness guarantee; a
			// separate parser cache could still return outdated semantic data.
			'stale_full_html_observed' => $oldHtml && !$newHtml,
		];
	} finally {
		if (is_file($baselineToggle)) {
			unlink($baselineToggle);
		}
	}
	fsFlush();
	if ($untrackedMinervaObservation['stale_full_html_observed']) {
		fwrite(STDERR, "WARNING: untracked mobile HTML retained an old SMW result in the disposable lab.\n");
	}

	$report = [
		'backend' => $dbType,
		'mediawiki' => MW_VERSION,
		'smw' => SMW_VERSION,
		'semantic_scribunto' => SMW_SCRIBUNTO_VERSION,
		'transport' => 'real loopback HTTP, three PHP CLI server workers',
		'corpus_debate' => $corpusMeasurements,
		'untracked_minerva_semantic_baseline' => $untrackedMinervaObservation,
		'unprofiled_native_argument_migration' => $unprofiledArgumentObservation,
		'targeted_forced_smw_update_argument_backfill' => $backfillObservation,
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
