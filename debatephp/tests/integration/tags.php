<?php
// Differential tests against the former HTML Tags / SHORTDESC paths. Test wiki only.
$wikiRoot = null;
foreach ($argv as $a) { if (str_starts_with($a, '--wiki-root=')) { $wikiRoot = substr($a, 12); } }
if (!$wikiRoot) { fwrite(STDERR, "--wiki-root is required\n"); exit(2); }
require $wikiRoot . '/maintenance/Maintenance.php';

use MediaWiki\Extension\DebatePHP\MediaWikiContext;
use MediaWiki\Extension\DebatePHP\Table;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

class NativeTagsTest extends Maintenance {
	public function __construct() { parent::__construct(); $this->addOption('wiki-root', 'Test wiki', true, true); }
	public function execute() {
		if (!class_exists(HTMLTags::class)) { throw new RuntimeException('This differential test needs HTML Tags as its oracle'); }
		$cases = [
			['kind'=>'xml', 'body'=>'＋ A & <b>B</b> [[Page]]', 'attrs'=>['href'=>'javascript:void(0)', 'data-href'=>'/w/index.php?a=1&amp;b=2', 'title'=>'  A  B\n C &quot; &#39; ']],
			['kind'=>'xml', 'body'=>'{{#if:1|{{{token|BAD}}}|BAD}}', 'attrs'=>['title'=>'{{{token|BAD}}}', 'onclick'=>'{{#tag:ref|IGNORED|name=ignored}}']],
			['kind'=>'xml', 'body'=>'<nowiki>{{{token|BAD}}}</nowiki>', 'attrs'=>['title'=>"A\r\n\t B & < > \""]],
			['kind'=>'xml', 'body'=>'{{#tag:ref|BODY NOTE|name=body}}', 'attrs'=>['class'=>'wk-btn__a']],
			['kind'=>'xml', 'body'=>'Button', 'attrs'=>['title'=>'{{#tag:ref|ATTRIBUTE NOTE|name=attribute}}']],
			['kind'=>'pf', 'body'=>'{"x":"A &#124; B &#61; C", "y":"{{{token|BAD}}}"}', 'attrs'=>['class'=>'"navigation-not-searchable"','type'=>'application/ld+json']],
			['kind'=>'pf', 'body'=>'{"text":"<nowiki>A<\/nowiki>","template":"{{#if:1&#124;Y&#124;N} }"}', 'attrs'=>['class'=>'{{#if:1|"quoted"|bad}}','type'=>'application/ld+json','ignored'=>'{{#tag:ref|EXPANDED|name=expanded}}']],
			['kind'=>'xml', 'body'=>'{{#tag:ref|NOT EXPANDED|name=denied}}', 'attrs'=>[], 'denied'=>true],
			['kind'=>'pf', 'body'=>'{{#tag:ref|EXPANDED BEFORE DENIAL|name=denied}}', 'attrs'=>[], 'denied'=>true],
			['kind'=>'xml', 'body'=>'Cached button', 'attrs'=>['title'=>'cache'], 'cache'=>true],
			['kind'=>'sd', 'body'=>'A &amp; <b>B</b>', 'attrs'=>[]],
			['kind'=>'sd', 'body'=>"A\r\n\t B", 'attrs'=>[]],
			['kind'=>'sd', 'body'=>'<nowiki>{{{token|BAD}}}</nowiki>', 'attrs'=>[]],
			['kind'=>'sd', 'body'=>'A | {{#tag:ref|UNUSED ARGUMENT STILL EXPANDED|name=shortdesc}}', 'attrs'=>[]],
			['kind'=>'sd', 'body'=>'A }} after {{#tag:ref|AFTER CLOSURE|name=closure}}', 'attrs'=>[]],
			['kind'=>'sd', 'body'=>'{{#if:1|{{{token|BAD}}}|BAD}}', 'attrs'=>[]],
			['kind'=>'sd', 'body'=>'Cached description', 'attrs'=>[], 'cache'=>true],
			['kind'=>'sd', 'body'=>'[[Unclosed link', 'attrs'=>[]],
			['kind'=>'sd', 'body'=>'<nowiki>Unclosed tag', 'attrs'=>[]],
			['kind'=>'sd', 'body'=>"A\n== Heading ==\nB", 'attrs'=>[]],
			['kind'=>'pf', 'body'=>'[[Unclosed link', 'attrs'=>[]],
			['kind'=>'pf', 'body'=>'== Heading ==', 'attrs'=>[]],
			['kind'=>'xml', 'body'=>'Uppercase attribute', 'attrs'=>['TITLE'=>'TITLE VALUE']],
			['kind'=>'pf', 'body'=>'{"ok":1}', 'attrs'=>[' class '=>'with-spaces','type'=>'application/ld+json']],
			['kind'=>'pf', 'body'=>'{"ok":1}', 'attrs'=>['class'=>false,'type'=>'application/ld+json']],
			['kind'=>'pf', 'body'=>'{"ok":1}', 'attrs'=>['class'=>true,'type'=>'application/ld+json']],
		];
		$count = 0;
		foreach ([false, true] as $preprocess) {
			foreach ($cases as $index => $case) {
				$a = $this->one($case, false, $preprocess); $b = $this->one($case, true, $preprocess);
				if ($a !== $b) {
					file_put_contents(sys_get_temp_dir() . '/debate-tags-failure.json', json_encode([$a,$b], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
					throw new RuntimeException('Native tag/description differs: ' . $index . ' preprocess=' . (int)$preprocess);
				}
				$count++;
			}
		}
		$this->output($count . " tag/SHORTDESC comparisons passed (HTML and preprocess modes), including notes, policies and shared cache.\n");
	}
	private function one(array $case, bool $native, bool $preprocess): array {
		$GLOBALS['wgHTMLTagsAttributes'] = ($case['denied'] ?? false) ? [] : ['a'=>['href','data-href','class','title'],'script'=>['class','type']];
		$parser = MediaWikiServices::getInstance()->getParserFactory()->create();
		$parser->setFunctionHook('debate', static function($p,$frame,$args) use ($case,$native) {
			unset($args[0]); $context = new MediaWikiContext($p,$frame,$args,true,true);
			$context->arguments(); $xml = $case['kind'] === 'xml'; $name = $xml ? 'a' : 'script';
			$attrs = $case['attrs'];
			if ($xml) {
				foreach ($attrs as &$value) { $value = strtr(str_replace(["\r","\n","\t"], ' ', $value), ['&'=>'&amp;','<'=>'&lt;','>'=>'&gt;','"'=>'&quot;']); } unset($value);
			} else { ksort($attrs,SORT_STRING); foreach ($attrs as &$v) { $v = \MediaWiki\Extension\DebatePHP\Lua::str($v); } unset($v); }
			$source = $case['kind'] === 'sd' ? '{{SHORTDESC: ' . $case['body'] . '}}' : ($xml ? '<htmltag tagname="a"' : '{{#tag:htmltag|' . $case['body'] . '|tagname=script');
			if ($case['kind'] !== 'sd') {
				foreach ($attrs as $key=>$value) { $source .= $xml ? ' ' . $key . '="' . $value . '"' : '|' . $key . '=' . $value; }
				$source .= $xml ? '>' . $case['body'] . '</htmltag>' : '}}';
			}
			if ($case['cache'] ?? false) {
				$context->preprocess($source);
				if ($case['kind']==='sd') { $p->getOutput()->setPageProperty('shortdesc','INTERVENING DESCRIPTION'); }
			}
			$out = !$native ? $context->preprocess($source) : ($case['kind']==='sd'
				? $context->shortDescription($case['body']) : $context->htmlTag($name,$case['body'],new Table($attrs),$xml));
			return [$out,'noparse'=>true,'isHTML'=>false];
		},Parser::SFH_OBJECT_ARGS);
		$options = ParserOptions::newFromAnon(); $options->setTimestamp('20261018000000');
		$title = Title::newFromText('DebatePHP native tags');
		$text = '{{#debate:test|token=MODULE}}<references/>';
		if ($preprocess) { $html = $parser->preprocess($text,$title,$options); $out = $parser->getOutput(); }
		else { $out = $parser->parse($text,$title,$options); $html = $out->getText(); }
		return [$html,$out->getCategoryMap(),$out->getPageProperties(),$out->getTemplates(),$out->getAllFlags(),$out->getCacheExpiry(),$out->getLinks(),$out->getExternalLinks()];
	}
}
$maintClass = NativeTagsTest::class;
require RUN_MAINTENANCE_IF_MAIN;
