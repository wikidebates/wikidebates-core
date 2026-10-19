<?php
require __DIR__ . '/../../tests/bootstrap.php';

use MediaWiki\Extension\DebatePHP\FifoCache;
use MediaWiki\Extension\DebatePHP\Lua;
use MediaWiki\Extension\DebatePHP\RenderData;
use MediaWiki\Extension\DebatePHP\SerializedData;
use MediaWiki\Extension\DebatePHP\Table;

$checks = 0;
// Snapshot semantics include structural mutation, holes and shared nested objects.
$nested = new Table(['name' => 'before']);
$table = new Table([1 => false, 2 => '', 3 => '0', 4 => $nested, 6 => 'after hole']);
$expected = Lua::ipairs($table);
$sequence = $table->sequence();
$table[2] = 'changed'; $table[5] = 'filled'; $nested['name'] = 'after';
same($expected, iterator_to_array($sequence), 'Sequence captures values immediately, keeps nested identity, stops at hole');
$checks++;
mt_srand(806);
for ($sample = 0; $sample < 400; $sample++) {
	$values = [];
	for ($i = 0; $i < 30; $i++) {
		$key = mt_rand(-1, 20);
		$values[$key] = [null, false, '', 0, '0', 'é'][mt_rand(0, 5)];
	}
	$table = new Table($values);
	same(Lua::ipairs($table), iterator_to_array($table->sequence()), 'Sparse/dense sequence equivalence');
	$checks++;
}

// Independent copy of the former Table FIFO algorithm, including nil/duplicate quirks.
$keys = [null, false, true, '', '0', 0, 1, 'a', 'b', 'é'];
foreach ([null, false, -1, 0, 1, 2, 5, '2', 2.5] as $limit) {
	$cache = new FifoCache($limit);
	$data = new Table; $order = new Table;
	for ($operation = 0; $operation < 400; $operation++) {
		$key = $keys[mt_rand(0, count($keys) - 1)];
		$value = [null, false, '', 0, '0', $nested, 'value'][mt_rand(0, 6)];
		if ($data[$key] !== null) { $data[$key] = $value; }
		else {
			$data[$key] = $value;
			Lua::insert($order, $key);
			if (count($order) > $cache->max) {
				$old = Lua::remove($order, 1);
				if ($old !== null) { $data[$old] = null; }
			}
		}
		$alias = $cache;
		$alias->put($key, $value);
		foreach ($keys as $probe) { same($data[$probe], $cache->get($probe), 'FIFO exact behavior and shared identity'); $checks++; }
	}
}

$prepared = new RenderData;
foreach ([null, '', '0', 'a, b,,a', "\0a\0,\v b\f, c ", 'é⟬0⟬é'] as $raw) {
	foreach ([null, ',', '⟬'] as $separator) {
		$expected = SerializedData::csv($raw, $separator)->values;
		$first = $prepared->csv($raw, $separator);
		same($expected, $first->values, 'Prepared CSV output equivalence');
		$first[1] = 'caller mutation';
		same($expected, $prepared->csv($raw, $separator)->values, 'Caller cannot poison prepared CSV');
		$checks += 2;
	}
}
for ($i = 0; $i < 600; $i++) { $prepared->csv('unique,' . $i); }
same(SerializedData::csv('a, b')->values, $prepared->csv('a, b')->values, 'Eviction preserves results');
try { $prepared->csv('a', ''); throw new LogicException('Expected empty separator error'); }
catch (ValueError $e) { $checks++; }
same(['a', 'b'], array_values($prepared->csv('a, b')->values), 'Failed preprocessing does not poison cache');
echo "$checks native collection assertions: snapshots, nil, FIFO eviction, aliases and isolated preprocessing\n";
