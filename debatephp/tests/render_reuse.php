<?php
require __DIR__ . '/../../tests/bootstrap.php';
require __DIR__ . '/argument_context.php';

use MediaWiki\Extension\DebatePHP\ArgumentReferenceRenderer;
use MediaWiki\Extension\DebatePHP\ArgumentRenderer;

$case = ['title' => 'Native reuse', 'smw' => false, 'args' => []];
$caseProperty = new ReflectionProperty(ArgumentTestContext::class, 'case');
$rendererClass = new ReflectionClass(ArgumentReferenceRenderer::class);
$checks = 0;
foreach ([ArgumentReferenceRenderer::class, ArgumentRenderer::class] as $class) {
	$context = new ArgumentTestContext($case);
	$renderer = new $class($context);
	$urlCache = $rendererClass->getProperty('URLENC_CACHE')->getValue($renderer);
	$previousPreparation = null;
	foreach (['fr', 'en', 'fr', 'en'] as $iteration => $lang) {
		$case['args'] = ['lang' => $lang, 'creation-date' => '2026-01-01', 'summary' => 'Summary ' . $iteration,
			'summary-warnings' => 'summary_unclear', 'keywords' => '0,é,kw ' . $iteration, 'sections' => 'section ' . $iteration,
			'bibliography' => 'Author⟬Article⟬Work⟬⟬⟬⟬⟬Publisher⟭'];
		$caseProperty->setValue($context, $case);
		foreach ($context->effects as $key => $_) { $context->effects[$key] = []; }
		$actual = $renderer->render();
		$freshContext = new ArgumentTestContext($case);
		$expected = (new $class($freshContext))->render();
		same($expected, $actual, 'Reusing renderer across languages/changed text equals fresh renderer');
		same($freshContext->effects, $context->effects, 'No variable, SEO or preprocessing effects skipped on reuse');
		$preparation = $rendererClass->getProperty('renderData')->getValue($renderer);
		check($preparation !== null && $preparation !== $previousPreparation, 'Pure preprocessing reset on render entry');
		same($urlCache, $rendererClass->getProperty('URLENC_CACHE')->getValue($renderer), 'Pure URL FIFO keeps original renderer lifetime');
		$previousPreparation = $preparation;
		$checks += 4;
	}
}
// The nine reference fields are expanded once, in the original order, and their
// expanded values reach both HTML and the JSON-LD citation builder.
foreach ([\MediaWiki\Extension\DebatePHP\ReferenceRenderer::class, \MediaWiki\Extension\DebatePHP\Renderer::class] as $class) {
	$case = ['title' => 'Native debate references', 'args' => ['lang' => 'en']];
	$context = new class($case) extends ArgumentTestContext {
		public array $expansions = [];
		public array $referenceForms = [];
		public function message(string $domain, string $lang, string $key, ...$args): string {
			if ($domain === 'Debate.forms' && preg_match('/^(biblio|weblio(?:graphy)?|video(?:graphy)?)_(pro|con|neutral)_title$/D', $key)) {
				$this->referenceForms[] = $key;
			}
			return parent::message($domain, $lang, $key, ...$args);
		}
		public function expandField(string $text, string $origin = ''): string {
			$this->expansions[] = [$origin, $text];
			return str_replace('RAW', 'Expanded', $text);
		}
	};
	$renderer = new $class($context);
	foreach ([true, false] as $populated) {
		$expected = [];
		$args = ['lang' => 'en'];
		foreach (['bibliography', 'webliography', 'videography'] as $format) {
			foreach (['pro', 'con', 'neutral'] as $side) {
				$label = 'RAW-' . $format . '-' . $side;
				$raw = match ($format) {
					'bibliography' => 'Author⟬' . $label . '⟬Work⟬⟬⟬⟬⟬Publisher⟭',
					'webliography' => $label . '⟬Site⟬https://example.test/⟬Author⟭',
					'videography' => $label . '⟬https://example.test/⟭'
				};
				$field = $side === 'neutral' ? $format : $side . '-' . $format;
				$args[$field] = $populated ? $raw : '';
				if ($populated) {
					$expected[] = ['debate.' . $format . '.' . $side, $raw];
				}
			}
		}
		$case['args'] = $args;
		$caseProperty->setValue($context, $case);
		$context->expansions = [];
		$context->referenceForms = [];
		$html = $renderer->render();
		same([
			'biblio_pro_title', 'biblio_con_title', 'biblio_neutral_title',
			'webliography_pro_title', 'webliography_con_title', 'webliography_neutral_title',
			'videography_pro_title', 'videography_con_title', 'videography_neutral_title'
		], $context->referenceForms, 'Exact editing form translations and order');
		$checks++;
		same($expected, array_values(array_filter($context->expansions, static fn($entry) =>
			preg_match('/^debate\\.(bibliography|webliography|videography)\\./', $entry[0]))),
			'Reference expansion occurs once per nonempty field in the historical order');
		foreach ($expected as [$origin, $raw]) {
			$label = str_replace(['debate.', '.'], ['', '-'], $origin);
			check(substr_count($html, 'Expanded-' . $label) >= 2, 'Expanded reference appears in visible HTML and JSON-LD: ' . $label);
		}
		check(!str_contains($html, 'RAW-'), 'Unexpanded reference markers never reach HTML or JSON-LD');
		if (!$populated) {
			check(!str_contains($html, 'Expanded-'), 'No stale references after reusing the renderer');
		}
		$checks += count($expected) + ($populated ? 2 : 3);
	}
	$markers = new ReflectionMethod($renderer, 'wkParseWikipediaMarkers');
	foreach ([
		['Single page', []], ['⟭', []], ['⟭ 0 ⟭⟭ A ⟭ A ⟭', ['0', 'A', 'A']],
		["⟭\vA\f⟭ B ⟭", ['A', ' B ']]
	] as [$input, $expected]) {
		same($expected, $markers->invoke($renderer, $input), 'Native Wikipedia marker list preserves grammar and duplicates');
		$checks++;
	}
}
// SEO is an invocation-local effect, not a cached cross-render operation.
foreach ([ArgumentReferenceRenderer::class, ArgumentRenderer::class,
	\MediaWiki\Extension\DebatePHP\ReferenceRenderer::class, \MediaWiki\Extension\DebatePHP\Renderer::class] as $class) {
	$case = ['title' => 'Native SEO reuse', 'smw' => false, 'args' => [
		'lang' => 'en', 'keywords' => '0, 0, Énergie, A, A, é, , 00',
		'sections' => 'Énergie, 0, é, B, 00, 0.0'
	]];
	$context = new ArgumentTestContext($case);
	$renderer = new $class($context);
	foreach ([
		[$case, '0, Énergie, A, é, 00, B, 0.0'],
		[$case, '0, Énergie, A, é, 00, B, 0.0'],
		[array_replace($case, ['args' => ['lang' => 'fr', 'keywords' => '0', 'sections' => '0, B']]), '0, B'],
		[array_replace($case, ['args' => ['lang' => 'en']]), '']
	] as [$fixture, $expected]) {
		$caseProperty->setValue($context, $fixture);
		foreach ($context->effects as $key => $_) { $context->effects[$key] = []; }
		$actual = $renderer->render();
		$fresh = new ArgumentTestContext($fixture);
		same((new $class($fresh))->render(), $actual, 'Reused SEO renderer matches fresh HTML');
		same($fresh->effects, $context->effects, 'Reused SEO renderer matches fresh effects');
		same(1, count($context->effects['seo']), 'Exactly one SEO write per invocation');
		same($expected, $context->effects['seo'][0]['keywords'], 'Stable ordered keyword union retains zero');
		$checks += 4;
	}
}
// Normalization mutates only the renderer's local copy, including whitespace-only fields.
foreach ([ArgumentReferenceRenderer::class, ArgumentRenderer::class] as $class) {
	$case = ['title' => 'Argument input snapshot', 'smw' => false, 'args' => [
		'lang' => 'en', 'summary' => " \tSnapshot summary\r\n ", 'keywords' => ' 0 ',
		'sections' => " \t\r\n ", 'bibliography' => " \t ", 'justifications' => false
	]];
	$context = new ArgumentTestContext($case);
	$before = $context->arguments();
	$renderer = new $class($context);
	$html = $renderer->render();
	same($before, $context->arguments(), 'Renderer must not mutate its input snapshot');
	check(str_contains($html, 'Snapshot summary'), 'Normalized summary reaches HTML');
	same('0', $context->effects['seo'][0]['keywords'], 'Normalization preserves literal zero');
	$normalized = $case;
	$normalized['args'] = ['lang' => 'en', 'summary' => 'Snapshot summary', 'keywords' => '0'];
	$fresh = new ArgumentTestContext($normalized);
	same((new $class($fresh))->render(), $html, 'Whitespace-only and absent normalized fields produce identical HTML');
	same($fresh->effects, $context->effects, 'Whitespace-only and absent normalized fields produce identical effects');
	$checks += 5;
}
echo "$checks renderer reuse assertions: language changes, text changes, side effects and cache lifetimes\n";
