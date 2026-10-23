<?php

function ensure($value, string $message): void {
	if (!$value) { throw new RuntimeException($message); }
	$GLOBALS['checks'][] = $message;
}
function request(array $query, bool $mobile = true, array $extraHeaders = [], ?array $post = null, bool $gzip = false, ?string $method = null): array {
	$ua = $mobile ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36' :
		'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36';
	$options = ['method' => $method ?? ($post === null ? 'GET' : 'POST'), 'ignore_errors' => true, 'follow_location' => 0,
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
	$p = getenv('MINERVA_TEST_ROOT') . '/state/' . $name;
	return is_file($p) ? count(file($p)) : 0;
}
function cacheState(array $response): ?string { return $response['headers']['x-minerva-file-cache'][0] ?? null; }
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
