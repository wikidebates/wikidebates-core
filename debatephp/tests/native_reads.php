<?php

require __DIR__ . '/../../tests/bootstrap.php';
require __DIR__ . '/argument_context.php';

use MediaWiki\Extension\DebatePHP\{ArgumentReferenceRenderer, Context, JsonData, NativeJsonContext, NativeSemanticContext, RenderData, SemanticReadResult, Table, TextEscaper};

set_error_handler(static function($severity, $message, $file, $line): void { throw new ErrorException($message, 0, $severity, $file, $line); });
$assertions = 0;
$equal = static function($a, $b, string $label) use (&$assertions): void { same($a, $b, $label); $assertions++; };
$legacyValues = static function($value) use (&$legacyValues) {
	if (!is_array($value)) { return $value; }
	array_unshift($value, ''); unset($value[0]);
	return new Table(array_map($legacyValues, $value));
};
mt_srand(806);
$keys = [0, 1, 2, 7, -1, '01', '1e2', '2x', 'name', 'fulltext', 'page', '0.0', 'é'];
$make = static function(int $depth = 0) use (&$make, $keys) {
	if ($depth > 2 || mt_rand(0, 2) === 0) { return [null, false, true, 0, 1.25, '', '0', 'é </script> {{x}}'][mt_rand(0, 7)]; }
	$out = [];
	for ($i = 0, $n = mt_rand(0, 12); $i < $n; $i++) { $out[$keys[mt_rand(0, count($keys) - 1)]] = $make($depth + 1); }
	return $out;
};
for ($i = 0; $i < 2500; $i++) {
	$data = $make();
	$equal(Table::unwrap($legacyValues($data)), SemanticReadResult::values($data), 'SMW numeric reindexing, null and named fields');
	$equal(TextEscaper::jsonValue(Table::wrap($data)), JsonData::value($data), 'Internal JSON native/Table equivalence');
	if (is_array($data)) {
		$equal(iterator_to_array((new Table($data))->sequence()), iterator_to_array(SemanticReadResult::sequence($data)), 'First-hole/nil sequence semantics');
		$equal(json_encode(TextEscaper::jsonValue(Table::wrap($data)), JSON_THROW_ON_ERROR), json_encode(JsonData::value($data), JSON_THROW_ON_ERROR), 'Exact JSON byte/key order');
	}
}
$data = ['@graph' => [1 => new Table(['name' => 'é', 'values' => new Table([1 => '0', 2 => false])])]];
$originalData = Table::unwrap(Table::wrap($data));
$equal(TextEscaper::jsonValue(Table::wrap($data)), JsonData::value($data), 'Mixed internal native/legacy graph');
$equal($originalData, Table::unwrap(Table::wrap($data)), 'JSON leaves remain unmodified');
$cache = new RenderData;
$first = $cache->csvValues(' a,0,é,,b '); $first[1] = 'mutated'; unset($first[2]);
$legacy = $cache->csv(' a,0,é,,b '); $legacy[1] = 'legacy mutation';
$equal([1 => 'a', 2 => '0', 3 => 'é', 4 => 'b'], $cache->csvValues(' a,0,é,,b '), 'Native and legacy CSV views cannot poison cache');
$equal(2, $cache->statistics()['hits'], 'Both CSV APIs share one cache');
$cache->csvValues('empty', 'no match');
try { $cache->csvValues('x', ''); throw new RuntimeException('Expected ValueError'); }
catch (ValueError $e) { $equal(1, $cache->statistics()['failures'], 'Native CSV failure counted'); }

require_once __DIR__ . '/native_context.php';
$cases = json_decode(file_get_contents(__DIR__ . '/fixtures/argument-cases.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($cases as $case) {
	$legacy = new ArgumentTestContext($case); $native = new NativeReadTestContext($case);
	$equal((new ArgumentReferenceRenderer($legacy))->render(), (new ArgumentReferenceRenderer($native))->render(), 'Optional native context exact HTML/JSON: ' . $case['title']);
	$equal($legacy->effects, $native->effects, 'Optional native context exact effects');
}
$case = ['title' => 'An isolated orphan', 'args' => ['lang' => 'en', 'summary' => 'Summary', 'creation-date' => '2026-01-01']];
$old = new ArgumentTestContext($case); $native = new NativeReadTestContext($case); $native->certify = true;
$renderer = new ArgumentReferenceRenderer($native);
$equal((new ArgumentReferenceRenderer($old))->render(), $renderer->render(), 'Certified empty parents preserve output');
$equal(4, count($old->effects['ask']) - count($native->effects['ask']), 'Two fallback reads skipped for each proven empty parent union');
$equal(['debate-parents' => 2, 'argument-parents' => 2], $native->skipped, 'Only certified fallbacks recorded');
$beforeCalls = count($native->effects['ask']); $renderer->render();
$equal($beforeCalls, count($native->effects['ask']) - $beforeCalls, 'Native semantic cache resets for every render');
$native->failJson = true;
try { $renderer->render(); throw new LogicException('Expected JSON failure'); }
catch (RuntimeException $e) { $equal('Synthetic JSON insertion failure', $e->getMessage(), 'JSON failures propagate'); }
$native->failJson = false;
$equal((new ArgumentReferenceRenderer(new ArgumentTestContext($case)))->render(), $renderer->render(), 'Native JSON render recovers without retained construction state');
$probe = new ReflectionMethod($renderer, 'containsTitle');
foreach ([['label' => 'Target'], [1 => 'no', 3 => 'Target'], [1 => null, 2 => 'Target'], ['fulltext' => 'Target'], [1 => false, 2 => 'Target']] as $value) {
	$equal($probe->invoke($renderer, Table::wrap($value), 'Target'), $probe->invoke($renderer, $value, 'Target'), 'Native title lookup matches legacy table');
}

// The Lua bridge must respect one-based inclusive bounds, including an empty range.
$concatValues = new Table([1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D']);
$concat = \MediaWiki\Extension\DebatePHP\Lua::class;
$equal('A, B, C, D', $concat::concat($concatValues, ', '), 'Unbounded concatenation');
$equal('A, B', $concat::concat($concatValues, ', ', 1, 2), 'Exclude trailing values');
$equal('B, C', $concat::concat($concatValues, ', ', 2, 3), 'Inclusive range');
$equal('C, D', $concat::concat($concatValues, ', ', 3), 'Default end');
$equal('', $concat::concat($concatValues, ', ', 3, 2), 'Empty range');
$equal('D', $concat::concat($concatValues, ', ', 4, 4), 'Single value range');
$equal('A, B, C, D', $concat::concat($concatValues, ', '), 'Input table remains unchanged');

// Check the final join, independently of title deduplication, for zero to four cards.
$join = new ReflectionMethod(ArgumentReferenceRenderer::class, 'joinCards');
$joinRenderer = new ArgumentReferenceRenderer(new ArgumentTestContext(['title' => 'Child', 'args' => ['lang' => 'fr']]));
foreach ([
	'' => [],
	'A' => [1 => 'A'],
	'A&#32;et&#32;B' => [1 => 'A', 2 => 'B'],
	'A, B&#32;et&#32;C' => [1 => 'A', 2 => 'B', 3 => 'C'],
	'A, B, C&#32;et&#32;D' => [1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D']
] as $expected => $cards) {
	$equal($expected, $join->invoke($joinRenderer, array_values($cards), 'fr'), 'French list with ' . count($cards) . ' cards');
}

// Native list joins use zero-based arrays and never mutate their caller.
$cards = ['0', 'A', 'A', 'B'];
$equal('0, A, A&#32;and&#32;B', $join->invoke($joinRenderer, $cards, 'en'), 'English conjunction and zero');
$equal(['0', 'A', 'A', 'B'], $cards, 'Joining does not change caller list');
$equal('', $join->invoke($joinRenderer, null, 'fr'), 'No cached cards');
$equal('0', $join->invoke($joinRenderer, ['0'], 'fr'), 'Single zero card');
$articles = new ReflectionMethod(\MediaWiki\Extension\DebatePHP\ReferenceRenderer::class, 'joinArticles');
$debate = new \MediaWiki\Extension\DebatePHP\ReferenceRenderer(new ArgumentTestContext(['title' => 'Question ?', 'args' => []]));
foreach (['fr' => ' et ', 'en' => ' and '] as $language => $conjunction) {
	$equal('', $articles->invoke($debate, [], $language), 'Empty article list');
	$equal('0', $articles->invoke($debate, ['0'], $language), 'Single zero article');
	$equal('A' . $conjunction . 'B', $articles->invoke($debate, ['A', 'B'], $language), 'Two articles');
	$equal('A, B, C' . $conjunction . 'D', $articles->invoke($debate, ['A', 'B', 'C', 'D'], $language), 'Four articles, no repeated last link');
}

// Equivalent raw SMW forms must produce one link per relation, preserving order.
$parentContext = new class(['title' => 'Child', 'args' => ['lang' => 'en']]) extends ArgumentTestContext {
	public function smwAsk(Table $query): ?Table {
		$this->effects['ask'][] = $query->values;
		$fields = implode("\n", $query->values);
		if (str_contains($fields, '=pdeb')) {
			throw new LogicException('Unused inherited-parent query must not run');
		}
		if (str_contains($fields, '=pro')) {
			return Table::wrap([
				1 => ['fulltext' => 'Debate A', 'pro' => 'Child', 'con' => 'Child'],
				2 => ['fulltext' => 'Debate_A', 'pro' => 'Child'],
				3 => ['fulltext' => 'Debate B', 'pro' => 'Child'],
				4 => ['fulltext' => 'Debate C', 'pro' => 'Child']
			]);
		}
		if (str_contains($fields, '=justif')) {
			return Table::wrap([
				1 => ['fulltext' => 'Parent &amp; A', 'justif' => 'Child', 'obj' => 'Child'],
				2 => ['fulltext' => 'Parent & A', 'justif' => 'Child'],
				3 => ['fulltext' => 'Parent B', 'justif' => 'Child'],
				4 => ['fulltext' => 'Parent C', 'justif' => 'Child']
			]);
		}
		if (str_contains($fields, '=chain')) {
			return Table::wrap([
				1 => ['chain' => 'Debate A⟭Parent A'],
				2 => ['chain' => 'Debate_A⟭Parent B'],
				3 => ['chain' => 'Debate &amp; B⟭Parent A'],
				4 => ['chain' => 'Debate & B⟭Parent B'],
				5 => ['chain' => 'Debate C⟭Parent C']
			]);
		}
		return null;
	}
};
foreach ([ArgumentReferenceRenderer::class, \MediaWiki\Extension\DebatePHP\ArgumentRenderer::class] as $class) {
	$parentContext->effects['ask'] = [];
	$rendered = (new $class($parentContext))->render();
	$breadcrumbQueries = array_filter($parentContext->effects['ask'], static fn($query) => str_contains(implode("\n", $query), '=chain'));
	$equal(1, count($breadcrumbQueries), 'The used breadcrumb read remains, without the unused inherited-parent read');
	$semanticWrite = end($parentContext->effects['set']);
	$equal(['Debate A', 'Debate & B', 'Debate C'], $semanticWrite['Parent debate'], 'Inherited roots remain in semantic parent debate writes');
	preg_match_all('~<div class="bandeau-en-tete searchaux wk-icon-before [^"]+">.*?</div>~s', $rendered, $matches);
	$parentBanners = array_values(array_filter($matches[0], static fn($banner) => str_contains($banner, 'class="hover-map"')));
	$equal(5, count($parentBanners), 'All five parent relation banners remain present');
	$expected = [
		['Debate A', 'Debate & B', 'Debate C'],
		['Debate A', 'Debate B', 'Debate C'],
		['Debate A'],
		['Parent & A', 'Parent B', 'Parent C'],
		['Parent & A']
	];
	foreach ($parentBanners as $i => $banner) {
		preg_match_all('~\[\[([^\]]+)\]\]~', $banner, $links);
		$equal($expected[$i], $links[1], 'Unique ordered parent links in banner ' . $i);
	}
}

// The native state distinguishes a not-yet-loaded relation from a known empty list.
$relations = new \MediaWiki\Extension\DebatePHP\ParentRelations;
$equal(null, $relations->proDebates, 'Unloaded parent relation');
$relations->proDebates = [];
$equal([], $relations->proDebates, 'Loaded empty parent relation');

$parentRenderer = new ArgumentReferenceRenderer($parentContext);
$parentRenderer->render();
$parentFields = new Table([
	'prop_arg_pro' => 'Pro', 'prop_arg_con' => 'Con',
	'prop_justif' => 'Justif', 'prop_obj' => 'Obj',
	'prop_breadcrumb' => 'Chain', 'prop_debate_name' => 'Debate'
]);
$pv = $parentContext->pageVars();
$relations = new \MediaWiki\Extension\DebatePHP\ParentRelations;
(new ReflectionMethod($parentRenderer, 'ensureDebateParents'))->invoke($parentRenderer, $pv, $relations, $parentFields);
$equal(['Debate A', 'Debate B', 'Debate C'], $relations->proDebates, 'Native direct-parent list preserves canonical order');
$equal(['Debate A'], $relations->conDebates, 'Parent relation sets remain distinct');
(new ReflectionMethod($parentRenderer, 'ensureArgumentParents'))->invoke($parentRenderer, $pv, $relations, $parentFields);
$equal(['Parent & A', 'Parent B', 'Parent C'], $relations->justificationParents, 'Canonical native argument-parent order');
$breadcrumbCategories = [];
(new ReflectionMethod($parentRenderer, 'ensureBreadcrumbSelection'))->invokeArgs($parentRenderer, [$pv, $relations, &$breadcrumbCategories, 'en', $parentFields]);
$equal(['Debate A', 'Debate & B', 'Debate C'], $relations->breadcrumbDebates, 'Breadcrumb roots deduplicate equivalent titles');
$equal(true, array_is_list($relations->breadcrumbChains), 'Native breadcrumb chains are dense zero-based lists');

$lookup = new ReflectionMethod($parentRenderer, 'containsTitle');
foreach ([
	[['fulltext' => '', 'page' => 'Target'], false],
	[['fulltext' => false, 'page' => 'Target'], true],
	[['fulltext' => '0', 'page' => 'Target'], false],
	[[1 => 'Other', 3 => 'Target'], false],
	[[1 => ['name' => 'Target']], true]
] as [$input, $expected]) {
	$equal($expected, $lookup->invoke($parentRenderer, $input, 'Target'), 'Title field priority and sparse semantic lists');
}

$rowTitle = new ReflectionMethod($parentRenderer, 'semanticRowTitle');
foreach ([
	[['fulltext' => '', 'page' => 'Target'], ''],
	[['fulltext' => false, 'page' => 'Target'], 'Target'],
	[['fulltext' => null, 'page' => false, 1 => 'Target'], 'Target'],
	[['fulltext' => '0', 'page' => 'Target'], '0'],
	[[], null]
] as [$row, $expected]) {
	$equal($expected, $rowTitle->invoke($parentRenderer, $row), 'Preserve semantic title field precedence');
}

// Compare the literal title grammar against the retained compatibility helpers.
$canonical = new ReflectionMethod($parentRenderer, 'canonicalTitle');
$safe = new ReflectionMethod($parentRenderer, 'smwSafeTitle');
foreach (['', '0', " \t: [[Title|label]]\f", '[[A_B]]', 'Title#section',
	"Title#fragment\nline", "Title#fragment\n", '[[A|B]]', 'A]B|C', 'A<B',
	" A ", "\0A\0", 'Title{bad}', '[[Title|0]]', '::A'] as $input) {
	$expected = trim($input, " \t\r\n\v\f");
	if ($expected !== '') {
		$expected = $concat::gsub($expected, '^:%s*', '');
		$expected = $concat::gsub($concat::gsub($expected, '^%[%[%s*', ''), '%s*%]%]$', '');
		$expected = $concat::match($expected, '^([^%]|]+)%|') ?? $expected;
		$expected = str_replace('_', ' ', trim($expected, " \t\r\n\v\f"));
	}
	$equal($expected, $canonical->invoke($parentRenderer, $input), 'Canonical title retains the historical byte grammar');
	$smw = trim($concat::gsub($expected, '#.*$', ''), " \t\r\n\v\f");
	if ($concat::truth($concat::find($smw, '[%[%]{}|<>]'))) {
		$smw = '';
	}
	$equal($smw, $safe->invoke($parentRenderer, $input), 'SMW title filtering remains unchanged');
}

// The two native catch blocks must never hide a SQL error.
$initContext = new class(['title' => 'Child argument test', 'args' => ['lang' => 'en', 'initialization' => 'Pro argument@id-123'], 'publication_date' => '2025-01-01T00:00:00Z']) extends ArgumentTestContext {
	public ?Throwable $queryFailure = null;
	public ?Throwable $editFailure = null;
	public ?Throwable $urlFailure = null;
	public int $initQueries = 0;
	public int $edits = 0;
	public function smwAsk(Table $query): ?Table {
		if (!in_array('limit=1', $query->values, true)) {
			return parent::smwAsk($query);
		}
		$this->initQueries++;
		if ($this->queryFailure) {
			throw $this->queryFailure;
		}
		return Table::wrap([1 => ['fulltext' => 'Parent title']]);
	}
	public function autoEdit(Table $params): string {
		$this->edits++;
		if ($this->editFailure) {
			throw $this->editFailure;
		}
		return parent::autoEdit($params);
	}
	public function fullUrl(string $page, ?Table $query = null): ?string {
		if ($this->urlFailure) {
			throw $this->urlFailure;
		}
		return parent::fullUrl($page, $query);
	}
};
$initRenderer = new ArgumentReferenceRenderer($initContext);
$initHtml = $initRenderer->render();
$equal(1, $initContext->initQueries, 'Initialization does one uncached lookup');
$equal(1, $initContext->edits, 'Initialization still renders its edit button');
$equal(true, str_contains($initHtml, 'Parent title'), 'Initialization shows the found parent');
$initContext->queryFailure = new RuntimeException('Recoverable query');
$initContext->editFailure = new RuntimeException('Recoverable edit');
$recovered = $initRenderer->render();
$equal(false, str_contains($recovered, 'Recoverable'), 'Recoverable initialization errors remain private');
$initContext->queryFailure = null;
$initContext->editFailure = null;
foreach (['queryFailure', 'editFailure'] as $field) {
	$failure = new PDOException('SQL failure');
	$initContext->$field = $failure;
	try {
		$initRenderer->render();
		throw new LogicException('Initialization swallowed a database error');
	} catch (PDOException $error) {
		$equal($failure, $error, 'Initialization propagates the original SQL exception');
	}
	$initContext->$field = null;
}
$url = new ReflectionMethod($initRenderer, 'wkFullUrl');
$initContext->urlFailure = new RuntimeException('Recoverable URL');
$equal('', $url->invoke($initRenderer, 'Target'), 'Ordinary URL failure falls back to empty string');
$failure = new PDOException('SQL failure');
$initContext->urlFailure = $failure;
try {
	$url->invoke($initRenderer, 'Target');
	throw new LogicException('URL swallowed SQL error');
} catch (PDOException $error) {
	$equal($failure, $error, 'URL lookup propagates SQL exception');
}

// Semantic write queues preserve ordering, multiplicity, scalar replacement and literal zero.
$properties = ['first' => 'unchanged', 'multiple' => 'old'];
$semantic = \MediaWiki\Extension\DebatePHP\SemanticProperties::class;
$semantic::add($properties, 'multiple', ['0', 'x', 'x'], true);
$semantic::add($properties, 'multiple', ' y ', true);
$semantic::add($properties, 'empty', " \t ", true);
$semantic::add($properties, '', 'ignored', true);
$semantic::add($properties, false, 'ignored', false);
$semantic::add($properties, 'nil', null, false);
$semantic::add($properties, 'zero', 0, false);
$semantic::add($properties, 'false', false, false);
$semantic::add($properties, 'replace', ' old ', false);
$semantic::merge($properties, ['replace' => ' new ', 'multiple' => ['last', '0'], 'list' => ['a', 'b']]);
$expectedProperties = ['first' => 'unchanged', 'multiple' => ['old', '0', 'x', 'x', 'y', 'last', '0'],
	'zero' => 0, 'false' => false, 'replace' => 'new', 'list' => ['a', 'b']];
$equal($expectedProperties, $properties, 'Native semantic properties retain ordered and repeated values');
$equal($expectedProperties, TextEscaper::jsonValue($semantic::toLegacyTable($properties)), 'Legacy write boundary preserves semantic values');
$equal([1 => 'old', 2 => '0', 3 => 'x', 4 => 'x', 5 => 'y', 6 => 'last', 7 => '0'],
	Table::unwrap($semantic::toLegacyTable($properties))['multiple'], 'Only the legacy boundary uses one-based sequences');
$source = ['new' => ['a']];
$semantic::merge($properties, $source);
$semantic::add($properties, 'new', 'b', true);
$equal(['new' => ['a']], $source, 'Merging native lists does not mutate the source');
$semantic::add($properties, 'new', 'scalar', false);
$equal('scalar', $properties['new'], 'Single-valued writes replace previous lists');
$semantic::add($properties, 'new', '0', true);
$equal(['scalar', '0'], $properties['new'], 'A later multi-value write retains the previous scalar');
$equal(['empty' => []], TextEscaper::jsonValue($semantic::toLegacyTable(['empty' => [], 'missing' => null])), 'Empty list and missing property remain distinct');


// A missing category sink suppresses only category collection, not the banner.
$categoryContext = new ArgumentTestContext(['title' => 'Category probe', 'args' => ['lang' => 'en']]);
foreach ([ArgumentReferenceRenderer::class, \MediaWiki\Extension\DebatePHP\ReferenceRenderer::class] as $class) {
	$renderer = new $class($categoryContext);
	$banner = new ReflectionMethod($renderer, 'renderSubsectionBanner');
	$category = new ReflectionMethod($renderer, 'subMsg');
	$key = 'reference_incomplete';
	$expected = '[[Category:' . $category->invoke($renderer, 'en', $key . '_category') . ']]';
	$categories = [];
	$html = $banner->invokeArgs($renderer, [$categoryContext->pageVars(), 'en', $key, null, &$categories]);
	$equal(true, $html !== '', 'Known reference warning renders a banner');
	$equal([$expected], $categories, 'An initially empty native category sink accepts a warning');
	$without = $banner->invoke($renderer, $categoryContext->pageVars(), 'en', $key);
	$equal($html, $without, 'Suppressing category collection leaves banner bytes unchanged');
	$banner->invokeArgs($renderer, [$categoryContext->pageVars(), 'en', $key, null, &$categories]);
	$equal([$expected, $expected], $categories, 'Category collection preserves repeated entries and order');
	$equal(true, array_is_list($categories), 'Collected categories are a zero-based list');
}


/* PageForms query construction preserves historical keys, values and insertion order. */
$formContext = new class(['title' => 'Child', 'args' => ['lang' => 'en']]) extends ArgumentTestContext {
	public array $queries = [];
	public bool $failFullUrl = false;
	public function fullUrl(string $page, ?Table $query = null): ?string {
		$this->queries[] = ['full', $page, $query?->values];
		return $this->failFullUrl ? null : parent::fullUrl($page, $query);
	}
	public function localUrl(string $page, ?Table $query = null): ?string {
		$this->queries[] = ['local', $page, $query?->values];
		return substr(parent::fullUrl($page, $query), strlen('https://wiki.example'));
	}
};
$argumentForm = new ArgumentReferenceRenderer($formContext);
$debateForm = new \MediaWiki\Extension\DebatePHP\ReferenceRenderer($formContext);
$argumentQuery = new ReflectionMethod($argumentForm, 'runQueryLink');
$debateQuery = new ReflectionMethod($debateForm, 'runQueryLink');
$extra = ['0' => '0', 'blank' => '', 'missing' => null, 'flag' => false, 'yes' => true, 'literal%[field]' => 'x'];
$options = ['form' => 'Search', 'field' => 'Keyword', 'value' => '0', 'label' => false, 'extra' => $extra];
$link = $argumentQuery->invoke($argumentForm, $options);
$equal(['full', 'Special:RunQuery/Search', ['Search[Keyword]' => '0', 'Search[0]' => '0',
	'Search[flag]' => 'false', 'Search[yes]' => 'true', 'literal%[field]' => 'x', '_run' => '1']],
	end($formContext->queries), 'Argument preserves exact historical extra parameter rules');
$equal(true, str_ends_with($link, ' 0]</span>'), 'False label falls back to literal zero');
$equal($extra, $options['extra'], 'Query construction does not mutate extra options');
$formContext->failFullUrl = true;
$argumentQuery->invoke($argumentForm, ['form' => 'Search', 'field' => 'Keyword', 'value' => '0']);
$equal(['local', 'Special:RunQuery/Search', ['Search[Keyword]' => '0', '_run' => '1']],
	end($formContext->queries), 'Argument keeps full-to-local URL fallback');
$formContext->failFullUrl = false;
$link = $debateQuery->invoke($debateForm, 'Search', 'Fields', 'Keyword', '0', false, '', $extra);
$equal(['full', 'Special:RunQuery/Search', ['Fields[Keyword]' => '0', 0 => '0',
	'flag' => 'false', 'yes' => 'true', 'literal%[field]' => 'x', '_run' => '']],
	end($formContext->queries), 'Debate keeps its own historical extra parameter and run flag rules');
$equal(true, str_ends_with($link, ' 0]</span>'), 'Debate false label falls back to literal zero');
foreach ([$argumentForm, $debateForm] as $renderer) {
	$button = new ReflectionMethod($renderer, 'wkRunQueryHtmlTagButton');
	$button->invoke($renderer, 'Search', '0', '', ['ID' => 0, '_run' => '1']);
	$equal(['local', 'Special:RunQuery/Search', ['ID' => 0, '_run' => '1']],
		end($formContext->queries), 'Navigation button passes native parameters intact at context boundary');
	$button->invoke($renderer, 'Search', '0', '');
	$equal(['local', 'Special:RunQuery/Search', []], end($formContext->queries), 'Absent navigation query remains an empty map');
}
$edit = new ReflectionMethod($argumentForm, 'addDataLink');
$equal(true, str_contains($edit->invoke($argumentForm, 'Edit', 'Child', '', '', true), 'bouton-modifier'), 'Native edit flag enables modifier class');
$equal(false, str_contains($edit->invoke($argumentForm, 'Edit', 'Child', '', ''), 'bouton-modifier'), 'Default link does not get modifier class');


// Native JSON context receives a recursively native graph, including documentary references.
$equal(true, is_subclass_of(Context::class, NativeJsonContext::class), 'Native JSON is a mandatory Context contract');
$equal(false, method_exists(Context::class, 'jsonEncode'), 'Renderer interface does not accept Table JSON encoding');
$equal(false, method_exists(Context::class, 'jsonLd'), 'Renderer interface does not accept externally encoded JSON');
$graphIsNative = static function($value) use (&$graphIsNative): bool {
	if (is_object($value)) {
		return false;
	}
	if (is_array($value)) {
		foreach ($value as $child) {
			if (!$graphIsNative($child)) {
				return false;
			}
		}
	}
	return true;
};
foreach ([ArgumentReferenceRenderer::class, \MediaWiki\Extension\DebatePHP\ReferenceRenderer::class] as $class) {
	$isArgument = $class === ArgumentReferenceRenderer::class;
	$fixture = ['title' => $isArgument ? 'An argument with references' : 'A debate with references?',
		'args' => ['lang' => 'en', 'keywords' => '0,First,Second,Third', 'sections' => 'One,Two',
			'expanded-topic' => 'the issue', 'summary' => 'Summary',
			'bibliography' => '⟭Alice, Bob⟬Article⟬Journal⟬2⟬5⟬Section⟬12⟬Publisher⟬Paris⟬2020⟬https://example.org/a⟬',
			'pro-bibliography' => '⟭Alice, Bob⟬Article⟬Journal⟬2⟬5⟬Section⟬12⟬Publisher⟬Paris⟬2020⟬https://example.org/a⟬']];
	$context = new class($fixture) extends NativeReadTestContext {
		public array $graph = [];
		public function jsonLdData(array $data): string {
			$this->graph = $data;
			return parent::jsonLdData($data);
		}
	};
	$legacy = new ArgumentTestContext($fixture);
	$equal((new $class($legacy))->render(), (new $class($context))->render(), 'Native graph renders identical bytes with either semantic adapter');
	$equal($legacy->effects, $context->effects, 'Native graph preserves all renderer effects');
	$equal(true, $graphIsNative($context->graph), 'Entire JSON graph consists of native arrays and scalar values');
	$equal($isArgument ? 5 : 4, count($context->graph['@graph']), 'Graph retains all top-level nodes');
	$node = $context->graph['@graph'][$isArgument ? 5 : 4];
	$equal('0', $node['about'][1]['name'], 'Literal zero retained in about metadata');
	$equal($isArgument ? [1] : [1, 2], array_keys($node['citation']), 'Native reference sequence retains one-based order and repeated entries');
	$equal('Article', $node['citation'][1]['@type'], 'Documentary reference retains schema type');
	$equal('PublicationIssue', $node['citation'][1]['isPartOf']['@type'], 'Article retains issue metadata');
	$equal('PublicationVolume', $node['citation'][1]['isPartOf']['isPartOf']['@type'], 'Article retains volume metadata');
	$equal(['Alice', 'Bob'], array_values(array_column($node['citation'][1]['author'], 'name')), 'Reference author sequence remains ordered');
}

echo "$assertions native-read assertions: semantic indexing, JSON bytes, CSV isolation, optional adapters and certified fallback/cache lifetimes\n";
