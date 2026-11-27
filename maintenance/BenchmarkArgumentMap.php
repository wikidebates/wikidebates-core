<?php

use MediaWiki\Extension\DebatePHP\ArgumentMapData;
use MediaWiki\Extension\DebatePHP\I18n;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * Bounded read-only audit of actual map fields. Never edit pages, parse
 * article wikitext, change schema, schedule jobs or purge page caches.
 * Optional cache probe writes only an ephemeral synthetic benchmark key.
 */
class BenchmarkArgumentMap extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Compare real stored ArgumentMap fields and warm CLI read costs; optionally probe the configured WAN cache.');
		$this->requireExtension('Wikidebates');
		$this->requireExtension('SemanticMediaWiki');
		$this->addOption('limit', 'Maximum main-namespace non-redirect pages (default 100, maximum 1000)', false, true);
		$this->addOption('after-id', 'Resume after this page ID (default 0)', false, true);
		$this->addOption('ids', 'Optional comma-separated positive page IDs', false, true);
		$this->addOption('cache-probe', 'Measure synthetic WAN cache hits without caching wiki content');
	}

	private function emit(array $value): void {
		$this->output(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
	}

	private static function stats(array $samples): array {
		sort($samples, SORT_NUMERIC);
		$n = count($samples);
		if ($n === 0) {
			return ['count' => 0, 'median_us' => null, 'p95_us' => null];
		}
		return ['count' => $n, 'median_us' => round(($samples[intdiv($n - 1, 2)] + $samples[intdiv($n, 2)]) / 2, 2),
			'p95_us' => round($samples[max(0, (int)ceil($n * 0.95) - 1)], 2)];
	}

	private static function fields(array $row, array $properties): array {
		$result = [];
		foreach ($properties as $alias => $unused) {
			$result[$alias] = ArgumentMapData::field($row[$alias] ?? null);
		}
		return $result;
	}

	public function execute() {
		$limit = (int)$this->getOption('limit', 100);
		$after = (int)$this->getOption('after-id', 0);
		$ids = trim((string)$this->getOption('ids', ''));
		if ($limit < 1 || $limit > 1000 || $after < 0 ||
			($ids !== '' && !preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $ids))) {
			$this->fatalError('Invalid limit (1-1000), after-id or ids.');
		}
		$ids = $ids === '' ? [] : array_values(array_unique(array_map('intval', explode(',', $ids))));
		if (count($ids) > 1000) {
			$this->fatalError('At most 1000 explicit page IDs.');
		}

		$services = MediaWikiServices::getInstance();
		$lang = I18n::bundled()->language([], $services->getContentLanguage()->getCode());
		$i18n = I18n::bundled();
		$property = static fn(string $key): string => $i18n->message('ArgumentMap.props', $lang, $key);
		$modes = [
			'full' => ['map' => $property('arg_map')],
			'debate' => ['left' => $property('pro_list'), 'right' => $property('con_list')],
			'argument' => ['left' => $property('justifs_list'), 'right' => $property('objs_list')]
		];
		$db = $services->getConnectionProvider()->getReplicaDatabase();
		$where = ['page_namespace' => NS_MAIN, 'page_is_redirect' => 0, $db->expr('page_id', '>', $after)];
		if ($ids !== []) {
			$where['page_id'] = $ids;
		}
		$pages = $db->newSelectQueryBuilder()->select(['page_id', 'page_title'])->from('page')
			->where($where)->orderBy('page_id')->limit($limit)->caller(__METHOD__)->fetchResultSet();
		$report = [];
		foreach ($modes as $mode => $properties) {
			$report[$mode] = ['eligible' => 0, 'nonempty' => 0, 'eligible_nonempty' => 0,
				'mismatches' => 0, 'query' => [], 'direct' => []];
		}
		$count = 0;
		$last = $after;
		$errors = 0;
		foreach ($pages as $page) {
			$count++;
			$last = (int)$page->page_id;
			$title = Title::makeTitle(NS_MAIN, $page->page_title);
			foreach ($modes as $mode => $properties) {
				try {
					$times = [];
					$values = [];
					foreach (($count % 2 === 0 ? ['query', 'direct'] : ['direct', 'query']) as $reader) {
						$started = hrtime(true);
						$values[$reader] = $reader === 'query' ? ArgumentMapData::readQuery($title, $properties) :
							ArgumentMapData::readDirect($title, $properties);
						$times[$reader] = (hrtime(true) - $started) / 1000;
					}
					$expected = self::fields($values['query'], $properties);
					$nonempty = count(array_filter($expected, static fn(string $value): bool => $value !== '')) > 0;
					$report[$mode]['nonempty'] += (int)$nonempty;
					if ($values['direct'] === null) {
						continue;
					}
					$report[$mode]['eligible']++;
					$report[$mode]['eligible_nonempty'] += (int)$nonempty;
					if (self::fields($values['direct'], $properties) !== $expected) {
						$report[$mode]['mismatches']++;
						$this->emit(['type' => 'mismatch', 'pageid' => $last, 'mode' => $mode]);
					}
					if ($nonempty) {
						$report[$mode]['query'][] = $times['query'];
						$report[$mode]['direct'][] = $times['direct'];
					}
				} catch (\Throwable $e) {
					$errors++;
					$this->emit(['type' => 'error', 'pageid' => $last, 'mode' => $mode, 'class' => get_class($e)]);
				}
			}
		}
		foreach ($report as &$mode) {
			$mode['query'] = self::stats($mode['query']);
			$mode['direct'] = self::stats($mode['direct']);
		}
		unset($mode);
		$this->emit(['type' => 'summary', 'wiki_language' => $services->getContentLanguage()->getCode(),
			'smw' => SMW_VERSION, 'php' => PHP_VERSION, 'pages' => $count, 'resume_after' => $last,
			'errors' => $errors, 'modes' => $report, 'timing' => 'CLI, process state may be warm; nonempty eligible fields only']);
		if ($this->hasOption('cache-probe')) {
			$this->probeCache($services);
		}
	}

	private function probeCache(MediaWikiServices $services): void {
		$cache = $services->getMainWANObjectCache();
		$key = $cache->makeKey('wikidebates-argumentmap-benchmark', bin2hex(random_bytes(12)));
		// A dedicated, stable check key represents the overhead of validation;
		// it is never shared with real data or touched by page/SMW hooks.
		$checkKey = $cache->makeKey('wikidebates-argumentmap-benchmark-check', 'v1');
		$payload = ['left' => str_repeat('synthetic benchmark data ', 40), 'right' => 'synthetic'];
		$misses = 0;
		$times = [];
		$callback = static function() use ($payload, &$misses): array {
			$misses++;
			return $payload;
		};
		try {
			$cache->getWithSetCallback($key, 30, $callback, ['checkKeys' => [$checkKey]]);
			$misses = 0;
			for ($i = 0; $i < 100; $i++) {
				$started = hrtime(true);
				$value = $cache->getWithSetCallback($key, 30, $callback, ['checkKeys' => [$checkKey]]);
				$times[] = (hrtime(true) - $started) / 1000;
				if ($value !== $payload) {
					throw new RuntimeException('Synthetic cache payload mismatch');
				}
			}
			$this->emit(['type' => 'cache_probe', 'hits' => count($times) - $misses,
				'misses' => $misses, 'round_trip' => self::stats($times), 'scope' => 'CLI synthetic data, no FPM guarantee']);
		} finally {
			$cache->delete($key, 0);
		}
	}
}

$maintClass = BenchmarkArgumentMap::class;
require_once RUN_MAINTENANCE_IF_MAIN;
