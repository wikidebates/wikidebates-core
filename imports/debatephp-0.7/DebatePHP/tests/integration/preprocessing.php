<?php
/** Real parser counters; can also run with Scribunto entirely unloaded. No wiki writes. */
$wikiRoot = null;
foreach ($argv as $arg) { if (str_starts_with($arg, '--wiki-root=')) { $wikiRoot = substr($arg, 12); } }
if (!$wikiRoot) { fwrite(STDERR, "--wiki-root required\n"); exit(2); }
require $wikiRoot . '/maintenance/Maintenance.php';

use MediaWiki\Extension\DebatePHP\{MediaWikiContext,Telemetry,Table,NativeTags};
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\{Parser,ParserOptions};
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

class PreprocessingTest extends Maintenance {
	public function __construct() { parent::__construct(); $this->addOption('wiki-root', 'Wiki root', true, true); }
	public function execute() {
		$services = MediaWikiServices::getInstance(); $checks = 0; $profiles = [];
		$check = static function($condition, $message) use (&$checks) {
			$checks++; if (!$condition) { throw new RuntimeException($message); }
		};
		// Fail on any attempted consultation of the old source pages, even if present.
		$services->getHookContainer()->register('BeforeParserFetchTemplateRevisionRecord', static function($context, $title) {
			if (in_array($title->getText(), ['Argument', 'Debate', 'WD/I18N', 'WD/I18N/Data'], true)
				&& $title->getNamespace() === 828) { throw new RuntimeException('Unexpected Lua source fetch'); }
		});
		foreach (['literal', 'dynamic', 'volatile', 'tags', 'xml-tags', 'fallback', 'shortdesc', 'autoedit', 'nested'] as $case) {
			$events = []; $trace = []; $p = $services->getParserFactory()->create();
			if ($case === 'volatile') {
				$p->setFunctionHook('debate', static function($parser, $frame, $args) {
					$frame->setVolatile(); return 'VOLATILE';
				}, Parser::SFH_OBJECT_ARGS);
			}
			$p->setFunctionHook('argument', static function($parser, $frame, $args) use ($case, &$events, &$trace) {
				$telemetry = new Telemetry(true, false, [], static function($e) use (&$events) { $events[] = $e; });
				$c = new MediaWikiContext($parser, $frame, [], true, true, $telemetry, 'Argument');
				if ($case === 'literal') {
					$c->expandField("A\r\nB"); $c->expandField("A\r\nB");
					$c->jsonLd($c->jsonEncode(new Table(['nested' => new Table(['text' => '{{literal}} </script>'])])));
				} elseif ($case === 'tags') {
					NativeTags::observe($telemetry, static function() use ($parser, $frame) {
						return NativeTags::render($parser, $frame, 'a', '{{#if:1|BODY}}', ['title' => '{{#if:1|TITLE}}'], false, ['a' => ['title']]);
					});
				} elseif ($case === 'xml-tags') {
					$c->htmlTag('a', '{{#if:1|BODY}}', new Table(['title' => '{{#if:1|TITLE}}']), true);
				} elseif ($case === 'fallback') {
					$c->htmlTag('a', 'A|B', new Table(['title' => 'C']));
				} elseif ($case === 'shortdesc') {
					$c->shortDescription('{{#if:1|Description}}');
				} elseif ($case === 'autoedit') {
					$c->autoEdit(new Table(['form' => '{{#if:1|Argument}}', 'target' => 'Counter test', 'link text' => 'OK']));
				} elseif ($case === 'nested') {
					// Outer observer must resume after an inner observer, including an exception.
					NativeTags::observe($telemetry, static function() use ($parser, $frame) {
						try { NativeTags::observe(null, static function() { throw new RuntimeException('expected'); }); }
						catch (RuntimeException $e) {}
						return NativeTags::argument($parser, $frame, '{{#if:1|OK}}');
					});
				} else {
					$text = $case === 'volatile' ? '{{#debate:render}}' : '{{#if:1|DYNAMIC_OK}}';
					$c->expandField($text, 'test.field');
					$c->expandField($text, 'test.field');
				}
				$trace = $c->trace(); $telemetry->report();
				return ['OK', 'noparse' => true, 'isHTML' => false];
			}, Parser::SFH_OBJECT_ARGS);
			$out = $p->parse('{{#argument:render}}', Title::newFromText('Counter test'), ParserOptions::newFromAnon());
			$check(!isset($out->getTemplates()[828]), 'Lua source dependency present');
			$profile = $events[0]; $counters = $profile['counters'];
			$expected = ['literal' => 0, 'dynamic' => 1, 'volatile' => 2, 'tags' => 2, 'xml-tags' => 2, 'fallback' => 1, 'shortdesc' => 1, 'autoedit' => 1, 'nested' => 1][$case];
			$check($counters['preprocess_executed'] === $expected, 'Wrong executed count: ' . $case);
			$check(array_sum(array_column($profile['preprocessing'], 'calls')) === $expected, 'Reason totals differ');
			if ($case === 'literal') {
				$check($counters['native_cache_hits'] === 1 && $counters['literal_fields'] === 2, 'Literal cache counters');
				$check($counters['preprocess_cache_hits'] === 0, 'Native hit counted as preprocessing');
			} elseif ($case === 'dynamic' || $case === 'volatile') {
				$check($counters['preprocess_cache_hits'] === ($case === 'dynamic' ? 1 : 0), 'Dynamic cache counters');
				$check(count(array_filter($trace, static fn($e) => !$e['hit'])) === $expected, 'Executed count disagrees with expansion trace');
			}
			$profiles[$case] = $profile;
		}
		foreach (['reference', 'optimized'] as $mode) {
			$GLOBALS['wgDebatePHPMode'] = $mode;
			foreach (['argument', 'debate'] as $function) {
				$p = $services->getParserFactory()->create();
				$out = $p->parse('{{#' . $function . ':render}}', Title::newFromText('Autonomous PHP test'), ParserOptions::newFromAnon());
				$check(!isset($out->getTemplates()[828]) && !str_contains($out->getText(), 'scribunto-error'), 'Native render depends on source modules');
				$check(str_contains($out->getText(), 'application/ld+json'), 'Native JSON-LD missing');
			}
		}
		$withoutScribunto = !ExtensionRegistry::getInstance()->isLoaded('Scribunto');
		if (getenv('DEBATEPHP_WITHOUT_SCRIBUNTO')) { $check($withoutScribunto, 'Scribunto unexpectedly loaded'); }
		$this->output(json_encode(['assertions' => $checks, 'passed' => true, 'without_scribunto' => $withoutScribunto, 'profiles' => $profiles], JSON_PRETTY_PRINT) . "\n");
	}
}
$maintClass = PreprocessingTest::class; require RUN_MAINTENANCE_IF_MAIN;
