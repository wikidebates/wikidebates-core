<?php

use MediaWiki\Extension\Wikidebates\LuaUsageAudit as Audit;

foreach (['invoke', 'INVOKE', 'invoque', 'aufrufen', 'invocar'] as $word) {
	check(in_array('invoke', Audit::signals('{{#' . $word . ':A|render}}', 'wikitext', 0), true),
		'Localized invocation ' . $word);
}
check(in_array('invoke', Audit::signals('{{#in<!-- split -->voke:Test|x}}', 'wikitext', 10), true), 'Comment-separated magic word');
check(in_array('invoke', Audit::signals('<nowiki>{{#invoke:Example|x}}</nowiki>', 'wikitext', 8), true), 'Documentation is not silently ignored');
check(in_array('dynamic_parser_name', Audit::signals('{{#{{{func}}}:A|x}}', 'wikitext', 10), true), 'Dynamic parser-function name requires review');
foreach (['Module', 'Modul', 'Módulo', 'Modulo'] as $ns) {
	check(in_array('module_reference', Audit::signals('[[:' . $ns . ':A]]', 'wikitext', 0), true), 'Localized module reference');
}
same(['native_argument', 'native_debate'], Audit::signals('{{#argument:render}}{{#debate:render}}', 'wikitext', 10), 'Native marker classification');
check(!Audit::needsReview(['native_argument', 'native_debate']), 'Native render markers are informational');
check(Audit::needsReview(['native_argument', 'invoke']), 'Mixed legacy and native content is not safe by default');
same([], Audit::signals('Ordinary text #invoked and a normal link [[Article]]', 'wikitext', 0), 'Ordinary text');
same(['module_namespace'], Audit::signals('Documentation', 'wikitext', 829), 'Module discussion is a namespace candidate, not a Lua model');
same(['lua_content_model', 'module_namespace', 'semantic_lua_api', 'variables_lua_api', 'seo_lua_api', 'lua_module_loader'],
	Audit::signals('require("Module:Example"); mw.loadData("Module:Data"); mw.smw.ask({}); mw.ext.VariablesLua.var("a"); mw.ext.seo.set({})', 'Scribunto', 828),
	'Module and Lua bridge signals');
check(Audit::needsReview(['lua_content_model']), 'An unused Lua module still needs a content-model decision');
echo "OK: conservative six-language Lua source inventory\n";
