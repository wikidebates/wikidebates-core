<?php
require __DIR__ . '/bootstrap.php';
use MediaWiki\Extension\DebatePHP\{Table,ReferenceRenderer,ArgumentReferenceRenderer};
function plain($value) {
	if (!$value instanceof Table) { return $value; }
	$out = array_map('plain', $value->values);
	return $out && array_keys($out) === range(1, count($out)) ? array_values($out) : $out;
}
$out = [];
foreach (json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) as $case) {
	$class = new ReflectionClass($case['module'] === 'Argument' ? ArgumentReferenceRenderer::class : ReferenceRenderer::class);
	$object = $class->newInstanceWithoutConstructor();
	foreach (['ITEM_SEP' => '⟭', 'FIELD_SEP' => '⟬', 'REF_SEP' => '⟭', 'REF_FIELD_SEP' => '⟬', 'INTRO_SUB_SEP' => '⟭⟭⟭', 'INTRO_FIELD_SEP' => '⟬⟬⟬'] as $name => $value) {
		if ($class->hasProperty($name)) { $class->getProperty($name)->setValue($object, $value); }
	}
	$out[] = plain($class->getMethod($case['method'])->invokeArgs($object, $case['args']));
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
