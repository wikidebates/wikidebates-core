<?php

namespace MediaWiki\Extension\Wikidebates\Reading {
	// Test-only barrier: pause compression in a separate process, without adding
	// any hooks or delays to production. Other operations use the real zlib call.
	function gzencode(string $data, int $level): string|false {
		if ($GLOBALS['cacheWorkerSettings']['pauseCompression'] ?? false) {
			echo "COMPRESSING\n";
			if (fgets(STDIN) !== "GO\n") { throw new \RuntimeException('Compression barrier closed'); }
		}
		return \gzencode($data, $level);
	}
}
namespace {
	$settings = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
	$GLOBALS['cacheWorkerSettings'] = $settings;
	$directory = $settings['directory'];
	if (!is_dir($directory) || @file_get_contents($directory . '/.cache-concurrency-test') !== 'disposable') {
		throw new RuntimeException('A marked disposable cache directory is required');
	}
	require $settings['storeFile'] ?? dirname(__DIR__, 2) . '/src/Reading/FragmentStore.php';
	$store = new \MediaWiki\Extension\Wikidebates\Reading\FragmentStore($directory, 'concurrency-test', 300, 4194304);
	echo "READY\n";
	if (fgets(STDIN) !== "GO\n") { throw new RuntimeException('Start barrier closed'); }
	$page = $settings['page'] ?? 'A';
	$started = hrtime(true);
	switch ($settings['operation']) {
		case 'read':
			$result = $store->read($page, 'variant', ['revision' => 1]);
			break;
		case 'invalidate':
			$store->invalidate($page);
			$result = true;
			break;
		case 'publish':
			$result = $store->publish($page, 'variant', ['revision' => 1], $settings['generation'],
				$settings['body'] ?? 'new', [], $settings['expires'] ?? time() + 300);
			break;
		case 'benchmark':
			for ($i = 0; $i < $settings['iterations']; $i++) {
				$result = $store->read($page, 'variant', ['revision' => 1]);
				if ($result['entry'] === null || strlen($result['entry']['body']) !== $settings['bodyBytes']) {
					throw new RuntimeException('Benchmark cache miss or corrupt body');
				}
			}
			$result = ['reads' => $settings['iterations']];
			break;
		default:
			throw new RuntimeException('Unknown worker operation');
	}
	echo json_encode(['result' => $result, 'ms' => (hrtime(true) - $started) / 1e6], JSON_THROW_ON_ERROR) . "\n";
}
