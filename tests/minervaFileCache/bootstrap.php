<?php

// Laboratory only. Never loaded by Wikidebates' manifest.
$studyRoot = getenv('WD_MINERVA_STUDY_ROOT');
if (!$studyRoot || !is_file($studyRoot . '/.wikidebates-minerva-lab')) {
	throw new RuntimeException('A marked, disposable Minerva laboratory is required');
}
define('MW_CONFIG_CALLBACK', 'minervaFileCacheStudySettings');

function minervaFileCacheStudySettings($settings): void {
	$root = getenv('WD_MINERVA_STUDY_ROOT');
	$settings->overrideConfigValues([
		'Server' => 'http://127.0.0.1:' . ($_SERVER['SERVER_PORT'] ?? '8080'),
		'ScriptPath' => '', 'ArticlePath' => '/index.php?title=$1',
		'Sitename' => 'Disposable Minerva cache study', 'LanguageCode' => 'en',
		'DBtype' => 'sqlite', 'DBname' => 'minerva_file_cache_test', 'DBserver' => '',
		'SQLiteDataDir' => $root . '/state', 'SecretKey' => 'disposable-minerva-cache-study-only',
		'CacheDirectory' => $root . '/state', 'UseFileCache' => true,
		'FileCacheDirectory' => $root . '/state/file-cache', 'FileCacheDepth' => 0,
		'UseGzip' => true, 'DisableOutputCompression' => true,
		'MainCacheType' => CACHE_DB, 'ParserCacheType' => CACHE_DB,
		'MessageCacheType' => CACHE_DB, 'SessionCacheType' => CACHE_DB,
		'UseDatabaseMessages' => false, 'JobRunRate' => 0,
		'DefaultSkin' => 'vector', 'ShowExceptionDetails' => true,
		'Hooks' => [
			'ParserAfterParse' => [static function ($parser) {
				if ($parser->getTitle() && $parser->getTitle()->getText() === 'Cache study argument') {
					file_put_contents(getenv('WD_MINERVA_STUDY_ROOT') . '/state/parse.log',
						json_encode(['reason' => $parser->getOptions()->getRenderReason()]) . "\n", FILE_APPEND | LOCK_EX);
					if ($parser->getOptions()->getRenderReason() === 'page-view') {
						file_put_contents(getenv('WD_MINERVA_STUDY_ROOT') . '/state/page-parse.log', "page\n", FILE_APPEND | LOCK_EX);
					}
				}
			}],
			'ArticleFromTitle' => [static function ($title, &$article, $context) {
				if (is_file(getenv('WD_MINERVA_STUDY_ROOT') . '/state/prototype-enabled') &&
					$title->getNamespace() === NS_MAIN && !$article) {
					require_once __DIR__ . '/Prototype.php';
					$article = new MinervaStudyArticle($title);
				}
			}],
			'BeforePageDisplay' => [static function ($out, $skin) {
				file_put_contents(getenv('WD_MINERVA_STUDY_ROOT') . '/state/render.log',
					json_encode(['skin' => $skin->getSkinName(), 'time' => microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);
			}],
			'TitleSquidURLs' => [static function ($title, &$urls) {
				// Follow the core's HTML invalidation pipeline, including dependency purges.
				require_once __DIR__ . '/Prototype.php';
				(new MinervaStudyFileCache($title, 'view'))->clearCache();
			}]
		]
	]);
	wfLoadSkin('Vector');
	wfLoadSkin('MinervaNeue');
	wfLoadExtension('MobileFrontend');
	wfLoadExtension('Wikidebates', dirname(__DIR__, 2) . '/extension.json');
}
