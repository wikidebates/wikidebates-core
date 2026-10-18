<?php
$wikiRoot=null;foreach($argv as $arg) { if(str_starts_with($arg,'--wiki-root=')) { $wikiRoot=substr($arg,12); } }
if(!$wikiRoot) { fwrite(STDERR,"--wiki-root required\n");exit(2); }
require $wikiRoot.'/maintenance/Maintenance.php';
use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\DebatePHP\{MediaWikiContext,Table};
use MediaWiki\Parser\{Parser,ParserOptions};
use MediaWiki\Title\Title;
class ArgumentOperationsTest extends Maintenance {
 public function __construct() { parent::__construct();$this->addOption('wiki-root','Wiki root',true,true); }
 public function execute() {
  $cases=[['kind'=>'date'],['kind'=>'quotes','text'=>'⟬⟬⟬Alice⟬⟬⟬Livre'],['kind'=>'quotes','text'=>"0\r\nTexte"],
   ['kind'=>'quotes','text'=>'{{#if:1|CITATION|BAD}}⟬⟬⟬Auteur'],['kind'=>'quotes','text'=>'<ref>Note</ref>⟬⟬⟬Auteur'],
   ['kind'=>'quotes','text'=>'<nowiki>{{{token|BAD}}}</nowiki>'],['kind'=>'quotes','text'=>'<htmltag tagname="a" href="/wiki/A">Lien</htmltag>'],
   ['kind'=>'auto','form'=>'Formulaire','target'=>'Page parent','title'=>'Titre'],
   ['kind'=>'auto','form'=>'Formulaire','target'=>'Page parent','title'=>'Titre = égal & accent é'],
   ['kind'=>'auto','form'=>'Formulaire','target'=>'Page parent','title'=>'[[Lien]]'],
   ['kind'=>'auto','form'=>'Formulaire','target'=>'Page parent','title'=>'{{#if:1|Texte|BAD}}']];
  $count=0;
  foreach([false,true] as $pre) {
   foreach($cases as $case) {
    $a=$this->one($case,false,$pre);$b=$this->one($case,true,$pre);
    if($a!==$b) { file_put_contents($this->getConfig()->get('TmpDirectory').'/argument-operation-failure.json',json_encode([$case,$pre,$a,$b],JSON_PRETTY_PRINT));throw new RuntimeException('Operation differs: '.json_encode($case).' pre='.(int)$pre); }
    $count++;
   }
  }
  $this->output($count." native date/quote/autoedit comparisons passed in HTML and preprocess modes.\n");
 }
 private function one(array $case,bool $native,bool $pre):array {
  $p=MediaWikiServices::getInstance()->getParserFactory()->create();
  $p->setFunctionHook('argument',static function($parser,$frame,$args) use($case,$native) {
   unset($args[0]);$c=new MediaWikiContext($parser,$frame,$args,true,false,null,'Argument');$c->arguments();
   if($case['kind']==='date') { $out=$native?$c->currentDate():$c->preprocess('{{CURRENTYEAR}}-{{CURRENTMONTH}}-{{CURRENTDAY2}}'); }
   elseif($case['kind']==='quotes') { $out=$native?$c->expandQuotes($case['text']):$c->preprocess($case['text']); }
   else {
    $params=['form'=>$case['form'],'target'=>$case['target'],'query string'=>'Argument[99][page]='.$case['title'],'link text'=>'Terminer','link type'=>'button','tooltip'=>'Initialiser','ok text'=>'Terminé','summary'=>'Ajout : '.$case['title'],'reload'=>true];
    $source='{{#autoedit:';foreach($params as $key=>$value) { $source.=$value===true?'| '.$key:'| '.$key.' = '.$value; }$source.='}}';
    $out=$native?$c->autoEdit(new Table($params)):$c->preprocess($source);
   }
   return [$out,'noparse'=>true,'isHTML'=>false];
  },Parser::SFH_OBJECT_ARGS);
  $options=ParserOptions::newFromAnon();$options->setTimestamp('20261018120000');$title=Title::newFromText('Test opération Argument');
  if($pre) { $text=$p->preprocess('{{#argument:render|token=MODULE}}<references/>',$title,$options);$out=$p->getOutput(); }
  else { $out=$p->parse('{{#argument:render|token=MODULE}}<references/>',$title,$options);$text=$out->getText(); }
  return [$text,$out->getCategoryMap(),$out->getPageProperties(),$out->getTemplates(),$out->getModules(),$out->getAllFlags(),$out->getCacheExpiry(),$out->getLinks(),$out->getExternalLinks()];
 }
}
$maintClass=ArgumentOperationsTest::class;require RUN_MAINTENANCE_IF_MAIN;
