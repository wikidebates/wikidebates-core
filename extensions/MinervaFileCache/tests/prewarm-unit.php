<?php

require dirname(__DIR__) . '/src/Prewarm.php';

use MediaWiki\Extension\MinervaFileCache\Prewarm;

$assertions = 0;
function checkPrewarm($ok, string $message): void {
	global $assertions;
	if (!$ok) {
		throw new RuntimeException($message);
	}
	$assertions++;
}

$directory = sys_get_temp_dir() . '/minerva-prewarm-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
try {
	$android = Prewarm::userAgent('android');
	$iphone = Prewarm::userAgent('iphone');
	checkPrewarm(str_contains($android, 'Android') && str_contains($android, 'Mobile'), 'Android UA is mobile');
	checkPrewarm(str_contains($iphone, 'iPhone') && str_contains($iphone, 'Mobile'), 'iPhone UA is mobile');
	try {
		Prewarm::userAgent('desktop');
		throw new RuntimeException('Unsupported device accepted');
	} catch (InvalidArgumentException $e) {
		checkPrewarm(true, 'Unsupported devices rejected');
	}
	checkPrewarm(Prewarm::number('0', '--limit', 0, PHP_INT_MAX) === 0, 'Zero limit accepted');
	checkPrewarm(Prewarm::number('250', '--delay-ms', 250, 60000) === 250, 'Minimum safe delay accepted');
	foreach (['-1', '2.5', 'oops', '92233720368547758080000', ''] as $invalid) {
		try {
			Prewarm::number($invalid, '--limit', 0, PHP_INT_MAX);
			throw new RuntimeException('Invalid number accepted: ' . $invalid);
		} catch (InvalidArgumentException $e) {
			checkPrewarm(true, 'Invalid numbers rejected');
		}
	}
	$path = Prewarm::checkpointPath($directory, 'fr', 'https://fr.example.org', 'fr', 'android', null);
	$other = Prewarm::checkpointPath($directory, 'en', 'https://en.example.org', 'en', 'android', null);
	$iphonePath = Prewarm::checkpointPath($directory, 'fr', 'https://fr.example.org', 'fr', 'iphone', null);
	$nsPath = Prewarm::checkpointPath($directory, 'fr', 'https://fr.example.org', 'fr', 'android', 0);
	checkPrewarm(count(array_unique([$path, $other, $iphonePath, $nsPath])) === 4, 'Checkpoints isolate wikis/devices/namespaces');
	checkPrewarm(Prewarm::readCheckpoint($path) === 0, 'Missing checkpoint starts from zero');
	Prewarm::saveCheckpoint($path, 42);
	checkPrewarm(Prewarm::readCheckpoint($path) === 42, 'Checkpoint roundtrip');
	checkPrewarm((fileperms($path) & 0777) === 0600, 'Private checkpoint permissions');
	Prewarm::saveCheckpoint($path, 73);
	checkPrewarm(Prewarm::readCheckpoint($path) === 73, 'Checkpoint replaced atomically');
	file_put_contents($path, 'invalid');
	try {
		Prewarm::readCheckpoint($path);
		throw new RuntimeException('Malformed checkpoint accepted');
	} catch (RuntimeException $e) {
		checkPrewarm(true, 'Corrupted checkpoint is rejected');
	}
	foreach ([
		[200, 'MISS', 'MISS'], [200, 'HIT', 'HIT'],
		[200, 'BYPASS; reason=nonce', 'BYPASS'], [200, null, 'NO_HEADER'],
		[200, 'unexpected', 'UNKNOWN_HEADER'], [403, 'MISS', 'HTTP_403'],
		[429, null, 'HTTP_429']
	] as [$code, $header, $expected]) {
		checkPrewarm(Prewarm::responseState($code, $header) === $expected,
			'Cache response classified as ' . $expected);
	}
	echo json_encode(['assertions' => $assertions, 'result' => 'PASS']) . "\n";
} finally {
	foreach (glob($directory . '/*') as $path) {
		unlink($path);
	}
	rmdir($directory);
}
