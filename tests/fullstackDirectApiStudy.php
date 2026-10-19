<?php

/** Study only: direct query construction, never used by the extension at runtime. */
$raw = ['[[WD FS Text::updated]]', '?WD FS Text=text', '?WD FS Number=number',
	'?WD FS Page=target', 'mainlabel=page', 'link=none', 'limit=10'];
$factory = new \SMW\Scribunto\LibraryFactory($store);
$reference = $factory->newLuaAskResultProcessor($factory->newQueryResultFrom($raw))->getProcessedResult();
[$queryString, $parameters, $printouts] = \SMWQueryProcessor::getComponentsFromFunctionParams($raw, false);
\SMWQueryProcessor::addThisPrintout($printouts, $parameters);
$query = \SMWQueryProcessor::createQuery($queryString,
	\SMWQueryProcessor::getProcessedParams($parameters, $printouts), \SMW\Query\QueryContext::SPECIAL_PAGE, '', $printouts);
if (defined('SMWQuery::PROC_CONTEXT')) { $query->setOption(\SMWQuery::PROC_CONTEXT, 'SSC.LibraryFactory'); }
$directResult = $store->getQueryResult($query);
$direct = $factory->newLuaAskResultProcessor($directResult)->getProcessedResult();
same(fsCanonical($reference), fsCanonical($direct), 'Direct SMW query construction reproduces LibraryFactory on the typed probe');
$report['direct_api_study'] = ['typed_read_probe_matches' => true, 'runtime_adapter_changed' => false,
	'formatter' => 'SemanticScribunto LuaAskResultProcessor retained; raw DataItems are not output-equivalent'];
echo "Direct SMW PHP query construction study: typed read probe matches; runtime adapter unchanged\n";
