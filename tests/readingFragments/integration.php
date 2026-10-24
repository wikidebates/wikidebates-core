<?php

require __DIR__ . '/http.php';
require dirname(__DIR__, 2) . '/src/Reading/FragmentExtractor.php';
use MediaWiki\Extension\Wikidebates\Reading\FragmentExtractor;
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab')) { throw new RuntimeException('Disposable wiki required'); }
$state = $root . '/state';
$server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'output_buffering=0',
	'-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php', '-S', '127.0.0.1:8080', '-t', $root . '/mediawiki'],
	[['pipe', 'r'], ['file', $state . '/reading-server.log', 'a'], ['file', $state . '/reading-server.log', 'a']], $pipes);
fclose($pipes[0]);
function fragment(string $page = 'Cache study argument', string $skin = 'vector', bool $mobile = false, array $headers = [], string $lang = 'en'): array {
	$r = request(['api' => 1, 'action' => 'wikidebatesreading', 'page' => $page, 'useskin' => $skin,
		'uselang' => $lang, 'format' => 'json', 'formatversion' => 2], $mobile, $headers);
	$data = json_decode($r['body'], true);
	if (!isset($data['wikidebatesreading'])) { throw new RuntimeException('Fragment API failed: ' . $r['body']); }
	return $data['wikidebatesreading'];
}
try {
	$ready = false;
	for ($i = 0; $i < 50; $i++) {
		$s = @fsockopen('127.0.0.1', 8080, $errno, $error, .1);
		if ($s) { fclose($s); $ready = true; break; } usleep(100000);
	}
	ensure($ready, 'Disposable HTTP server starts');
	foreach ([['vector', false], ['minerva', true]] as [$skin, $mobile]) {
		$parse = request(['api' => 1, 'action' => 'parse', 'page' => 'Cache study argument', 'useskin' => $skin,
			'prop' => 'text', 'usearticle' => 1, 'redirects' => 1, 'formatversion' => 2, 'format' => 'json', 'uselang' => 'en'], $mobile);
		$full = json_decode($parse['body'], true)['parse']['text'];
		ensure(str_contains($full, 'Une référence réelle.'), 'Cite renders the actual reference: ' . $skin);
		$cold = fragment('Cache study argument', $skin, $mobile);
		file_put_contents($state . '/expected-' . $skin . '.html', FragmentExtractor::canonical($full));
		file_put_contents($state . '/actual-' . $skin . '.html', $cold['html']);
		ensure($cold['html'] === FragmentExtractor::canonical($full), 'Same HTML bytes as original API extraction: ' . $skin);
		$before = countLines('pipeline.log');
		$hit = fragment('Cache study argument', $skin, $mobile);
		ensure($hit['source'] === 'fragment-cache', 'Prepared fragment is reused: ' . $skin . ' source=' . $hit['source']);
		ensure($hit['html'] === $cold['html'] && $before === countLines('pipeline.log'), 'A hit does not run the output pipeline: ' . $skin);
	}
	$before = countLines('pipeline.log');
	operation('daily');
	ensure($before === countLines('pipeline.log'), 'Daily preparation skips valid shared fragments');
	// Prime real full-page files, then remove just the derived fragment.
	operation('clear-fragments');
	operation('clear-vector');
	$desktop = request(['title' => 'Cache_study_argument'], false);
	ensure($desktop['status'] === 200, 'A real Vector view creates its native file');
	ensure(fragment()['source'] === 'fragment-cache', 'Full Vector rendering also prepares the excerpt without a second render');
	operation('clear-fragments');
	$before = countLines('pipeline.log');
	$vector = fragment('Cache study argument', 'minerva', true);
	ensure($vector['source'] === 'vector-file', 'Mobile reader first reuses the native Vector file');
	ensure($before === countLines('pipeline.log'), 'Vector file reuse runs no output pipeline');
	operation('clear-fragments');
	$before = countLines('pipeline.log');
	operation('daily');
	ensure($before === countLines('pipeline.log') && fragment()['source'] === 'fragment-cache', 'Daily preparation also reuses a valid Vector source without rendering');
	$mobile = request(['title' => 'Cache_study_argument'], true);
	ensure(cacheState($mobile) === 'MISS', 'A real mobile view creates a Minerva file');
	operation('clear-vector');
	operation('clear-fragments');
	$before = countLines('pipeline.log');
	foreach (['file', 'article'] as $veto) {
		ensure(fragment('Cache study argument', 'vector', false, ['X-Test-Veto: ' . $veto])['source'] === 'parser', 'Full-file source respects extension veto: ' . $veto);
		operation('clear-fragments');
	}
	$before = countLines('pipeline.log');
	$minerva = fragment('Cache study argument', 'vector', false);
	ensure($minerva['source'] === 'minerva-file', 'Desktop reader can reuse a Minerva file');
	ensure($minerva['html'] === $vector['html'], 'Both full-page sources preserve the identical fragment including Cite');
	ensure($before === countLines('pipeline.log'), 'Minerva file reuse runs no output pipeline');
	operation('clear-fragments');
	$blocked = fragment('Cache study argument', 'minerva', true, ['Cookie: custom=1']);
	ensure($blocked['source'] === 'parser', 'Cookies bypass full files as well as fragments');
	operation('clear-vector');
	ensure(fragment('Cache study argument', 'vector', false, [], 'fr')['source'] === 'parser', 'Language variants do not share prepared HTML');
	ensure(fragment('Cache study argument', 'vector', false, ['Cookie: preference=custom'])['source'] === 'parser', 'Cookie-bearing requests bypass shared fragments');
	$variant = request(['api' => 1, 'action' => 'wikidebatesreading', 'page' => 'Cache study argument',
		'format' => 'json', 'useformat' => 'desktop'], false);
	ensure((json_decode($variant['body'], true)['wikidebatesreading']['source'] ?? '') === 'parser',
		'Unrecognized rendering parameters cannot poison a shared variant');
	$jar = [];
	$tokens = request(['api' => 1, 'action' => 'query', 'meta' => 'tokens', 'type' => 'login', 'format' => 'json']);
	$cookie = absorbCookies($jar, $tokens);
	$login = request(['api' => 1], true, ['Cookie: ' . $cookie], ['action' => 'login', 'format' => 'json',
		'lgname' => 'CacheStudyReader', 'lgpassword' => 'Disposable-cache-study-2026!',
		'lgtoken' => json_decode($tokens['body'], true)['query']['tokens']['logintoken']]);
	ensure((json_decode($login['body'], true)['login']['result'] ?? '') === 'Success', 'Laboratory user authenticates');
	$cookie = absorbCookies($jar, $login);
	$editor = fragment('Cache study argument', 'vector', false, ['Cookie: ' . $cookie]);
	ensure($editor['source'] === 'parser' && str_contains($editor['html'], 'EDITOR_ONLY_VERSION'), 'Authenticated HTML keeps its personal transforms');
	ensure(!str_contains(fragment()['html'], 'EDITOR_ONLY_VERSION'), 'Personal HTML never enters the anonymous file');
	ensure(fragment('Uncacheable')['source'] === 'parser' && fragment('Uncacheable')['source'] === 'parser',
		'Parser output with expiry zero is never retained');
	request(['title' => 'Short_lived'], false);
	request(['title' => 'Short_lived'], true);
	usleep(2100000);
	ensure(fragment('Short lived')['source'] === 'parser', 'Dynamic parser lifetime prevents reuse of either full-file source');

	touch($state . '/short-ttl');
	usleep(1200000);
	ensure(fragment()['source'] !== 'fragment-cache', 'Fragment TTL expires without extending source lifetime');
	unlink($state . '/short-ttl');
	touch($state . '/deny-read');
	$denied = request(['api' => 1, 'action' => 'wikidebatesreading', 'page' => 'Cache study argument', 'format' => 'json']);
	ensure(!str_contains($denied['body'], 'ARGUMENT_VERSION_ONE') && isset(json_decode($denied['body'], true)['error']), 'Read authorization is checked before a warm file');
	unlink($state . '/deny-read');
	ensure(fragment('Study redirect')['html'] === fragment()['html'], 'Redirects resolve to an authorized target');
	operation('edit');
	$changed = fragment();
	ensure($changed['source'] === 'parser' && str_contains($changed['html'], 'ARGUMENT_VERSION_TWO'), 'Edit invalidates the prepared fragment immediately');
	operation('purge');
	operation('daily');
	$prewarmed = fragment();
	ensure($prewarmed['source'] === 'fragment-cache' && str_contains($prewarmed['html'], 'ARGUMENT_VERSION_TWO'), 'Explicit preparation prepares the new anonymous fragment');
	usleep(1200000);
	operation('template');
	operation('jobs');
	$r = fragment();
	ensure(str_contains($r['html'], 'DEPENDENCY_VERSION_TWO') && !str_contains($r['html'], 'DEPENDENCY_VERSION_ONE'), 'Dependency jobs invalidate and rebuild after a template edit');
	touch($state . '/epoch-changed');
	ensure(fragment()['source'] !== 'fragment-cache', 'Deployment epoch invalidates an existing file');
	rename($state . '/reading-cache', $state . '/reading-cache-offline');
	try { ensure(str_contains(fragment()['html'], 'ARGUMENT_VERSION_TWO'), 'Unavailable file cache falls back to the parser cache'); }
	finally { rename($state . '/reading-cache-offline', $state . '/reading-cache'); }
	$missing = request(['api' => 1, 'action' => 'wikidebatesreading', 'page' => 'Missing reading page', 'format' => 'json']);
	ensure((json_decode($missing['body'], true)['error']['code'] ?? '') === 'missingtitle', 'Missing-page error remains compatible with reading notice');
	operation('move');
	ensure(str_contains(fragment('Moved study argument')['html'], 'ARGUMENT_VERSION_TWO'), 'Moved page renders current content');
	$report = ['date' => gmdate(DATE_ATOM), 'checks' => $GLOBALS['checks']];
	file_put_contents($state . '/reading-tests.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	echo json_encode(['checks' => count($GLOBALS['checks']), 'result' => 'PASS']) . "\n";
} finally { proc_terminate($server); proc_close($server); }
