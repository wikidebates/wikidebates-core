<?php
require __DIR__ . '/bootstrap.php';
use MediaWiki\Extension\DebatePHP\{SerializedData,ReferenceRenderer,ArgumentReferenceRenderer};
function plain($value) {
	if (!is_array($value)) { return $value; }
	$out = array_map('plain', $value);
	return $out && array_keys($out) === range(1, count($out)) ? array_values($out) : $out;
}
$out = [];
foreach (json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) as $case) {
	$class = new ReflectionClass($case['module'] === 'Argument' ? ArgumentReferenceRenderer::class : ReferenceRenderer::class);
	$object = $class->newInstanceWithoutConstructor();
	foreach (['ITEM_SEP' => '⟭', 'FIELD_SEP' => '⟬', 'REF_SEP' => '⟭', 'REF_FIELD_SEP' => '⟬', 'INTRO_SUB_SEP' => '⟭⟭⟭', 'INTRO_FIELD_SEP' => '⟬⟬⟬'] as $name => $value) {
		if ($class->hasProperty($name)) { $class->getProperty($name)->setValue($object, $value); }
	}
	// Oracle names remain the original Lua names; exercise the actual native
	// production consumers rather than restoring retired renderer wrappers.
	$method = $case['method'];
	$formats = ['wkParseBiblioMarkers' => 'bibliography', 'wkParseWeblioMarkers' => 'webliography',
		'wkParseVideoMarkers' => 'videography', 'wkParseQuoteMarkers' => 'quotes'];
	$value = match (true) {
		isset($formats[$method]) => $class->getMethod('nativeReferenceValues')->invoke($object, $formats[$method], ...$case['args']),
		$method === 'splitCSV' => $class->getMethod('csvValues')->invokeArgs($object, $case['args']),
		$method === 'splitByLiteral' => SerializedData::split(...$case['args']),
		default => $class->getMethod($method)->invokeArgs($object, $case['args'])
	};
	$out[] = plain($value);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
