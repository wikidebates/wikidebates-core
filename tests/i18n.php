<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../debatephp/tools/I18nCompiler.php';
require_once __DIR__ . '/../tools/I18nCatalogImporter.php';

use MediaWiki\Extension\DebatePHP\I18n;
use MediaWiki\Extension\DebatePHP\MessageCatalog;
use MediaWiki\Extension\DebatePHP\Maintenance\I18nCompiler;
use MediaWiki\Extension\DebatePHP\Maintenance\I18nCatalogImporter;

$root = dirname(__DIR__);
$compiled = require $root . '/debatephp/resources/I18nData.php';
$normalize = static function($value) use (&$normalize) {
	if (!is_array($value)) { return $value; }
	if (!array_is_list($value)) { ksort($value); }
	return array_map($normalize, $value);
};
$files = I18nCatalogImporter::prepare($root, $compiled);
foreach ($files as $path => $data) {
	$current = json_decode(file_get_contents($root . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
	same($normalize($current), $normalize($data), 'Legacy import round trip: ' . $path);
}
foreach ([false, true] as $memoize) {
	$messages = I18n::bundled($memoize);
	same('Modifier', $messages->message('Common.labels', 'fr-ca', 'edit_label'), 'French regional rendering language');
	same('Pro argument', $messages->message('Argument.props', 'en', 'arg_pro'), 'Stable technical property name');
	same('Modifier', $messages->message('Argument.text', 'fr', 'edit_label'), 'Common message alias');
	same('missing', $messages->message('Missing', 'en', 'missing'), 'Unknown key fallback');
}
same('Title then $2', MessageCatalog::substitute('$2 then $1', ['$2', 'Title']), 'Substitution does not reinterpret parameter content');
same('100%: A', MessageCatalog::substitute('100%: $1', ['A']), 'Literal percent is preserved');
same('No parameters', MessageCatalog::substitute('No parameters', [new stdClass]), 'Unused arguments are not converted');
same('TEN', MessageCatalog::substitute('$10', [...array_fill(0, 9, new stdClass), 'TEN']), 'Multi-digit parameters do not consume unused arguments');
same('B / A', sprintf(MessageCatalog::legacyTemplate('$2 / $1'), 'A', 'B'), 'Deferred legacy parameter order');

$temp = sys_get_temp_dir() . '/wikidebates-i18n-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
try {
	foreach (array_keys($files) as $path) {
		if (!is_dir(dirname($temp . '/' . $path))) { mkdir(dirname($temp . '/' . $path), 0700, true); }
		copy($root . '/' . $path, $temp . '/' . $path);
	}
	chmod($temp . '/i18n/fr.json', 0640);
	$source = file_get_contents($root . '/debatephp/resources/WD-I18N-Data.snapshot.lua');
	$source = str_replace('fr = "Modifier"', 'fr = "Modifier importé"', $source);
	$logic = file_get_contents($root . '/debatephp/resources/WD-I18N.reference.lua');
	$candidate = I18nCompiler::prepare($source, $logic);
	I18nCatalogImporter::write($temp, $candidate['compiled']);
	$updated = json_decode(file_get_contents($temp . '/i18n/fr.json'), true, 512, JSON_THROW_ON_ERROR);
	same('Modifier importé', $updated['wikidebates-common-labels-edit-label'], 'Explicit import updates the canonical JSON');
	same(0640, fileperms($temp . '/i18n/fr.json') & 0777, 'Atomic JSON import preserves file permissions');
	same(MessageCatalog::json('resources/wiki-names.json'), json_decode(file_get_contents($temp . '/resources/wiki-names.json'), true, 512, JSON_THROW_ON_ERROR), 'Importing a UI label preserves technical identifiers');
	// Exercise the historical compiler's active-output path on a complete isolated copy.
	foreach (['debatephp/tools/compileI18n.php', 'debatephp/tools/I18nCompiler.php', 'debatephp/src/LiteralData.php',
		'debatephp/resources/WD-I18N.reference.lua', 'debatephp/resources/I18nData.php', 'tools/I18nCatalogImporter.php'] as $path) {
		if (!is_dir(dirname($temp . '/' . $path))) { mkdir(dirname($temp . '/' . $path), 0700, true); }
		copy($root . '/' . $path, $temp . '/' . $path);
	}
	$entryHash = hash_file('sha256', $temp . '/debatephp/resources/I18nData.php');
	file_put_contents($temp . '/candidate.lua', str_replace('Modifier importé', 'Modifier CLI', $source));
	$command = [PHP_BINARY];
	if (php_ini_loaded_file()) { array_push($command, '-c', php_ini_loaded_file()); }
	array_push($command, $temp . '/debatephp/tools/compileI18n.php', $temp . '/candidate.lua', $temp . '/debatephp/resources/I18nData.php');
	$process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	same(0, proc_close($process), 'Historical compiler succeeds: ' . $output);
	same($entryHash, hash_file('sha256', $temp . '/debatephp/resources/I18nData.php'), 'Historical compiler preserves the active compatibility entry point');
	$updated = json_decode(file_get_contents($temp . '/i18n/fr.json'), true, 512, JSON_THROW_ON_ERROR);
	same('Modifier CLI', $updated['wikidebates-common-labels-edit-label'], 'Historical compiler updates canonical messages');
} finally {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
	rmdir($temp);
}
echo "OK: canonical catalogue round trip, regional rendering, stable names, parameters and atomic legacy import\n";
