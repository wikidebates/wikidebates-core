<?php

$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab')) {
	throw new RuntimeException('A marked disposable laboratory is required');
}
define('MW_CONFIG_CALLBACK', 'minervaExtensionTestSettings');
function minervaExtensionTestSettings($settings): void {
	$root = getenv('WD_MINERVA_STUDY_ROOT');
	$overrides = [
		'Server' => 'http://127.0.0.1:8080', 'ScriptPath' => '', 'ArticlePath' => '/index.php?title=$1',
		'Sitename' => 'Disposable extension test', 'LanguageCode' => 'en',
		'DBtype' => 'sqlite', 'DBname' => 'minerva_file_cache_test', 'DBserver' => '',
		'SQLiteDataDir' => $root . '/state', 'SecretKey' => 'disposable-extension-test-only',
		'CacheDirectory' => $root . '/state', 'UseFileCache' => true,
		'FileCacheDirectory' => $root . '/state/desktop-cache', 'FileCacheDepth' => 0,
		'UseGzip' => true, 'DisableOutputCompression' => false,
		'MainCacheType' => CACHE_DB, 'ParserCacheType' => CACHE_DB,
		'MessageCacheType' => CACHE_DB, 'SessionCacheType' => CACHE_DB,
		'UseDatabaseMessages' => true, 'JobRunRate' => 0, 'DefaultSkin' => 'vector',
		'ShowExceptionDetails' => true,
		'MinervaFileCacheDirectory' => is_file($root . '/state/storage-unconfigured') ?
			false : $root . '/state/mobile-cache',
		'MinervaFileCacheEpoch' => is_file($root . '/state/epoch-changed') ? '2' : '1',
		'MinervaFileCacheTTL' => is_file($root . '/state/short-ttl') ? 1 : 300,
		'MinervaFileCacheDebugHeaders' => !is_file($root . '/state/debug-off'),
		'Hooks' => [
			'HTMLFileCache::useFileCache' => [static function ($context) {
				return $context->getRequest()->getHeader('X-Test-Veto') !== 'file';
			}],
			'IsFileCacheable' => [static function ($article) {
				return $article->getContext()->getRequest()->getHeader('X-Test-Veto') !== 'article';
			}],
			'getUserPermissionsErrors' => [static function ($title, $user, $action, &$result) {
				if ($action === 'read' && is_file(getenv('WD_MINERVA_STUDY_ROOT') . '/state/deny-read')) {
					$result = 'badaccess-group0';
					return false;
				}
			}],
			'BeforePageDisplay' => [static function ($out, $skin) {
				file_put_contents(getenv('WD_MINERVA_STUDY_ROOT') . '/state/render.log', $skin->getSkinName() . "\n", FILE_APPEND);
				$out->getRequest()->response()->header('Referrer-Policy: same-origin');
				if ($out->getTitle()->getText() === 'Uncacheable') { $out->disableClientCache(); }
			}]
		]
	];
	if (!is_file($root . '/state/extension-enabled')) {
		$overrides['MinervaFileCacheEnabled'] = false;
	}
	$settings->overrideConfigValues($overrides);
	wfLoadSkin('Vector');
	wfLoadSkin('MinervaNeue');
	wfLoadExtension('MobileFrontend');
	wfLoadExtension('MinervaFileCache', dirname(__DIR__) . '/extension.json');
	if (getenv('WD_MINERVA_WITH_WIKIDEBATES')) {
		wfLoadExtension('Wikidebates', dirname(__DIR__, 3) . '/extension.json');
	}
}
