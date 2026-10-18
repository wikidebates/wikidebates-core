<?php

// Standalone tests use the same namespace mappings as the extension loader.
$manifest = json_decode(file_get_contents(__DIR__ . '/../extension.json'), true, 512, JSON_THROW_ON_ERROR);
spl_autoload_register(static function(string $class) use ($manifest): void {
	foreach ($manifest['AutoloadNamespaces'] as $prefix => $path) {
		if (str_starts_with($class, $prefix)) {
			$file = __DIR__ . '/../' . $path . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($file)) { require_once $file; }
			return;
		}
	}
});

function same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
	}
}

function check(bool $condition, string $message): void {
	if (!$condition) { throw new RuntimeException($message); }
}

function rejects(callable $operation, string $message): void {
	try { $operation(); }
	catch (RuntimeException $e) { return; }
	throw new RuntimeException($message);
}
