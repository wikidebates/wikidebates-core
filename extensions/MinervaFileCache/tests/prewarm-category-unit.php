<?php

/** Isolated category graph and pagination tests: no MediaWiki installation needed. */

define('NS_CATEGORY', 14);
require dirname(__DIR__) . '/src/Prewarm.php';

use MediaWiki\Extension\MinervaFileCache\Prewarm;

class PrewarmCategoryTestDatabase {
	public array $pages = [];
	public array $links = [];

	public function page(int $id, int $namespace, string $title, bool $redirect = false): void {
		$this->pages[$id] = (object)[
			'page_id' => $id, 'page_namespace' => $namespace,
			'page_title' => $title, 'page_is_redirect' => (int)$redirect,
		];
	}

	public function link(int $id, string $category, string $type = 'page'): void {
		$this->links[] = ['cl_from' => $id, 'cl_to' => $category, 'cl_type' => $type];
	}

	public function select($tables, $fields, $conditions, $caller, $options, $joins): array {
		if ($tables !== ['categorylinks', 'page'] ||
			!isset($joins['page']) || $joins['page'][1] !== 'page_id = cl_from') {
			throw new RuntimeException('Expected categorylinks to page join');
		}
		$afterId = 0;
		foreach ($conditions as $key => $value) {
			if (is_int($key) && preg_match('/^page_id > ([0-9]+)$/', $value, $matches)) {
				$afterId = (int)$matches[1];
			}
		}
		$categories = (array)$conditions['cl_to'];
		$rows = [];
		foreach ($this->links as $link) {
			if (!in_array($link['cl_to'], $categories, true)) {
				continue;
			}
			if (isset($conditions['cl_type']) && $link['cl_type'] !== $conditions['cl_type']) {
				continue;
			}
			$page = $this->pages[$link['cl_from']] ?? null;
			if (!$page || $page->page_id <= $afterId ||
				(isset($conditions['page_namespace']) && $page->page_namespace !== $conditions['page_namespace']) ||
				(isset($conditions['page_is_redirect']) && $page->page_is_redirect !== $conditions['page_is_redirect'])) {
				continue;
			}
			$rows[] = $page;
		}
		usort($rows, static fn($a, $b) => $a->page_id <=> $b->page_id);
		if (in_array('DISTINCT', $options, true)) {
			$unique = [];
			foreach ($rows as $row) {
				$unique[$row->page_id] = $row;
			}
			$rows = array_values($unique);
		}
		return array_slice($rows, 0, (int)$options['LIMIT']);
	}
}

$checks = 0;
function assertCategory($condition, string $message): void {
	global $checks;
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$checks++;
}

$db = new PrewarmCategoryTestDatabase();
$db->page(1, NS_CATEGORY, 'Child');
$db->page(2, NS_CATEGORY, 'Grandchild');
$db->page(3, NS_CATEGORY, 'Root');
$db->page(10, 0, 'A');
$db->page(11, 0, 'B');
$db->page(12, 6, 'File');
$db->page(13, 0, 'Redirect', true);
$db->page(14, 0, 'Unrelated');
$db->link(1, 'Root', 'subcat');
$db->link(2, 'Child', 'subcat');
$db->link(3, 'Grandchild', 'subcat'); // Cycle back to Root.
$db->link(10, 'Root');
$db->link(10, 'Child');
$db->link(11, 'Child');
$db->link(12, 'Grandchild', 'file');
$db->link(13, 'Root');

$direct = Prewarm::categories($db, 'Root', false);
assertCategory($direct === ['Root'], 'Direct mode includes only the selected category');

$recursive = Prewarm::categories($db, 'Root', true);
assertCategory($recursive === ['Root', 'Child', 'Grandchild'], 'Nested categories traverse without loops');

$directPages = Prewarm::categoryPageBatch($db, $direct, 0, 0, 100);
assertCategory(array_map(static fn($row) => $row->page_id, $directPages) === [10],
	'Only direct member is included; redirects excluded');

$all = Prewarm::categoryPageBatch($db, $recursive, 0, null, 100);
assertCategory(array_map(static fn($row) => $row->page_id, $all) === [1, 2, 3, 10, 11, 12],
	'Recursive mode includes all namespaces and deduplicates member pages');

$filtered = Prewarm::categoryPageBatch($db, $recursive, 0, 0, 100);
assertCategory(array_map(static fn($row) => $row->page_id, $filtered) === [10, 11],
	'Namespace filter can be combined with recursive categories');

$after = Prewarm::categoryPageBatch($db, $recursive, 10, 0, 100);
assertCategory(array_map(static fn($row) => $row->page_id, $after) === [11],
	'Resume cursor excludes already processed IDs');

$checkpoint = fn(?string $category, bool $recurse) =>
	Prewarm::checkpointPath('/tmp', 'wiki-a', 'https://example.test', 'en', 'android', 0, $category, $recurse);
assertCategory(count(array_unique([
	$checkpoint(null, false), $checkpoint('Root', false),
	$checkpoint('Root', true), $checkpoint('Other', true),
])) === 4, 'Category/mode/wide-wiki checkpoints cannot overwrite one another');

$bulk = new PrewarmCategoryTestDatabase();
for ($id = 1; $id <= 225; $id++) {
	$key = 'Child_' . $id;
	$bulk->page($id, NS_CATEGORY, $key);
	$bulk->link($id, 'Root', 'subcat');
	$bulk->page($id + 1000, 0, 'Article_' . $id);
	$bulk->link($id + 1000, $key);
}
$many = Prewarm::categories($bulk, 'Root', true);
assertCategory(count($many) === 226, 'Recursive traversal paginates more than 100 subcategories');
$batch = Prewarm::categoryPageBatch($bulk, $many, 0, 0, 100);
assertCategory(count($batch) === 100 && $batch[0]->page_id === 1001 &&
	$batch[99]->page_id === 1100, 'Category chunks merge into a sorted, bounded batch');
$next = Prewarm::categoryPageBatch($bulk, $many, 1100, 0, 100);
assertCategory(count($next) === 100 && $next[0]->page_id === 1101 &&
	$next[99]->page_id === 1200, 'Next batch does not skip members at a chunk boundary');
$last = Prewarm::categoryPageBatch($bulk, $many, 1200, 0, 100);
assertCategory(count($last) === 25 && $last[24]->page_id === 1225,
	'Pagination finishes without duplicate or missing pages');

echo json_encode(['assertions' => $checks, 'result' => 'PASS']) . "\n";
