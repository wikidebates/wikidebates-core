<?php
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

class DebateTagBenchmark extends Maintenance {
	public function __construct() {
		parent::__construct(); $this->addOption('wiki-root','Test wiki',true,true);
		$this->addOption('output','JSON result',true,true);
	}
	public function execute() {
		if (!class_exists(HTMLTags::class)) { throw new RuntimeException('HTML Tags is needed only for the legacy oracle'); }
		$timings = ['legacy'=>[], 'native'=>[]];
		$this->one(false); $this->one(true);
		for ($round=0; $round<15; $round++) {
			$modes=[false,true]; shuffle($modes); $results=[];
			foreach ($modes as $native) {
				[$elapsed,$snapshot]=$this->one($native); $name=$native?'native':'legacy';
				$timings[$name][]=$elapsed/100; $results[$name]=$snapshot;
			}
			if ($results['legacy']!==$results['native']) { throw new RuntimeException('Timed outputs differ'); }
		}
		$summary=[];
		foreach ($timings as $name=>$values) { $sorted=$values;sort($sorted);$summary[$name]=['median_ms_per_bundle'=>$sorted[7],'samples'=>$values]; }
		$summary['reduction_percent']=100*(1-$summary['native']['median_ms_per_bundle']/$summary['legacy']['median_ms_per_bundle']);
		$summary['bundles_per_sample']=100;$summary['rounds']=15;
		$summary['scope']='Two buttons, one JSON script and one short description. Fresh parser per sample, unique content per bundle; no fragment cache hit. Excludes page parse and context setup.';
		file_put_contents($this->getOption('output'),json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
		$this->output(json_encode($summary,JSON_PRETTY_PRINT) . "\n");
	}
	private function one(bool $native): array {
		$parser=MediaWikiServices::getInstance()->getParserFactory()->create(); $elapsed=0;
		$parser->setFunctionHook('debate',static function($p,$frame,$args) use($native,&$elapsed) {
			unset($args[0]);$context=new MediaWikiContext($p,$frame,$args,true);$context->arguments();
			$records=[];
			for($i=0;$i<100;$i++){$records[]=['id'=>$i,'json'=>'{"@context":"https:\\/\\/schema.org","name":"'.str_repeat('sample ',70).'","serial":'.$i.'}'];}
			$started=hrtime(true);$last='';
			foreach($records as $record){
				$i=$record['id'];$last='';
				foreach(['pro','con'] as $side){
					$href='/w/index.php?side='.$side.'&amp;n='.$i;$label='＋ '.$side.' '.$i;
					$last.=$native?$context->htmlTag('a',$label,new Table(['href'=>'javascript:void(0)','data-href'=>$href,'class'=>'wk-btn__a wk-js-nav','title'=>'Add']),true)
						:$context->preprocess('<htmltag tagname="a" href="javascript:void(0)" data-href="'.$href.'" class="wk-btn__a wk-js-nav" title="Add">'.$label.'</htmltag>');
				}
				$last.=$native?$context->htmlTag('script',$record['json'],new Table(['class'=>'navigation-not-searchable','type'=>'application/ld+json']))
					:$context->preprocess('{{#tag:htmltag|'.$record['json'].'|tagname=script|class=navigation-not-searchable|type=application/ld+json}}');
				$desc='Arguments pour et contre la proposition '.$i;
				$last.=$native?$context->shortDescription($desc):$context->preprocess('{{SHORTDESC: '.$desc.'}}');
			}
			$elapsed=(hrtime(true)-$started)/1e6;
			return[$last,'noparse'=>true,'isHTML'=>false];
		},Parser::SFH_OBJECT_ARGS);
		$options=ParserOptions::newFromAnon();$options->setTimestamp('20261018000000');
		$out=$parser->parse('{{#debate:render}}',Title::newFromText('DebatePHP tag benchmark'),$options);
		return[$elapsed,[$out->getText(),$out->getPageProperties(),$out->getCategoryMap(),$out->getTemplates(),$out->getAllFlags(),$out->getCacheExpiry()]];
	}
}
$maintClass=DebateTagBenchmark::class;
require RUN_MAINTENANCE_IF_MAIN;
