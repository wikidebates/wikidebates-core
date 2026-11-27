<?php

/** Exercise the complete native corpus against real MediaWiki and SMW. */
use MediaWiki\Extension\DebatePHP\I18n;
use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Title\Title;

require __DIR__ . '/nativeOptimizationSupport.php';
$resources = dirname(__DIR__) . '/debatephp/resources/';
$renderModes = ['optimized'];
// Public snapshots already retained in the repository; no live wiki is contacted.
foreach (['wiki-snapshot.xml', 'helpers.xml'] as $file) {
	$xml = simplexml_load_file(dirname(__DIR__) . '/debatephp/tests/fixtures/' . $file);
	foreach ($xml->page as $page) {
		if ((int)$page->ns !== NS_TEMPLATE) { continue; }
		$name = preg_replace('/^[^:]+:/', '', (string)$page->title);
		$source = (string)$page->revision->text;
		fsSave(Title::makeTitle(NS_TEMPLATE, $name)->getPrefixedText(), fsSource($source));
	}
}
foreach (['Débat' => 'Template-Debat.php.wikitext', 'Argument' => 'Template-Argument.php.wikitext'] as $name => $file) {
	fsSave(Title::makeTitle(NS_TEMPLATE, $name)->getPrefixedText(), file_get_contents($resources . $file));
}
fsFlush();

// Real semantic parent graph, both languages, including a second level and a cycle.
$i18n = I18n::bundled();
foreach (['en', 'fr'] as $lang) {
	// Set actual map fields explicitly in both laboratories, before any render.
	foreach (['arg_map', 'pro_list', 'con_list', 'justifs_list', 'objs_list'] as $key) {
		fsSave('Property:' . $i18n->message('ArgumentMap.props', $lang, $key), '[[Has type::Text]]');
	}
	foreach (['arg_pro', 'arg_con', 'justif', 'obj', 'parent_debate'] as $key) {
		fsSave('Property:' . $i18n->message('Argument.props', $lang, $key), '[[Has type::Page]]');
	}
	foreach (['breadcrumb', 'argument_map'] as $key) {
		fsSave('Property:' . $i18n->message('Argument.props', $lang, $key), '[[Has type::Text]]');
	}
	$prop = static fn($key) => $i18n->message('Argument.props', $lang, $key);
	fsSave('WD FS parent debate ' . $lang, '{{#set:' . $prop('arg_pro') . '=WD FS related argument|'
		. $prop('arg_con') . '=WD FS related argument|' . $prop('breadcrumb') . '=WD FS parent debate ' . $lang . '}}');
	fsSave('WD FS parent argument ' . $lang, '{{#set:' . $prop('justif') . '=WD FS related argument|'
		. $prop('obj') . '=WD FS child argument|' . $prop('parent_debate') . '=WD FS parent debate ' . $lang
		. '|' . $prop('breadcrumb') . '=WD FS parent debate ' . $lang . '⟭WD FS parent argument ' . $lang . '}}');
	fsSave('WD FS child argument', '{{#set:' . $prop('obj') . '=WD FS related argument}}');
}
fsFlush();

function fsRender(string $title, string $source, string $mode, bool $profile = false, ?callable $parseObserver = null, ?int $seoRevision = null): array {
	if (str_starts_with($mode, 'before-')) { $GLOBALS['wgDebatePHPMode'] = 'optimized'; }
	$parser = MediaWikiServices::getInstance()->getParserFactory()->create();
	$events = [];
	nativeOptimizationHooks($parser, $mode, $profile, $events);
	$options = ParserOptions::newFromAnon();
	$options->setTimestamp('20261018000000');
	$normal = $options->getTemplateCallback();
	$options->setTemplateCallback(static function($title, $parser) use ($normal) {
		$value = $normal($title, $parser);
		if (is_string($value['text']) && $title->getNamespace() === NS_TEMPLATE) {
			$value['text'] = fsSource($value['text']);
		}
		return $value;
	});
	$gcBefore = $parseObserver ? gc_status() : null;
	$start = hrtime(true);
	$out = $parser->parse(fsSource($source), Title::newFromText($title), $options, true, true, $seoRevision);
	$ms = (hrtime(true) - $start) / 1e6;
	if ($parseObserver) { $parseObserver($gcBefore, gc_status()); }
	check(!str_contains($out->getText(), 'scribunto-error'), 'Native renderer succeeds: ' . $title . '/' . $mode
		. (str_contains($out->getText(), 'scribunto-error') ? ' ' . substr(strip_tags($out->getText()), 0, 1200) : ''));
	check(str_contains($out->getText(), 'application/ld+json'), 'Real renderer emits JSON-LD: ' . $title . '/' . $mode);
	$data = $out->getExtensionData(\SMW\ParserData::DATA_ID);
	check($data instanceof \SMW\SemanticData, 'Genuine SMW parser data: ' . $title . '/' . $mode);
	$context = new MediaWikiContext($parser, $parser->getPreprocessor()->newFrame(), [], false);
	$templates = [];
	foreach ($out->getLinkList(ParserOutputLinkTypes::TEMPLATE) as $entry) {
		$templates[$entry['link']->getNamespace()][$entry['link']->getDBkey()] = [$entry['pageid'], $entry['revid']];
	}
	check(!isset($templates[828]), 'No dependency on historical modules: ' . $title);
	$variables = [];
	foreach (['MAX', 'AUTHORS', 'ARTICLES', 'WORKS', 'PUBLISHERS', 'PLACES'] as $key) {
		$variables[$key] = $context->variableGet('WD_QUOTES_' . $key);
	}
	return [
		'milliseconds' => $ms, 'html' => $out->getText(), 'categories' => $out->getCategoryMap(),
		'properties' => $out->getPageProperties(), 'templates' => $templates,
		'links' => array_map(static fn($entry) => [$entry['link']->getNamespace(), $entry['link']->getDBkey(), $entry['pageid']], $out->getLinkList(ParserOutputLinkTypes::LOCAL)),
		'externalLinks' => $out->getExternalLinks(), 'languageLinks' => $out->getLanguageLinks(),
		'modules' => $out->getModules(), 'flags' => $out->getAllFlags(), 'expiry' => $out->getCacheExpiry(),
		'semantic_snapshot' => fsSnapshot($data), 'variables' => $variables, 'profiles' => $events,
		...($seoRevision !== null ? [
			'native_seo' => $out->getExtensionData(\MediaWiki\Extension\Wikidebates\Seo\Metadata::DATA_KEY)
		] : [])
	];
}

function fsSource(string $source): string {
	return str_replace(['{{#invoke:Debate|render', '{{#invoke:Argument|render'], ['{{#debate:render', '{{#argument:render'], $source);
}

$cases = json_decode(file_get_contents(dirname(__DIR__) . '/debatephp/tests/fixtures/debate-v070.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = array_merge($cases, require __DIR__ . '/nativeOptimizationCases.php');
foreach ((static fn() => require __DIR__ . '/renderCases.php')() as $name => $text) {
	$cases[] = ['title' => 'WD FS ' . $name, 'text' => $text];
}
$report['skipped_argument_cases'] = [];
foreach (json_decode(file_get_contents(dirname(__DIR__) . '/debatephp/tests/fixtures/argument-cases.json'), true, 512, JSON_THROW_ON_ERROR) as $case) {
	if (!Title::newFromText($case['title']) || ($case['smw'] ?? true) === false || ($case['vars'] ?? true) === false) {
		$report['skipped_argument_cases'][] = ['name' => $case['name'], 'reason' => !Title::newFromText($case['title'])
			? 'Invalid MediaWiki title; covered by the standalone boundary tests'
			: 'Extension absence is covered by tests/mediawiki.php; this run requires the real extensions'];
		continue;
	}
	$text = '{{#argument:render';
	foreach ($case['args'] as $key => $value) { $text .= '|' . $key . '=' . str_replace('|', '{{!}}', $value); }
	$cases[] = ['title' => $case['title'], 'name' => 'argument ' . $case['name'], 'text' => $text . '}}<references/>'];
}
foreach (['en', 'fr'] as $lang) {
	$cases[] = ['title' => 'WD FS related argument', 'name' => 'semantic parent graph ' . $lang,
		'text' => '{{#argument:render|lang=' . $lang . '|summary=Related argument|justifications=WD FS child argument⟬Child⟬⟭|creation-date=2026-01-01}}'];
}
$quote = '{{#argument:render|lang=en|quotes=Quote⟬⟬⟬Author⟬⟬⟬Article⟬⟬⟬Book⟬⟬⟬1⟬⟬⟬2⟬⟬⟬Chapter⟬⟬⟬3⟬⟬⟬Publisher⟬⟬⟬Paris⟬⟬⟬2026⟬⟬⟬https://example.test⟬⟬⟬quote_translated⟭⟭⟭|creation-date=2026-01-01}}';
$cases[] = ['title' => 'WD FS repeated quotes', 'text' => $quote . $quote];
$results = [];
foreach ($cases as $case) {
	$seoSameDay = ($case['name'] ?? '') === 'argument init-today';
	if ($seoSameDay) {
		// The original fixture shares a title with many older arguments.
		// Give only this case its own genuinely same-day stored page.
		$case['title'] = 'WD FS same-day initialization';
	}
	$title = Title::newFromText($case['title']);
	if (!$title) { throw new RuntimeException('Invalid render fixture: ' . $case['title']); }
	if (!$title->exists()) {
		// A stable, genuine historical revision anchors the legacy and native
		// orphan grace-period comparison; the ordinary suite is unchanged.
		\MWTimestamp::setFakeTime($seoSameDay ? '20261018000000' : '20251018000000');
		try { fsSave($case['title'], 'Stable page identity for the isolated render comparison'); fsFlush(); }
		finally { \MWTimestamp::setFakeTime(false); }
	}
	$row = ['title' => $case['title'], 'name' => $case['name'] ?? $case['title'], 'full_stack' => true,
		'subject_id' => (int)$title->getArticleID(), 'source_sha256' => hash('sha256', $case['text']), 'variants' => []];
	$modes = getenv('WD_OPTIMIZATION_BASELINE') ? [...$renderModes, 'before-optimized'] : $renderModes;
	$record = $services->getWikiPageFactory()->newFromTitle($title)->getRevisionRecord();
	check($record && $record->getId(), 'Stored fixture revision: ' . $row['name']);
	$seoRevision = $record->getId();
	$row['seo_fixture'] = [
		'preview' => false,
		'modified' => wfTimestamp(TS_ISO_8601, $record->getTimestamp())
	];
	foreach ($modes as $mode) {
		$row['variants'][$mode] = fsRender($case['title'], $case['text'], $mode, false, null, $seoRevision);
	}
	if (getenv('WD_OPTIMIZATION_BASELINE')) {
		$before = $row['variants']['before-optimized']; $after = $row['variants']['optimized'];
		unset($before['milliseconds'], $after['milliseconds']);
		same($before, $after, 'Exact native before/after HTML, JSON-LD, semantics, variables and dependencies: ' . $row['name']);
	}
	if (str_contains($row['name'], 'semantic parent graph')) {
		$props = $row['variants']['optimized']['semantic_snapshot']['properties'];
		check(isset($props['Breadcrumb']) || isset($props["Fil_d'Ariane"]), 'Parent graph produces real breadcrumb properties');
	}
	$results[] = $row;
	echo 'Rendered real variants: ' . $row['name'] . "\n";
}

// Save actual rendered Debate/Argument pages, then compare their SQLStore data.
$persisted = 0;
foreach ($cases as $index => $case) {
	$name = $case['name'] ?? $case['title'];
	if (!str_contains($name, 'complete debate') && !str_contains($name, 'complete argument') && !str_contains($name, 'semantic parent graph') && !str_contains($name, 'rich native') && !str_contains($name, 'measured lists')) { continue; }
	$modes = getenv('WD_OPTIMIZATION_BASELINE') ? [...$renderModes, 'before-optimized'] : $renderModes;
	foreach ($modes as $mode) {
		fsSave($case['title'], 'Reset semantic state ' . $mode);
		fsFlush();
		if (str_starts_with($mode, 'before-')) { $GLOBALS['wgDebatePHPMode'] = 'optimized'; }
		$source = fsSource($case['text']);
		$isBefore = str_starts_with($mode, 'before-');
		if ($isBefore) {
			$GLOBALS['wdTestPersistBaseline'] = true;
			$installBefore($services->getParser());
		}
		try { fsSave($case['title'], $source); fsFlush(); }
		finally {
			if ($isBefore) {
				$GLOBALS['wdTestPersistBaseline'] = false;
				\MediaWiki\Extension\DebatePHP\Hooks::onParserFirstCallInit($services->getParser());
			}
		}
		$stored = fsStored($case['title']);
		same($results[$index]['variants'][$mode]['semantic_snapshot'], $stored, 'Rendered properties persist unchanged: ' . $name . '/' . $mode);
		$results[$index]['variants'][$mode]['stored_semantics'] = $stored;
		$persisted++;
	}
}
$report['persisted_render_variants'] = $persisted;
$report['render_cases'] = count($results);
$report['native_before_after_comparisons'] = getenv('WD_OPTIMIZATION_BASELINE') ? count($results) : 0;
if (getenv('WD_FULLSTACK_RENDER_OUTPUT')) {
	// Stream complete rows before the benchmarks: avoid duplicating the whole corpus.
	$artifact = fopen(getenv('WD_FULLSTACK_RENDER_OUTPUT'), 'wb');
	check(is_resource($artifact), 'Render comparison artifact opens');
	try {
		check(fwrite($artifact, '[') === 1, 'Render artifact starts');
		foreach ($results as $index => $row) {
			$json = ($index ? ',' : '') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			check(fwrite($artifact, $json) === strlen($json), 'Complete render row written');
		}
		check(fwrite($artifact, ']') === 1, 'Render artifact completes');
	} finally { fclose($artifact); }
}
unset($results, $row, $json, $before, $after);

// A genuine template edit changes its revision dependency and subsequent rendering.
fsSave('Template:WD FS cache', 'WD FS cache BEFORE');
fsFlush();
$cacheSource = '{{#debate:render|lang=en|topic={{WD FS cache}}|introduction=Intro⟬⟬⟬{{WD FS cache}}⟬⟬⟬⟭⟭⟭|creation-date=2026-01-01}}';
$cacheBefore = [];
foreach ($renderModes as $mode) { $cacheBefore[$mode] = fsRender('WD FS cache probe', $cacheSource, $mode); }
fsSave('Template:WD FS cache', 'WD FS cache AFTER');
fsFlush();
foreach ($renderModes as $mode) {
	$after = fsRender('WD FS cache probe', $cacheSource, $mode);
	$before = $cacheBefore[$mode];
	check(str_contains($before['html'], 'WD FS cache BEFORE') && str_contains($after['html'], 'WD FS cache AFTER'), 'Template update is rendered: ' . $mode);
	check($before['templates'][NS_TEMPLATE]['WD_FS_cache'] !== $after['templates'][NS_TEMPLATE]['WD_FS_cache'], 'Template revision dependency updated: ' . $mode);
	$report['template_edit'][$mode] = ['before' => $before['templates'][NS_TEMPLATE]['WD_FS_cache'], 'after' => $after['templates'][NS_TEMPLATE]['WD_FS_cache']];
}

// Warm parse-only measurements: real SMW stack, randomized order, same content.
$timings = [];
foreach (['debate' => $cases[1], 'argument' => ['title' => 'WD FS related argument', 'text' => $quote]] as $kind => $case) {
	foreach ($renderModes as $mode) { fsRender($case['title'], $case['text'], $mode); }
	for ($repeat = 0; $repeat < 9; $repeat++) {
		$modes = $renderModes;
		shuffle($modes);
		foreach ($modes as $mode) { $timings[$kind][$mode][] = fsRender($case['title'], $case['text'], $mode)['milliseconds']; }
	}
}
$report['parse_timings_ms'] = $timings;
if (getenv('WD_OPTIMIZATION_BASELINE')) { require __DIR__ . '/nativeOptimizationBenchmarks.php'; }
fsFlush();
$report['queues_empty'] = !MediaWikiServices::getInstance()->getJobQueueGroup()->queuesHaveJobs();
check($report['queues_empty'], 'All isolated job queues are empty at completion');
