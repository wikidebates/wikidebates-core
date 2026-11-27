<?php
/** Native adapter tests using explicit doubles. No real MediaWiki installation is loaded. */
namespace {
	define('CONTENT_MODEL_SCRIBUNTO', 'Scribunto'); define('NS_SPECIAL', -1); define('NS_MEDIA', -2); define('NS_FILE', 6);
	function wfDebugLog(...$args) {}
	final class ExtVariables {
		private array $values = [];
		public static function get($parser) { return $parser->variables; }
		public static function pfObj_vardefine($parser, $frame, $args) { $frame->setVolatile(); self::get($parser)->setVarValue($args[0],$args[1]); }
		public static function pfObj_var($parser, $frame, $args) { $frame->setVolatile(); return self::get($parser)->getVarValue($args[0]) ?? ''; }
		public function setVarValue($key, $value = '') { $this->values[trim($key)] = trim($value); }
		public function getVarValue($key) { return $this->values[trim($key)] ?? null; }
	}
}
namespace MediaWiki\Registration {
	final class ExtensionRegistry {
		public static bool $variables = true;
		public static function getInstance() { return new self; }
		public function isLoaded($name) { return $name === 'Variables' && self::$variables; }
	}
}
namespace MediaWiki {
	final class MediaWikiServices {
		public static string $mode = 'reference';
		public static function getInstance() { return new self; }
		public function getMainConfig() { return new class {
			public function get($key) { return match ($key) { 'DebatePHPMode' => MediaWikiServices::$mode, 'UrlProtocols' => [], default => false }; }
		}; }
		public function getContentLanguage() { return new class { public function getCode() { return 'fr'; } }; }
		public function getHookContainer() { return new class { public function run(...$args) {} }; }
	}
}
namespace MediaWiki\Html { final class Html {
	public static function rawElement($name,$attrs,$content) { return '<'.$name.'>'.$content.'</'.$name.'>'; }
} }
namespace MediaWiki\Json { final class FormatJson {
	const ALL_OK = 0;
	public static function encode($value, ...$rest) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
} }
namespace UtfNormal { final class Validator { public static function cleanUp($text) { return $text; } } }
namespace MediaWiki\Title {
	final class Title {
		public function __construct(private string $text) {}
		public static function newFromText($text) { return str_contains($text, '[') ? null : new self(str_replace('_', ' ', $text)); }
		public static function newFromURL($text) { return self::newFromText($text); }
		public static function makeTitle($ns, $key) { return new self($key); }
		public function hasContentModel($model) { return true; }
		public function getPrefixedText() { return $this->text; }
		public function getArticleID() { return 42; }
		public function getNamespace() { return str_starts_with($this->text, 'Special:') ? NS_SPECIAL : 0; }
		public function getDBkey() { return str_replace(' ', '_', $this->text); }
		public function equals($other) { return $this->text === $other->text; }
		public function getFullURL($query = []) { return 'https://wiki.example' . $this->getLocalURL($query); }
		public function getLocalURL($query = []) { return '/wiki/' . rawurlencode($this->getDBkey()) . ($query ? '?' . http_build_query($query) : ''); }
	}
}
namespace MediaWiki\Extension\DebatePHP {
	final class NativeTags {
		public static array $lastAttributes = [];
	public static function observe($telemetry, $operation): string { return $operation(); }
	public static function renderLink($parser, $frame, $content, $attrs): string { self::$lastAttributes = $attrs; return '<a>'.$content.'</a>'; }
	}
}
namespace MediaWiki\Parser {
	final class ParserOutputFlags {
		const VARY_PAGE_ID = 'vary-page-id';
		const VARY_REVISION = 'vary-revision';
	}
	final class PPFrame {
		public int $depth = 1;
		public int $reads = 0;
		public int $expands = 0;
		public ?self $child = null;
		public ?string $childTitle = null;
		public function __construct(private array $arguments = [], public bool $volatile = false) {}
		public function newChild($nodes, $title) {
			$this->childTitle = $title->getPrefixedText(); $args = [];
			foreach ($nodes as $node) { $args[$node->name] = $node->value; }
			return $this->child = new self($args, $this->volatile);
		}
		public function getArguments() { $this->reads++; return $this->arguments; }
		public function expand($node) { $this->expands++; return is_string($node) ? $node : $node->value; }
		public function isVolatile() { return $this->volatile; }
		public function getTTL() { return 3600; }
		public function setVolatile() { $this->volatile = true; }
	}
	final class Parser {
		const MARKER_PREFIX = "\x7f'\"`UNIQ-";
		const SFH_OBJECT_ARGS = 2; const PTD_FOR_INCLUSION = 1;
		public array $hooks = []; public array $fetched = []; public ?string $changed = null;
		public \ExtVariables $variables;
		private object $output;
		public function __construct() { $this->variables = new \ExtVariables; $this->output = new class {
			public array $flags = [];
			private array $extensionData = [];
			public function getExtensionData($key) { return $this->extensionData[$key] ?? null; }
			public function setExtensionData($key, $value): void { $this->extensionData[$key] = $value; }
			public function setOutputFlag($name) { $this->flags[] = $name; }
			public function addLink($title) {}
			public function setPageProperty($key, $value) {}
		}; }
		public function setFunctionHook($name, $callback, $flags) { $this->hooks[$name] = [$callback, $flags]; }
		public function insertStripItem($text) { return $text; }
		public function getTags() { return []; }
		public function setHook($name,$callback) {}
		public function getFunctionSynonyms() { return [['shortdesc'=>'MAG_SHORTDESC'],[]]; }
		public function getTitle() { return \MediaWiki\Title\Title::newFromText('Argument de démonstration'); }
		public function getOutput() { return $this->output; }
		public function getRevisionId(): ?int { return null; }
		public function fetchTemplateAndTitle($title) {
			$name = $title->getPrefixedText(); $this->fetched[] = $name;
			$file = match ($name) {
				'Module:Argument' => 'Module-Argument.reference.lua', 'Module:Debate' => 'Module-Debate.reference.lua',
				'Module:WD/I18N' => 'WD-I18N.reference.lua', 'Module:WD/I18N/Data' => 'WD-I18N-Data.snapshot.lua',
			};
			$text = file_get_contents(__DIR__ . '/../resources/' . $file);
			return [$text . ($name === $this->changed ? '\n-- changed' : ''), $title];
		}
		public function getPreprocessor() { return new class { public function preprocessToObj($text, $flags) { return $text; } public function newFrame() { return new PPFrame; } }; }
	}
}
namespace {
	require __DIR__ . '/bootstrap.php';
	require __DIR__ . '/semantic_api_double.php';
	use MediaWiki\Extension\DebatePHP\{Hooks,MediaWikiContext};
	use MediaWiki\Parser\{Parser,PPFrame};
	use MediaWiki\Registration\ExtensionRegistry;
	use MediaWiki\Extension\DebatePHP\Tests\SemanticApiDouble;
	SemanticApiDouble::$rows = [[['fulltext', [['_txt', 'Parent']]], ['pro', [['_txt', 'A'], ['_txt', ['fulltext' => 'B']]]]]];
	set_error_handler(static function($severity, $message, $file, $line) { throw new \ErrorException($message, 0, $severity, $file, $line); });
	$count = 0;
	$check = static function($condition, $message) use (&$count) { $count++; if (!$condition) { throw new \RuntimeException($message); } };
	$node = static fn($name, $value) => (object)['name' => $name, 'value' => $value];
	$p = new Parser; Hooks::onParserFirstCallInit($p);
	$check(array_keys($p->hooks) === ['argument', 'debate', 'argumentmap'], 'All three parser functions must be registered');
	$check($p->hooks['argument'][1] === Parser::SFH_OBJECT_ARGS, 'Arguments must remain parser nodes');
	$f = new PPFrame(['summary' => 'inherited', 'keywords' => 'mot']);
	$c = new MediaWikiContext($p, $f, [$node('summary', 'A=B|C'), $node('creation-date', ' 2025-01-01 ')], true, true, null, 'Argument');
	$check($f->childTitle === 'Module:Argument', 'Wrong module frame');
	$check($p->fetched === [], 'Runtime must not read Lua pages');
	$check($c->arguments()['summary'] === 'A=B|C', 'Expanded delimiters changed parameter boundaries');
	$check($c->arguments()['keywords'] === 'mot', 'Parent arguments missing');
	$check($c->arguments()['creation-date'] === ' 2025-01-01 ', 'Argument-specific trimming changed');
	$copy = $c->arguments();
	$copy['summary'] = 'changed locally';
	$check($c->arguments()['summary'] === 'A=B|C', 'Native argument snapshots cannot mutate the frames');
	$copy = $c->pageVars();
	$copy['rawTitle'] = 'changed locally';
	$check($c->pageVars()['rawTitle'] !== $copy['rawTitle'], 'Page metadata is an independent native array');
	$copy = $c->titleInfo('A_B');
	$copy['prefixedText'] = 'changed locally';
	$check($c->titleInfo('A_B') === ['prefixedText' => 'A B'], 'Title cache returns an independent native array');
	$merged = new MediaWikiContext(new Parser, new PPFrame(['summary' => 'inherited', 'keywords' => 'old']),
		[$node('summary', ''), $node('keywords', '0')], true, false, null, 'Argument');
	$check($merged->arguments() === ['summary' => 'inherited', 'keywords' => '0'], 'Empty child values do not replace inherited parameters; zero does');
	$check($c->message('Argument.text', 'fr', 'summary_title') === 'Résumé', 'Argument translations missing');
	$debateContext = new MediaWikiContext(new Parser, new PPFrame(['topic' => "\v\t0\f", 'intro' => "\0A\0", 'blank' => "\r\n"]), [], true, false, null, 'Debate');
	$check($debateContext->arguments()['topic'] === '0' && $debateContext->arguments()['intro'] === "\0A\0"
		&& !array_key_exists('blank', $debateContext->arguments()), 'Debate trims ASCII whitespace, retains zero and NUL');
	$check($c->dbKey("[ A\tB\r\n\v\f C") === '[_A_B____ C', 'Invalid-title fallback replaces each ASCII whitespace byte');
	$c->htmlTag('a', 'native attribute conversion', ['title' => true, 'class' => false, 'href' => '0', 'data-href' => 0, 'other' => null], true);
	$check(\MediaWiki\Extension\DebatePHP\NativeTags::$lastAttributes === ['title' => 'true', 'class' => 'false', 'href' => '0', 'data-href' => '0', 'other' => 'nil'], 'Native link attributes preserve legacy scalar text');

	$c->preprocess('test'); $c->preprocess('test');
	$check($f->child->expands === 1 && $f->child->reads >= 2, 'Expansion cache did not force frame arguments');
	$rows = $c->readSemantic([1 => '[[A]]', 2 => '?P=pro'], 'other')->rows;
	$check(SemanticApiDouble::$query === ['[[A]]','?P=pro'], 'SMW query parameters changed');
	$check($rows[1]['pro'][1] === 'A' && $rows[1]['pro'][2]['fulltext'] === 'B', 'Nested query arrays must be one-based');
	SemanticApiDouble::$fail = true; $check($c->readSemantic([1 => '[[A]]'], 'other')->rows === null, 'SMW error must be swallowed'); SemanticApiDouble::$fail = false;
	$c->variableSet(' WD_QUOTES_AUTHORS ', ' Alice ');
	$check(!ExtensionRegistry::getInstance()->isLoaded('VariablesLua'), 'Test does not expose VariablesLua');
	$check($c->variableGet('WD_QUOTES_AUTHORS') === 'Alice', 'Variable state must use Variables trimming without VariablesLua');
	$c->variableSet('WD_TEST_ZERO', '0');
	$check($c->variableGet('WD_TEST_ZERO') === '0', 'Variable zero is not missing');
	$check($c->variableGet('WD_TEST_MISSING') === '', 'Variables defines the missing-value fallback');
	$p->variables->setVarValue('WD_QUOTES_AUTHORS', 'Bob');
	$check($c->variableGet('WD_QUOTES_AUTHORS') === 'Bob', 'External template variable writes must remain visible');
	ExtensionRegistry::$variables = false; $c->variableSet('absent', 'value');
	$check($c->variableGet('absent') === null, 'Missing Variables must be a no-op'); ExtensionRegistry::$variables = true;
	$check($c->titleInfo('A_B')['prefixedText'] === 'A B' && $c->titleInfo('[') === null, 'Title normalization changed');
	$check($c->pageVars()['pageId'] === '42' && in_array('vary-page-id', $p->getOutput()->flags), 'Page id cache dependency missing');
	$p2 = new Parser; $p2->changed = 'Module:Argument';
	new MediaWikiContext($p2, new PPFrame, [], true, false, null, 'Argument');
	$check($p2->fetched === [], 'Changed Lua must have no effect');
	$p3 = new Parser; $f3 = new PPFrame([], true); $c3 = new MediaWikiContext($p3, $f3, [], true, false, null, 'Argument');
	$c3->preprocess('test'); $c3->preprocess('test'); $check($f3->child->expands === 2, 'Volatile expansion was cached');
	foreach (['reference','optimized'] as $mode) {
		\MediaWiki\MediaWikiServices::$mode = $mode;
		$renderParser = new Parser;
		$result = Hooks::argument($renderParser, new PPFrame, ['render', $node('summary', 'A=B|C')]);
		$payload = $renderParser->getOutput()->getExtensionData(
			\MediaWiki\Extension\Wikidebates\Seo\Metadata::DATA_KEY);
		$check(is_array($payload) && array_key_exists('wd_published_time', $payload) &&
			$payload['wd_published_time'] === null, 'No invented date for unsaved parser double');
		$check(in_array('vary-revision', $renderParser->getOutput()->flags, true),
			'Unsaved output must be revisited after save');
		$check(str_contains($result[0], 'A=B|C'), 'Hook lost summary in '.$mode);
		$check($result['noparse'] === true && $result['isHTML'] === false, 'Wrong parser return flags');
	}
	try { Hooks::argument(new Parser, new PPFrame, ['unknown']); $check(false,'Unknown entry accepted'); }
	catch (\InvalidArgumentException $e) { $check(str_contains($e->getMessage(),'#argument'), 'Wrong argument entry error'); }
	$events = [];
	$profile = new \MediaWiki\Extension\DebatePHP\Telemetry(true, false, [], static function($event) use (&$events): void { $events[] = $event; });
	$profiled = new MediaWikiContext(new Parser, new PPFrame, [], false, false, $profile, 'Argument');
	$renderer = new \MediaWiki\Extension\DebatePHP\ArgumentRenderer($profiled);
	$reflection = new \ReflectionClass($renderer);
	$makeCache = $reflection->getMethod('cacheMake');
	foreach (['SMW_TITLES_CACHE', 'CARD_CACHE'] as $name) {
		$reflection->getProperty($name)->setValue($renderer, $makeCache->invoke($renderer, 250));
	}
	$ask = $reflection->getMethod('readSemantic');
	$ask->invoke($renderer, [1 => '[[SECRET]]'], 'debate-parents');
	$ask->invoke($renderer, [1 => '[[SECRET]]'], 'argument-parents');
	$titles = $reflection->getMethod('smwAskTitles');
	$titles->invoke($renderer, '[[SECRET2]]', 'breadcrumb');
	$titles->invoke($renderer, '[[SECRET2]]', 'breadcrumb');
	SemanticApiDouble::$fail = true;
	$ask->invoke($renderer, [1 => '[[SECRET3]]'], 'cards');
	$ask->invoke($renderer, [1 => '[[SECRET3]]'], 'cards');
	SemanticApiDouble::$fail = false;
	$profiled->readSemantic([1 => '[[SECRET4]]'], 'other');
	$profiled->smwSet(['SECRET' => 'VALUE']);
	$check(SemanticApiDouble::$write === ['SECRET=VALUE'], 'Native SMW write keeps literal values');
	$profile->report();
	$write = ['prop' => ['0', 'A|B=C', 'é', '0'], 'zero' => 0, 'missing' => null, 'empty' => []];
	$c->smwSet($write);
	$check(SemanticApiDouble::$write === ['prop=0', 'prop=A|B=C', 'prop=é', 'prop=0', 'zero=0'], 'SMW writes preserve ordered values, delimiters and zero, omit missing properties');
	$check($write['prop'] === ['0', 'A|B=C', 'é', '0'] && array_key_exists('missing', $write), 'Native SMW write leaves caller data unchanged');
	$event = $events[0];
	$check($event['semantic']['debate-parents']['read']['calls'] === 1, 'Executed SMW calls counted once');
	$check($event['semantic_cache_hits']['argument-parents']['query'] === 1, 'Shared query hit attributed to its requesting origin');
	$check($event['semantic_cache_hits']['breadcrumb']['titles'] === 1, 'Title-layer hit is distinct from query cache hit');
	$check($event['semantic']['cards']['query']['failures'] === 1 && $event['semantic']['cards']['read']['failures'] === 1, 'Failures counted, including recovered exceptions');
	$check($event['semantic_cache_hits']['cards']['query'] === 1, 'Cached null stays a cache hit');
	$check($event['semantic']['other']['read']['calls'] === 1, 'Query origin restored after exception');
	$check($event['semantic']['writes']['write']['calls'] === 1, 'Write scheduling counted');
	$check($event['semantic']['debate-parents']['read']['milliseconds'] >= $event['semantic']['debate-parents']['query']['milliseconds'], 'Inclusive timings consistent');
	$check(!str_contains(json_encode($events), 'SECRET') && !str_contains(json_encode($events), 'VALUE'), 'Profiler must not log queries or property values');
	echo "$count native adapter assertions passed (MediaWiki/SMW doubles).\n";
}
