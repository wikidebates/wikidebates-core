<?php

use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\Extension\DebatePHP\NativeTags;
use MediaWiki\Extension\DebatePHP\Table;
use MediaWiki\Extension\DebatePHP\Telemetry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

$operationCount = 0;
foreach (['reference', 'optimized'] as $mode) {
	foreach (['literal', 'dynamic', 'volatile', 'nested', 'json', 'date', 'quotes'] as $case) {
		$events = [];
		$values = [];
		$parser = MediaWikiServices::getInstance()->getParserFactory()->create();
		if ($case === 'volatile') {
			$parser->setFunctionHook('debate', static function($parser, $frame): string {
				$frame->setVolatile();
				return 'VOLATILE';
			}, Parser::SFH_OBJECT_ARGS);
		}
		$parser->setFunctionHook('argument', static function($parser, $frame) use ($case, $mode, &$events, &$values): array {
			$telemetry = new Telemetry(true, false, [], static function(array $event) use (&$events): void { $events[] = $event; });
			$context = new MediaWikiContext($parser, $frame, [], $mode === 'optimized', true, $telemetry, 'Argument');
			$text = 'OK';
			if ($case === 'literal') {
				$values[] = $context->expandField("A\r\nB", 'test.literal');
				$values[] = $context->expandField("A\r\nB", 'test.literal');
			} elseif ($case === 'dynamic' || $case === 'volatile') {
				$source = $case === 'volatile' ? '{{#debate:render}}' : '{{!}}';
				$values[] = $context->expandField($source, 'test.dynamic');
				$values[] = $context->expandField($source, 'test.dynamic');
			} elseif ($case === 'nested') {
				NativeTags::observe($telemetry, static function() use ($parser, $frame): string {
					try { NativeTags::observe(null, static function() { throw new RuntimeException('expected'); }); }
					catch (RuntimeException $e) {}
					return NativeTags::argument($parser, $frame, '{{!}}');
				});
				// A completed observer must not retain state for the next invocation.
				NativeTags::argument($parser, $frame, '{{!}}');
			} elseif ($case === 'json') {
				$values[] = ['outer' => ['inner' => ['text' => '{{literal}} </script><script> " &=| é']]];
				$text = $context->jsonLd($context->jsonEncode(Table::wrap($values[0])));
			} elseif ($case === 'date') {
				$values[] = $context->currentDate();
				$values[] = $context->preprocess('{{CURRENTYEAR}}-{{CURRENTMONTH}}-{{CURRENTDAY2}}');
			} else {
				$values[] = $context->expandQuotes('0⟬⟬⟬Author');
				$values[] = $context->expandQuotes('{{!}}⟬⟬⟬Author');
			}
			$telemetry->report();
			return [$text, 'noparse' => true, 'isHTML' => false];
		}, Parser::SFH_OBJECT_ARGS);
		$options = ParserOptions::newFromAnon();
		$options->setTimestamp('20261018120000');
		$output = $parser->parse('{{#argument:render}}', Title::newFromText('Operation test'), $options);
		$profile = $events[0];
		$expected = ['literal' => 0, 'dynamic' => 1, 'volatile' => 2, 'nested' => 1, 'json' => 0, 'date' => 0, 'quotes' => 1][$case];
		same($expected, $profile['counters']['preprocess_executed'], 'Actual preprocessing count: ' . $case);
		same($expected, array_sum(array_column($profile['preprocessing'], 'calls')), 'Per-reason counter sum: ' . $case);
		if ($case === 'literal') {
			same(["A\nB", "A\nB"], $values, 'Literal newline normalization');
			same(2, $profile['counters']['literal_fields'], 'Literal requests include cache hits');
			same(1, $profile['counters']['native_cache_hits'], 'Native cache hit');
		} elseif ($case === 'dynamic' || $case === 'volatile') {
			same($case === 'dynamic' ? ['|', '|'] : ['VOLATILE', 'VOLATILE'], $values, 'Dynamic values');
			same($case === 'dynamic' ? 1 : 0, $profile['counters']['preprocess_cache_hits'], 'Volatile frames bypass cache');
		} elseif ($case === 'json') {
			preg_match('~<script[^>]+>(.*?)</script>~s', $output->getText(), $match);
			same($values[0], json_decode($match[1], true, 512, JSON_THROW_ON_ERROR), 'Direct JSON preserves values');
			same(1, substr_count($output->getText(), '<script'), 'JSON cannot close the script element');
		} elseif ($case === 'date') {
			same(['2026-10-18', '2026-10-18'], $values, 'Native current date equals MediaWiki expansion');
			same(1, $profile['counters']['preprocess_cache_hits'], 'Native date shares the invocation cache');
		} elseif ($case === 'quotes') {
			same(['0⟬⟬⟬Author', '|⟬⟬⟬Author'], $values, 'Quote expansion preserves literal and dynamic data');
		}
		$operationCount++;
	}
}
echo "OK: $operationCount MediaWiki operation cases, counters, volatile cache, observer restoration, JSON-LD, dates and quotes\n";
