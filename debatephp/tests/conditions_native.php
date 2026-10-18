<?php
/** Executes expressions compiled by the real builders, exclusively for offline tests. */
require __DIR__ . '/bootstrap.php';
use MediaWiki\Extension\DebatePHP\Table;
$out = [];
foreach (json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) as $case) {
	$decode = static fn($value) => is_array($value) ? new Table : $value;
	$a = $decode($case['a']); $b = $decode($case['b']); $trace = [];
	$touch = static function($label, $value) use (&$trace) { $trace[] = $label; return $value; };
	$value = eval('return ' . $case['php'] . ';');
	$out[] = ['value' => $value instanceof Table ? ['test_table' => true] : $value, 'trace' => $trace];
}
echo json_encode($out, JSON_THROW_ON_ERROR);
