<?php

require_once __DIR__ . '/../../src/Reading/ParallelBackfillGovernor.php';
require_once __DIR__ . '/../../src/Reading/ParallelBackfillFpmStatus.php';

use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillGovernor;
use MediaWiki\Extension\Wikidebates\Reading\ParallelBackfillFpmStatus;

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

$fpmJson = '{"active processes":4,"idle processes":2,"listen queue":0}';
$fpm = ParallelBackfillFpmStatus::parse($fpmJson);
if ($fpm !== ['active' => 4, 'idle' => 2, 'listen_queue' => 0] ||
	ParallelBackfillFpmStatus::parse('{"listen queue":4}') !== null) {
	throw new RuntimeException('Invalid FPM scoreboard was accepted');
}
$fpmController = new ParallelBackfillGovernor(4, 10.0, 2048, 2500);
$fpmController->sample(2.0, 12000, 200, 100, $fpm, true);
if ($fpmController->permitted() !== 2) {
	throw new RuntimeException('Healthy PHP-FPM queue must not block initial workers');
}
$fpmController->sample(2.0, 12000, 200, 100,
	['active' => 4, 'idle' => 0, 'listen_queue' => 2], true);
if ($fpmController->permitted() !== 0) {
	throw new RuntimeException('PHP-FPM listen backlog must stop new cache renders');
}
$fpmController->sample(2.0, 12000, 200, 100, null, true);
if ($fpmController->permitted() !== 0) {
	throw new RuntimeException('Unavailable configured FPM scoreboard must fail closed');
}

$databaseController = new ParallelBackfillGovernor(4, 10.0, 2048, 2500, 500);
$databaseController->sample(2.0, 12000, 200, 100, null, false, 10, true);
if ($databaseController->permitted() !== 2) {
	throw new RuntimeException('Healthy indexed database query must not delay initial renders');
}
$databaseController->sample(2.0, 12000, 200, 100, null, false, 850, true);
if ($databaseController->permitted() !== 0) {
	throw new RuntimeException('Slow PRIMARY queries must pause new render operations');
}
$databaseController->sample(2.0, 12000, 200, 100, null, false, null, true);
if ($databaseController->permitted() !== 0) {
	throw new RuntimeException('Unavailable primary SQL latency must fail closed');
}
echo "OK: automatic concurrency reduction, pause and gradual recovery\n";
