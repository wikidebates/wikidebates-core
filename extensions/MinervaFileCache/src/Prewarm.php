<?php

namespace MediaWiki\Extension\MinervaFileCache;

/** Small, MediaWiki-independent helpers for the prewarming maintenance command. */
class Prewarm {
	private const USER_AGENTS = [
		'android' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36',
		'iphone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 Version/17.6 Mobile/15E148 Safari/604.1',
	];

	public static function userAgent(string $device): string {
		if (!isset(self::USER_AGENTS[$device])) {
			throw new \InvalidArgumentException('Device must be android or iphone');
		}
		return self::USER_AGENTS[$device];
	}

	public static function number($value, string $option, int $min, int $max): int {
		if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string)$value)) {
			throw new \InvalidArgumentException("$option must be an integer between $min and $max");
		}
		$value = filter_var((string)$value, FILTER_VALIDATE_INT);
		if ($value === false || $value < $min || $value > $max) {
			throw new \InvalidArgumentException("$option must be an integer between $min and $max");
		}
		return $value;
	}

	/** A checkpoint is bound to a wiki, origin, language, device and namespace filter. */
	public static function checkpointPath(
		string $directory, string $wiki, string $origin, string $language, string $device, ?int $namespace,
		?string $category = null, bool $recursive = false
	): string {
		$identity = [$wiki, $origin, $language, $device, $namespace];
		if ($category !== null) {
			$identity[] = $category;
			$identity[] = $recursive;
		}
		$key = hash('sha256', json_encode($identity));
		return rtrim($directory, '/') . '/prewarm-' . $key . '.checkpoint';
	}

	public static function readCheckpoint(string $path): int {
		if (!is_file($path)) {
			return 0;
		}
		$data = @file_get_contents($path);
		if ($data === false || !preg_match('/^(0|[1-9][0-9]*)\n?$/D', $data)) {
			throw new \RuntimeException('Malformed prewarm checkpoint: ' . $path);
		}
		return self::number(trim($data), 'checkpoint', 0, PHP_INT_MAX);
	}

	/** Atomic replacement prevents a half-written checkpoint after an interruption. */
	public static function saveCheckpoint(string $path, int $lastId): void {
		if ($lastId < 0) {
			throw new \InvalidArgumentException('A checkpoint ID cannot be negative');
		}
		$dir = dirname($path);
		$temp = @tempnam($dir, '.prewarm-');
		if ($temp === false || dirname($temp) !== $dir) {
			throw new \RuntimeException('Cannot create temporary prewarm checkpoint');
		}
		try {
			$value = $lastId . "\n";
			if (@file_put_contents($temp, $value) !== strlen($value) ||
				!@chmod($temp, 0600) || !@rename($temp, $path)) {
				throw new \RuntimeException('Cannot save prewarm checkpoint');
			}
		} finally {
			if (is_file($temp)) {
				@unlink($temp);
			}
		}
	}

	/** Never interpret an upstream page as a cache success without our own header. */
	public static function responseState(int $httpStatus, ?string $header): string {
		if ($httpStatus !== 200) {
			return 'HTTP_' . $httpStatus;
		}
		if ($header === null || trim($header) === '') {
			return 'NO_HEADER';
		}
		$state = trim(explode(';', $header, 2)[0]);
		return in_array($state, ['MISS', 'HIT', 'BYPASS'], true) ? $state : 'UNKNOWN_HEADER';
	}

	/**
	 * Resolve subcategories breadth-first. A visited set prevents cycles.
	 * The categorylinks cl_to keys and page_title values exclude the namespace.
	 *
	 * @return string[] Category DB keys including the root.
	 */
	public static function categories($dbr, string $root, bool $recursive): array {
		$seen = [$root => true];
		if (!$recursive) {
			return [$root];
		}
		$queue = [$root];
		for ($index = 0; $index < count($queue); $index++) {
			$parent = $queue[$index];
			$afterId = 0;
			do {
				$rows = $dbr->select(
					['categorylinks', 'page'],
					['page_id', 'page_title'],
					[
						'cl_to' => $parent,
						'cl_type' => 'subcat',
						'page_namespace' => NS_CATEGORY,
						'page_id > ' . $afterId,
					],
					__METHOD__,
					['ORDER BY' => 'page_id ASC', 'LIMIT' => 100],
					['page' => ['INNER JOIN', 'page_id = cl_from']]
				);
				$count = 0;
				foreach ($rows as $row) {
					$count++;
					$afterId = (int)$row->page_id;
					$key = (string)$row->page_title;
					if (!isset($seen[$key])) {
						$seen[$key] = true;
						$queue[] = $key;
						if (count($queue) > 100000) {
							throw new \RuntimeException('Too many subcategories (>100000); narrow the category selection');
						}
					}
				}
			} while ($count === 100);
		}
		return $queue;
	}

	/**
	 * Return the next page-id-ordered batch, deduplicated across categories.
	 * Chunking avoids very large SQL IN clauses in large category trees.
	 *
	 * @return object[] Page records
	 */
	public static function categoryPageBatch(
		$dbr, array $categories, int $afterId, ?int $namespace, int $limit
	): array {
		$pages = [];
		foreach (array_chunk($categories, 200) as $chunk) {
			$conditions = [
				'cl_to' => $chunk,
				'page_is_redirect' => 0,
				'page_id > ' . $afterId,
			];
			if ($namespace !== null) {
				$conditions['page_namespace'] = $namespace;
			}
			$rows = $dbr->select(
				['categorylinks', 'page'],
				['page_id', 'page_namespace', 'page_title'],
				$conditions,
				__METHOD__,
				['DISTINCT', 'ORDER BY' => 'page_id ASC', 'LIMIT' => $limit],
				['page' => ['INNER JOIN', 'page_id = cl_from']]
			);
			foreach ($rows as $row) {
				$pages[(int)$row->page_id] = $row;
			}
		}
		ksort($pages, SORT_NUMERIC);
		return array_slice(array_values($pages), 0, $limit);
	}

}
