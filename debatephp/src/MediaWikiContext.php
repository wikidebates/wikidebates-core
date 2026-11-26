<?php
namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\Extension\Wikidebates\Seo\PublicationDate;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Title\Title;

/** MediaWiki 1.43 legacy parser boundary. No recursive parse/reset of the current Parser. */
final class MediaWikiContext implements ArgumentContext, SemanticProfilingContext, PreparationProfilingContext, NativeSemanticContext, NativeJsonContext, SeoTextContext {
	private Parser $parser;
	private PPFrame $caller;
	private PPFrame $module;
	private I18n $i18n;
	private array $expansions = [];
	private array $trace = [];
	private bool $tracing;
	private bool $optimize;
	private ?Telemetry $telemetry;
	private bool $argumentMode;
	private array $titles = [];
	private string $semanticOrigin = 'other';
	public function __construct(Parser $parser, PPFrame $caller, array $nodes, bool $optimize, bool $tracing = false, ?Telemetry $telemetry = null, string $moduleName = 'Debate') {
		$this->telemetry = $telemetry; $this->argumentMode = $moduleName === 'Argument';
		$this->parser = $parser; $this->caller = $caller; $this->optimize = $optimize; $this->tracing = $tracing;
		if (!in_array($moduleName, ['Debate', 'Argument'], true)) { throw new \InvalidArgumentException('Unknown renderer'); }
		$title = Title::newFromText('Module:' . $moduleName);
		// Keep the historical parameter-frame identity without reading a wiki module.
		$this->module = $caller->newChild($nodes, $title);
		$this->i18n = I18n::bundled($optimize);
	}
	public function arguments(): Table {
		$started = $this->telemetry?->start();
		try { return $this->readArguments(); }
		finally { $this->telemetry?->finish('arguments', $started); }
	}
	private function readArguments(): Table {
		$a = new Table;
		foreach ([$this->caller, $this->module] as $frame) {
			foreach ($frame->getArguments() as $k => $v) { $v = $this->argumentMode ? (string)$v : trim((string)$v, " \t\r\n\v\f"); if ($v !== '') { $a[$k] = $v; } }
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
		return $t ? str_replace(' ', '_', $t->getPrefixedText()) : strtr($page, " \t\r\n\v\f", "______");
	}
	public function preprocess(string $text): string {
		$started = $this->telemetry?->start();
		try { return $this->expand($text); }
		finally { $this->telemetry?->finish('expansions', $started); }
	}
	private function expand(string $text, string $reason = 'explicit'): string {
		// LuaEngine::preprocess, including mandatory argument forcing BEFORE a cache hit.
		$this->module->getArguments(); $key = md5($text);
		if (isset($this->expansions[$key])) {
			$result = $this->expansions[$key]; $hit = true;
			$this->telemetry?->count('preprocess_cache_hits');
		}
		else {
			$input = str_replace(["\r\n", "\r"], "\n", $text);
			$operation = function() use ($input): string {
				$dom = $this->parser->getPreprocessor()->preprocessToObj($input, $this->module->depth ? Parser::PTD_FOR_INCLUSION : 0);
				return $this->module->expand($dom);
			};
			$result = NativeTags::observe($this->telemetry, fn() => $this->telemetry
				? $this->telemetry->preprocess($reason, $operation) : $operation());
			$hit = false; $this->rememberExpansion($key, $result);
		}
		if ($this->tracing) { $this->trace[] = ['input' => $text, 'output' => $result, 'hit' => $hit, 'volatile' => $this->module->isVolatile(), 'ttl' => $this->module->getTTL()]; }
		return $result;
	}
	public function trace(): array { return $this->trace; }
	private function rememberExpansion(string $key, string $result): void {
		if ($this->module->isVolatile()) { return; }
		if (count($this->expansions) > 100) { unset($this->expansions[array_key_first($this->expansions)]); }
		$this->expansions[$key] = $result;
	}
	private function nativeOperation(string $hash, callable $operation, ?string $source, string $kind): string {
		// Preserve Scribunto's invocation-local cache, including collisions with field expansions.
		$this->module->getArguments(); $hit = isset($this->expansions[$hash]);
		if ($hit) { $result = $this->expansions[$hash]; $this->telemetry?->count('native_cache_hits'); }
		else {
			$result = $operation();
			$this->rememberExpansion($hash, $result);
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
				return $this->expand($source, 'shortdesc.fallback');
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
	/** PHP-only navigation anchor; no htmltag parser invocation or fallback. */
	public function htmlTag(string $name, string $content, Table $attributes, bool $xml = false): string {
		if ($name !== 'a' || !$xml) {
			throw new \RuntimeException('Only native XML-style navigation links are supported');
		}
		$started = $this->telemetry?->start();
		try {
			$attrs = [];
			foreach ($attributes as $key => $value) {
				// Preserve the textual attribute contract (including non-string legacy values).
				$attrs[(string)$key] = match ($value) {
					null => 'nil',
					false => 'false',
					true => 'true',
					default => (string)$value
				};
			}
			$key = 'native-link:' . hash('sha256', serialize([$content, $attrs]));
			return $this->nativeOperation($key, fn() => NativeTags::observe($this->telemetry,
				fn() => NativeTags::renderLink($this->parser, $this->module, $content, $attrs)),
				$this->tracing ? $content : null, 'native-link');
		} finally { $this->telemetry?->finish('native_tags', $started); }
	}
	/** The JSON is generated by our renderer: never run wiki expansion inside its strings. */
	public function jsonLd(string $json): string {
		$started = $this->telemetry?->start();
		try {
			json_decode($json, false, 512, JSON_THROW_ON_ERROR);
			return $this->insertJson($json);
		} finally { $this->telemetry?->finish('native_jsonld', $started); }
	}
	/** Resolve literal nowiki content but never run general extension-tag callbacks. */
	public function resolveSeoMarkers(string $text): string {
		if (!str_contains($text, Parser::MARKER_PREFIX)) {
			return $text;
		}
		$strip = $this->parser->getStripState();
		// Decode only recovered nowiki contents, not unrelated entities.
		$text = $strip->replaceNoWikis($text, static function($value) use ($strip): string {
			return html_entity_decode($strip->killMarkers($strip->unstripNoWiki($value)),
				ENT_QUOTES | ENT_HTML5, 'UTF-8');
		});
		$text = $strip->killMarkers($text);
		return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
	}

	public function jsonLdData(array $data): string {
		$started = $this->telemetry?->start();
		try {
			// Only the persisted revision is authoritative. ContentRenderer fills
			// ParserOutput's timestamp after parsing, and some old extensions
			// (e.g. SMW 6.0.1) used that field for the cache/rendering time.
			// Do not use it or Parser::getRevisionTimestamp() as a fallback.
			$parserOutput = $this->parser->getOutput();
			$timestamp = null;
			if ($this->parser->getRevisionId() !== null) {
				$revision = $this->parser->getRevisionRecordObject();
				if ($revision && $revision->getId() > 0) {
					$timestamp = $revision->getTimestamp();
				}
			}
			$modified = is_string($timestamp) && preg_match('/^\d{14}$/D', $timestamp)
				? wfTimestamp(TS_ISO_8601, $timestamp) : null;
			// Follow MediaWiki's revision-sensitive pre-save rendering contract.
			// If the saved revision has a different timestamp, the prepared
			// output must be parsed again instead of caching a speculative date.
			if (method_exists($parserOutput, 'setRevisionTimestampUsed')) {
				if ($modified !== null) {
					$parserOutput->setRevisionTimestampUsed($timestamp);
					$parserOutput->setOutputFlag(\MediaWiki\Parser\ParserOutputFlags::VARY_REVISION_TIMESTAMP);
				} else {
					// A preview with no known timestamp must not become the
					// canonical saved output with dateModified still missing.
					$parserOutput->setOutputFlag(\MediaWiki\Parser\ParserOutputFlags::VARY_REVISION);
				}
			}
			if ($modified !== null) {
				$data = \MediaWiki\Extension\Wikidebates\Seo\Metadata::withModificationDate($data, $modified);
			}
			$data = \MediaWiki\Extension\Wikidebates\Seo\Metadata::withPublicationDate(
				$data, $this->publicationDate()
			);
			$payload = $parserOutput->getExtensionData(\MediaWiki\Extension\Wikidebates\Seo\Metadata::DATA_KEY);
			if (is_array($payload)) {
				$data = \MediaWiki\Extension\Wikidebates\Seo\Metadata::withDescription($data, $payload);
			}
			$json = FormatJson::encode(JsonData::schemaValue($data, $this), false, FormatJson::ALL_OK);
			if ($json === false) { throw new \RuntimeException('Unable to encode JSON-LD'); }
		} finally { $this->telemetry?->finish('json', $started); }
		$started = $this->telemetry?->start();
		try { return $this->insertJson($json); }
		finally { $this->telemetry?->finish('native_jsonld', $started); }
	}
	private function insertJson(string $json): string {
		// Both entry points escape script closers; only the internally encoded path skips decoding.
		$safe = str_replace(['<', '>', '&'], ['\\u003C', '\\u003E', '\\u0026'], $json);
		$html = \MediaWiki\Html\Html::rawElement('script', ['type' => 'application/ld+json', 'class' => 'navigation-not-searchable'], $safe);
		return $this->parser->insertStripItem($html);
	}
	/** Preserve second expansion only for actual template or extension-tag syntax. */
	public function expandField(string $text, string $reason = 'field'): string {
		$started = $this->telemetry?->start();
		try {
			if (str_contains($text, '{{') || str_contains($text, '<')) { return $this->expand($text, $reason); }
			$this->telemetry?->count('literal_fields');
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
		if ($result === false) { throw new \RuntimeException('Unable to encode JSON-LD'); } return $result;
		} finally { $this->telemetry?->finish('json', $started); }
	}
	public function uriEncode(string $text, string $mode = 'QUERY'): string { return TextEscaper::uriEncode($text, $mode); }
	public function profilePreparation(array $statistics): void { $this->telemetry?->preparation($statistics); }
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
		$failed = true;
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
			$failed = is_string($error) && trim($error) !== '';
			if ($failed) { $this->telemetry?->problem('semantic', 'returned-error'); }
		} catch (\Throwable $e) {
			DatabaseFailure::rethrow($e);
			$this->telemetry?->problem('semantic', 'exception', $e);
		}
		finally {
			$this->telemetry?->semanticFinish('writes', 'write', $started, $failed);
			$this->telemetry?->finish('semantic', $started);
		}
	}
	public function seoSet(Table $data): void {
		$started = $this->telemetry?->start();
		try {
			$values = Table::unwrap($data);
			$args = $this->readArguments();
			$values['wd_kind'] = $this->argumentMode ? 'argument' : 'debate';
			$values['wd_published_time'] = $this->publicationDate();
			if ($this->argumentMode) {
				$values['wd_summary'] = (string)( $args['summary'] ?? '' );
			}

			// StripState is only available at parse time. Persist clean values,
			// not parser-local tokens. Mark already-plain fields so literal
			// nowiki text is not interpreted as wiki syntax a second time.
			$plainFields = [];
			foreach ($values as $key => $value) {
				if (!is_string($value) || !str_contains($value, Parser::MARKER_PREFIX)) {
					continue;
				}
				if (in_array($key, ['title', 'description', 'image_alt', 'wd_summary'], true)) {
					$values[$key] = NativeText::seoPlainText($value, $this);
					$plainFields[] = $key;
				} else {
					$values[$key] = $this->resolveSeoMarkers($value);
				}
			}
			if ($plainFields !== []) {
				$values['wd_plain_fields'] = $plainFields;
			}

			// ParserCache carries these values into the OutputPage. No WikiSEO
			// Validator, page_props or legacy SEO hooks are required. WikiSEO
			// remains free to manage every other page.
			$this->parser->getOutput()->setExtensionData(
				\MediaWiki\Extension\Wikidebates\Seo\Metadata::DATA_KEY, $values
			);
		} catch (\Throwable $e) {
			DatabaseFailure::rethrow($e);
			$this->telemetry?->problem('seo', 'exception', $e);
		} finally {
			$this->telemetry?->finish('seo', $started);
		}
	}
	public function smwAvailable(): bool { return class_exists(\SMW\Scribunto\LibraryFactory::class); }
	public function profiledSmwAsk(Table $query, string $origin): ?Table {
		if (!$this->telemetry?->isProfiling()) { return $this->smwAsk($query); }
		$previous = $this->semanticOrigin;
		$this->semanticOrigin = $origin;
		try { return $this->smwAsk($query); }
		finally { $this->semanticOrigin = $previous; }
	}
	public function smwCacheHit(string $origin, string $layer): void {
		$this->telemetry?->semanticCacheHit($origin, $layer);
	}
	public function smwAsk(Table $query): ?Table {
		return $this->semanticQuery($query, false);
	}
	public function readSemantic(Table $query, string $origin): SemanticReadResult {
		$previous = $this->semanticOrigin;
		$this->semanticOrigin = $origin;
		try { return $this->semanticQuery($query, true); }
		finally { $this->semanticOrigin = $previous; }
	}
	public function skipEmptyParentFallback(string $origin): void {
		$this->telemetry?->semanticFallbackSkipped($origin);
	}
	private function semanticQuery(Table $query, bool $native): Table|SemanticReadResult|null {
		if (!$this->smwAvailable()) {
			$this->telemetry?->problem('semantic-query', 'service-unavailable');
			return $native ? new SemanticReadResult(null) : null;
		}
		$started = $this->telemetry?->start();
		$failed = true;
		try {
			$queryStarted = $this->telemetry?->start();
			$queryFailed = true;
			try {
				$factory = new \SMW\Scribunto\LibraryFactory(\SMW\Services\ServicesFactory::getInstance()->getStore());
				$enabled = class_exists(MediaWikiServices::class) &&
					(bool)MediaWikiServices::getInstance()->getMainConfig()->get('WikidebatesSemanticCacheTracking');
				$result = $enabled
					? TrackedSemanticQuery::execute($this->parser, array_values(Table::unwrap($query)),
						\SMW\Services\ServicesFactory::getInstance()->getStore())
					: $factory->newQueryResultFrom(array_values(Table::unwrap($query)));
				$queryFailed = !$result instanceof \SMW\Query\QueryResult;
			} finally { $this->telemetry?->semanticFinish($this->semanticOrigin, 'query', $queryStarted, $queryFailed); }
			// Same conversion and printout formatting as mw.smw.ask, without a Lua engine.
			if (!$result instanceof \SMW\Query\QueryResult) { return $native ? new SemanticReadResult(null) : null; }
			$resultStarted = $this->telemetry?->start();
			try {
				$values = $factory->newLuaAskResultProcessor($result)->getProcessedResult();
				if ($native) {
					$converted = new SemanticReadResult(SemanticReadResult::values($values),
						$values === null && EmptyParentQuery::certified($result, $query));
					$failed = false;
					return $converted;
				}
				$toTable = static function($value) use (&$toTable) {
					if (!is_array($value)) { return $value; }
					array_unshift($value, ''); unset($value[0]);
					return new Table(array_map($toTable, $value));
				};
				$converted = $toTable($values);
				$failed = false;
				return $converted;
			} finally { $this->telemetry?->semanticFinish($this->semanticOrigin, 'result', $resultStarted, $failed); }
		} catch (\Throwable $e) {
			if (class_exists(MediaWikiServices::class) &&
				MediaWikiServices::getInstance()->getMainConfig()->get('WikidebatesSemanticCacheTracking')) {
				// Never cache a partial render after a failed tracked query.
				$this->parser->getOutput()->updateCacheExpiry(0);
			}
			DatabaseFailure::rethrow($e);
			$this->telemetry?->problem('semantic-query', 'exception', $e);
			return $native ? new SemanticReadResult(null) : null;
		}
		finally {
			$this->telemetry?->semanticFinish($this->semanticOrigin, 'read', $started, $failed);
			$this->telemetry?->finish('semantic-query', $started);
		}
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
		catch (\Throwable $e) {
			DatabaseFailure::rethrow($e);
			$this->telemetry?->problem('variables', 'exception', $e);
		}
	}
	public function variableGet(string $name): ?string {
		if (!class_exists(\ExtVariables::class) || !\MediaWiki\Registration\ExtensionRegistry::getInstance()->isLoaded('VariablesLua')) { return null; }
		try { return \ExtVariables::pfObj_var($this->parser, $this->parser->getPreprocessor()->newFrame(), [$name]); }
		catch (\Throwable $e) {
			DatabaseFailure::rethrow($e);
			$this->telemetry?->problem('variables', 'exception', $e);
			return null;
		}
	}

	/** The same ParserOutput value also feeds Open Graph and JSON-LD. */
	public function publicationDate(): ?string {
		return PublicationDate::forParser($this->parser);
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
					if (str_contains($text, '{{') || str_contains($text, '<')) {
						$text = NativeTags::observe($this->telemetry, fn() => NativeTags::argument($this->parser, $this->module, $text, 'autoedit.parameter'));
					}
					$args[] = (string)$name . '=' . $text;
				}
				$result = $this->parser->callParserFunction($this->module, '#autoedit', $args);
				if (!($result['found'] ?? false)) { throw new \RuntimeException('Argument initialization requires the Page Forms #autoedit callback.'); }
				return $this->parserFunctionResult($result);
			}, null, 'native-autoedit');
		} finally { $this->telemetry?->finish('autoedit', $started); }
	}
	/** Serialized quote data is already expanded in normal model arguments. */
	public function expandQuotes(string $text): string { return $this->expandField($text, 'argument.quotes'); }

}
