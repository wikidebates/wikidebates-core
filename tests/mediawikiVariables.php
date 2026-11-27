<?php

/** Real Variables integration without Scribunto, VariablesLua or a semantic bridge. */
putenv('WD_TEST_EXTENSIONS=Variables');
putenv('WD_TEST_LANGUAGE=en');
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

$registry = ExtensionRegistry::getInstance();
check($registry->isLoaded('Variables'), 'Real Variables extension loaded');
foreach (['Scribunto', 'VariablesLua', 'SemanticScribunto'] as $name) {
	check(!$registry->isLoaded($name), 'Independent of ' . $name);
}
$file = (new ReflectionClass(\ExtVariables::class))->getFileName();
check(str_starts_with(realpath($file), realpath(MW_INSTALL_PATH . '/extensions/Variables') . '/'),
	'Native bridge uses the installed Variables class');

$services = MediaWikiServices::getInstance();
foreach ([false, true] as $optimized) {
	$parser = $services->getParserFactory()->create();
	$seen = [];
	$parser->setFunctionHook('argument', static function($parser) use (&$seen, $optimized): string {
		$context = new MediaWikiContext($parser, $parser->getPreprocessor()->newFrame(), [], $optimized, false, null, 'Argument');
		$seen = [$context->variableGet(' WD FS shared '), $context->variableGet('WD FS zero'),
			$context->variableGet('WD FS absent')];
		$context->variableSet(' WD FS shared ', ' PHP → template ');
		$context->variableSet('WD FS zero', '0');
		$context->variableSet('WD FS empty', '');
		return '';
	});
	$result = $parser->preprocess('{{#vardefine:WD FS shared| template → PHP }}{{#vardefine:WD FS zero|0}}'
		. '{{#argument:probe}}{{#var:WD FS shared|MISSING}}/{{#var:WD FS zero|MISSING}}/{{#var:WD FS empty|EMPTY}}',
		Title::newFromText('WD Variables native'), ParserOptions::newFromAnon());
	same(['template → PHP', '0', ''], $seen, 'PHP sees Variables parser-function state without a Lua extension');
	same('PHP → template/0/EMPTY', $result, 'Template sees native writes including zero and empty-value fallback');

	$fresh = $services->getParserFactory()->create()->preprocess('{{#var:WD FS shared|MISSING}}',
		Title::newFromText('WD Variables isolated'), ParserOptions::newFromAnon());
	same('MISSING', $fresh, 'Native Variables state does not leak between parsers');
}
echo "OK: native Variables integration, template sharing and parser isolation without Lua extensions\n";
