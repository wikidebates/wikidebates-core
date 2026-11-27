<?php

/** Real pinned SMW hover integration; no Lua extension is loaded. */
define('WD_FULLSTACK_TEST', true);
putenv('WD_TEST_LANGUAGE=en');
putenv('WD_TEST_EXTENSIONS=SemanticMediaWiki');
require __DIR__ . '/mediawikiEnvironment.php';
require __DIR__ . '/fullstackSupport.php';
require __DIR__ . '/fullstackArgumentMapCases.php';

use MediaWiki\Registration\ExtensionRegistry;
use SMW\Services\ServicesFactory;

$expected = getenv('WD_TEST_ARGUMENTMAP_SMW') ?: '7.3.1';
same($expected, SMW_VERSION, 'Exact modern SMW release');
foreach (['Scribunto', 'SemanticScribunto', 'VariablesLua'] as $name) {
	check(!ExtensionRegistry::getInstance()->isLoaded($name), 'No Lua extension: ' . $name);
}
$store = ServicesFactory::getInstance()->getStore();
check($store instanceof \SMW\SQLStore\SQLStore && $store->setup(false), 'Disposable real SMW store');
$schema = $GLOBALS;
(new \SMW\SetupFile())->loadSchema($schema);
\SMW\Globals::replace($schema);
check(\SMW\Site::isReady(), 'SMW is ready');
$fsUser = \MediaWiki\User\User::newSystemUser('Wikidebates map test', ['steal' => false]);
check($fsUser !== null, 'Synthetic test author');
fsArgumentMapCases();
check(!class_exists(\MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine::class, false), 'No Lua engine loaded');
echo "OK: native ArgumentMap on SMW " . SMW_VERSION . " without Lua\n";
