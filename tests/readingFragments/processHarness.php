<?php

/** Isolated process barriers shared by tests and the optional benchmark. */
function cacheWorkerStart(string $directory, array $settings): array {
	$process = proc_open([PHP_BINARY, __DIR__ . '/concurrencyWorker.php'],
		[['pipe', 'r'], ['pipe', 'w'], ['file', $directory . '/workers.log', 'a']], $pipes);
	if (!is_resource($process)) { throw new RuntimeException('Cannot start cache worker'); }
	fwrite($pipes[0], json_encode(['directory' => $directory] + $settings, JSON_THROW_ON_ERROR) . "\n");
	$worker = ['process' => $process, 'input' => $pipes[0], 'output' => $pipes[1]];
	try {
		if (cacheWorkerLine($worker) !== 'READY') { throw new RuntimeException('Worker did not reach start barrier'); }
	} catch (Throwable $error) {
		cacheWorkerStop($worker);
		throw $error;
	}
	return $worker;
}
function cacheWorkerGo(array $worker): void { fwrite($worker['input'], "GO\n"); }
function cacheWorkerLine(array $worker): string {
	$read = [$worker['output']];
	$write = $except = [];
	if (stream_select($read, $write, $except, 10) !== 1) { throw new RuntimeException('Worker barrier timed out'); }
	$line = fgets($worker['output']);
	if ($line === false) { throw new RuntimeException('Worker ended before producing a result'); }
	return rtrim($line, "\r\n");
}
function cacheWorkerResult(array &$worker): array {
	$result = json_decode(cacheWorkerLine($worker), true, 512, JSON_THROW_ON_ERROR);
	fclose($worker['input']); fclose($worker['output']);
	$exit = proc_close($worker['process']);
	$worker = [];
	if ($exit !== 0) { throw new RuntimeException('Cache worker failed: ' . $exit); }
	return $result;
}
function cacheWorkerStop(array &$worker): void {
	if (!$worker) { return; }
	proc_terminate($worker['process']);
	fclose($worker['input']); fclose($worker['output']);
	proc_close($worker['process']);
	$worker = [];
}
function cacheTestDirectory(): string {
	$directory = sys_get_temp_dir() . '/wd-reading-concurrency-' . bin2hex(random_bytes(8));
	if (!mkdir($directory, 0700)) { throw new RuntimeException('Cannot create disposable directory'); }
	file_put_contents($directory . '/.cache-concurrency-test', 'disposable');
	return $directory;
}
function cacheTestCleanup(string $directory): void {
	if (@file_get_contents($directory . '/.cache-concurrency-test') !== 'disposable') {
		throw new RuntimeException('Refusing to clean an unmarked directory');
	}
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
	rmdir($directory);
}
