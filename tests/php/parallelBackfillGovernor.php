<?php

require_once __DIR__ . '/../../src/Reading/ParallelBackfillGovernor.php';

use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillGovernor;

$controller = new ParallelBackfillGovernor(4, 10.0, 2048, 2500);
if ($controller->permitted() !== 2) {
	throw new RuntimeException('Initial concurrency must be conservative');
}
for ($i = 0; $i < 3; $i++) {
	$controller->sample(3.0, 12000, 200, 100);
}
if ($controller->permitted() !== 3) {
	throw new RuntimeException('Healthy throughput must increase one worker at a time');
}
$controller->sample(10.1, 12000, 200, 100);
if ($controller->permitted() !== 1) {
	throw new RuntimeException('High load must promptly reduce worker count');
}
$controller->sample(2.0, 1024, 200, 100);
if ($controller->permitted() !== 0) {
	throw new RuntimeException('Insufficient memory must pause all new pages');
}
for ($i = 0; $i < 6; $i++) {
	$controller->sample(2.0, 12000, 200, 100);
}
if ($controller->permitted() !== 2) {
	throw new RuntimeException('Recovery must be gradual');
}
$controller->sample(2.0, 12000, 429, 50);
if ($controller->permitted() !== 0) {
	throw new RuntimeException('HTTP 429 must pause all new pages');
}
$controller->sample(2.0, 12000, 200, 5000);
if ($controller->permitted() !== 0) {
	throw new RuntimeException('Unresponsive public site must remain protected');
}
$controller->sample(null, null, null, null);
if ($controller->permitted() !== 0) {
	throw new RuntimeException('Unavailable health signals must fail closed');
}
echo "OK: automatic concurrency reduction, pause and gradual recovery\n";
