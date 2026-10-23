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
		file_put_contents($state . '/expected-' . $skin . '.html', FragmentExtractor::extract($full));
		file_put_contents($state . '/actual-' . $skin . '.html', $cold['html']);
		ensure($cold['html'] === FragmentExtractor::extract($full), 'Same HTML bytes as original API extraction: ' . $skin);
		$before = countLines('pipeline.log');
		$hit = fragment('Cache study argument', $skin, $mobile);
		ensure($hit['source'] === 'fragment-cache', 'Prepared fragment is reused: ' . $skin . ' source=' . $hit['source']);
		ensure($hit['html'] === $cold['html'] && $before === countLines('pipeline.log'), 'A hit does not run the output pipeline: ' . $skin);
	}
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
	touch($state . '/short-ttl');
	usleep(1200000);
	ensure(fragment()['source'] === 'parser', 'Fragment TTL expires without extending source lifetime');
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
	operation('jobs');
	$prewarmed = fragment();
	ensure($prewarmed['source'] === 'fragment-cache' && str_contains($prewarmed['html'], 'ARGUMENT_VERSION_TWO'), 'Asynchronous job prepares the new anonymous fragment');
	usleep(1200000);
	operation('template');
	operation('jobs');
	$r = fragment();
	ensure(str_contains($r['html'], 'DEPENDENCY_VERSION_TWO') && !str_contains($r['html'], 'DEPENDENCY_VERSION_ONE'), 'Dependency jobs invalidate and rebuild after a template edit');
	touch($state . '/epoch-changed');
	ensure(fragment()['source'] === 'parser', 'Deployment epoch invalidates an existing file');
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
