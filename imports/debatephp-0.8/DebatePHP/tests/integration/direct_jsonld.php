<?php
$wikiRoot=null;foreach($argv as $a) { if(str_starts_with($a,'--wiki-root=')) { $wikiRoot=substr($a,12); } }
if(!$wikiRoot) { fwrite(STDERR,"--wiki-root required\n");exit(2); }
require $wikiRoot.'/maintenance/Maintenance.php';
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\{Parser,ParserOptions};
use MediaWiki\Title\Title;
use MediaWiki\Extension\DebatePHP\{MediaWikiContext,Table};
class DirectJsonLdTest extends Maintenance {
 public function __construct() { parent::__construct();$this->addOption('wiki-root','Wiki root',true,true); }
 public function execute() {
  $cases=[
   'A & B | C = D }}',
   '</script><script>alert("x")</script>',
   '{{CURRENTYEAR}} {{#if:1|changed|bad}} [[JSON-only-link]] <ref>not a citation</ref>',
   'Literal &#124; and &amp; and &#61;',
   'Accents éàç 漢字 "quote" and apostrophe',
  ];$assertions=0;
  foreach([false,true] as $preprocess) {
   foreach($cases as $value) {
    $p=MediaWikiServices::getInstance()->getParserFactory()->create();$trace=[];
    $p->setFunctionHook('argument',static function($parser,$frame,$args) use($value,&$trace) {
     unset($args[0]);$c=new MediaWikiContext($parser,$frame,$args,true,true,null,'Argument');
     $text=$c->jsonLd($c->jsonEncode(new Table(['@context'=>'https://schema.org','@type'=>'Thing','name'=>$value])));$trace=$c->trace();
     return [$text,'noparse'=>true,'isHTML'=>false];
    },Parser::SFH_OBJECT_ARGS);
    $options=ParserOptions::newFromAnon();$title=Title::newFromText('JSON direct test');
    if($preprocess) { $text=$p->preprocess('{{#argument:render}}',$title,$options);$out=$p->getOutput();$text=$p->getStripState()->unstripBoth($text); }
    else { $out=$p->parse('{{#argument:render}}',$title,$options);$text=$out->getText(); }
    if(substr_count($text,'<script')!==1 || substr_count($text,'</script>')!==1) { throw new RuntimeException('Script boundary escaped'); }$assertions++;
    if(!preg_match('~<script[^>]*>(.*?)</script>~s',$text,$m)) { throw new RuntimeException('Script missing'); }
    $data=json_decode($m[1],true,512,JSON_THROW_ON_ERROR);
    if($data['name']!==$value) { throw new RuntimeException('JSON string changed: '.json_encode([$value,$data['name']])); }$assertions++;
    if($trace!==[] || $out->getTemplates()!==[] || $out->getLinks()!==[]) { throw new RuntimeException('JSON data was parsed as wiki source'); }$assertions++;
   }
  }
  $p=MediaWikiServices::getInstance()->getParserFactory()->create();$trace=[];
  $p->setFunctionHook('argument',static function($parser,$frame,$args) use(&$trace) {
   unset($args[0]);$c=new MediaWikiContext($parser,$frame,$args,true,true,null,'Argument');$parts=[];
   foreach(['0',"A\r\nB",'[[Page|Titre]]','A⟬Titre⟭','A=B | C'] as $text) { $parts[]=$c->expandField($text); }
   $parts[]=$c->expandField('{{#if:1|DYNAMIC_OK|BAD}}');$trace=$c->trace();return [implode(';',$parts),'noparse'=>true,'isHTML'=>false];
  },Parser::SFH_OBJECT_ARGS);
  $out=$p->parse('{{#argument:render}}',Title::newFromText('Field expansion test'),ParserOptions::newFromAnon());
  if(count($trace)!==6 || count(array_filter($trace,static fn($t)=>($t['kind']??'')==='native-field-data'))!==5 || !str_contains($out->getText(),'DYNAMIC_OK')) { throw new RuntimeException('Literal/dynamic field classification failed'); }$assertions++;
  $this->output($assertions." assertions passed: direct JSON-LD, exact values, script boundaries, no wiki expansion, literal/dynamic fields (HTML and preprocess modes).\n");
 }
}
$maintClass=DirectJsonLdTest::class;require RUN_MAINTENANCE_IF_MAIN;
