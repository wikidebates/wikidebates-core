<?php

// Start a loopback-only PHP server inside an explicitly disposable container.
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab')) {
	throw new RuntimeException('A marked, disposable laboratory is required');
}
$state = $root . '/state';
if (is_file($state . '/prototype-enabled')) {
	throw new RuntimeException('Start with a fresh laboratory state');
}
$server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'zlib.output_compression=0', '-d',
	'auto_prepend_file=' . __DIR__ . '/bootstrap.php', '-S', '127.0.0.1:8080', '-t', $root . '/mediawiki'],
	[['pipe', 'r'], ['file', $state . '/server.log', 'a'], ['file', $state . '/server.log', 'a']], $pipes);
if (!is_resource($server)) { throw new RuntimeException('Cannot start the study server'); }
fclose($pipes[0]);

function ensure($value, string $message): void {
	if (!$value) { throw new RuntimeException($message); }
	$GLOBALS['checks'][] = $message;
}
function request(array $query, bool $mobile = true, array $extraHeaders = [], ?array $post = null, bool $gzip = false): array {
	$ua = $mobile ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36' :
		'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36';
	$options = ['method' => $post === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0,
		'timeout' => 15, 'header' => array_merge(['User-Agent: ' . $ua, 'Accept-Encoding: ' . ($gzip ? 'gzip' : 'identity')], $extraHeaders)];
	if ($post !== null) {
		$options['content'] = http_build_query($post);
		$options['header'][] = 'Content-Type: application/x-www-form-urlencoded';
	}
	$script = isset($query['api']) ? 'api.php' : 'index.php';
	unset($query['api']);
	$start = hrtime(true);
	$body = file_get_contents('http://127.0.0.1:8080/' . $script . '?' . http_build_query($query), false,
		stream_context_create(['http' => $options]));
	$elapsed = (hrtime(true) - $start) / 1e6;
	$headers = [];
	foreach ($http_response_header ?? [] as $header) {
		if (str_contains($header, ':')) {
			[$name, $value] = explode(':', $header, 2);
			$headers[strtolower($name)][] = trim($value);
		}
	}
	$status = (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
	$html = ($headers['content-encoding'][0] ?? '') === 'gzip' ? gzdecode($body) : $body;
	return ['status' => $status, 'headers' => $headers, 'body' => $html, 'bodyBytes' => strlen($body), 'totalMs' => $elapsed];
}
function countLines(string $name): int {
	$p = getenv('WD_MINERVA_STUDY_ROOT') . '/state/' . $name;
	return is_file($p) ? count(file($p)) : 0;
}
function cacheState(array $response): ?string { return $response['headers']['x-wikidebates-lab-cache'][0] ?? null; }
function skin(array $response): string {
	preg_match('/<body[^>]*class="([^"]+)"/', $response['body'], $matches);
	return $matches[1] ?? '';
}
function operation(string $operation): void {
	$process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__ . '/manage.php', $operation],
		[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	ensure(proc_close($process) === 0, 'Disposable operation succeeds: ' . $operation . ' ' . trim($output));
}
function absorbCookies(array &$jar, array $response): string {
	foreach ($response['headers']['set-cookie'] ?? [] as $cookie) {
		[$pair] = explode(';', $cookie, 2);
		[$name, $value] = explode('=', $pair, 2);
		// Honour deletion headers instead of replaying an expired login token.
		if ($value === '' || preg_match('/;\s*Max-Age=0(?:;|$)/i', $cookie)) {
			unset($jar[$name]);
		} else {
			$jar[$name] = $value;
		}
	}
	return implode('; ', array_map(static fn($name) => $name . '=' . $jar[$name], array_keys($jar)));
}
function measure(array $query, bool $prototype): array {
	$renderBefore = countLines('render.log');
	$parseBefore = countLines('page-parse.log');
	$allParsesBefore = countLines('parse.log');
	$samples = [];
	for ($i = 0; $i < 31; $i++) {
		$r = request($query);
		ensure($r['status'] === 200 && str_contains(skin($r), 'skin-minerva'), 'Measured response is a Minerva page');
		if ($prototype) ensure(cacheState($r) === 'HIT', 'Measured prototype read is a file-cache hit');
		$samples[] = $r['totalMs'];
	}
	sort($samples);
	return ['samplesMs' => $samples, 'medianMs' => $samples[15], 'p95Ms' => $samples[29],
		'skinRenders' => countLines('render.log') - $renderBefore,
		'pageParses' => countLines('page-parse.log') - $parseBefore,
		'otherParses' => countLines('parse.log') - $allParsesBefore - (countLines('page-parse.log') - $parseBefore)];
}

try {
	// Wait only for the loopback listening socket; avoid warming the studied page.
	$ready = false;
	for ($i = 0; $i < 50; $i++) {
		$socket = @fsockopen('127.0.0.1', 8080, $errno, $error, 0.1);
		if ($socket) { fclose($socket); $ready = true; break; }
		usleep(100000);
	}
	ensure($ready, 'Study HTTP server starts on loopback');
	$query = ['title' => 'Cache_study_argument'];
	operation('purge');
	$calibrationBefore = countLines('page-parse.log');
	$desktop = request($query, false);
	ensure($desktop['status'] === 200 && str_contains(skin($desktop), 'skin-vector'),
		'Desktop uses Vector: HTTP ' . $desktop['status'] . ' encoding=' . json_encode($desktop['headers']['content-encoding'] ?? []) .
		' prefix=' . bin2hex(substr($desktop['body'], 0, 8)));
	ensure(countLines('page-parse.log') > $calibrationBefore, 'The page-parse counter detects a real cold render');
	$mobile = request($query);
	ensure($mobile['status'] === 200 && str_contains(skin($mobile), 'skin-minerva'),
		'Mobile uses Minerva: HTTP ' . $mobile['status'] . ' ' . substr(strip_tags($mobile['body']), 0, 300));
	ensure(!str_contains($mobile['body'], '<!-- Cached'), 'Baseline mobile does not use the native HTML file cache');
	$baseline = measure($query, false);
	ensure($baseline['skinRenders'] === 31, 'Baseline rebuilds the skin on every measured mobile read');
	ensure($baseline['pageParses'] === 0, 'Baseline measured reads already use the parser cache');
	touch($state . '/prototype-enabled');
	$miss = request($query);
	ensure(cacheState($miss) === 'MISS', 'First prototype mobile read misses its own file cache');
	$hit = request($query);
	ensure(cacheState($hit) === 'HIT', 'Second prototype mobile read hits its own file cache');
	ensure($miss['body'] === $hit['body'], 'File-cache replay preserves the complete Minerva HTML');
	ensure(str_contains(skin($hit), 'skin-minerva'), 'A mobile hit never serves Vector');
	$desktopAgain = request($query, false);
	ensure($desktopAgain['body'] === $desktop['body'], 'The desktop cache is separate and unchanged');
	$cached = measure($query, true);
	ensure($cached['skinRenders'] === 0 && $cached['pageParses'] === 0, 'Mobile file hits skip skin construction and page parsing');
	$compressed = request($query, true, [], null, true);
	ensure(cacheState($compressed) === 'HIT' && ($compressed['headers']['content-encoding'][0] ?? '') === 'gzip' &&
		$compressed['body'] === $hit['body'], 'Gzip cache replay returns the same Minerva HTML: encoding=' .
		json_encode($compressed['headers']['content-encoding'] ?? []) . ' state=' . cacheState($compressed) .
		' prefix=' . bin2hex(substr($compressed['body'], 0, 8)));
	foreach ([['uselang' => 'fr'], ['useskin' => 'minerva'], ['oldid' => '1']] as $extra) {
		$r = request($query + $extra);
		ensure(cacheState($r) !== 'HIT', 'Variant query bypasses mobile file cache: ' . key($extra));
	}
	ensure(cacheState(request($query, true, ['Cookie: studyPreference=custom'])) === 'BYPASS', 'Cookie-bearing requests bypass the prototype');
	ensure(cacheState(request($query, true, [], ['unused' => 'test'])) !== 'HIT', 'POST bypasses the prototype');
	$jar = [];
	$tokenResponse = request(['api' => 1, 'action' => 'query', 'meta' => 'tokens', 'type' => 'login', 'format' => 'json']);
	$cookieHeader = absorbCookies($jar, $tokenResponse);
	$loginToken = json_decode($tokenResponse['body'], true)['query']['tokens']['logintoken'];
	$loginResponse = request(['api' => 1], true, ['Cookie: ' . $cookieHeader], [
		'action' => 'login', 'format' => 'json', 'lgname' => 'CacheStudyReader',
		'lgpassword' => 'Disposable-cache-study-2026!', 'lgtoken' => $loginToken
	]);
	$loginData = json_decode($loginResponse['body'], true);
	$loginResult = $loginData['login']['result'] ?? 'missing';
	ensure($loginResult === 'Success', 'A real laboratory user can log in: ' . $loginResult .
		' HTTP=' . $loginResponse['status'] . ' error=' . ($loginData['error']['code'] ?? 'none') .
		' JSON=' . json_last_error_msg());
	$cookieHeader = absorbCookies($jar, $loginResponse);
	$identity = request(['api' => 1, 'action' => 'query', 'meta' => 'userinfo', 'format' => 'json'], true, ['Cookie: ' . $cookieHeader]);
	$identityData = json_decode($identity['body'], true);
	ensure(($identityData['query']['userinfo']['id'] ?? 0) > 0, 'The bypass test uses an authenticated session: cookies=' .
		implode(',', array_keys($jar)) . ' HTTP=' . $identity['status'] . ' error=' . ($identityData['error']['code'] ?? 'none'));
	ensure(cacheState(request($query, true, ['Cookie: ' . $cookieHeader])) === 'BYPASS', 'Authenticated mobile readers bypass the shared file');
	$redirect = request(['title' => 'Study_redirect']);
	ensure(cacheState($redirect) !== 'HIT', 'Redirects are not served as cached article content');
	operation('edit');
	$edited = request($query);
	ensure(cacheState($edited) === 'MISS' && str_contains($edited['body'], 'ARGUMENT_VERSION_TWO'), 'Page edit invalidates the mobile file and serves new content');
	ensure(cacheState(request($query)) === 'HIT', 'Edited page is cached again');
	operation('purge');
	ensure(cacheState(request($query)) === 'MISS', 'Explicit purge invalidates the mobile variant');
	ensure(cacheState(request($query)) === 'HIT', 'Purged page is cached again');
	operation('template');
	$changed = request($query);
	ensure(cacheState($changed) === 'MISS' && str_contains($changed['body'], 'DEPENDENCY_VERSION_TWO'), 'Template update and refresh jobs invalidate the dependent mobile HTML');
	$report = ['generatedAt' => gmdate(DATE_ATOM), 'php' => PHP_VERSION,
		'methodology' => ['network' => 'Loopback HTTP in a network-none container; synthetic page, no production access.',
			'compression' => 'Timed responses use identity encoding; files stay gzip-compressed. Gzip HTTP replay is checked separately. DisableOutputCompression avoids double encoding by the native file cache and OutputHandler.',
			'baseline' => 'Actual MediaWiki, MobileFrontend and Minerva; parser cache warm; 31 sequential requests.',
			'prototype' => 'Separate mobile HTML file directory; lab-only Article subclass; 31 sequential hits.',
			'limits' => 'No SMW/site templates, physical device, real network or multi-user concurrency benchmark.'],
		'baseline' => $baseline, 'prototype' => $cached,
		'medianReduction' => 1 - $cached['medianMs'] / $baseline['medianMs'],
		'assertions' => count($GLOBALS['checks']),
		'checks' => array_values(array_unique($GLOBALS['checks']))];
	if (is_file($root . '/versions.json')) {
		$report['sources'] = json_decode(file_get_contents($root . '/versions.json'), true, 512, JSON_THROW_ON_ERROR);
	}
	file_put_contents($state . '/minerva-file-cache-study.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
	echo json_encode(['checks' => count($GLOBALS['checks']), 'baselineMedianMs' => $baseline['medianMs'],
		'prototypeMedianMs' => $cached['medianMs'], 'report' => $state . '/minerva-file-cache-study.json']) . "\n";
} finally {
	proc_terminate($server);
	proc_close($server);
}
