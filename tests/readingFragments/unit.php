<?php

require dirname(__DIR__, 2) . '/src/Reading/FragmentStore.php';
require dirname(__DIR__, 2) . '/src/Reading/FragmentExtractor.php';
use MediaWiki\Extension\Wikidebates\Reading\FragmentStore as Store;
use MediaWiki\Extension\Wikidebates\Reading\FragmentExtractor;

$assertions = 0;
function check($value, string $message): void {
	global $assertions;
	if (!$value) { throw new RuntimeException($message); }
	$assertions++;
}
$dir = sys_get_temp_dir() . '/minerva-store-unit-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
	$metadata = json_decode(file_get_contents(dirname(__DIR__, 2) . '/extension.json'), true, 512, JSON_THROW_ON_ERROR);
	check($metadata['config']['WikidebatesReadingCacheTTL']['value'] === 0,
		'Reading fragments do not expire by default');
	$store = new Store($dir, 'wiki-one', 300, 4096);
	$identity = ['revision' => 1, 'touched' => '20261022000000'];
	$first = $store->read('A', 'android', $identity);
	check($first['entry'] === null, 'Cold read misses');
	$fill = $store->startFill('A');
	check(is_resource($fill) && $store->startFill('A') === false, 'Concurrent cache fills are excluded');
	check($store->publish('A', 'android', $identity, $first['generation'], '<html>Résumé</html>', ['revid' => 1], time() + 300), 'Atomic publication succeeds');
	fclose($fill);
	check($store->read('A', 'android', $identity)['entry']['body'] === '<html>Résumé</html>', 'Body bytes round-trip exactly');
	check($store->read('A', 'iphone', $identity)['entry'] === null, 'User agent variants are isolated');
	check($store->read('A', 'android', ['revision' => 2])['entry'] === null, 'Revision identity invalidates a file');
	$other = new Store($dir, 'wiki-two', 300, 4096);
	check($other->read('A', 'android', $identity)['entry'] === null, 'Wikis are isolated');
	$store->invalidate('A');
	check(!$store->publish('A', 'android', $identity, $first['generation'], 'STALE', [], time() + 300), 'Invalidation prevents an in-flight stale writer from publishing');
	check($store->read('A', 'android', $identity)['entry'] === null, 'Invalidation removes all variants');
	$new = $store->read('A', 'android', $identity);
	check(!$store->publish('A', 'android', $identity, $new['generation'], str_repeat('x', 4097), [], time() + 300), 'Oversize bodies are not cached');
	check($store->publish('A', 'android', $identity, $new['generation'], 'CURRENT', [], time() + 300), 'New generation can publish');
	$paths = glob($dir . '/*/*/*/*.cache');
	$path = $paths[0];
	clearstatcache(true, $path);
	check(filemtime($path) > time(), 'New payload carries an expiration mtime for cheap pruning');
	check($store->prune() === 0 && is_file($path), 'Pruning preserves live payloads');
	check($store->read('A', 'android', $identity)['entry']['body'] === 'CURRENT', 'Pruning cannot alter reading behavior');
	$entry = json_decode(gzdecode(file_get_contents($path)), true);
	$entry['created'] = time() - 301;
	file_put_contents($path, gzencode(json_encode($entry)));
	check($store->read('A', 'android', $identity)['entry'] === null, 'Expired entries miss');
	check($store->prune() === 1 && !is_file($path), 'Cleanup removes expired payloads');
	$store->publish('A', 'android', $identity, $new['generation'], 'CURRENT', [], time() + 300);
	file_put_contents($path, 'corrupt payload');
	@touch($path, time() + 120);
	check($store->read('A', 'android', $identity)['entry'] === null, 'Corrupt entries miss even with a future pruning hint');
	@touch($path, time() - 120);
	check($store->prune() === 1 && !is_file($path), 'Pruning removes due corrupt entries');
	check($store->publish('A', 'android', $identity, $new['generation'], 'LEGACY', [], time() + 300), 'A new payload is available for legacy migration');
	@touch($path, time());
	check($store->prune() === 0, 'Pruning migrates valid legacy payloads without deleting them');
	clearstatcache(true, $path);
	check(filemtime($path) > time(), 'Legacy payload receives an expiration mtime');
	check($store->read('A', 'android', $identity)['entry']['body'] === 'LEGACY', 'Migrated legacy data remains valid');
	foreach (json_decode(file_get_contents(__DIR__ . '/extractor-cases.json'), true) as $case) {
		check(FragmentExtractor::extract($case['html']) === $case['expected'], 'PHP matches the actual JavaScript extractor');
	}
	$alias = new Store($dir . '/../' . basename($dir), 'wiki-one', 300, 4096);
	$snapshot = $alias->read('A', 'canonical-path', $identity);
	check($alias->publish('A', 'canonical-path', $identity, $snapshot['generation'], 'CANONICAL', [], time() + 300),
		'Equivalent noncanonical directory paths still publish atomically');
	$body = '<p>Résumé</p><blockquote>Citation</blockquote><sup class="reference"><a href="#cite_note-1">1</a></sup>' .
		'<ol class="references"><li id="cite_note-1">Référence</li></ol>' .
		'<h3>Justifications</h3><a class="wk-reading-link" data-page="Argument">Justification</a><h3>Objections</h3><p>Objection</p>';
	$vector = '<h2><span id="Summary">Summary</span></h2>' . $body . '<h2><span id="Parent_debates">Parents</span></h2>';
	$mobile = '<section id="mf-section-0"></section><h2 class="section-heading" onclick="mfTempOpenSection(1)">' .
		'<span class="indicator mf-icon"></span><span id="Summary">Summary</span></h2><section id="mf-section-1" class="collapsible-block">' .
		$body . '</section><h2><span id="Parent_debates">Parents</span></h2>';
	check(FragmentExtractor::canonical($vector) === FragmentExtractor::canonical($mobile), 'Skin scaffolding normalizes without losing any reader content');
	check(str_contains(FragmentExtractor::canonical($mobile), 'data-page="Argument"'), 'Reader interactions and attributes survive');
	check(!str_contains(FragmentExtractor::canonical($mobile), 'collapsible-block'), 'No mobile collapsible block requires CSS masking');
	check(FragmentExtractor::canonical(str_replace('<p>Résumé', '<p nonce="x">Résumé', $vector)) === '', 'Nonce-bearing full documents fail closed');
	$long = new Store($dir, 'long-wiki', 99999999, 4096);
	$snapshot = $long->read('A', 'common', $identity);
	$long->publish('A', 'common', $identity, $snapshot['generation'], 'LONG', [], time() + 99999999);
	$entry = $long->read('A', 'common', $identity)['entry'];
	check($entry['expires'] === $entry['created'] + 99999999,
		'Explicit fragment TTL is honored without a fixed 30-day cap');

	// No explicit TTL: even an old file bearing a historical 30-day
	// expiry remains valid until this page is invalidated.
	$unlimited = new Store($dir, 'unlimited-reading', 0, 4096);
	$shot = $unlimited->read('Old', 'common', $identity);
	check($unlimited->publish('Old', 'common', $identity, $shot['generation'],
		'STABLE_FRAGMENT', [], time() + 2), 'Unlimited reading fragment is eligible');
	$oldPath = glob($dir . '/' . hash('sha256', 'unlimited-reading') . '/*/*/*.cache')[0];
	$legacy = json_decode(gzdecode(file_get_contents($oldPath)), true, 512, JSON_THROW_ON_ERROR);
	$legacy['created'] = time() - 86400 * 90;
	$legacy['expires'] = $legacy['created'] + 2592000;
	file_put_contents($oldPath, gzencode(json_encode($legacy, JSON_THROW_ON_ERROR)));
	check($unlimited->read('Old', 'common', $identity)['entry']['body'] === 'STABLE_FRAGMENT',
		'Legacy fragment still loads after its old 30-day deadline');
	check($unlimited->prune() === 0 && is_file($oldPath),
		'Unlimited pruning retains old fragments');
	check($unlimited->read('Old', 'common', ['revision' => 3])['entry'] === null,
		'Unlimited fragments still obey page identity');
	$unlimited->invalidate('Old');
	check($unlimited->read('Old', 'common', $identity)['entry'] === null,
		'Direct page invalidation still removes unlimited fragments');
	try {
		new Store($dir, 'negative-ttl', -1, 4096);
		throw new RuntimeException('Negative TTL accepted');
	} catch (RuntimeException $e) {
		check($e->getMessage() === 'Cache directory or limits are not usable',
			'Negative fragment TTL remains invalid');
	}

	echo json_encode(['assertions' => $assertions, 'result' => 'PASS']) . "\n";
} finally {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
	rmdir($dir);
}
