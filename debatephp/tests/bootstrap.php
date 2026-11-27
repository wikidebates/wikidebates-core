<?php
spl_autoload_register(static function($class) {
	$p = 'MediaWiki\\Extension\\DebatePHP\\';
	if (str_starts_with($class, $p)) {
		$name = substr($class, strlen($p));
		$file = in_array($name, ['Table', 'Lua', 'LegacyJson'], true)
			? __DIR__ . '/legacy/' . $name . '.php'
			: __DIR__ . '/../src/' . $name . '.php';
		if (is_file($file)) { require_once $file; }
		return;
	}
	$p = 'MediaWiki\\Extension\\Wikidebates\\Seo\\';
	if (str_starts_with($class, $p)) {
		require_once __DIR__ . '/../../src/Seo/' . substr($class, strlen($p)) . '.php';
	}
});
