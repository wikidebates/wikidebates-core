<?php

/**
 * Configuration for independent HTTP requests in the disposable SMW lab.
 * Loaded using auto_prepend_file before MediaWiki's normal web bootstrap.
 * Never include a production LocalSettings.php here.
 */
$wdHttpRoot = realpath((string)getenv('WD_SEMANTIC_HTTP_ROOT'));
$wdHttpPort = (int)getenv('WD_SEMANTIC_HTTP_PORT');
if (!$wdHttpRoot || !str_starts_with($wdHttpRoot . '/', rtrim(sys_get_temp_dir(), '/') . '/wikidebates-') ||
	!is_file($wdHttpRoot . '/.semantic-http-disposable') ||
	$wdHttpPort < 1024 || $wdHttpPort > 65535 ||
	getenv('WD_SEMANTIC_HTTP_DISPOSABLE') !== '1') {
	throw new RuntimeException('Refusing to run semantic HTTP tests without an isolated, marked lab');
}

header('X-WD-Semantic-Lab-Worker: ' . getmypid());
define('MW_CONFIG_CALLBACK', 'wdSemanticHttpTestSettings');

function wdSemanticHttpTestSettings($settings): void {
	$root = realpath((string)getenv('WD_SEMANTIC_HTTP_ROOT'));
	$socket = getenv('WD_TEST_MARIADB_SOCKET') ?: null;
	$port = (int)getenv('WD_SEMANTIC_HTTP_PORT');

	$settings->overrideConfigValues([
		'Server' => 'http://127.0.0.1:' . $port,
		'ScriptPath' => '',
		'ArticlePath' => '/index.php?title=$1',
		'Sitename' => 'Isolated Wikidebates semantic HTTP test',
		'LanguageCode' => 'en',
		'DefaultSkin' => 'vector',
		'DBtype' => $socket ? 'mysql' : 'sqlite',
		'DBserver' => $socket ? 'localhost:' . $socket : 'localhost',
		'DBuser' => $socket ? 'root' : '',
		'DBpassword' => '',
		'DBname' => (string)getenv('WD_SEMANTIC_HTTP_DB'),
		'SQLiteDataDir' => $root,
		'SecretKey' => 'disposable-semantic-http-lab-2026',
		'CacheDirectory' => $root,
		'CacheEpoch' => '20261120000000',
		'MainCacheType' => CACHE_DB,
		'ParserCacheType' => CACHE_DB,
		'MessageCacheType' => CACHE_DB,
		'SessionCacheType' => CACHE_DB,
		'JobRunRate' => 0,
		'UseFileCache' => true,
		'FileCacheDirectory' => $root . '/semantic-http-full-pages',
		'FileCacheDepth' => 0,
		'WikidebatesReadingFragments' => true,
		'WikidebatesSemanticCacheTracking' => true,
		'WikidebatesReadingCacheDirectory' => $root . '/semantic-http-fragments',
		'WikidebatesReadingCacheTTL' => 300,
		'WikidebatesReadingCacheEpoch' => 'semantic-http-lab-1',
		'ShowExceptionDetails' => true,
		'CookiePrefix' => 'wd_semantic_http_lab',
		'Hooks' => [
			'ParserOutputPostCacheTransform' => [static function ($output, &$text, &$options) use ($root): void {
				file_put_contents($root . '/semantic-http-parser-outputs.jsonl',
					json_encode([
						'pid' => getmypid(),
						'revision' => $output->getCacheRevisionId(),
						'cacheable' => $output->isCacheable(),
						'expiry' => $output->getCacheExpiry(),
						'cacheTime' => $output->getCacheTime(),
						'semanticRevision' => $output->getExtensionData(
							\MediaWiki\Extension\Wikidebates\Reading\SemanticPageCacheHooks::OUTPUT_REVISION
						)
					], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
			}],
			'ParserFirstCallInit' => [static function ($parser): void {
				$parser->setHook('wd-http-parents',
					static function ($input, $args, $parser, $frame): string {
						$root = (string)getenv('WD_SEMANTIC_HTTP_ROOT');
						file_put_contents($root . '/semantic-http-parses.log',
							getmypid() . "\n", FILE_APPEND | LOCK_EX);
						$context = new \MediaWiki\Extension\DebatePHP\MediaWikiContext(
							$parser, $frame, [], false
						);
						$rows = $context->readSemantic(
							\MediaWiki\Extension\DebatePHP\Table::wrap([
								'[[WD HTTP Parent::' . trim($input) . ']]',
								'limit=200',
								'link=none'
							]),
							'cache-tracking'
						);
						return 'WD_HTTP_MATCHES=' . count($rows->rows ?? []);
					}
				);
			}],
			'getUserPermissionsErrors' => [static function ($title, $user, $action, &$result) use ($root) {
				if ($action === 'read' && $title->getPrefixedText() === 'WD HTTP query A' &&
					is_file($root . '/semantic-http-deny-read')) {
					$result = 'badaccess-group0';
					return false;
				}
			}]
		]
	]);

	$GLOBALS['smwgConfigFileDir'] = $root;
	$GLOBALS['smwgEnabledDeferredUpdate'] = true;
	$GLOBALS['smwgEnableUpdateJobs'] = true;
	$GLOBALS['smwgEnabledQueryDependencyLinksStore'] = true;
	$GLOBALS['smwgQueryProfiler'] = 0;
	$GLOBALS['smwgQueryResultCacheType'] = 0;
	$GLOBALS['wgScribuntoDefaultEngine'] = 'luastandalone';

	wfLoadSkin('Vector');
	wfLoadExtension('Scribunto');
	wfLoadExtension('SemanticMediaWiki');
	wfLoadExtension('SemanticScribunto');
	wfLoadExtension('Wikidebates', dirname(__DIR__) . '/extension.json');
}
