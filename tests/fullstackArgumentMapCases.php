<?php

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\DebatePHP\I18n;
use MediaWiki\Request\FauxRequest;
use Wikimedia\ScopedCallback;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

/** Same synthetic SMW data for the no-Lua integration and historical oracle. */
function fsArgumentMapCases(bool $legacy): void {
	if ($legacy) {
		$source = gzdecode(file_get_contents(__DIR__ . '/fixtures/ArgumentMap.reference.lua.gz'));
		same('d355b6a200310a77e3595cd7758fbe3944f2457ff8488898bd8b17e4084ade6b',
			hash('sha256', $source), 'Exact FR 195841 / EN 10184 module snapshot');
		fsSave('Module:ArgumentMap', $source, CONTENT_MODEL_SCRIBUNTO);
	}
	$parserHtml = static function(string $source): string {
		return MediaWikiServices::getInstance()->getParserFactory()->create()
			->parse($source, Title::newFromText('WD Map hover caller'), ParserOptions::newFromAnon())->getText();
	};
	$i18n = I18n::bundled();
	$mapItems = \MediaWiki\Extension\DebatePHP\SerializedData::class;
	same([['page' => 'A', 'title' => 'A'], ['page' => 'B', 'title' => '0'],
		['page' => 'C', 'title' => 'label⟬tail']], iterator_to_array($mapItems::mapItems(' A -;- B⟬0 -;- C⟬label⟬tail -;- ', '-;-')),
		'Shared structured map projection preserves bare titles, zero and extra field markers');
	same('A', $mapItems::mapItem('A', 'A'), 'Shared serialization omits redundant labels');
	same('A⟬B', $mapItems::mapItem('A', 'B'), 'Shared serialization preserves different labels');
	fsSave('WD Map source', 'Source');
	fsSave('WD Map other', 'Other');
	fsSave('WD Map été + 100%', 'Encoded source');
	foreach (['fr', 'en'] as $lang) {
		$properties = [];
		foreach (['arg_map', 'pro_list', 'con_list', 'justifs_list', 'objs_list'] as $key) {
			$properties[$key] = $i18n->message('ArgumentMap.props', $lang, $key);
			fsSave('Property:' . $properties[$key], '[[Has type::Text]]');
		}
		$page = 'WD Map fixture ' . $lang;
		$values = [
			'arg_map' => 'WD Map stored fallback',
			'pro_list' => 'WD_Map_source⟬Source alias-;-WD Map other⟬Other label',
			'con_list' => 'WD Map redlink⟬Missing',
			'justifs_list' => 'WD Map other⟬0⟭WD_Map_source⟬Source alias⟭WD Map été + 100%⟬Encoded source',
			'objs_list' => 'WD Map other'
		];
		$assign = [];
		foreach ($values as $key => $value) {
			$assign[] = $properties[$key] . '=' . $value;
		}
		fsSave($page, '{{#set:' . implode('|', $assign) . '}}');
		fsSave('WD Map cible + 100% ' . $lang, '{{#set:' . implode('|', $assign) . '}}');
		fsFlush();
		$cases = [
			'fallback' => [$page, '', ''],
			'debate' => [$page, 'WD Map source', 'debate'],
			'argument' => [$page, 'WD Map source', ''],
			'unknown source' => [$page, 'WD Map missing source', 'debate'],
			'empty target' => ['', 'WD Map source', 'debate'],
			'empty properties' => ['WD Map other', 'WD Map source', '']
		];
		foreach ($cases as $case => $params) {
			$call = implode('|', $params) . '|lang=' . $lang . '}}';
			$html = $parserHtml('{{#argumentmap:render|' . $call);
			if ($legacy) {
				$oracle = $parserHtml('{{#invoke:ArgumentMap|main|' . $call);
				check(!str_contains($oracle, 'scribunto-error'), 'Real historical map runs: ' . $lang . '/' . $case);
				same($oracle, $html, 'Native and historical map HTML: ' . $lang . '/' . $case);
			}
			if (getenv('WD_FULLSTACK_MAP_CAPTURE') === '1') {
				echo 'WD_MAP_CONTRACT ' . json_encode(['variant' => $legacy ? 'lua-validated' : 'native-only',
					'language' => $lang, 'case' => $case, 'sha256' => hash('sha256', $html)], JSON_THROW_ON_ERROR) . "\n";
			}
			if ($case === 'fallback') {
				check(str_contains($html, 'WD Map stored fallback'), 'Stored full-map fallback');
			} elseif ($case === 'empty target') {
				check(!str_contains($html, 'wk-arg-map'), 'Empty target returns no map');
			} else {
				// MediaWiki may serialize underscores as numeric HTML entities.
				$decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				check(str_contains($decoded, 'wk-arg-map__col is-pro') && str_contains($decoded, 'wk-arg-map__col is-con'),
					'Two map columns: ' . $lang . '/' . $case . '\n' . $html);
				if ($case === 'argument' || $case === 'debate') {
					check(str_contains($html, '<b>Source alias</b>'), 'Existing source highlighted by canonical identity: ' . $html);
					check(str_contains($html, 'Other label') || str_contains($html, '>0</a>'), 'Other links and zero labels survive');
				} elseif ($case === 'unknown source') {
					check(!str_contains($html, '<b>Source alias</b>'), 'Missing source is not highlighted');
				} else {
					check(substr_count($html, 'class="carte-vide"') === 2, 'Empty properties produce two empty cards');
				}
			}
		}
		$encoded = $parserHtml('{{#argumentmap:render|' . rawurlencode('WD Map cible + 100% ' . $lang) . '|'
			. rawurlencode('WD Map été + 100%') . '||encoding=uri|lang=' . $lang . '}}');
		check(str_contains($encoded, '<b>Encoded source</b>'), 'Accents and literal plus/percent survive URI encoding: ' . $encoded);
		$invalid = $parserHtml('{{#argumentmap:render|' . rawurlencode('WD Map]] OR [[+')
			. '||debate|encoding=uri|lang=' . $lang . '}}');
		check(!str_contains($invalid, 'WD Map stored fallback') && !str_contains($invalid, 'wk-arg-map'),
			'Injected semantic syntax is rejected');
		if (!$legacy) {
			fsArgumentMapLazyLookup($parserHtml, $page, $properties, $lang);
			fsArgumentMapApiEnvelope($page, $lang);
			fsArgumentMapDirectReadStudy($page, $properties, $lang);
			fsArgumentMapUpdatedData($parserHtml, $properties, $lang);
		}
	}
	if (!$legacy && in_array(SMW_VERSION, ['7.3.1', '7.4.1'], true)) {
		fsArgumentMapBenchmarkMaintenance();
	}
	echo "OK: native hover map, FR/EN, full fallback, contextual columns, empty/missing data and encoded titles"
		. ($legacy ? '; exact historical Lua HTML comparison' : '; no Lua extensions loaded') . "\n";
}


/** A source not present in a card must cause zero existence probes. */
function fsArgumentMapLazyLookup(callable $render, string $page, array $properties, string $lang): void {
	$services = MediaWikiServices::getInstance();
	$source = 'WD Map probe ' . $lang;
	$target = 'WD Map repeated ' . $lang;
	fsSave($source, 'Probe source');
	$repeated = $source . '⟬First occurrence-;-' . $source . '⟬Second occurrence';
	fsSave($target, '{{#set:' . $properties['pro_list'] . '=' . $repeated . '|'
		. $properties['con_list'] . '=' . $source . '⟬Third occurrence}}');
	fsFlush();
	$exists = 0;
	$queries = 0;
	$hook = $services->getHookContainer()->scopedRegister('TitleExists',
		static function(Title $title, &$value) use ($source, &$exists): void {
			if ($title->getPrefixedText() === $source) {
				$exists++;
			}
		});
	$queryHook = $services->getHookContainer()->scopedRegister('SMW::Store::BeforeQueryResultLookupComplete',
		static function() use (&$queries): bool {
			$queries++;
			return true;
		});
	try {
		$expectedQueries = $services->getMainConfig()->get('WikidebatesArgumentMapDirectRead') ? 0 : 1;
		$absent = $render('{{#argumentmap:render|' . $page . '|' . $source . '|debate|lang=' . $lang . '}}');
		check(str_contains($absent, 'Other label'), 'Nonmatching source still renders the map');
		same(0, $exists, 'Unmatched source incurs no existence probe');
		same($expectedQueries, $queries, 'Selected read mode uses the expected number of SMW queries');
		$queries = 0;
		$matched = $render('{{#argumentmap:render|' . $target . '|' . $source . '|debate|lang=' . $lang . '}}');
		same(1, $exists, 'All three matching cards share one source existence probe');
		same($expectedQueries, $queries, 'Selected read mode works across two populated columns');
		foreach (['First', 'Second', 'Third'] as $label) {
			check(str_contains($matched, '<b>' . $label . ' occurrence</b>'), 'All matching cards are highlighted');
		}
	} finally {
		ScopedCallback::consume($queryHook);
		ScopedCallback::consume($hook);
	}
	$red = $render('{{#argumentmap:render|' . $page . '|WD Map redlink|debate|lang=' . $lang . '}}');
	check(str_contains($red, 'class="new"') && !str_contains($red, '<b>Missing</b>'),
		'Nonexistent matching source remains a red link, not a bold label');
	echo "OK: semantic query count $expectedQueries; source existence probes 0 for no match, 1 for three matches across both columns ($lang)\n";
}

/** Measure real MediaWiki API payloads rather than a synthetic JSON estimate. */
function fsArgumentMapApiEnvelope(string $page, string $lang): void {
	$call = static function(string $text, bool $compact): array {
		$params = ['action' => 'parse', 'text' => $text, 'contentmodel' => 'wikitext',
			'formatversion' => 2, 'usearticle' => 1, 'redirects' => 1, 'uselang' => $GLOBALS['wgLanguageCode']];
		if ($compact) {
			$params['prop'] = 'text';
			$params['disablelimitreport'] = 1;
		}
		$api = new ApiMain(new FauxRequest($params, true));
		$api->execute();
		return $api->getResult()->getResultData([], ['Strip' => 'all']);
	};
	$visible = static function(array $response): string {
		$html = $response['parse']['text'] ?? null;
		check(is_string($html), 'Actual API response contains HTML');
		// The browser already discards comments; ignore whitespace only between tags.
		return trim(preg_replace('/>\s+</', '><', preg_replace('/<!--[\s\S]*?-->/', '', $html)));
	};
	foreach (['fallback' => '||', 'debate' => '|WD Map source|debate', 'argument' => '|WD Map source|'] as $mode => $tail) {
		$text = '{{#argumentmap:render|' . $page . $tail . '|lang=' . $lang . '}}';
		$original = $call($text, false);
		$compact = $call($text, true);
		$typedParams = ['action' => 'wikidebatesargumentmap', 'page' => $page,
			'source' => $mode === 'fallback' ? '' : 'WD Map source',
			'mode' => $mode === 'fallback' ? 'full' : $mode, 'lang' => $lang,
			'formatversion' => 2, 'uselang' => $GLOBALS['wgLanguageCode']];
		$typedCall = static function() use ($typedParams): array {
			$typed = new ApiMain(new FauxRequest($typedParams, false));
			$typed->execute();
			return $typed->getResult()->getResultData([], ['Strip' => 'all']);
		};
		$typedResponse = $typedCall();
		same($visible($compact), $visible(['parse' => ['text' => $typedResponse['wikidebatesargumentmap']['html'] ?? null]]),
			'Typed API keeps map HTML: ' . $lang . '/' . $mode);
		same($visible($original), $visible($compact), 'Compact API keeps map HTML: ' . $lang . '/' . $mode);
		check(isset($original['parse']['links']) && !isset($compact['parse']['links']),
			'Compact request omits the default links payload');
		$before = strlen(json_encode($original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$after = strlen(json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		check($after < $before, 'Compact JSON is strictly smaller');
		$typedBytes = strlen(json_encode($typedResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		echo 'ARGUMENTMAP_API_BYTES ' . json_encode(['lang' => $lang, 'mode' => $mode,
			'default' => $before, 'compact' => $after, 'typed' => $typedBytes], JSON_THROW_ON_ERROR) . "\n";

		// Measure the entire PHP API envelope (including JSON serialization),
		// not the network or the browser. Alternate order and clear only the
		// isolated SMW Store's internal state before each measured call.
		$store = \SMW\Services\ServicesFactory::getInstance()->getStore();
		$times = ['compact' => [], 'typed' => []];
		for ($i = 0; $i < 12; $i++) {
			foreach ($i % 2 === 0 ? ['compact', 'typed'] : ['typed', 'compact'] as $transport) {
				$store->clear();
				$started = hrtime(true);
				$response = $transport === 'compact' ? $call($text, true) : $typedCall();
				json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
				$times[$transport][] = (hrtime(true) - $started) / 1000;
				same($visible($compact), $visible($transport === 'compact' ? $response :
					['parse' => ['text' => $response['wikidebatesargumentmap']['html'] ?? null]]),
					'Measured transport retains visible map HTML');
			}
		}
		foreach ($times as &$samples) {
			sort($samples);
		}
		unset($samples);
		echo 'ARGUMENTMAP_API_TIMING ' . json_encode(['lang' => $lang, 'mode' => $mode,
			'read_mode' => MediaWikiServices::getInstance()->getMainConfig()->get('WikidebatesArgumentMapDirectRead') ? 'direct' : 'query',
			'compact_median_us' => ($times['compact'][5] + $times['compact'][6]) / 2,
			'typed_median_us' => ($times['typed'][5] + $times['typed'][6]) / 2], JSON_THROW_ON_ERROR) . "\n";
	}
}

/**
 * Lab-only comparison: direct Store reads can differ for redirects, property
 * aliases, missing subjects, multiple values or output formatting. Report
 * exact map-field parity and paired timings; do not switch the production path.
 */
function fsArgumentMapDirectReadStudy(string $page, array $properties, string $lang): void {
	$store = \SMW\Services\ServicesFactory::getInstance()->getStore();
	$read = \MediaWiki\Extension\DebatePHP\ArgumentMapData::class;
	$factory = \SMW\DataValueFactory::getInstance();
	$propertyClass = class_exists(\SMW\DataItems\Property::class) ? \SMW\DataItems\Property::class : \SMW\DIProperty::class;
	$subjectClass = class_exists(\SMW\DataItems\WikiPage::class) ? \SMW\DataItems\WikiPage::class : \SMW\DIWikiPage::class;
	$direct = static function(Title $title, array $props) use ($store, $factory, $propertyClass, $subjectClass, $read): ?array {
		$subject = $subjectClass::newFromTitle($title);
		$row = [];
		$linker = new \MediaWiki\Linker\Linker();
		foreach ($props as $alias => $name) {
			$property = $propertyClass::newFromUserLabel($name);
			// The experiment is deliberately limited to text properties.
			if ($property->findPropertyValueType() !== '_txt') {
				return null;
			}
			$values = [];
			foreach ($store->getPropertyValues($subject, $property) as $item) {
				$value = $factory->newDataValueByItem($item, $property);
				$values[] = $value->getShortText(SMW_OUTPUT_WIKI, $linker);
			}
			$row[$alias] = $read::field(match (count($values)) { 0 => null, 1 => $values[0], default => $values });
		}
		return $row;
	};
	$redirect = 'WD Map redirect ' . $lang;
	$multi = 'WD Map multiple ' . $lang;
	fsSave($redirect, '#REDIRECT [[' . $page . ']]');
	fsSave($multi, '{{#set:' . $properties['pro_list'] . '=First|' . $properties['pro_list'] . '=Second}}');
	fsFlush();
	$cases = [
		'full' => [$page, ['map' => $properties['arg_map']]],
		'debate' => [$page, ['left' => $properties['pro_list'], 'right' => $properties['con_list']]],
		'argument' => [$page, ['left' => $properties['justifs_list'], 'right' => $properties['objs_list']]],
		'empty' => ['WD Map other', ['left' => $properties['pro_list']]],
		'missing' => ['WD Map missing ' . $lang, ['left' => $properties['pro_list']]],
		'redirect' => [$redirect, ['left' => $properties['pro_list']]],
		'multiple' => [$multi, ['left' => $properties['pro_list']]]
	];
	foreach ($cases as $case => [$name, $props]) {
		$title = Title::newFromText($name);
		$reference = static function() use ($read, $title, $props): array {
			$row = $read::readQuery($title, $props);
			return array_map(static fn($alias) => $read::field($row[$alias] ?? null), array_combine(array_keys($props), array_keys($props)));
		};
		$expected = $reference();
		$candidate = $direct($title, $props);
		$reason = 'stale';
		$runtime = $read::readDirect($title, $props, $reason);
		$eligible = in_array(SMW_VERSION, ['7.3.1', '7.4.1'], true) &&
			in_array($case, ['full', 'debate', 'argument', 'empty'], true);
		same($eligible, $runtime !== null, 'Restricted runtime direct-read eligibility: ' . $case);
		$expectedReason = $eligible ? null : (in_array(SMW_VERSION, ['7.3.1', '7.4.1'], true)
			? ['missing' => 'missing-page', 'redirect' => 'redirect', 'multiple' => 'multiple-values'][$case]
			: 'unsupported-smw');
		same($expectedReason, $reason, 'Restricted runtime diagnostic reason: ' . $case);
		if ($runtime !== null) {
			same($expected, array_map(static fn($alias) => $read::field($runtime[$alias] ?? null),
				array_combine(array_keys($props), array_keys($props))), 'Restricted direct read preserves formatted fields: ' . $case);
		}
		$selected = $read::read($title, $props);
		same($expected, array_map(static fn($alias) => $read::field($selected[$alias] ?? null),
			array_combine(array_keys($props), array_keys($props))), 'Configured path preserves fields: ' . $case);
		$equal = $candidate === $expected;
		$queryNs = $directNs = $runtimeNs = [];
		// Alternate order to reduce warming bias. Clear SMW's local store state
		// before each measurement; database/shared cache state may remain warm.
		for ($i = 0; $i < 12; $i++) {
			foreach ($i % 2 === 0 ? ['query', 'direct', 'runtime'] : ['runtime', 'direct', 'query'] as $mode) {
				$store->clear();
				$start = hrtime(true);
				$result = match ($mode) {
					'query' => $reference(),
					'direct' => $direct($title, $props),
					'runtime' => $read::readDirect($title, $props)
				};
				$elapsed = hrtime(true) - $start;
				if ($mode === 'query') {
					$queryNs[] = $elapsed;
				} elseif ($mode === 'runtime') {
					$runtimeNs[] = $elapsed;
					same($runtime, $result, 'Restricted lookup remains stable');
				} else {
					$directNs[] = $elapsed;
					$equal = $equal && $result === $expected;
				}
			}
		}
		sort($queryNs);
		sort($directNs);
		sort($runtimeNs);
		echo 'ARGUMENTMAP_DIRECT_READ ' . json_encode(['lang' => $lang, 'case' => $case,
			'equal' => $equal, 'query_median_us' => ($queryNs[5] + $queryNs[6]) / 2000,
			'direct_median_us' => ($directNs[5] + $directNs[6]) / 2000,
			'runtime_eligible' => $eligible, 'runtime_median_us' => ($runtimeNs[5] + $runtimeNs[6]) / 2000], JSON_THROW_ON_ERROR) . "\n";
	}
}

/** New semantic values must be read after the real deferred update commits. */
function fsArgumentMapUpdatedData(callable $render, array $properties, string $lang): void {
	$page = 'WD Map fresh ' . $lang;
	$source = 'WD Map source';
	$call = '{{#argumentmap:render|' . $page . '|' . $source . '|debate|lang=' . $lang . '}}';
	foreach (['Before update', 'After update'] as $label) {
		fsSave($page, '{{#set:' . $properties['pro_list'] . '=' . $source . '⟬' . $label . '}}');
		fsFlush();
		\SMW\Services\ServicesFactory::getInstance()->getStore()->clear();
		check(str_contains($render($call), '<b>' . $label . '</b>'), 'Selected map path reads newly persisted data: ' . $label);
	}
	fsSave($page, 'Removed semantic list');
	fsFlush();
	\SMW\Services\ServicesFactory::getInstance()->getStore()->clear();
	check(!str_contains($render($call), 'After update'), 'Removed semantic value is not retained by the read path');
}

/** Execute the same maintenance audit on the isolated wiki, not merely php -l. */
function fsArgumentMapBenchmarkMaintenance(): void {
	require_once dirname(__DIR__) . '/maintenance/BenchmarkArgumentMap.php';
	$audit = new class extends BenchmarkArgumentMap {
		public array $records = [];
		protected function output($out, $channel = null) {
			$this->records[] = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
		}
	};
	$audit->loadParamsAndArgs('BenchmarkArgumentMap', ['limit' => 100, 'cache-probe' => true, 'report-ineligible' => true], []);
	$audit->execute();
	$records = array_column($audit->records, null, 'type');
	$summary = $records['summary'] ?? [];
	check(($summary['pages'] ?? 0) > 0 && $summary['pages'] <= 100, 'Maintenance audit is bounded and finds real fixture pages');
	same(0, $summary['errors'], 'Maintenance audit has no read errors');
	check(!isset($records['mismatch']), 'Maintenance audit finds no mismatches on fixture data');
	$nonempty = 0;
	foreach ($summary['modes'] as $name => $mode) {
		same(0, $mode['mismatches'], 'Maintenance audit verifies real stored fields');
		same($summary['pages'] - $mode['eligible'], array_sum($mode['ineligible_reasons']), 'Audit accounts for every ineligible read: ' . $name);
		same($mode['nonempty'] - $mode['eligible_nonempty'], array_sum($mode['ineligible_nonempty_reasons']), 'Audit accounts for every nonempty ineligible read: ' . $name);
		check(!isset($mode['ineligible_reasons']['unclassified']), 'Every ineligible read has a reason: ' . $name);
		$nonempty += $mode['eligible_nonempty'];
	}
	check($nonempty > 0, 'Maintenance audit actually measures populated eligible map fields');
	$probe = $records['cache_probe'] ?? [];
	same(100, ($probe['hits'] ?? 0) + ($probe['misses'] ?? 0), 'Synthetic WAN probe accounts for all lookups');
	same(100, $probe['round_trip']['count'], 'Synthetic WAN probe times all lookups');
	echo "OK: bounded real-data audit and synthetic WAN cache probe\n";
}
