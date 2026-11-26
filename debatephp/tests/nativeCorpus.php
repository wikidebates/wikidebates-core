<?php
/**
 * Native PHP behaviour contract and isolated renderer benchmark.
 * The same driver can load the production source of a pinned checkout.
 * No MediaWiki installation, network or live semantic database is used.
 */
use MediaWiki\Extension\DebatePHP\{ArgumentReferenceRenderer, ArgumentRenderer, ReferenceRenderer, Renderer, Table};

$options = getopt('', ['root:', 'repeats:', 'warmup:']);
$root = realpath($options['root'] ?? dirname(__DIR__, 2));
if ($root === false) {
	throw new InvalidArgumentException('Unknown source checkout');
}
$repeats = filter_var($options['repeats'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
$warmup = filter_var($options['warmup'] ?? '3', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
if ($repeats === false || $warmup === false) {
	throw new InvalidArgumentException('Invalid repetition or warmup count');
}
require $root . '/debatephp/tests/argument_context.php';
set_error_handler(static function($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) {
		return false;
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});

class NativeCorpusContext extends ArgumentTestContext {
	public function __construct(private array $fixture) {
		parent::__construct($fixture);
	}

	private static function rows($value) {
		if (!is_array($value)) {
			return $value;
		}
		$rows = [];
		foreach ($value as $key => $item) {
			$rows[is_int($key) ? $key + 1 : $key] = self::rows($item);
		}
		return new Table($rows);
	}

	public function smwAsk(Table $query): ?Table {
		$this->effects['ask'][] = $query->values;
		if ($this->fixture['ask_error'] ?? false) {
			throw new RuntimeException('fixture query failed');
		}
		$fields = implode("\n", $query->values);
		$key = match (true) {
			str_contains($fields, '=pro'), str_contains($fields, '=con') => 'debate_rows',
			str_contains($fields, '=justif'), str_contains($fields, '=obj') => 'argument_rows',
			str_contains($fields, '=pdeb') => 'inherited_rows',
			str_contains($fields, '=chain') => 'breadcrumb_rows',
			str_contains($fields, '=map') => 'dedicated_rows',
			in_array('limit=1', $query->values, true) => 'init_rows',
			default => ''
		};
		$rows = $this->fixture[$key] ?? [];
		if (!$rows) {
			foreach ($this->fixture['query_rules'] ?? [] as [$needle, $answer]) {
				if (str_contains($fields, $needle)) {
					$rows = $answer;
					break;
				}
			}
		}
		return self::rows($rows);
	}
}

function nativeCorpus(): array {
	$arguments = json_decode(file_get_contents(__DIR__ . '/fixtures/argument-cases.json'), true, 512, JSON_THROW_ON_ERROR);
	foreach ($arguments as &$case) {
		$case['module'] = 'Argument';
		$case['publication_date'] = '2025-01-01T12:34:56Z';
	}
	unset($case);
	$cases = $arguments;
	foreach (['fr', 'en', 'de', 'es', 'it', 'pt'] as $lang) {
		foreach ([1, 4, 24] as $size) {
			$items = [];
			$references = [];
			$parents = [];
			for ($i = 1; $i <= $size; $i++) {
				$items[] = 'Page ' . $i . '⟬Titre ' . $i . '⟬';
				$references[] = 'Auteur ' . $i . '⟬Article⟬Livre⟬2⟬5⟬Section⟬12-15⟬Éditeur⟬Paris⟬2020⟬https://example.org/' . $i . '⟬';
				$parents[] = ['fulltext' => 'Parent ' . $i, 'pro' => 'Child', 'con' => 'Child'];
			}
			$raw = '⟭' . implode('⟭', $items);
			$biblio = '⟭' . implode('⟭', $references);
			$common = ['lang' => $lang, 'keywords' => '0,Énergie,Science', 'sections' => 'Science,Politique', 'bibliography' => $biblio];
			$cases[] = [
				'module' => 'Debate', 'name' => "debate-$lang-$size", 'title' => 'Question de démonstration ?', 'lang' => $lang,
				'args' => $common + ['topic' => 'Sujet', 'expanded-topic' => 'le sujet', 'introduction' => 'Introduction [[Lien|libellé]]',
					'pro-arguments' => $raw, 'con-arguments' => $raw, 'related-debates' => 'Autre débat'],
			];
			$cases[] = [
				'module' => 'Argument', 'name' => "argument-$lang-$size", 'title' => 'Child', 'lang' => $lang,
				'publication_date' => '2025-01-01T12:34:56Z',
				'args' => $common + ['summary' => 'Résumé [[Lien|libellé]]', 'justifications' => $raw, 'objections' => $raw],
				'debate_rows' => $parents,
			];
		}
		$cases[] = ['module' => 'Debate', 'name' => "debate-$lang-empty", 'title' => 'Question ?', 'lang' => $lang, 'args' => ['lang' => $lang]];
	}
	return $cases;
}

function nativeRender(array $case, string $class): array {
	$context = new NativeCorpusContext($case);
	try {
		$text = (new $class($context))->render();
		$result = ['text' => $text];
	} catch (RuntimeException $e) {
		// Do not silently bless unexpected renderer failures.
		if (!(($case['ask_error'] ?? false) && $e->getMessage() === 'fixture query failed')
			&& !(isset($case['preprocess_fail']) && $e->getMessage() === 'expansion failed')) {
			throw $e;
		}
		$result = ['exception' => get_class($e), 'message' => $e->getMessage()];
	}
	return $result + $context->effects;
}

function cpuMicroseconds(): int {
	$usage = getrusage();
	return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000000
		+ $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
}

function measureNativeRender(array $case, string $class): array {
	gc_collect_cycles();
	memory_reset_peak_usage();
	$memory = memory_get_usage();
	$cpu = cpuMicroseconds();
	$start = hrtime(true);
	$result = nativeRender($case, $class);
	$elapsed = hrtime(true) - $start;
	$cpu = cpuMicroseconds() - $cpu;
	$peak = max(0, memory_get_peak_usage() - $memory);
	return [
		'wall_ns' => $elapsed, 'cpu_us' => $cpu, 'peak_php_bytes_delta' => $peak,
		'preprocess_calls' => count($result['preprocess']), 'semantic_read_calls' => count($result['ask']),
		'semantic_write_calls' => count($result['set']),
	];
}

$output = [];
$cases = nativeCorpus();
foreach ($cases as $case) {
	$classes = $case['module'] === 'Argument'
		? ['reference' => ArgumentReferenceRenderer::class, 'optimized' => ArgumentRenderer::class]
		: ['reference' => ReferenceRenderer::class, 'optimized' => Renderer::class];
	$row = [];
	foreach ($classes as $mode => $class) {
		try {
			$result = nativeRender($case, $class);
			$hashes = [];
			foreach ($result as $key => $value) {
				$hashes[$key] = hash('sha256', serialize($value));
			}
			$row[$mode] = ['sha256' => $hashes];
			unset($result);
			if ($repeats) {
				for ($i = 0; $i < $warmup; $i++) {
					nativeRender($case, $class);
				}
				$samples = [];
				for ($i = 0; $i < $repeats; $i++) {
					$samples[] = measureNativeRender($case, $class);
				}
				$row[$mode]['samples'] = $samples;
			}
		} catch (Throwable $e) {
			throw new RuntimeException($case['module'] . '/' . $case['name'] . '/' . $mode, 0, $e);
		}
	}
	$key = $case['module'] . '/' . $case['name'];
	if (isset($output[$key])) {
		throw new LogicException('Duplicate fixture: ' . $key);
	}
	$output[$key] = $row;
}
if ($repeats) {
	$output = [
		'environment' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'opcache_cli' => ini_get('opcache.enable_cli'),
			'opcache_status' => function_exists('opcache_get_status') && opcache_get_status(false) !== false,
			'cases' => count($cases), 'warmup' => $warmup, 'repeats' => $repeats],
		'cases' => $output,
	];
}
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
