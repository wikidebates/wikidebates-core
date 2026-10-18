<?php

// Offline compiler: parses literal data only, never executes Lua or edits wiki pages.
require_once __DIR__ . '/../debatephp/tools/I18nCompiler.php';

use MediaWiki\Extension\DebatePHP\Maintenance\I18nCompiler;

if ($argc !== 3) {
	fwrite(STDERR, "Usage: php tools/compileI18n.php data.lua logic.lua > I18nData.php\n");
	exit(1);
}
$data = file_get_contents($argv[1]);
$logic = file_get_contents($argv[2]);
if ($data === false || $logic === false) { throw new RuntimeException('Cannot read translation sources'); }
echo I18nCompiler::prepare($data, $logic)['php'];
