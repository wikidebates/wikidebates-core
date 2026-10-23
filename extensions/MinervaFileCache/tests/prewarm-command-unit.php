<?php

/* Isolated smoke test of the maintenance loop: no MediaWiki, network or production DB. */

namespace MediaWiki\WikiMap {
	class WikiMap {
		public static function getCurrentWikiId(): string { return 'fr'; }
	}
}

namespace MediaWiki\Title {
	class Title {
		private string $title;
		public function __construct(string $title) { $this->title = $title; }
		public static function makeTitle(int $namespace, string $title): self { return new self($title); }
		public function getFullURL(): string { return 'https://fr.example.test/wiki/' . rawurlencode($this->title); }
	}
}

namespace MediaWiki\Extension\MinervaFileCache {
	class Hooks {
		public static function store(): void {}
	}
}

namespace {
	require dirname(__DIR__) . '/src/Prewarm.php';
	use MediaWiki\Extension\MinervaFileCache\Prewarm;

	class Maintenance {
		private array $options = [];
		public function __construct() {}
		public function addDescription(string $text): void {}
		public function requireExtension(string $name): void {}
		public function addOption(string $name, string $text, bool $required = false, bool $hasArgument = false): void {}
		public function setOptions(array $options): void { $this->options = $options; }
		public function getOption(string $key, $default = null) { return $this->options[$key] ?? $default; }
		public function hasOption(string $key): bool { return isset($this->options[$key]); }
		public function getServiceContainer(): TestPrewarmServices { return $GLOBALS['prewarmServices']; }
		public function output(string $text): void {}
		public function error(string $text): void {}
		public function fatalError(string $text): void { throw new RuntimeException($text); }
	}

	class TestPrewarmConfig {
		public function __construct(private string $directory) {}
		public function get(string $key) {
			return [
				'Server' => 'https://fr.example.test', 'LanguageCode' => 'fr',
				'MinervaFileCacheEnabled' => true, 'UseFileCache' => true,
				'MinervaFileCacheDebugHeaders' => true, 'MinervaFileCacheDirectory' => $this->directory
			][$key];
		}
	}

	class TestPrewarmDb {
		public array $pages = [];
		public function select($table, $fields, $conditions, $caller, $options): array {
			if ($table !== 'page' || !preg_match('/page_id > ([0-9]+)/', $conditions[0], $match)) {
				throw new RuntimeException('Incorrect SQL pagination');
			}
			$after = (int)$match[1];
			$pages = array_filter($this->pages, static function ($page) use ($conditions, $after) {
				return $page->page_id > $after && !$page->page_is_redirect &&
					(!isset($conditions['page_namespace']) || $page->page_namespace === $conditions['page_namespace']);
			});
			return array_slice(array_values($pages), 0, (int)$options['LIMIT']);
		}
	}

	class TestPrewarmRequest {
		private array $headers = [];
		private int $status = 200;
		public function __construct(private string $url, private array $options) {}
		public function setHeader(string $key, string $value): void { $this->headers[$key] = $value; }
		public function execute() {
			$GLOBALS['prewarmRequests'][] = [$this->url, $this->options, $this->headers];
			if (($GLOBALS['prewarmFailUrl'] ?? null) === $this->url) { $this->status = 429; }
			return new class {
				public function isOK(): bool { return true; }
			};
		}
		public function getStatus(): int { return $this->status; }
		public function getResponseHeader(string $name): ?string {
			return $this->status === 200 ? 'MISS' : null;
		}
	}

	class TestPrewarmHttp {
		public function canMakeRequests(): bool { return true; }
		public function create(string $url, array $options, string $caller): TestPrewarmRequest {
			return new TestPrewarmRequest($url, $options);
		}
	}

	class TestPrewarmServices {
		private TestPrewarmConfig $config;
		private TestPrewarmDb $db;
		public function __construct(string $directory) {
			$this->config = new TestPrewarmConfig($directory);
			$this->db = new TestPrewarmDb();
			$this->db->pages = [
				(object)['page_id'=>1,'page_namespace'=>0,'page_title'=>'One','page_is_redirect'=>0],
				(object)['page_id'=>2,'page_namespace'=>0,'page_title'=>'Two','page_is_redirect'=>0],
				(object)['page_id'=>3,'page_namespace'=>6,'page_title'=>'Three','page_is_redirect'=>0],
				(object)['page_id'=>4,'page_namespace'=>0,'page_title'=>'Redirect','page_is_redirect'=>1],
				(object)['page_id'=>5,'page_namespace'=>0,'page_title'=>'Five','page_is_redirect'=>0],
			];
		}
		public function getMainConfig(): TestPrewarmConfig { return $this->config; }
		public function getHttpRequestFactory(): TestPrewarmHttp { return new TestPrewarmHttp(); }
		public function getConnectionProvider() {
			$db = $this->db;
			return new class($db) {
				public function __construct(private TestPrewarmDb $db) {}
				public function getReplicaDatabase(): TestPrewarmDb { return $this->db; }
			};
		}
	}

	function checkPrewarmCli(bool $ok, string $message): void {
		if (!$ok) { throw new RuntimeException($message); }
		$GLOBALS['assertions']++;
	}

	$root = sys_get_temp_dir() . '/prewarm-command-test-' . bin2hex(random_bytes(6));
	mkdir($root . '/maintenance', 0700, true);
	file_put_contents($root . '/maintenance/Maintenance.php', '<?php');
	file_put_contents($root . '/noop.php', '<?php');
	putenv('MW_INSTALL_PATH=' . $root);
	define('RUN_MAINTENANCE_IF_MAIN', $root . '/noop.php');
	$GLOBALS['prewarmServices'] = new TestPrewarmServices($root);
	$GLOBALS['prewarmRequests'] = [];
	$GLOBALS['assertions'] = 0;
	require dirname(__DIR__) . '/maintenance/prewarm.php';
	try {
		$checkpoint = Prewarm::checkpointPath($root, 'fr', 'https://fr.example.test', 'fr', 'android', null);
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['resume'=>true,'limit'=>'2','delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === true, 'Initial limited run');
		checkPrewarmCli(Prewarm::readCheckpoint($checkpoint) === 2, 'Checkpoint after limited run');
		checkPrewarmCli(count($GLOBALS['prewarmRequests']) === 2, 'Only first two pages');
		checkPrewarmCli(($GLOBALS['prewarmRequests'][0][2]['Accept-Language'] ?? null) === 'fr', 'Language sent');
		checkPrewarmCli(str_contains($GLOBALS['prewarmRequests'][0][1]['userAgent'] ?? '', 'Android'), 'Mobile UA sent');
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['resume'=>true,'delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === true, 'Resume to completion');
		checkPrewarmCli(count($GLOBALS['prewarmRequests']) === 4, 'Redirect skipped');
		checkPrewarmCli(!is_file($checkpoint), 'Completed checkpoint removed');
		$GLOBALS['prewarmRequests'] = [];
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['dry-run'=>true,'limit'=>'1']);
		checkPrewarmCli($runner->execute() === true && count($GLOBALS['prewarmRequests']) === 0, 'Dry-run sends no HTTP');
		$GLOBALS['prewarmFailUrl'] = 'https://fr.example.test/wiki/Two';
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['resume'=>true,'reset'=>true,'delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === false, 'Stops after HTTP 429');
		checkPrewarmCli(Prewarm::readCheckpoint($checkpoint) === 1, 'Failure preserves last completed ID');
		unset($GLOBALS['prewarmFailUrl']);
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['resume'=>true,'delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === true, 'Retry after rate limit');
		checkPrewarmCli(!is_file($checkpoint), 'Recovered checkpoint removed');
		$GLOBALS['prewarmRequests'] = [];
		$runner = new PrewarmMinervaFileCache();
		$runner->setOptions(['namespace'=>'6','delay-ms'=>'250']);
		checkPrewarmCli($runner->execute() === true, 'Namespace filter succeeds');
		checkPrewarmCli(count($GLOBALS['prewarmRequests']) === 1 &&
			str_contains($GLOBALS['prewarmRequests'][0][0], '/Three'), 'Namespace filter is respected');
		echo json_encode(['assertions'=>$GLOBALS['assertions'],'result'=>'PASS']) . "\n";
	} finally {
		foreach (glob($root . '/*') as $path) {
			if (is_dir($path)) {
				foreach (glob($path . '/*') as $file) { unlink($file); }
				rmdir($path);
			} else { unlink($path); }
		}
		rmdir($root);
	}
}
