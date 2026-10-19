<?php
require __DIR__ . '/bootstrap.php';
use MediaWiki\Extension\DebatePHP\{ArgumentContext,ArgumentRenderer,ArgumentReferenceRenderer,Table,I18n,TextEscaper};

final class ArgumentTestContext implements ArgumentContext {
	public array $effects = ['preprocess' => [], 'ask' => [], 'set' => [], 'seo' => [], 'variables' => []];
	private I18n $i18n;
	public function __construct(private array $case) { $this->i18n = new I18n((require __DIR__ . '/../resources/I18nData.php')['data'], true); }
	public function arguments(): Table { return Table::wrap($this->case['args']); }
	public function language(Table $args): string { return $this->i18n->language($args, $this->case['lang'] ?? 'fr'); }
	public function message(string $domain, string $lang, string $key, ...$args): string { return $this->i18n->message($domain, $lang, $key, ...$args); }
	public function pageVars(): Table { $t = $this->case['title']; return new Table(['rawTitle'=>$t,'encoded'=>$this->uriEncode($t,'WIKI'),'pageUrl'=>$this->fullUrl($t),'pageId'=>'42']); }
	public function dbKey(string $page): string { return str_replace(' ', '_', $page); }
	public function titleInfo(string $text): ?Table { return new Table(['prefixedText' => str_replace('_', ' ', $text)]); }
	public function shortDescription(string $text): string { return $this->preprocess('{{SHORTDESC: ' . $text . '}}'); }
	public function htmlTag(string $name, string $content, Table $attributes, bool $xml = false): string {
		$attrs=$attributes->values;
		if (!$xml) { ksort($attrs,SORT_STRING); }
		$source=$xml ? '<htmltag tagname="'.$name.'"' : '{{#tag:htmltag|'.$content.'|tagname='.$name;
		foreach ($attrs as $key=>$value) { $source.=$xml ? ' '.$key.'="'.$value.'"' : '|'.$key.'='.$value; }
		return $this->preprocess($source.($xml ? '>'.$content.'</htmltag>' : '}}'));
	}
	public function expandField(string $text): string { return $this->preprocess($text); }
	// The pure renderer oracle retains the legacy trace format. Integration tests exercise raw scripts.
	public function jsonLd(string $json): string {
		$protected=str_replace(['|','='],['&#124;','&#61;'],$json);
		$protected=str_replace('/','\\/',$protected);
		$protected=str_replace('}}','} }',$protected);
		return $this->htmlTag('script',$protected,new Table(['type'=>'application/ld+json','class'=>'navigation-not-searchable']));
	}
	public function currentDate(): string { return $this->preprocess('{{CURRENTYEAR}}-{{CURRENTMONTH}}-{{CURRENTDAY2}}'); }
	public function expandQuotes(string $text): string { return $this->preprocess($text); }
	public function autoEdit(Table $params): string {
		$text='{{#autoedit:';
		foreach ($params as $key=>$value) {
			$text .= $key === 'link type' ? '| link type = '.$value : ($value === true ? '| '.$key : '| '.$key.' = '.$value);
		}
		return $this->preprocess($text.'}}');
	}
	public function preprocess(string $text): string {
		$this->effects['preprocess'][] = $text;
		if ($text === ($this->case['preprocess_fail'] ?? null)) { throw new RuntimeException('expansion failed'); }
		return str_replace('{{CURRENTYEAR}}-{{CURRENTMONTH}}-{{CURRENTDAY2}}', '2026-10-18', $text);
	}
	public function nowiki(string $text): string { return '<nowiki>' . $text . '</nowiki>'; }
	public function jsonEncode(Table $data): string { return json_encode(TextEscaper::jsonValue($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
	public function uriEncode(string $text, string $mode = 'QUERY'): string { return TextEscaper::uriEncode($text, $mode); }
	public function fullUrl(string $page, ?Table $query = null): ?string { return 'https://wiki.example/wiki/' . $this->uriEncode($page, 'WIKI') . ($query ? '?' . http_build_query(Table::unwrap($query), '', '&', PHP_QUERY_RFC1738) : ''); }
	public function localUrl(string $page, ?Table $query = null): ?string { return substr($this->fullUrl($page, $query), strlen('https://wiki.example')); }
	private static function answerTable($data) {
		if (!is_array($data)) { return $data; }
		$out = [];
		foreach ($data as $key => $value) { $out[is_int($key) ? $key + 1 : $key] = self::answerTable($value); }
		return new Table($out);
	}
	public function smwAvailable(): bool { return $this->case['smw'] ?? true; }
	public function smwAsk(Table $query): ?Table {
		$key = implode("\n", $query->values); $this->effects['ask'][] = $query->values;
		return isset($this->case['answers'][$key]) ? self::answerTable($this->case['answers'][$key]) : null;
	}
	public function smwSet(Table $data): void { if ($this->smwAvailable()) { $this->effects['set'][] = TextEscaper::jsonValue($data); } }
	public function seoSet(Table $data): void { if ($this->case['seo'] ?? true) { $this->effects['seo'][] = TextEscaper::jsonValue($data); } }
	public function variableSet(string $name, string $value): void { if ($this->case['vars'] ?? true) { $this->effects['variables'][$name] = trim($value); } }
	public function variableGet(string $name): ?string { return $this->effects['variables'][$name] ?? null; }
}

if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) { return; }
set_error_handler(static function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$cases = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $out = [];
foreach ($cases as $case) {
	$row = [];
	foreach ([ArgumentReferenceRenderer::class, ArgumentRenderer::class] as $renderer) {
		$c = new ArgumentTestContext($case);
		try { $r = new $renderer($c); $text = $r->render(); $c->effects['variables'] = (object)$c->effects['variables']; $row[] = ['text' => $text, 'effects' => $c->effects]; }
		catch (Throwable $e) { $row[] = ['error' => (string)$e]; }
	}
	$out[] = $row;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
