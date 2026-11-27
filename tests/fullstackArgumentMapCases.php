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
		}
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
		$absent = $render('{{#argumentmap:render|' . $page . '|' . $source . '|debate|lang=' . $lang . '}}');
		check(str_contains($absent, 'Other label'), 'Nonmatching source still renders the map');
		same(0, $exists, 'Unmatched source incurs no existence probe');
		same(1, $queries, 'One semantic query for the contextual map');
		$queries = 0;
		$matched = $render('{{#argumentmap:render|' . $target . '|' . $source . '|debate|lang=' . $lang . '}}');
		same(1, $exists, 'All three matching cards share one source existence probe');
		same(1, $queries, 'One semantic query even across two populated columns');
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
	echo "OK: one semantic read; source existence probes 0 for no match, 1 for three matches across both columns ($lang)\n";
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
		same($visible($original), $visible($compact), 'Compact API keeps map HTML: ' . $lang . '/' . $mode);
		check(isset($original['parse']['links']) && !isset($compact['parse']['links']),
			'Compact request omits the default links payload');
		$before = strlen(json_encode($original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$after = strlen(json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		check($after < $before, 'Compact JSON is strictly smaller');
		echo 'ARGUMENTMAP_API_BYTES ' . json_encode(['lang' => $lang, 'mode' => $mode,
			'default' => $before, 'compact' => $after], JSON_THROW_ON_ERROR) . "\n";
	}
}
