<?php
namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Title\Title;

/** MediaWiki 1.43 legacy parser boundary. No recursive parse/reset of the current Parser. */
final class MediaWikiContext implements ArgumentContext {
	private Parser $parser;
	private PPFrame $caller;
	private PPFrame $module;
	private I18n $i18n;
	private array $expansions = [];
	private array $trace = [];
	private bool $tracing;
	private bool $optimize;
	private ?Telemetry $telemetry;
	private array $htmlAttributes;
	private bool $argumentMode;
	private array $titles = [];
	public function __construct(Parser $parser, PPFrame $caller, array $nodes, bool $optimize, bool $tracing = false, ?Telemetry $telemetry = null, string $moduleName = 'Debate') {
		$this->telemetry = $telemetry; $this->argumentMode = $moduleName === 'Argument';
		$this->htmlAttributes = NativeTags::policy();
		$this->parser = $parser; $this->caller = $caller; $this->optimize = $optimize; $this->tracing = $tracing;
		if (!in_array($moduleName, ['Debate', 'Argument'], true)) { throw new \InvalidArgumentException('Unknown renderer'); }
		$title = Title::newFromText('Module:' . $moduleName);
		// Retain all three wiki dependencies. Never read revision text directly from SQL.
		$reference = $this->fetch('Module:' . $moduleName);
		$expected = file_get_contents(__DIR__ . '/../resources/Module-' . $moduleName . '.reference.lua');
		if (rtrim($reference, "\r\n") !== rtrim($expected, "\r\n")) { throw new \RuntimeException('Module:' . $moduleName . ' changed: rebase and revalidate the PHP port.'); }
		$this->module = $caller->newChild($nodes, $title);
		$logic = $this->fetch('Module:WD/I18N');
		$source = $this->fetch('Module:WD/I18N/Data');
		// Both reference and optimized renderers load native PHP translations.
		// Fetching wiki sources above retains dependencies and checks synchronization.
		$this->i18n = I18n::bundled($source, $optimize, $logic);
	}
	private function fetch(string $name): string {
		$title = Title::newFromText($name);
		if (!$title || !$title->hasContentModel(CONTENT_MODEL_SCRIBUNTO)) { throw new \RuntimeException('Missing Scribunto data source: ' . $name); }
		[$text] = $this->parser->fetchTemplateAndTitle($title);
		if ($text === false) { throw new \RuntimeException('Cannot read ' . $name); } return $text;
	}
	public function arguments(): Table {
		$started = $this->telemetry?->start();
		try { return $this->readArguments(); }
		finally { $this->telemetry?->finish('arguments', $started); }
	}
	private function readArguments(): Table {
		$a = new Table;
		foreach ([$this->caller, $this->module] as $frame) {
			foreach ($frame->getArguments() as $k => $v) { $v = $this->argumentMode ? (string)$v : Lua::trim((string)$v); if ($v !== '') { $a[$k] = $v; } }
		} return $a;
	}
	public function language(Table $args): string { return $this->i18n->language($args, MediaWikiServices::getInstance()->getContentLanguage()->getCode()); }
	public function message(string $domain, string $lang, string $key, ...$args): string {
		$started = $this->telemetry?->start();
		try { return $this->i18n->message($domain, $lang, $key, ...$args); }
		finally { $this->telemetry?->finish('messages', $started); }
	}
	public function pageVars(): Table {
		$this->parser->getOutput()->setOutputFlag(\MediaWiki\Parser\ParserOutputFlags::VARY_PAGE_ID);
		$t = $this->parser->getTitle(); $text = $t->getPrefixedText();
		return new Table(['title' => $t, 'rawTitle' => $text, 'encoded' => $this->uriEncode($text, 'WIKI'), 'pageUrl' => $this->fullUrl($text), 'pageId' => (string)$t->getArticleID()]);
	}
	public function dbKey(string $page): string {
		$t = Title::newFromText($page);
		if ($t && $t->getNamespace() === NS_SPECIAL && !$t->equals($this->parser->getTitle())) { $this->parser->getOutput()->addLink($t); }
		return $t ? str_replace(' ', '_', $t->getPrefixedText()) : Lua::gsub($page, '%s', '_');
	}
	public function preprocess(string $text): string {
		$started = $this->telemetry?->start();
		try { return $this->expand($text); }
		finally { $this->telemetry?->finish('expansions', $started); }
	}
	private function expand(string $text): string {
		// LuaEngine::preprocess, including mandatory argument forcing BEFORE a cache hit.
		$this->module->getArguments(); $key = md5($text);
		if (isset($this->expansions[$key])) { $result = $this->expansions[$key]; $hit = true; }
		else {
			$input = str_replace(["\r\n", "\r"], "\n", $text);
			$dom = $this->parser->getPreprocessor()->preprocessToObj($input, $this->module->depth ? Parser::PTD_FOR_INCLUSION : 0);
			$result = $this->module->expand($dom); $hit = false;
			if (!$this->module->isVolatile()) {
				if (count($this->expansions) > 100) { unset($this->expansions[array_key_first($this->expansions)]); }
				$this->expansions[$key] = $result;
			}
		}
		if ($this->tracing) { $this->trace[] = ['input' => $text, 'output' => $result, 'hit' => $hit, 'volatile' => $this->module->isVolatile(), 'ttl' => $this->module->getTTL()]; }
		return $result;
	}
	public function trace(): array { return $this->trace; }
	private function nativeOperation(string $hash, callable $operation, ?string $source, string $kind): string {
		// Preserve Scribunto's invocation-local cache, including collisions with field expansions.
		$this->module->getArguments(); $hit = isset($this->expansions[$hash]);
		if ($hit) { $result = $this->expansions[$hash]; }
		else {
			$result = $operation();
			if (!$this->module->isVolatile()) {
				if (count($this->expansions) > 100) { unset($this->expansions[array_key_first($this->expansions)]); }
				$this->expansions[$hash] = $result;
			}
		}
		if ($this->tracing) { $this->trace[] = ['kind' => $kind, 'input' => $source, 'output' => $result, 'hit' => $hit, 'volatile' => $this->module->isVolatile(), 'ttl' => $this->module->getTTL()]; }
		return $result;
	}
	public function shortDescription(string $text): string {
		$started = $this->telemetry?->start();
		try {
			$source = '{{SHORTDESC: ' . $text . '}}';
			[$insensitive, $sensitive] = $this->parser->getFunctionSynonyms();
			$registered = ($sensitive['SHORTDESC'] ?? $insensitive['shortdesc'] ?? null) === 'MAG_SHORTDESC';
			// A delimiter in generated wikitext can change argument boundaries or close the call.
			// Retain the original parse in those cases and for a different SHORTDESC implementation.
			if (strpbrk($text, '{}|<[]=') !== false || !$registered) {
				return $this->expand($source);
			}
			return $this->nativeOperation(md5($source), function() use ($text): string {
				// Only literal PP text remains: no template, tag/comment, link or heading opener.
				// Its expansion is exactly newline normalization; keep callback trimming and effects.
				$expanded = trim(str_replace(["\r\n", "\r"], "\n", $text));
				$result = $this->parser->callParserFunction($this->module, 'SHORTDESC', [$expanded]);
				$value = $result['text'] ?? '';
				if ($result['isChildObj'] ?? false) { $value = $this->module->newChild([], $result['title'] ?? false)->expand($value, ($result['nowiki'] ?? false) ? PPFrame::RECOVER_ORIG : 0); }
				elseif ($result['isLocalObj'] ?? false) { $value = $this->module->expand($value, ($result['nowiki'] ?? false) ? PPFrame::RECOVER_ORIG : 0); }
				if ($result['isHTML'] ?? false) { $value = $this->parser->insertStripItem($value); }
				elseif (($result['nowiki'] ?? false) && in_array($this->parser->getOutputType(), [Parser::OT_HTML, Parser::OT_PREPROCESS], true)) { $value = wfEscapeWikiText($value); }
				return (string)$value;
			}, $this->tracing ? $source : null, 'native-shortdesc');
		} finally { $this->telemetry?->finish('shortdesc', $started); }
	}
	public function htmlTag(string $name, string $content, Table $attributes, bool $xml = false): string {
		$started = $this->telemetry?->start();
		try {
			$attrs = [];
			foreach ($attributes as $key => $value) { $attrs[Lua::str($key)] = Lua::str($value); }
			if (!$xml) { ksort($attrs, SORT_STRING); }
			$grammar = $xml ? (stripos($content, '<htmltag') !== false || stripos($content, '</htmltag') !== false)
				: (strpbrk($content, '<=|') !== false || str_contains($content, '[[') || str_contains($content, '{{') || str_contains($content, '}}'));
			foreach ($attrs as $key => $value) {
				$grammar = $grammar || ($xml ? !preg_match('/^[a-z][a-z0-9-]*$/iD', (string)$key)
					: (strpbrk((string)$value . (string)$key, '{}|<>[]=') !== false));
			}
			if ($grammar) {
				// Preserve boundaries when a nested/unclosed construct can consume the wrapper.
				$source = $xml ? '<htmltag tagname="' . $name . '"' : '{{#tag:htmltag|' . $content . '|tagname=' . $name;
				foreach ($attrs as $key => $value) { $source .= $xml ? ' ' . $key . '="' . $value . '"' : '|' . $key . '=' . $value; }
				$source .= $xml ? '>' . $content . '</htmltag>' : '}}';
				return $this->expand($source);
			}
			// Incremental legacy cache key: no constructed HTML tag/#tag invocation in normal use.
			$hash = hash_init('md5'); $source = $this->tracing ? '' : null;
			$append = static function(string $part) use ($hash, &$source): void { hash_update($hash, $part); if ($source !== null) { $source .= $part; } };
			$append($xml ? '<htmltag tagname="' . $name . '"' : '{{#tag:htmltag|' . $content . '|tagname=' . $name);
			foreach ($attrs as $key => $value) { $append($xml ? ' ' . $key . '="' . $value . '"' : '|' . $key . '=' . $value); }
			$append($xml ? '>' . $content . '</htmltag>' : '}}');
			return $this->nativeOperation(hash_final($hash), fn() => NativeTags::render($this->parser, $this->module, $name, $content, $attrs, $xml, $this->htmlAttributes), $source, 'native-htmltag');
		} finally { $this->telemetry?->finish('native_tags', $started); }
	}
	/** The JSON is generated by our renderer: never run wiki expansion inside its strings. */
	public function jsonLd(string $json): string {
		$started = $this->telemetry?->start();
		try {
			json_decode($json, false, 512, JSON_THROW_ON_ERROR);
			// A raw script element must never contain an HTML closing-script sequence.
			// JSON Unicode escapes preserve the exact string values, including wiki delimiters.
			$safe = str_replace(['<', '>', '&'], ['\\u003C', '\\u003E', '\\u0026'], $json);
			$html = \MediaWiki\Html\Html::rawElement('script', ['type' => 'application/ld+json', 'class' => 'navigation-not-searchable'], $safe);
			return $this->parser->insertStripItem($html);
		} finally { $this->telemetry?->finish('native_jsonld', $started); }
	}
	/** Preserve second expansion only for actual template or extension-tag syntax. */
	public function expandField(string $text): string {
		$started = $this->telemetry?->start();
		try {
			if (str_contains($text, '{{') || str_contains($text, '<')) { return $this->expand($text); }
			return $this->nativeOperation(md5($text), static fn() => str_replace(["\r\n", "\r"], "\n", $text), $this->tracing ? $text : null, 'native-field-data');
		} finally { $this->telemetry?->finish('fields', $started); }
	}
	public function nowiki(string $text): string {
		return TextEscaper::nowiki($text, MediaWikiServices::getInstance()->getMainConfig()->get('UrlProtocols'));
	}
	public function jsonEncode(Table $data): string {
		$started = $this->telemetry?->start();
		try {
		$result = FormatJson::encode(TextEscaper::jsonValue($data), false, FormatJson::ALL_OK);
		if ($result === false) { throw new \RuntimeException('Unable to encode JSON-LD'); } return TextEscaper::spaceStructuralClosures($result);
		} finally { $this->telemetry?->finish('json', $started); }
	}
	public function uriEncode(string $text, string $mode = 'QUERY'): string { return TextEscaper::uriEncode($text, $mode); }
	private function url(string $page, ?Table $query, string $method): ?string {
		$t = Title::newFromText($page) ?? Title::newFromURL(urldecode($page));
		if (!$t) { return null; }
		if ($t->getNamespace() === NS_MEDIA) { $t = Title::makeTitle(NS_FILE, $t->getDBkey()); }
		$url = $query === null ? $t->$method() : $t->$method(Table::unwrap($query));
		// mw.uri.new rebuilds the query with QUERY escaping (e.g. %20 -> +).
		$hash = strpos($url, '#'); $fragment = $hash === false ? '' : substr($url, $hash);
		$base = $hash === false ? $url : substr($url, 0, $hash); $q = strpos($base, '?');
		if ($q !== false) {
			$parts = [];
			foreach (explode('&', substr($base, $q + 1)) as $part) {
				if ($part === '') { continue; } $p = explode('=', $part, 2);
				$parts[] = urlencode(urldecode($p[0])) . (count($p) === 2 ? '=' . urlencode(urldecode($p[1])) : '');
			} $base = substr($base, 0, $q) . '?' . implode('&', $parts);
		}
		return $base . $fragment;
	}
	public function fullUrl(string $page, ?Table $query = null): ?string { return $this->url($page, $query, 'getFullURL'); }
	public function localUrl(string $page, ?Table $query = null): ?string { return $this->url($page, $query, 'getLocalURL'); }
	public function smwSet(Table $data): void {
		if (!class_exists(\SMW\Scribunto\LibraryFactory::class)) { $this->telemetry?->problem('semantic', 'service-unavailable'); return; }
		$started = $this->telemetry?->start();
		try {
			// Same path as SemanticScribunto 2.3.3. Values are parameters, never rebuilt wikitext.
			$raw = [];
			foreach (Table::unwrap($data) as $key => $value) {
				if (is_array($value)) { foreach ($value as $item) { $raw[] = $key . '=' . $item; } }
				else { $raw[] = (!is_int($key) && !preg_match('/^[0-9]+$/D', (string)$key)) ? $key . '=' . $value : $value; }
			}
			$factory = new \SMW\Scribunto\LibraryFactory(\SMW\Services\ServicesFactory::getInstance()->getStore());
			$result = $factory->newSetParserFunction($this->parser)->parse($factory->newParserParameterProcessorFrom($raw));
			if (is_array($result) && !($result['noparse'] ?? true)) { $this->parser->recursiveTagParseFully($result[0]); }
			$error = is_array($result) ? ($result[0] ?? '') : $result;
			if (is_string($error) && trim($error) !== '') { $this->telemetry?->problem('semantic', 'returned-error'); }
		} catch (\Throwable $e) { $this->telemetry?->problem('semantic', 'exception', $e); }
		finally { $this->telemetry?->finish('semantic', $started); }
	}
	public function seoSet(Table $data): void {
		if (!class_exists(\MediaWiki\Extension\WikiSEO\Validator::class)) { $this->telemetry?->problem('seo', 'service-unavailable'); return; }
		$started = $this->telemetry?->start();
		try {
			$validated = \MediaWiki\Extension\WikiSEO\Validator::validateParams(Table::unwrap($data));
			MediaWikiServices::getInstance()->getHookContainer()->run('WikiSEOLuaPreAddPageProps', [&$validated]);
			foreach ($validated as $key => $v) { $this->parser->getOutput()->setPageProperty($key, $v); }
		} catch (\Throwable $e) { $this->telemetry?->problem('seo', 'exception', $e); }
		finally { $this->telemetry?->finish('seo', $started); }
	}	public function smwAvailable(): bool { return class_exists(\SMW\Scribunto\LibraryFactory::class); }
	public function smwAsk(Table $query): ?Table {
		if (!$this->smwAvailable()) { $this->telemetry?->problem('semantic-query', 'service-unavailable'); return null; }
		$started = $this->telemetry?->start();
		try {
			$factory = new \SMW\Scribunto\LibraryFactory(\SMW\Services\ServicesFactory::getInstance()->getStore());
			$result = $factory->newQueryResultFrom(array_values(Table::unwrap($query)));
			// Same conversion and printout formatting as mw.smw.ask, without a Lua engine.
			if (!$result instanceof \SMW\Query\QueryResult) { return null; }
			$values = $factory->newLuaAskResultProcessor($result)->getProcessedResult();
			$toTable = static function($value) use (&$toTable) {
				if (!is_array($value)) { return $value; }
				array_unshift($value, ''); unset($value[0]);
				return new Table(array_map($toTable, $value));
			};
			return $toTable($values);
		} catch (\Throwable $e) { $this->telemetry?->problem('semantic-query', 'exception', $e); return null; }
		finally { $this->telemetry?->finish('semantic-query', $started); }
	}
	public function titleInfo(string $text): ?Table {
		if (array_key_exists($text, $this->titles)) { return $this->titles[$text]; }
		$title = Title::newFromText($text);
		if (count($this->titles) >= 600) { unset($this->titles[array_key_first($this->titles)]); }
		return $this->titles[$text] = $title ? new Table(['prefixedText' => $title->getPrefixedText()]) : null;
	}
	public function variableSet(string $name, string $value): void {
		if (!class_exists(\ExtVariables::class) || !\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('VariablesLua')) { return; }
		try {
			if (method_exists(\ExtVariables::class, 'pfObj_vardefine')) {
				\ExtVariables::pfObj_vardefine($this->parser, $this->parser->getPreprocessor()->newFrame(), [$name, $value]);
			} else { \ExtVariables::pf_vardefine($this->parser, $name, $value); }
		}
		catch (\Throwable $e) { $this->telemetry?->problem('variables', 'exception', $e); }
	}
	public function variableGet(string $name): ?string {
		if (!class_exists(\ExtVariables::class) || !\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('VariablesLua')) { return null; }
		try { return \ExtVariables::pfObj_var($this->parser, $this->parser->getPreprocessor()->newFrame(), [$name]); }
		catch (\Throwable $e) { $this->telemetry?->problem('variables', 'exception', $e); return null; }
	}

	/** Structured magic-variable calls retain MW's timestamp hooks, numbering and cache deadlines. */
	public function currentDate(): string {
		$started = $this->telemetry?->start();
		try {
			return $this->nativeOperation(md5('{{CURRENTYEAR}}-{{CURRENTMONTH}}-{{CURRENTDAY2}}'), function(): string {
				$values = [];
				foreach (['CURRENTYEAR', 'CURRENTMONTH', 'CURRENTDAY2'] as $name) {
					$result = $this->parser->braceSubstitution(['title' => $name,
						'parts' => $this->parser->getPreprocessor()->newPartNodeArray([]), 'lineStart' => false], $this->module);
					$values[] = isset($result['object']) ? $this->module->expand($result['object']) : $result['text'];
				}
				return implode('-', $values);
			}, $this->tracing ? '{{CURRENTYEAR}}-{{CURRENTMONTH}}-{{CURRENTDAY2}}' : null, 'native-date');
		} finally { $this->telemetry?->finish('current_date', $started); }
	}
	private function parserFunctionResult(array $result): string {
		$value = $result['text'] ?? '';
		if ($result['isChildObj'] ?? false) { $value = $this->module->newChild([], $result['title'] ?? false)->expand($value, ($result['nowiki'] ?? false) ? PPFrame::RECOVER_ORIG : 0); }
		elseif ($result['isLocalObj'] ?? false) { $value = $this->module->expand($value, ($result['nowiki'] ?? false) ? PPFrame::RECOVER_ORIG : 0); }
		if ($result['isHTML'] ?? false) { $value = $this->parser->insertStripItem($value); }
		elseif (($result['nowiki'] ?? false) && in_array($this->parser->getOutputType(), [Parser::OT_HTML, Parser::OT_PREPROCESS], true)) { $value = wfEscapeWikiText($value); }
		return (string)$value;
	}
	/** Uses the registered Page Forms callback; rendering a button never performs an edit. */
	public function autoEdit(Table $params): string {
		$started = $this->telemetry?->start();
		try {
			$key = md5('native-autoedit\0' . serialize(Table::unwrap($params)));
			return $this->nativeOperation($key, function() use ($params): string {
				$args = [''];
				foreach ($params as $name => $value) {
					if ($value === true) { $args[] = (string)$name; continue; }
					$text = (string)$value;
					// Only actual user/template syntax needs expansion; parameter boundaries stay structured.
					if (str_contains($text, '{{') || str_contains($text, '<')) { $text = NativeTags::argument($this->parser, $this->module, $text); }
					$args[] = (string)$name . '=' . $text;
				}
				$result = $this->parser->callParserFunction($this->module, '#autoedit', $args);
				if (!($result['found'] ?? false)) { throw new \RuntimeException('Argument initialization requires the Page Forms #autoedit callback.'); }
				return $this->parserFunctionResult($result);
			}, null, 'native-autoedit');
		} finally { $this->telemetry?->finish('autoedit', $started); }
	}
	/** Serialized quote data is already expanded in normal model arguments. */
	public function expandQuotes(string $text): string { return $this->expandField($text); }

}
