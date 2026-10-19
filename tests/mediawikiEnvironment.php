<?php

/** Isolated MediaWiki 1.43 integration tests. No existing wiki settings or database is used. */
$install = getenv('MW_INSTALL_PATH');
if (!$install || !is_file($install . '/includes/Setup.php')) {
	fwrite(STDERR, "Set MW_INSTALL_PATH to a MediaWiki 1.43 checkout with vendor/.\n");
	exit(1);
}
if (!extension_loaded('pdo_sqlite')) { throw new RuntimeException('Tests require pdo_sqlite'); }
$temporary = sys_get_temp_dir() . '/wikidebates-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
register_shutdown_function(static function() use ($temporary): void {
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($files as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
	rmdir($temporary);
});
$db = new PDO('sqlite:' . $temporary . '/wikidebates_test.sqlite');
$db->exec(file_get_contents($install . '/maintenance/sqlite/tables-generated.sql'));
$db = null;

define('MEDIAWIKI', true);
define('MW_ENTRY_POINT', 'cli');
define('MW_INSTALL_PATH', realpath($install));
define('MW_CONFIG_CALLBACK', 'wikidebatesTestConfig');
function wikidebatesTestConfig($settings): void {
	global $temporary;
	$settings->overrideConfigValues([
		'Server' => 'https://example.test',
		'ScriptPath' => '/w',
		'ArticlePath' => '/wiki/$1',
		'Sitename' => 'Wikidebates tests',
		'LanguageCode' => getenv('WD_TEST_LANGUAGE') ?: 'en',
		'DBtype' => 'sqlite',
		'DBname' => 'wikidebates_test',
		'SQLiteDataDir' => $temporary,
		'SecretKey' => 'isolated-wikidebates-tests',
		'CacheDirectory' => $temporary,
		'MainCacheType' => CACHE_NONE,
		'MessageCacheType' => CACHE_NONE,
		'ParserCacheType' => CACHE_NONE,
		'UseDatabaseMessages' => false,
		'JobRunRate' => 0,
		'ShowExceptionDetails' => true
	]);
	if (defined('WD_FULLSTACK_TEST')) {
		// These paths always belong to the new temporary SQLite installation above.
		$GLOBALS['smwgConfigFileDir'] = $temporary;
		$GLOBALS['smwgEnabledDeferredUpdate'] = true;
		$GLOBALS['smwgEnableUpdateJobs'] = true;
		$GLOBALS['wgScribuntoDefaultEngine'] = 'luastandalone';
	}
	foreach (array_filter(explode(',', getenv('WD_TEST_EXTENSIONS') ?: '')) as $extension) {
		if (!in_array($extension, ['Scribunto', 'ParserFunctions', 'Cite', 'PageForms', 'ShortDescription', 'Variables', 'VariablesLua', 'WikiSEO', 'HTMLTags', 'SemanticMediaWiki', 'SemanticScribunto'], true)) {
			throw new RuntimeException('Unsupported test extension: ' . $extension);
		}
		wfLoadExtension($extension);
	}
	wfLoadExtension('Wikidebates', dirname(__DIR__) . '/extension.json');
}
if (defined('WD_FULLSTACK_TEST') && getenv('WD_FULLSTACK_AUTOLOAD')) {
	// Optional dependency autoloader for manual isolated SMW installations.
	require getenv('WD_FULLSTACK_AUTOLOAD');
}
require MW_INSTALL_PATH . '/includes/BootstrapHelperFunctions.php';
require MW_INSTALL_PATH . '/includes/Setup.php';
require __DIR__ . '/bootstrap.php';
