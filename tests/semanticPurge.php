<?php

/** Standalone orchestration tests. SQL/core adapters are doubles, not a MW lab. */
namespace {
	class Job {
		protected array $params;
		protected $title;
		protected bool $removeDuplicates = false;
		public string $error = '';
		public function __construct($type, $title, array $params) { $this->title = $title; $this->params = $params + ['namespace' => 0, 'title' => $title->getPrefixedDBkey()]; }
		public function getDeduplicationInfo() { $params = $this->params; unset($params['requestId']); ksort($params); return ['type' => 'wikidebatesSemanticCachePurge', 'params' => $params]; }
		public function setLastError($error): void { $this->error = $error; }
		public function getParams(): array { return $this->params; }
		public function ignoreDuplicates(): bool { return $this->removeDuplicates; }
		public function allowRetries(): bool { return true; }
	}
	function check($condition, string $message): void {
		if (!$condition) { throw new \RuntimeException($message); }
	}
}
namespace SMW\DataItems {
	class Property { public function __construct($name) {} }
	class WikiPage {
		public function __construct(private string $title, private int $namespace = 0) {}
		public function getSha1(): string { return sha1($this->namespace . ':' . $this->title); }
	}
}
namespace SMW\SQLStore {
	class SQLStore {
		public const ID_TABLE = 'smw_object_ids';
		public const QUERY_LINKS_TABLE = 'smw_query_links';
		public function __construct(public $db) {}
		public function getConnection($name) { return $this->db; }
		public function getPropertyTableInfoFetcher() { return new class { public function findTableIdForProperty($p) { return 0; } }; }
		public function getPropertyTables() { return [new class { public function getName() { return 'smw_ask'; } }]; }
	}
}
namespace SMW\Services {
	class ServicesFactory {
		public static $store;
		public static function getInstance() { return new self; }
		public function getStore() { return self::$store; }
	}
}
namespace MediaWiki\Logger {
	class LoggerFactory {
		public static array $events = [];
		public static function getInstance($channel) { return new self; }
		public function error($message, $context) { self::$events[] = ['error', $context]; }
		public function info($message, $context) { self::$events[] = ['info', $context]; }
	}
}
namespace MediaWiki\Title {
	class Title {
		public function __construct(public int $id, private string $name) {}
		public static function newFromRow($row) { return new self((int)$row->page_id, $row->page_title); }
		public function canExist(): bool { return true; }
		public function getPrefixedDBkey(): string { return $this->name; }
		public function invalidateCache(): void { \Lab::$touches[$this->id] = (\Lab::$touches[$this->id] ?? 0) + 1; }
	}
}
namespace MediaWiki {
	class MediaWikiServices {
		public static $instance;
		public static function getInstance() { return self::$instance; }
	}
}
namespace MediaWiki\Extension\Wikidebates\Reading {
	class SemanticCacheHooks {
		public static function changedIds(array $ids): array {
			$out = [];
			foreach ($ids as $id) {
				if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0) { $out[(int)$id] = (int)$id; }
			}
			return array_values($out);
		}
	}
	class SemanticRevisionStore { public const TABLE = 'wd_smw_entity_revisions'; }
	class FragmentService {
		public static $store;
		public static function store() { return self::$store; }
	}
}
namespace {
	use MediaWiki\Extension\Wikidebates\Reading\SemanticCachePurgeJob;
	use MediaWiki\Extension\Wikidebates\Reading\SemanticPurgeState;
	use MediaWiki\Extension\Wikidebates\Reading\FragmentService;
	use MediaWiki\Title\Title;

	// Standalone doubles intentionally do not initialize MediaWiki autoloading.
	require __DIR__ . '/../src/Reading/SemanticPurgeScope.php';
	require __DIR__ . '/../src/Reading/SemanticCachePurgeJob.php';
	require __DIR__ . '/../src/Reading/SemanticPurgeState.php';

	class Lab {
		public static array $touches = [];
		public array $pages = [], $queries = [], $links = [], $revisions = [], $receipts = [];
		public int $reads = 0, $writes = 0;
		public bool $schema = true, $failRead = false, $failWrite = false;
		public function newPrimarySelectQueryBuilder() { return new Builder($this); }
		public function tableExists($table, $caller): bool { return $this->schema; }
		public function escape_bytea($value) { return $value; }
		public function newReplaceQueryBuilder() { return new Replacement($this); }
		public function storeReceipts($rows): void {
			if ($this->failWrite) { throw new \RuntimeException('SQL write failure'); }
			$this->writes++;
			foreach ($rows as $row) { $this->receipts[$row['page_id']] = (object)$row; }
		}
		public function seed(int $queries, int $pages = 1): void {
			for ($id = 1; $id <= $pages; $id++) {
				$this->pages[$id] = (object)['page_id' => $id, 'page_namespace' => 0, 'page_title' => 'Page_' . $id, 'page_latest' => $id];
			}
			for ($q = 1; $q <= $queries; $q++) {
				$this->queries[$q] = ['page' => (int)ceil($q / max(1, $queries / $pages)), 'profile' => true];
				$this->links[$q] = [100, 200];
			}
			$this->revisions = [100 => '1', 200 => '1'];
		}
	}
	class Replacement {
		private array $rows = [];
		public function __construct(private Lab $db) {}
		public function replaceInto($table) { check($table === SemanticPurgeState::TABLE, 'Receipt table'); return $this; }
		public function uniqueIndexFields($keys) { check($keys === ['page_id'], 'Receipt primary key'); return $this; }
		public function rows($rows) { $this->rows = $rows; return $this; }
		public function caller($caller) { return $this; }
		public function execute(): void { $this->db->storeReceipts($this->rows); }
	}
	class Builder {
		private string $table = '', $alias = '';
		private array $conditions = [];
		private int $limit = PHP_INT_MAX;
		public function __construct(private Lab $db) {}
		public function from($table, $alias = '') { $this->table = $table; $this->alias = $alias; return $this; }
		public function where($conditions) { $this->conditions = $conditions; return $this; }
		public function limit($limit) { $this->limit = $limit; return $this; }
		public function __call($name, $args) { return $this; }
		public function fetchResultSet(): array {
			$this->db->reads++;
			if ($this->db->failRead) { throw new \RuntimeException('SQL read failure'); }
			$c = $this->conditions; $db = $this->db; $rows = [];
			if ($this->table === 'smw_query_links') {
				$cursor = (int)substr($c[0], strlen('s_id > '));
				foreach ($db->links as $qid => $deps) {
					if ($qid > $cursor && array_intersect($c['o_id'], $deps)) { $rows[] = (object)['s_id' => $qid]; }
				}
				usort($rows, static fn($a, $b) => $a->s_id <=> $b->s_id);
				return array_slice($rows, 0, $this->limit);
			}
			if ($this->table === 'smw_object_ids' && $this->alias === 'query') {
				foreach ($c['query.smw_id'] as $qid) {
					$p = $db->queries[$qid]['page'] ?? 0;
					if (isset($db->pages[$p])) { $row = clone $db->pages[$p]; $row->purge_query_id = $qid; $rows[] = $row; }
				}
				return $rows;
			}
			if ($this->table === 'smw_object_ids' && $this->alias === 'subject') {
				foreach ($db->queries as $qid => $query) {
					$p = $db->pages[$query['page']] ?? null;
					if (!$p || !$query['profile'] || !in_array(sha1('0:' . $p->page_title), $c['subject.smw_hash'], true)) { continue; }
					foreach ($db->links[$qid] ?: [null] as $dep) {
						$rows[] = (object)['smw_title' => $p->page_title, 'smw_namespace' => 0, 'query_id' => $qid,
							'dependency_id' => $dep, 'dependency_revision' => $db->revisions[$dep] ?? null];
					}
				}
				return $rows;
			}
			if ($this->table === 'page') { return array_values(array_intersect_key($db->pages, array_flip($c['page_id']))); }
			if ($this->table === SemanticPurgeState::TABLE) { return array_values(array_intersect_key($db->receipts, array_flip($c['page_id']))); }
			if ($this->table === 'wd_smw_entity_revisions') {
				foreach ($c['entity_id'] as $id) {
					if (isset($db->revisions[$id])) { $rows[] = (object)['entity_id' => $id, 'revision' => $db->revisions[$id]]; }
				}
				return $rows;
			}
			throw new \RuntimeException('Unexpected table: ' . $this->table);
		}
	}
	class Fragments {
		public array $generations = [], $entries = [];
		public int $invalidations = 0;
		public bool $fail = false;
		public function read($title, $variant, $identity) { return ['generation' => $this->generations[$title] ??= 'initial-' . $title]; }
		public function invalidate($title): void {
			if ($this->fail) { throw new \RuntimeException('Fragment invalidation failure'); }
			$this->generations[$title] = 'generation-' . ++$this->invalidations;
			unset($this->entries[$title]);
		}
	}
	class Services {
		public array $settings = ['WikidebatesSemanticCacheTracking' => true, 'WikidebatesSemanticCacheEagerPurge' => true,
			'WikidebatesSemanticCachePurgeBatchSize' => 1000, 'WikidebatesSemanticCachePurgeLog' => true];
		public array $queue = [], $parser = [], $html = [], $minerva = [], $cdn = [];
		public $duringPurge = null;
		public bool $failHtml = false;
		public const PURGE_NAIVE = 0;
		public function getMainConfig() { return $this; }
		public function has($key) { return array_key_exists($key, $this->settings); }
		public function get($key) { return $this->settings[$key]; }
		public function getWikiPageFactory() { return $this; }
		public function newFromTitle($title) { return $title; }
		public function getParserCache() { return $this; }
		public function deleteOptionsKey($title): void { unset($this->parser[$title->id]); }
		public function getHtmlCacheUpdater() { return $this; }
		public function purgeTitleUrls($titles, $flags): void {
			check($flags === self::PURGE_NAIVE, 'Purge dispatch is synchronous before receipt');
			if ($this->failHtml) { throw new \RuntimeException('HTML failure'); }
			foreach ($titles as $title) {
				unset($this->html[$title->id], $this->minerva[$title->id]);
				$this->cdn[] = $title->id;
				// Model the real TitleSquidURLs reading-cache hook.
				FragmentService::store()->invalidate($title->getPrefixedDBkey());
			}
			if ($this->duringPurge) { ($this->duringPurge)(); }
		}
		public function getJobQueueGroup() { return $this; }
		public function push($job): void {
			foreach ($this->queue as $queued) {
				if ($queued->getDeduplicationInfo() === $job->getDeduplicationInfo()) { return; }
			}
			$this->queue[] = $job;
		}
	}
	function setup(int $queries = 6, int $pages = 2): array {
		$db = new Lab; $db->seed($queries, $pages); $services = new Services; $fragments = new Fragments;
		\SMW\Services\ServicesFactory::$store = new \SMW\SQLStore\SQLStore($db);
		\MediaWiki\MediaWikiServices::$instance = $services;
		FragmentService::$store = $fragments;
		Lab::$touches = [];
		foreach ($db->pages as $id => $row) {
			$services->parser[$id] = $services->html[$id] = $services->minerva[$id] = 'stale';
			$fragments->entries[$row->page_title] = 'stale';
		}
		return [$db, $services, $fragments];
	}
	function job(array $ids = [100], int $cursor = 0, array $extra = []): SemanticCachePurgeJob {
		return new SemanticCachePurgeJob(new Title(99, 'Source'), $extra + ['dependencyIds' => $ids, 'cursor' => $cursor]);
	}
	function drain(Services $services): array {
		$metrics = ['jobs' => 0, 'invalidations' => 0, 'continuations' => 0];
		while ($services->queue) {
			$job = array_shift($services->queue);
			check($job->run(), 'Continuation succeeds');
			$metrics['jobs']++;
			foreach (['invalidations', 'continuations'] as $key) { $metrics[$key] += $job->getMetrics()[$key]; }
			check($metrics['jobs'] < 10000, 'Finite queue converges');
		}
		return $metrics;
	}

	[$db, $services, $fragments] = setup();
	$a = job([100]); check($a->run(), 'First overlapping job');
	check($a->getMetrics()['invalidations'] === 2 && !$fragments->entries && !$services->parser && !$services->html && !$services->minerva,
		'All model cache layers invalidated once per distinct page');
	$b = job([100, 200]); check($b->run(), 'Second overlapping job');
	check($b->getMetrics()['coalesced_pages'] === 2 && $b->getMetrics()['invalidations'] === 0, 'Overlap reuses exact completed state');
	$db->revisions[100] = '2'; $c = job(); check($c->run() && $c->getMetrics()['invalidations'] === 2, 'Next same-second semantic revision is never suppressed');
	$db->revisions[100] = '3'; $services->duringPurge = static function() use ($db) { $db->revisions[100] = '4'; };
	$previousReceipts = $db->receipts;
	check(job()->run(), 'Mutation during purge handled');
	check($db->receipts === $previousReceipts, 'Mutation during purge records no new completion');
	$services->duringPurge = null; $c = job(); check($c->run() && $c->getMetrics()['invalidations'] === 2, 'Interrupted revision was not certified');

	[$db, $services] = setup(6, 2); $services->settings['WikidebatesSemanticCachePurgeBatchSize'] = 2;
	$first = job([100], 2); check($first->run(), 'Legacy job resumes at original cursor');
	check($services->queue[0]->getParams()['cursor'] === 4, 'Continuation cursor advances without resetting');
	check($first->getMetrics()['queries_examined'] === 3, 'One-row lookahead');
	$tail = drain($services); check($tail['jobs'] === 1 && $tail['continuations'] === 0, 'Exact final batch needs no empty continuation');

	[$db, $services] = setup(6, 1); $services->settings['WikidebatesSemanticCachePurgeBatchSize'] = 2;
	$services->push(job()); $result = drain($services);
	check($result === ['jobs' => 3, 'invalidations' => 1, 'continuations' => 2], 'Queries on same page across batches purge page only once');
	$none = job([999]); check($none->run() && $none->getMetrics()['pages'] === 0 && !$services->queue, 'Unreferenced dependencies finish without successors');

	[$db, $services, $fragments] = setup(); $db->failRead = true;
	check(!job()->run() && !$db->receipts && !$services->queue, 'SQL failure neither records completion nor queues successor');
	$db->failRead = false; $fragments->fail = true;
	check(!job()->run() && !$db->receipts && !$services->queue, 'Fragment failure is not swallowed');
	$fragments->fail = false; $services->failHtml = true;
	check(!job()->run() && !$db->receipts, 'HTML failure is not certified');
	$services->failHtml = false; $db->failWrite = true;
	check(!job()->run() && !$db->receipts, 'Receipt SQL failure retries conservatively');
	$db->failWrite = false; check(job()->run(), 'Retry from unchanged cursor completes');

	[$db, $services] = setup(); $store = \SMW\Services\ServicesFactory::$store;
	$r1 = SemanticPurgeState::revisions($store, [100, 200]); $token1 = SemanticPurgeState::revisionToken($r1, [200, 100]);
	check($token1 === SemanticPurgeState::revisionToken($r1, [100, 200]), 'Revision vector canonicalization');
	$db->revisions[100] = '2'; $token2 = SemanticPurgeState::revisionToken(SemanticPurgeState::revisions($store, [100, 200]), [100, 200]);
	$services->push(job([100, 200], 0, ['semanticRevision' => $token1]));
	$services->push(job([200, 100], 0, ['semanticRevision' => $token1]));
	$services->push(job([100, 200], 0, ['semanticRevision' => $token2]));
	$services->push(job([100, 200], 2, ['semanticRevision' => $token1]));
	check(count($services->queue) === 3, 'Queue deduplicates only same revision AND cursor');
	$otherTitle = new SemanticCachePurgeJob(new Title(98, 'Other_source'), ['dependencyIds' => [100, 200], 'cursor' => 0, 'semanticRevision' => $token1]);
	check($otherTitle->getDeduplicationInfo() === $services->queue[0]->getDeduplicationInfo(), 'New jobs can share identical work across source titles');

	[$db, $services, $fragments] = setup(); check(job()->run(), 'Seed receipt');
	$db->queries[1]['profile'] = false;
	$orphan = job(); check($orphan->run() && $orphan->getMetrics()['invalidations'] === 1, 'Missing _ASK ownership cannot reuse receipt');
	$db->queries[1]['profile'] = true; $db->links[1] = [100, 201]; $db->revisions[201] = '1';
	$changed = job(); check($changed->run() && $changed->getMetrics()['invalidations'] === 1, 'Same-size graph substitution changes fingerprint');
	$fragments->invalidate('Page_2'); $local = job();
	check($local->run() && $local->getMetrics()['invalidations'] === 1, 'Receipt also matches local fragment generation');
	$db->pages[1]->page_latest++; $edited = job();
	check($edited->run() && $edited->getMetrics()['invalidations'] === 1, 'Page revision changes invalidate receipt');

	[$db, $services] = setup(); $db->schema = false;
	$legacy = job(); check($legacy->run() && !$legacy->getMetrics()['coalescing_available'], 'Missing new table safely falls back to ordinary purges');
	check(job()->run(), 'Jobs are runnable before schema upgrade');
	check(!str_contains(file_get_contents(__DIR__ . '/../src/Reading/SemanticCachePurgeJob.php'), '->doPurge('), 'No ArticlePurge/SMW rewrite loop');

	// Reproducible stable-graph workload, not a production latency benchmark.
	[$db, $services] = setup(1200, 40);
	$total = ['jobs' => 0, 'invalidations' => 0, 'continuations' => 0];
	for ($i = 0; $i < 12; $i++) {
		$services->push(job($i % 2 ? [100, 200] : [100]));
		$metrics = drain($services);
		foreach ($total as $key => $_) { $total[$key] += $metrics[$key]; }
	}
	$baselineJobs = 12 * (intdiv(1200, 150) + 1);
	check($total['jobs'] === 24 && $total['invalidations'] === 40, 'Batch/page coalescing performance regression');
	$baselineReads = $baselineJobs + 12 * (int)ceil(1200 / 150);
	check($db->reads < $baselineReads, 'Fewer explicit SQL reads even including receipt snapshots');
	echo json_encode(['baseline_jobs' => $baselineJobs, 'baseline_sql_reads_minimum' => $baselineReads, 'optimized' => $total, 'optimized_sql_reads' => $db->reads,
		'scope' => 'standalone doubles; real SQL/HTTP validation is separate'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
	echo "OK: semantic purge orchestration, overlap, revisions, concurrency, legacy cursors, failures and cache effects\n";
}
