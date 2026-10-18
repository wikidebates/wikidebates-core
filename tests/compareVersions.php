<?php

/** Read-only comparisons and interleaved timings against archived component directories. */
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Title\Title;

$options = getopt('', ['v060:', 'v070:', 'repeats::', 'output:']);
if (!isset($options['v060'], $options['v070'], $options['output'])) {
	throw new RuntimeException('Required: --v060=/archive/0.6 --v070=/archive/0.7 --output=/tmp/comparison.json');
}
check(\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('Scribunto'), 'Scribunto provides the historical 0.6 namespace for this comparison');
$versions = ['0.6' => $options['v060'], '0.7' => $options['v070'], '0.8' => dirname(__DIR__) . '/debatephp'];
$prefixes = [];
foreach ($versions as $version => $directory) {
	$directory = realpath($directory);
	check($directory !== false && is_dir($directory . '/src'), 'Archived source directory: ' . $version);
	$prefix = 'MediaWiki\\Extension\\DebatePHPComparison\\V' . str_replace('.', '', $version) . '\\';
	$target = $temporary . '/v' . str_replace('.', '', $version);
	mkdir($target . '/src', 0700, true);
	// The source is copied and namespaced in the temporary test directory only.
	foreach (glob($directory . '/src/*.php') as $file) {
		$source = str_replace('namespace MediaWiki\\Extension\\DebatePHP;', 'namespace ' . rtrim($prefix, '\\') . ';', file_get_contents($file));
		file_put_contents($target . '/src/' . basename($file), $source);
	}
	symlink($directory . '/resources', $target . '/resources');
	spl_autoload_register(static function(string $class) use ($prefix, $target): void {
		if (str_starts_with($class, $prefix)) { require_once $target . '/src/' . substr($class, strlen($prefix)) . '.php'; }
	});
	$prefixes[$version] = $prefix;
}

$resourceDir = dirname(__DIR__) . '/debatephp/resources/';
$pages = [];
$xml = new DOMDocument;
$xml->load(dirname(__DIR__) . '/debatephp/tests/fixtures/wiki-snapshot.xml');
foreach ($xml->getElementsByTagName('page') as $page) {
	$title = $page->getElementsByTagName('title')->item(0)->textContent;
	$title = preg_replace('/^Modèle:/u', 'Template:', $title);
	$pages[$title] = $page->getElementsByTagName('text')->item(0)->textContent;
}
foreach (['Debate', 'Argument', 'WD/I18N', 'WD/I18N/Data'] as $name) {
	$filename = match ($name) {
		'WD/I18N' => 'WD-I18N.reference.lua', 'WD/I18N/Data' => 'WD-I18N-Data.snapshot.lua',
		default => 'Module-' . $name . '.reference.lua'
	};
	$pages['Module:' . $name] = file_get_contents($resourceDir . $filename);
}
$pages['Template:Débat'] = file_get_contents($resourceDir . 'Template-Debat.php.wikitext');
$pages['Template:Argument'] = file_get_contents($resourceDir . 'Template-Argument.php.wikitext');
$corpus = [];
$skipped = [];
foreach (['argument-cases.json', 'debate-v070.json'] as $file) {
	foreach (json_decode(file_get_contents(dirname(__DIR__) . '/debatephp/tests/fixtures/' . $file), true, 512, JSON_THROW_ON_ERROR) as $case) {
		if (!Title::newFromText($case['title'])) {
			$skipped[] = ['name' => $case['name'] ?? $case['title'], 'reason' => 'Invalid MediaWiki title; retained in the mocked boundary corpus'];
			continue;
		}
		if (isset($case['args'])) {
			$text = '{{#argument:render';
			foreach ($case['args'] as $key => $value) { $text .= '|' . $key . '=' . str_replace('|', '{{!}}', $value); }
			$case['text'] = $text . '}}<references/>';
		}
		$corpus[] = $case;
	}
}

function comparisonValue($value) {
	if (is_object($value)) { $value = get_object_vars($value); }
	if (!is_array($value)) { return $value; }
	if (!array_is_list($value)) { ksort($value); }
	return array_map('comparisonValue', $value);
}
function comparisonHtml(string $html): string {
	return preg_replace_callback('~(<script[^>]*type="application/ld\+json"[^>]*>)(.*?)(</script>)~s', static function(array $match): string {
		return $match[1] . json_encode(comparisonJson(json_decode($match[2], false, 512, JSON_THROW_ON_ERROR)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . $match[3];
	}, $html);
}
function comparisonJson($value) {
	if ($value instanceof stdClass) {
		$properties = get_object_vars($value);
		ksort($properties);
		$result = new stdClass;
		foreach ($properties as $key => $property) { $result->$key = comparisonJson($property); }
		return $result;
	}
	return is_array($value) ? array_map('comparisonJson', $value) : $value;
}
check(comparisonHtml('<script type="application/ld+json">{"value":{}}</script>')
	!== comparisonHtml('<script type="application/ld+json">{"value":[]}</script>'), 'JSON comparison preserves object/array types');
$run = static function(array $case, string $version, string $mode) use ($prefixes, $pages): array {
	$GLOBALS['wgDebatePHPMode'] = $mode;
	$parser = MediaWikiServices::getInstance()->getParserFactory()->create();
	foreach (['debate', 'argument'] as $function) {
		$parser->setFunctionHook($function, [$prefixes[$version] . 'Hooks', $function], Parser::SFH_OBJECT_ARGS);
	}
	$parserOptions = ParserOptions::newFromAnon();
	$parserOptions->setTimestamp('20261018120000');
	$parserOptions->setTemplateCallback(static function($title) use ($pages): array {
		$name = $title->getNamespace() === NS_TEMPLATE ? 'Template:' . $title->getText() : $title->getPrefixedText();
		$text = $pages[$name] ?? false;
		if (is_string($text) && $title->getNamespace() === NS_TEMPLATE) {
			$text = str_replace(['{{#invoke:Debate|render', '{{#invoke:Argument|render'], ['{{#debate:render', '{{#argument:render'], $text);
		}
		return ['text' => $text, 'finalTitle' => $title, 'deps' => [['title' => $title, 'page_id' => 0, 'rev_id' => 0]]];
	});
	$start = hrtime(true);
	$text = str_replace(['{{#invoke:Debate|render', '{{#invoke:Argument|render'], ['{{#debate:render', '{{#argument:render'], $case['text']);
	$output = $parser->parse($text, Title::newFromText($case['title']), $parserOptions);
	$elapsed = (hrtime(true) - $start) / 1e6;
	$dependencies = [];
	$removed = [];
	foreach ($output->getLinkList(ParserOutputLinkTypes::TEMPLATE) as $entry) {
		$name = $entry['link']->getNamespace() . ':' . $entry['link']->getDBkey();
		if (in_array($name, ['828:Debate', '828:Argument', '828:WD/I18N', '828:WD/I18N/Data'], true)) { $removed[] = $name; continue; }
		$dependencies[] = [$name, $entry['pageid'], $entry['revid']];
	}
	return ['milliseconds' => $elapsed, 'removed_dependencies' => $removed, 'output' => comparisonValue([
		'html' => comparisonHtml($output->getText()), 'categories' => $output->getCategoryMap(),
		'properties' => $output->getPageProperties(), 'templates' => $dependencies,
		'flags' => $output->getAllFlags(), 'cache_expiry' => $output->getCacheExpiry(),
		'links' => $output->getLinks(), 'external_links' => $output->getExternalLinks(),
		'modules' => $output->getModules()
	])];
};
$report = ['cases' => count($corpus), 'skipped_cases' => $skipped, 'comparisons' => 0, 'failures' => [], 'timings' => [], 'normalized' => ['JSON object key order/whitespace', 'only the four intentionally removed Lua source dependencies']];
$repeats = max(1, (int)($options['repeats'] ?? 5));
mt_srand(808);
foreach ($corpus as $index => $case) {
	$reference = $run($case, '0.6', 'reference');
	foreach (array_keys($versions) as $version) {
		foreach (['reference', 'optimized'] as $mode) {
			$actual = $run($case, $version, $mode);
			if ($reference['output'] !== $actual['output']) {
				$fields = array_keys(array_filter($actual['output'], static fn($value, $key): bool => $value !== $reference['output'][$key], ARRAY_FILTER_USE_BOTH));
				$report['failures'][] = ['case' => $case['name'] ?? $case['title'], 'version' => $version, 'mode' => $mode, 'fields' => $fields];
			}
			if ($version !== '0.6') { same([], $actual['removed_dependencies'], '0.7/0.8 do not depend on the four old source modules'); }
			$report['comparisons']++;
		}
	}
	$order = [];
	foreach (array_keys($versions) as $version) { foreach (['reference', 'optimized'] as $mode) { $order[] = [$version, $mode]; } }
	for ($repeat = 0; $repeat < $repeats; $repeat++) {
		shuffle($order);
		foreach ($order as [$version, $mode]) {
			$report['timings'][$version][$mode][] = $run($case, $version, $mode)['milliseconds'];
		}
	}
	if (($index + 1) % 20 === 0) { fwrite(STDERR, ($index + 1) . '/' . count($corpus) . " cases compared\n"); }
}
foreach ($report['timings'] as &$modes) {
	foreach ($modes as &$times) {
		sort($times);
		$count = count($times);
		$times = ['samples' => $count, 'median_ms' => ($times[intdiv($count - 1, 2)] + $times[intdiv($count, 2)]) / 2,
			'p95_ms' => $times[(int)floor(($count - 1) * 0.95)], 'total_ms' => array_sum($times)];
	}
	unset($times);
}
unset($modes);
$report['passed'] = !$report['failures'];
$report['environment'] = ['mediawiki' => MW_VERSION, 'php' => PHP_VERSION, 'repeats' => $repeats, 'extensions' => getenv('WD_TEST_EXTENSIONS')];
file_put_contents($options['output'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
exit($report['passed'] ? 0 : 1);
