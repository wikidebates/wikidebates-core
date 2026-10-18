<?php
spl_autoload_register(static function($class) {
	$p = 'MediaWiki\\Extension\\DebatePHP\\';
	if (str_starts_with($class, $p)) { require_once __DIR__ . '/../src/' . substr($class, strlen($p)) . '.php'; }
});
