<?php
// Run with: php tests/integration/compare.php --wiki-root=/path/to/mediawiki --corpus=/path/cases.json --output=/path/results.json
$wikiRoot = null;
foreach ($argv as $a) { if (str_starts_with($a, '--wiki-root=')) { $wikiRoot = substr($a, 12); } }
if (!$wikiRoot) { fwrite(STDERR, "--wiki-root is required\n"); exit(2); }
require $wikiRoot . '/maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\Extension\DebatePHP\ReferenceRenderer;
use MediaWiki\Extension\DebatePHP\Renderer;
use MediaWiki\Extension\DebatePHP\Tests\CaptureLibrary;

class DebateComparison extends Maintenance {
	private bool $fullStack = false;
	private string $timestamp;
	private array $modes = ['lua', 'reference', 'optimized'];
	public function __construct() {
		parent::__construct();
		$this->addOption('wiki-root', 'MediaWiki root', true, true);
		$this->addOption('corpus', 'JSON cases [{title,text}]', true, true);
		$this->addOption('output', 'JSON output', true, true);
		$this->addOption('full-stack', 'Use real SMW/SEO; no semantic double or Lua instrumentation');
		$this->addOption('repeats', 'Uninstrumented timing repetitions', false, true);
		$this->addOption('no-trace', 'Verify production callbacks without expansion instrumentation');
		$this->addOption('profile-log', 'Enable profiling and diagnostics into this local log (requires --no-trace)', false, true);
		$this->addOption('native-only', 'Native variants only; use an existing Lua run as an external oracle');
		$this->addOption('oracle', 'Existing Lua result used to pin corpus timestamps', false, true);
		$this->addOption('legacy-renderer', 'Isolated archived PHP renderer prepared by legacy.py', false, true);
	}
	public function execute() {
		// Compare CURRENT* magic words at the same instant across all variants.
		$this->timestamp = wfTimestampNow();
		if ($this->hasOption('native-only')) { $this->modes = ['reference', 'optimized']; }
		if ($this->hasOption('legacy-renderer')) {
			require $this->getOption('legacy-renderer');
			$this->modes[] = 'legacy-php';
		}
		if ($this->hasOption('profile-log')) {
			if (!$this->hasOption('no-trace')) { throw new RuntimeException('--profile-log requires --no-trace'); }
			$GLOBALS['wgDebatePHPProfiling'] = true;
			$GLOBALS['wgDebatePHPDiagnostics'] = true;
			$GLOBALS['wgDebugLogGroups']['DebatePHP'] = $this->getOption('profile-log');
		}
		$this->fullStack = $this->hasOption('full-stack');
		if ($this->fullStack) {
			if (!class_exists(\SMW\ParserData::class) || !class_exists(\SMW\Scribunto\LibraryFactory::class)) { throw new \RuntimeException('Full-stack comparison requires real SMW and SemanticScribunto.'); }
			if (str_contains((new \ReflectionClass(\SMW\Scribunto\LibraryFactory::class))->getFileName(), 'SemanticDouble.php')) { throw new \RuntimeException('Remove the semantic double before full-stack validation.'); }
			if (class_exists(CaptureLibrary::class)) { throw new \RuntimeException('Remove the capture library and its Scribunto hook before full-stack validation.'); }
		}
		$cases = json_decode(file_get_contents($this->getOption('corpus')), true, 512, JSON_THROW_ON_ERROR);
		if ($this->hasOption('oracle')) {
			$oracle = [];
			foreach (json_decode(file_get_contents($this->getOption('oracle')), true, 512, JSON_THROW_ON_ERROR) as $row) { $oracle[$row['title']] = $row; }
			foreach ($cases as &$case) {
				$original = $oracle[$case['title']] ?? null;
				if (!$original || ($original['source_sha256'] ?? '') !== hash('sha256', $case['text'])) { throw new RuntimeException('Oracle corpus differs: ' . $case['title']); }
				$case['timestamp'] = $original['timestamp'];
			} unset($case);
		}
		$results = []; $repeats = (int)$this->getOption('repeats', 0);
		foreach ($cases as $case) {
			$row = ['title' => $case['title'], 'full_stack' => $this->fullStack, 'timestamp' => $case['timestamp'] ?? $this->timestamp,
				'source_sha256'=>hash('sha256', $case['text']), 'html_tags_loaded'=>\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('HTML Tags'), 'variants' => []];
			foreach ($this->modes as $mode) {
				$row['variants'][$mode] = $this->one($case, $mode, !$this->hasOption('no-trace'));
			}
			if ($repeats > 0) {
				foreach ($this->modes as $mode) { $this->one($case, $mode, false); }
				$row['timings_ms'] = array_fill_keys($this->modes, []);
				for ($i = 0; $i < $repeats; $i++) {
					$modes = $this->modes; shuffle($modes);
					foreach ($modes as $mode) { $row['timings_ms'][$mode][] = $this->one($case, $mode, false)['milliseconds']; }
				}
			}
			$results[] = $row;
			$this->output($case['title'] . "\n");
		}
		file_put_contents($this->getOption('output'), json_encode($results, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT));
	}
	private function one(array $case, string $mode, bool $trace): array {
		$GLOBALS['wgDebatePHPMode'] = in_array($mode, ['optimized','legacy-php'], true) ? 'optimized' : 'reference';
		if ($this->fullStack) { $trace = false; }
		else { CaptureLibrary::$semantic = []; CaptureLibrary::$trace = []; }
		$phpTrace = [];
		$parser = MediaWikiServices::getInstance()->getParserFactory()->create();
		if ($mode === 'legacy-php') {
			$parser->setFunctionHook('debate', [\MediaWiki\Extension\DebatePHP\LegacyHooks::class, 'debate'], Parser::SFH_OBJECT_ARGS);
		} elseif ($trace && $mode !== 'lua') {
			$parser->setFunctionHook('debate', static function($p, $f, $args) use ($mode, &$phpTrace) {
				$f->expand($args[0]); unset($args[0]); $c = new MediaWikiContext($p, $f, $args, $mode === 'optimized', true);
				$r = $mode === 'optimized' ? new Renderer($c) : new ReferenceRenderer($c);
				$out = \UtfNormal\Validator::cleanUp($r->render()); $phpTrace = array_merge($phpTrace, $c->trace());
				return [$out, 'noparse' => true, 'isHTML' => false];
			}, Parser::SFH_OBJECT_ARGS);
		}
		$options = ParserOptions::newFromAnon();
		$options->setTimestamp($case['timestamp'] ?? $this->timestamp);
		$normal = $options->getTemplateCallback();
		$options->setTemplateCallback(static function($title, $p) use ($normal, $mode, $trace) {
			$v = $normal($title, $p);
			if (is_string($v['text'])) {
				if ($title->getNamespace() === NS_TEMPLATE) {
					$v['text'] = $mode === 'lua' ? str_replace('{{#debate:render', '{{#invoke:Debate|render', $v['text']) : str_replace('{{#invoke:Debate|render', '{{#debate:render', $v['text']);
				}
				if ($title->getPrefixedText() === 'Module:Debate' && $mode === 'lua' && $trace) {
					// Wrap only explicit preprocess calls; delegate to the original current frame.
					$v['text'] = str_replace('F:preprocess(', 'tracePreprocess(', $v['text']);
					$needle = 'local ITEM_SEP';
					$position = strpos($v['text'], $needle);
					$helper = "local function tracePreprocess(s) local out = F:preprocess(s); mw.debateTest.trace(s, out); return out end\n";
					$v['text'] = substr_replace($v['text'], $helper, $position, 0);
				}
			} return $v;
		});
		$text = $case['text'];
		// Direct #invoke fixtures use the same signature; template cases are replaced above.
		$text = $mode === 'lua' ? str_replace('{{#debate:render', '{{#invoke:Debate|render', $text) : str_replace('{{#invoke:Debate|render', '{{#debate:render', $text);
		$start = hrtime(true);
		$out = $parser->parse($text, Title::newFromText($case['title']), $options, true, true);
		$elapsed = (hrtime(true) - $start) / 1e6;
		return [
			'milliseconds' => $elapsed, 'html' => $out->getText(),
			'categories' => $out->getCategoryMap(), 'properties' => $out->getPageProperties(),
			'links' => array_map(static fn($t) => [$t['link']->getNamespace(), $t['link']->getDBkey(), $t['pageid'] ?? null], $out->getLinkList(\MediaWiki\Parser\ParserOutputLinkTypes::LOCAL)),
			'templates' => $out->getTemplates(), 'externalLinks' => $out->getExternalLinks(),
			'languageLinks' => $out->getLanguageLinks(), 'modules' => $out->getModules(),
			'flags' => $out->getAllFlags(), 'expiry' => $out->getCacheExpiry(),
			'semantic' => $this->fullStack ? [] : CaptureLibrary::$semantic,
			'semantic_snapshot' => $this->fullStack ? $this->semanticSnapshot($out->getExtensionData(\SMW\ParserData::DATA_ID)) : null,
			'trace' => $this->fullStack ? [] : array_map(static function($entry) use ($parser) {
				$entry['output_resolved'] = $parser->getStripState()->unstripBoth($entry['output']); return $entry;
			}, $mode === 'lua' ? CaptureLibrary::$trace : $phpTrace),
		];
	}
	private function semanticSnapshot($data): array {
		if (!$data instanceof \SMW\SemanticData) { throw new \RuntimeException('Real SMW ParserOutput data is missing'); }
		$properties = [];
		foreach ($data->getProperties() as $property) {
			$values = [];
			foreach ($data->getPropertyValues($property) as $item) { $values[] = [$item->getDIType(), $item->getSerialization()]; }
			$properties[$property->getKey()] = $values;
		}
		$sub = []; foreach ($data->getSubSemanticData() as $child) { $sub[$child->getSubject()->getSerialization()] = $this->semanticSnapshot($child); }
		return ['subject' => $data->getSubject()->getSerialization(), 'properties' => $properties, 'subobjects' => $sub, 'sequence' => $data->getSequenceMap(), 'counts' => $data->getCountMap(), 'errors' => $data->getErrors()];
	}

}
$maintClass = DebateComparison::class;
require RUN_MAINTENANCE_IF_MAIN;
