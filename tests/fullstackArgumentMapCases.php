<?php

use MediaWiki\Extension\DebatePHP\I18n;
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
				check(str_contains($html, 'wk-arg-map__col is-pro') && str_contains($html, 'wk-arg-map__col is-con'),
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
	}
	echo "OK: native hover map, FR/EN, full fallback, contextual columns, empty/missing data and encoded titles"
		. ($legacy ? '; exact historical Lua HTML comparison' : '; no Lua extensions loaded') . "\n";
}
