<?php

/** Exercise the full corpus against real extension APIs, with optional historical comparison. */
use MediaWiki\Extension\DebatePHP\I18n;
use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Title\Title;

require __DIR__ . '/nativeOptimizationSupport.php';
$resources = dirname(__DIR__) . '/debatephp/resources/';
$nativeOnly = !\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('Scribunto');
$renderModes = $nativeOnly ? ['optimized'] : ['lua', 'optimized'];
if (!$nativeOnly) {
	foreach (['Module:Debate' => 'Module-Debate.reference.lua', 'Module:Argument' => 'Module-Argument.reference.lua',
		'Module:WD/I18N' => 'WD-I18N.reference.lua', 'Module:WD/I18N/Data' => 'WD-I18N-Data.snapshot.lua'] as $title => $file) {
		fsSave($title, file_get_contents($resources . $file), CONTENT_MODEL_SCRIBUNTO);
	}
}
// Public snapshots already retained in the repository; no live wiki is contacted.
foreach (['wiki-snapshot.xml', 'helpers.xml'] as $file) {
	$xml = simplexml_load_file(dirname(__DIR__) . '/debatephp/tests/fixtures/' . $file);
	foreach ($xml->page as $page) {
		if ((int)$page->ns !== NS_TEMPLATE) { continue; }
		$name = preg_replace('/^[^:]+:/', '', (string)$page->title);
		$source = (string)$page->revision->text;
		fsSave(Title::makeTitle(NS_TEMPLATE, $name)->getPrefixedText(), $nativeOnly ? fsSource($source, 'optimized') : $source);
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

/** Export only the old oracle's actual marker contents, not native results. */
function fsSeoMarkerMap(\MediaWiki\Parser\Parser $parser, array $values): array {
	$map = [];
	$strip = $parser->getStripState();
	$pattern = '/' . preg_quote($parser::MARKER_PREFIX, '/') . '[^\x7f<>&\'"]+' .
		preg_quote($parser::MARKER_SUFFIX, '/') . '/';
	$visit = static function($value) use (&$visit, &$map, $strip, $pattern): void {
		if (is_array($value)) {
			foreach ($value as $part) { $visit($part); }
		} elseif (is_string($value) && preg_match_all($pattern, $value, $matches)) {
			foreach ($matches[0] as $marker) {
				if (!array_key_exists($marker, $map)) {
					$map[$marker] = html_entity_decode($strip->killMarkers($strip->unstripNoWiki($marker)),
						ENT_QUOTES | ENT_HTML5, 'UTF-8');
				}
			}
		}
	};
	$visit($values);
	return $map;
}

function fsRender(string $title, string $source, string $mode, bool $profile = false, ?callable $parseObserver = null, ?int $seoRevision = null): array {
	if (str_starts_with($mode, 'before-')) { $GLOBALS['wgDebatePHPMode'] = 'optimized'; }
	$parser = MediaWikiServices::getInstance()->getParserFactory()->create();
	$events = [];
	nativeOptimizationHooks($parser, $mode, $profile, $events);
	$options = ParserOptions::newFromAnon();
	$options->setTimestamp('20261018000000');
	$normal = $options->getTemplateCallback();
	$options->setTemplateCallback(static function($title, $parser) use ($normal, $mode) {
		$value = $normal($title, $parser);
		if (is_string($value['text']) && $title->getNamespace() === NS_TEMPLATE) {
			$value['text'] = fsSource($value['text'], $mode);
		}
		return $value;
	});
	$gcBefore = $parseObserver ? gc_status() : null;
	$start = hrtime(true);
	$out = $parser->parse(fsSource($source, $mode), Title::newFromText($title), $options, true, true, $seoRevision);
	$ms = (hrtime(true) - $start) / 1e6;
	if ($parseObserver) { $parseObserver($gcBefore, gc_status()); }
	check(!str_contains($out->getText(), 'scribunto-error'), 'Real Lua renderer succeeds: ' . $title . '/' . $mode
		. (str_contains($out->getText(), 'scribunto-error') ? ' ' . substr(strip_tags($out->getText()), 0, 1200) : ''));
	check(str_contains($out->getText(), 'application/ld+json'), 'Real renderer emits JSON-LD: ' . $title . '/' . $mode);
	$data = $out->getExtensionData(\SMW\ParserData::DATA_ID);
	check($data instanceof \SMW\SemanticData, 'Genuine SMW parser data: ' . $title . '/' . $mode);
	$context = new MediaWikiContext($parser, $parser->getPreprocessor()->newFrame(), [], false);
	$templates = [];
	foreach ($out->getLinkList(ParserOutputLinkTypes::TEMPLATE) as $entry) {
		$templates[$entry['link']->getNamespace()][$entry['link']->getDBkey()] = [$entry['pageid'], $entry['revid']];
	}
	if (!\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('Scribunto')) {
		check(!isset($templates[828]), 'No dependency on historical modules: ' . $title);
	}
	$variables = [];
	foreach (['MAX', 'AUTHORS', 'ARTICLES', 'WORKS', 'PUBLISHERS', 'PLACES'] as $key) {
		$variables[$key] = $context->variableGet('WD_QUOTES_' . $key);
	}
	$markerMap = [];
	if ($seoRevision !== null && $mode === 'lua') {
		preg_match_all('~<script\b[^>]*\btype="application/ld\+json"[^>]*>(.*?)</script>~is', $out->getText(), $matches);
		$graphs = array_map(static fn($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
		$markerMap = fsSeoMarkerMap($parser, [$graphs, $out->getPageProperties()]);
	}
	return [
		'seo_marker_map' => $markerMap,
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

function fsSource(string $source, string $mode): string {
	return $mode === 'lua'
		? str_replace(['{{#debate:render', '{{#argument:render'], ['{{#invoke:Debate|render', '{{#invoke:Argument|render'], $source)
		: str_replace(['{{#invoke:Debate|render', '{{#invoke:Argument|render'], ['{{#debate:render', '{{#argument:render'], $source);
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
	$seoComparison = $nativeOnly || getenv('WD_FULLSTACK_LEGACY_HTMLTAGS') === '1';
	$seoSameDay = $seoComparison && ($case['name'] ?? '') === 'argument init-today';
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
		if ($seoComparison) { \MWTimestamp::setFakeTime($seoSameDay ? '20261018000000' : '20251018000000'); }
		try { fsSave($case['title'], 'Stable page identity for the isolated render comparison'); fsFlush(); }
		finally { if ($seoComparison) { \MWTimestamp::setFakeTime(false); } }
	}
	$row = ['title' => $case['title'], 'name' => $case['name'] ?? $case['title'], 'full_stack' => true,
		'subject_id' => (int)$title->getArticleID(), 'variants' => []];
	$modes = getenv('WD_OPTIMIZATION_BASELINE') ? [...$renderModes, 'before-optimized'] : $renderModes;
	$seoRevision = null;
	if ($seoComparison) {
		$record = $services->getWikiPageFactory()->newFromTitle($title)->getRevisionRecord();
		check($record && $record->getId(), 'Stored fixture revision: ' . $row['name']);
		$seoRevision = $record->getId();
		$row['seo_fixture'] = [
			'preview' => false,
			'modified' => wfTimestamp(TS_ISO_8601, $record->getTimestamp())
		];
	}
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
		$source = fsSource($case['text'], $mode);
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
