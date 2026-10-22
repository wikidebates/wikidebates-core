<?php

require __DIR__ . '/http.php';
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab')) {
	throw new RuntimeException('A marked disposable laboratory is required');
}
$state = $root . '/state';
if (is_file($state . '/extension-enabled')) { throw new RuntimeException('Use fresh laboratory state'); }
$server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'zlib.output_compression=0', '-d', 'output_buffering=0',
	'-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php', '-S', '127.0.0.1:8080', '-t', $root . '/mediawiki'],
	[['pipe', 'r'], ['file', $state . '/extension-server.log', 'a'], ['file', $state . '/extension-server.log', 'a']], $pipes);
if (!is_resource($server)) { throw new RuntimeException('Cannot start server'); }
fclose($pipes[0]);
function cacheStatus(array $response): string { return explode(';', cacheState($response) ?? '')[0]; }
try {
	$ready = false;
	for ($i = 0; $i < 50; $i++) {
		$s = @fsockopen('127.0.0.1', 8080, $errno, $error, 0.1);
		if ($s) { fclose($s); $ready = true; break; }
		usleep(100000);
	}
	ensure($ready, 'Loopback server starts');
	$query = ['title' => 'Cache_study_argument'];
	operation('purge');
	$disabled = request($query);
	ensure($disabled['status'] === 200 && str_contains(skin($disabled), 'skin-minerva') && cacheState($disabled) === null,
		'Disabled extension preserves normal mobile output');
	$desktop = request($query, false);
	ensure(str_contains(skin($desktop), 'skin-vector'), 'Desktop uses Vector');
	touch($state . '/extension-enabled');
	$miss = request($query);
	ensure(cacheStatus($miss) === 'MISS', 'First enabled request fills cache: ' . cacheState($miss) . ' HTTP=' . $miss['status']);
	$before = countLines('render.log');
	$hit = request($query);
	ensure(cacheStatus($hit) === 'HIT', 'Next request is a file hit: ' . cacheState($hit));
	ensure($before === countLines('render.log'), 'A hit skips skin rendering');
	ensure($miss['body'] === $hit['body'], 'Complete HTML is unchanged on replay');
	ensure(($hit['headers']['referrer-policy'][0] ?? '') === 'same-origin', 'Response security headers survive replay');
	ensure(str_contains($hit['headers']['cache-control'][0] ?? '', 'private'), 'Shared upstream caches cannot store a hit');
	ensure(request($query, false)['body'] === $desktop['body'], 'Desktop native cache remains separate');
	$gzip = request($query, true, [], null, true);
	ensure(cacheStatus($gzip) === 'HIT' && ($gzip['headers']['content-encoding'][0] ?? '') === 'gzip' &&
		$gzip['body'] === $hit['body'], 'Normal MediaWiki output compression encodes a hit exactly once: state=' .
		cacheState($gzip) . ' encoding=' . json_encode($gzip['headers']['content-encoding'] ?? []) .
		' equal=' . (int)($gzip['body'] === $hit['body']));
	touch($state . '/debug-off');
	$before = countLines('render.log');
	ensure(cacheState(request($query)) === null && countLines('render.log') === $before,
		'Diagnostic headers can be disabled without disabling the cache');
	unlink($state . '/debug-off');
	rename($state . '/mobile-cache', $state . '/mobile-cache-offline');
	try {
		$fallback = request($query);
		ensure($fallback['status'] === 200 && cacheState($fallback) === 'BYPASS; reason=storage-error' &&
			str_contains($fallback['body'], 'ARGUMENT_VERSION_ONE'), 'Unavailable storage falls back to complete normal rendering');
	} finally { rename($state . '/mobile-cache-offline', $state . '/mobile-cache'); }
	ensure(cacheStatus(request($query, true, [], null, false, 'HEAD')) !== 'HIT', 'HEAD never replays a GET body');
	$api = request(['api' => 1, 'action' => 'parse', 'page' => 'Cache study argument', 'prop' => 'text', 'format' => 'json']);
	ensure(cacheState($api) === null && isset(json_decode($api['body'], true)['parse']['text']), 'Parse API remains independent of the view cache');
	foreach (['Another_public_page', 'Talk:Cache_study_argument', 'Category:Study', 'File:Study.svg'] as $title) {
		$r = request(['title' => $title]);
		ensure($r['status'] === 200 && cacheStatus($r) === 'MISS', 'No page allowlist: cache first view of ' . $title . ' ' . cacheState($r));
		ensure(cacheStatus(request(['title' => $title])) === 'HIT', 'Cache subsequent view of ' . $title);
	}
	foreach ([['uselang' => 'fr'], ['useskin' => 'minerva'], ['oldid' => '1'], ['action' => 'render'], ['printable' => 'yes']] as $extra) {
		ensure(cacheStatus(request($query + $extra)) !== 'HIT', 'Special query never shares ordinary view: ' . key($extra));
	}
	foreach (['Cookie: studyPreference=custom', 'Authorization: Bearer laboratory-only', 'Range: bytes=0-100',
		'X-Test-Veto: file', 'X-Test-Veto: article', 'Accept-Language: fr', 'User-Agent: Mozilla/5.0 (Android) Mobile alternate'] as $header) {
		ensure(cacheStatus(request($query, true, [$header])) !== 'HIT', 'Personalization, variants or veto respected: ' . explode(':', $header, 2)[0]);
	}
	ensure(cacheStatus(request($query, true, [], ['unused' => 'test'])) !== 'HIT', 'POST bypasses shared files');
	$jar = [];
	$tokens = request(['api' => 1, 'action' => 'query', 'meta' => 'tokens', 'type' => 'login', 'format' => 'json']);
	$cookie = absorbCookies($jar, $tokens);
	$login = request(['api' => 1], true, ['Cookie: ' . $cookie], ['action' => 'login', 'format' => 'json',
		'lgname' => 'CacheStudyReader', 'lgpassword' => 'Disposable-cache-study-2026!',
		'lgtoken' => json_decode($tokens['body'], true)['query']['tokens']['logintoken']]);
	ensure((json_decode($login['body'], true)['login']['result'] ?? '') === 'Success', 'Laboratory account can log in');
	$cookie = absorbCookies($jar, $login);
	$identity = request(['api' => 1, 'action' => 'query', 'meta' => 'userinfo', 'format' => 'json'], true, ['Cookie: ' . $cookie]);
	ensure((json_decode($identity['body'], true)['query']['userinfo']['id'] ?? 0) > 0, 'Session is genuinely authenticated');
	ensure(cacheStatus(request($query, true, ['Cookie: ' . $cookie])) === 'BYPASS', 'Authenticated visitors bypass files');
	ensure(cacheStatus(request(['title' => 'Uncacheable'])) === 'BYPASS', 'Client-cache veto prevents storing a dynamic response');
	ensure(cacheStatus(request(['title' => 'Study_redirect'])) !== 'HIT', 'Redirect is excluded');
	touch($state . '/deny-read');
	$denied = request($query);
	ensure(cacheStatus($denied) !== 'HIT' && !str_contains($denied['body'], 'ARGUMENT_VERSION_ONE'), 'Read authorization is checked before accessing a warm file');
	unlink($state . '/deny-read');
	touch($state . '/epoch-changed');
	ensure(cacheStatus(request($query)) === 'MISS', 'Deployment epoch invalidates warm files');
	ensure(cacheStatus(request($query)) === 'HIT', 'New epoch fills normally');
	touch($state . '/short-ttl');
	usleep(1200000);
	ensure(cacheStatus(request($query)) === 'MISS', 'Configured TTL expires a file');
	unlink($state . '/short-ttl');
	operation('edit');
	$r = request($query);
	ensure(cacheStatus($r) === 'MISS' && str_contains($r['body'], 'ARGUMENT_VERSION_TWO'), 'Edit invalidates and renders new content');
	ensure(cacheStatus(request($query)) === 'HIT', 'Edited content is cached');
	operation('purge');
	ensure(cacheStatus(request($query)) === 'MISS', 'Explicit purge invalidates mobile file');
	ensure(cacheStatus(request($query)) === 'HIT', 'Purged content is cached again');
	// Core parser-cache timestamps have second precision; separate the template edit
	// from the previous render so this assertion measures dependency invalidation.
	usleep(1200000);
	operation('template');
	$r = request($query);
	ensure(cacheStatus($r) === 'MISS' && str_contains($r['body'], 'DEPENDENCY_VERSION_TWO'),
		'Template jobs invalidate dependent HTML: ' . cacheState($r) . ' new=' . (int)str_contains($r['body'], 'DEPENDENCY_VERSION_TWO'));
	operation('delete');
	$r = request(['title' => 'Another_public_page']);
	ensure(cacheStatus($r) !== 'HIT' && !str_contains($r['body'], 'Independent page Another public page'), 'Deleted page is never served from an existing file');
	operation('move');
	ensure(cacheStatus(request($query)) !== 'HIT', 'Renamed source never serves stale article HTML');
	$r = request(['title' => 'Moved_study_argument']);
	ensure(cacheStatus($r) === 'MISS' && str_contains($r['body'], 'ARGUMENT_VERSION_TWO'), 'Renamed destination renders current content');
	ensure(cacheStatus(request(['title' => 'Moved_study_argument'])) === 'HIT', 'Renamed destination can be cached');
	unlink($state . '/extension-enabled');
	ensure(cacheState(request(['title' => 'Moved_study_argument'])) === null, 'One setting disables serving existing files immediately');
	$report = ['generatedAt' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'mediawiki' => '1.43.11',
		'withWikidebates' => (bool)getenv('WD_MINERVA_WITH_WIKIDEBATES'), 'checks' => $GLOBALS['checks']];
	if (is_file($root . '/versions.json')) {
		$report['sources'] = json_decode(file_get_contents($root . '/versions.json'), true);
	}
	file_put_contents($state . '/extension-tests.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	echo json_encode(['assertions' => count($GLOBALS['checks']), 'result' => 'PASS']) . "\n";
} finally { proc_terminate($server); proc_close($server); }
