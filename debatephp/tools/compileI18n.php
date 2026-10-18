<?php
// Offline migration only; this literal reader is not autoloaded by MediaWiki.
// php tools/compileI18n.php literal-data.lua resources/I18nData.php
require __DIR__ . '/I18nCompiler.php';
if ($argc !== 3) { fwrite(STDERR, "Usage: php compileI18n.php input.lua output.php\n"); exit(2); }
$source = file_get_contents($argv[1]);
if ($source === false) { fwrite(STDERR, "Cannot read input source\n"); exit(1); }
$logic = file_get_contents(__DIR__ . '/../resources/WD-I18N.reference.lua');
if ($logic === false) { fwrite(STDERR, "Cannot read reference translation logic\n"); exit(1); }
$candidate = \MediaWiki\Extension\DebatePHP\Maintenance\I18nCompiler::prepare($source, $logic);
\MediaWiki\Extension\DebatePHP\Maintenance\I18nCompiler::writeAtomically($argv[2], $candidate['php']);
