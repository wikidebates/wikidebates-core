<?php
require __DIR__ . '/bootstrap.php';
use MediaWiki\Extension\DebatePHP\I18n;
$queries = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$out = [];
foreach ([false, true] as $optimize) {
	$i = I18n::bundled($optimize); $row = [];
	foreach ($queries as $q) { $row[] = $i->message(...$q); }
	$out[] = $row;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
