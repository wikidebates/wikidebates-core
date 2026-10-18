<?php
namespace MediaWiki\Extension\DebatePHP;

/** Optional, per-invocation observations. Never stores expanded text or property values. */
final class Telemetry {
	private bool $profiling;
	private bool $diagnostics;
	private array $context;
	private ?\Closure $sink;
	private ?int $started;
	private array $stages = [];
	private array $counters = ['preprocess_executed' => 0, 'preprocess_cache_hits' => 0, 'native_cache_hits' => 0, 'literal_fields' => 0];
	private array $preprocessing = [];
	public function __construct(bool $profiling, bool $diagnostics, array $context = [], ?callable $sink = null) {
		$this->profiling = $profiling; $this->diagnostics = $diagnostics;
		$this->context = $context; $this->sink = $sink === null ? null : \Closure::fromCallable($sink);
		$this->started = $this->start();
	}
	public function start(): ?int { return $this->profiling ? hrtime(true) : null; }
	public function count(string $name): void {
		if ($this->profiling) { $this->counters[$name] = ($this->counters[$name] ?? 0) + 1; }
	}
	/** Counts entries initiated by DebatePHP, including attempts that throw. Nested
	 * parser work inside MediaWiki/other extensions is not an additional entry here.
	 */
	public function preprocess(string $reason, callable $operation): string {
		if (!$this->profiling) { return $operation(); }
		$this->count('preprocess_executed');
		$this->preprocessing[$reason] ??= ['calls' => 0, 'nanoseconds' => 0];
		$this->preprocessing[$reason]['calls']++;
		$started = hrtime(true);
		try { return $operation(); }
		finally { $this->preprocessing[$reason]['nanoseconds'] += hrtime(true) - $started; }
	}
	public function finish(string $stage, ?int $started): void {
		if ($started === null) { return; }
		$this->stages[$stage] ??= ['calls' => 0, 'nanoseconds' => 0];
		$this->stages[$stage]['calls']++;
		$this->stages[$stage]['nanoseconds'] += hrtime(true) - $started;
	}
	public function problem(string $stage, string $reason, ?\Throwable $exception = null): void {
		if (!$this->diagnostics) { return; }
		$event = ['event' => 'diagnostic', 'stage' => $stage, 'reason' => $reason];
		if ($exception !== null) { $event['exception_class'] = get_class($exception); }
		$this->emit($event);
	}
	public function report(): void {
		if ($this->started === null) { return; }
		$stages = [];
		foreach ($this->stages as $name => $v) {
			$stages[$name] = ['calls' => $v['calls'], 'milliseconds' => round($v['nanoseconds'] / 1e6, 4)];
		}
		// Render includes its child stages. Report the remainder separately, never sum both.
		$children = 0;
		foreach (['arguments', 'expansions', 'messages', 'semantic', 'semantic-query', 'autoedit', 'current_date', 'quote_expansions', 'seo', 'json', 'native_tags', 'native_jsonld', 'fields', 'shortdesc'] as $name) {
			$children += $this->stages[$name]['nanoseconds'] ?? 0;
		}
		$other = max(0, ($this->stages['render']['nanoseconds'] ?? 0) - $children);
		$preprocessing = [];
		foreach ($this->preprocessing as $reason => $value) {
			$preprocessing[$reason] = ['calls' => $value['calls'], 'milliseconds' => round($value['nanoseconds'] / 1e6, 4)];
		}
		$this->emit(['event' => 'profile', 'total_ms' => round((hrtime(true) - $this->started) / 1e6, 4),
			'render_other_ms' => round($other / 1e6, 4), 'stages' => $stages,
			'counters' => $this->counters, 'preprocessing' => $preprocessing]);
	}
	private function emit(array $event): void {
		try {
			$event += $this->context;
			if ($this->sink !== null) { ($this->sink)($event); }
			else { wfDebugLog('DebatePHP', json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'private'); }
		} catch (\Throwable $e) { /* A log failure must not change parser behavior. */ }
	}
}
