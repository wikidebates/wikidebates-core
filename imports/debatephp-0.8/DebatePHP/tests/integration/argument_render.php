<?php
// Real MediaWiki parser comparison. Uses installed extensions; does not save any wiki page.
$wikiRoot = null;
foreach ($argv as $a) { if (str_starts_with($a, '--wiki-root=')) { $wikiRoot = substr($a, 12); } }
if (!$wikiRoot) { fwrite(STDERR,"--wiki-root is required\n");exit(2); }
require $wikiRoot . '/maintenance/Maintenance.php';
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
class ArgumentRenderComparison extends Maintenance {
 public function __construct() {
  parent::__construct();$this->addOption('wiki-root','Wiki root',true,true);$this->addOption('corpus','Cases with title/args',true,true);$this->addOption('output','Results JSON',true,true);
 }
 public function execute() {
  // This host cannot expose /proc to LuaStandalone's optional resource sampler.
  // Suppress only that upstream status warning; all other PHP warnings remain visible.
  set_error_handler(static function($n,$message,$file,$line) {
   return $message==='Undefined array key 1' && str_ends_with($file,'LuaStandaloneInterpreter.php') && $line===341;
  });
  $cases=json_decode(file_get_contents($this->getOption('corpus')),true,512,JSON_THROW_ON_ERROR);$results=[];
  foreach($cases as $case) {
   $title=Title::newFromText($case['title']); if (!$title) { continue; } // Invalid titles cannot exist as wiki pages.
   $row=['name'=>$case['name'],'title'=>$case['title'],'variants'=>[]];
   foreach(['lua','reference','optimized'] as $mode) {
    $GLOBALS['wgDebatePHPMode']=$mode==='optimized'?'optimized':'reference';
    $p=MediaWikiServices::getInstance()->getParserFactory()->create();
    $options=ParserOptions::newFromAnon();$options->setTimestamp('20261018120000');
    $text=$mode==='lua'?'{{#invoke:Argument|render':'{{#argument:render';
    foreach($case['args'] as $key=>$value) { $text.='|'.$key.'='.str_replace('|','{{!}}',$value); }
    $text.='}}<references/>';
    $out=$p->parse($text,$title,$options,true,true);
    $row['variants'][$mode]=['html'=>$out->getText(),'categories'=>$out->getCategoryMap(),'properties'=>$out->getPageProperties(),
     'templates'=>$out->getTemplates(),'links'=>$out->getLinks(),'externalLinks'=>$out->getExternalLinks(),'languageLinks'=>$out->getLanguageLinks(),
     'modules'=>$out->getModules(),'flags'=>$out->getAllFlags(),'expiry'=>$out->getCacheExpiry()];
   }
   $results[]=$row;
  }
  restore_error_handler();file_put_contents($this->getOption('output'),json_encode($results,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT));
  $this->output(count($results)." real-parser Argument cases completed.\n");
 }
}
$maintClass=ArgumentRenderComparison::class;require RUN_MAINTENANCE_IF_MAIN;
