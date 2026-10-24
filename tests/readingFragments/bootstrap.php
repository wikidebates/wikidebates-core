<?php

$readingResourceStart = getrusage();
$root = getenv('WD_MINERVA_STUDY_ROOT');
if (!$root || !is_file($root . '/.wikidebates-minerva-lab')) {
	throw new RuntimeException('A marked disposable laboratory is required');
}
define('MW_CONFIG_CALLBACK', 'readingFragmentTestSettings');
function readingFragmentTestSettings($settings): void {
	$root = getenv('WD_MINERVA_STUDY_ROOT');
	$settings->overrideConfigValues([
		'Server' => 'http://127.0.0.1:8080', 'ScriptPath' => '', 'ArticlePath' => '/index.php?title=$1',
		'CacheEpoch' => '20200101000000',
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
		'WikidebatesReadingFragments' => !is_file($root . '/state/reading-disabled'),
		'WikidebatesReadingCacheDirectory' => $root . '/state/reading-cache',
		'WikidebatesReadingPrewarm' => true,
		'MinervaFileCacheDirectory' => $root . '/state/minerva-cache',
		'MinervaFileCacheTTL' => 2592000,
		'MinervaFileCacheEnabled' => !is_file($root . '/state/minerva-disabled'),
		'WikidebatesReadingCacheEpoch' => is_file($root . '/state/epoch-changed') ? '2' : '1',
		'WikidebatesReadingCacheTTL' => is_file($root . '/state/short-ttl') ? 1 : 300,
		'Hooks' => [
			'ParserAfterParse' => [static function ($parser) {
				if ($parser->getTitle()->getText() === 'Uncacheable') { $parser->getOutput()->updateCacheExpiry(0); }
				if ($parser->getTitle()->getText() === 'Short lived') { $parser->getOutput()->updateCacheExpiry(1); }
			}],
			'OutputPageBeforeHTML' => [static function ($out, &$html) {
				if ($out->getUser()->isRegistered()) { $html = str_replace('ARGUMENT_VERSION', 'EDITOR_ONLY_VERSION', $html); }
			}],
			'ParserOutputPostCacheTransform' => [static function ($output, &$text, &$options) {
				if ($output->getCacheRevisionId()) {
					file_put_contents(getenv('WD_MINERVA_STUDY_ROOT') . '/state/pipeline.log', "pipeline\n", FILE_APPEND);
				}
			}],
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
	]);
	wfLoadSkin('Vector');
	wfLoadSkin('MinervaNeue');
	wfLoadExtension('MobileFrontend');
	wfLoadExtension('Cite', $root . '/Cite/extension.json');
	wfLoadExtension('MinervaFileCache', dirname(__DIR__, 2) . '/extensions/MinervaFileCache/extension.json');
	wfLoadExtension('Wikidebates', dirname(__DIR__, 2) . '/extension.json');
}

register_shutdown_function(static function () use ($readingResourceStart) {
	if (getenv('WD_READING_BENCHMARK')) {
		$r = getrusage();
		file_put_contents(getenv('WD_MINERVA_STUDY_ROOT') . '/state/resources.jsonl', json_encode([
			'cpu_us' => -($readingResourceStart['ru_utime.tv_sec'] * 1000000 + $readingResourceStart['ru_utime.tv_usec'] + $readingResourceStart['ru_stime.tv_sec'] * 1000000 + $readingResourceStart['ru_stime.tv_usec']) + $r['ru_utime.tv_sec'] * 1000000 + $r['ru_utime.tv_usec'] + $r['ru_stime.tv_sec'] * 1000000 + $r['ru_stime.tv_usec'],
			'peak_bytes' => memory_get_peak_usage(true)]) . "\n", FILE_APPEND);
	}
});
