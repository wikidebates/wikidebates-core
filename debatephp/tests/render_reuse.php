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
echo "$checks renderer reuse assertions: language changes, text changes, side effects and cache lifetimes\n";
