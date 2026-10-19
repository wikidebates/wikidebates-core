<?php

/** Real SMW formatter, parsed-query guards and the native/legacy read boundary. */
use MediaWiki\Extension\DebatePHP\EmptyParentQuery;
use MediaWiki\Extension\DebatePHP\SemanticReadResult;
use MediaWiki\Extension\DebatePHP\Table;

foreach (['Parent A', 'Parent B'] as $name) { fsSave('Property:WD FS ' . $name, '[[Has type::Page]]'); }
fsSave('Property:WD FS Flag', '[[Has type::Boolean]]');
fsSave('WD FS native values', '{{#set:WD FS Text=é|WD FS Text=0|WD FS Number=0|WD FS Number=12.5|WD FS Flag=no|WD FS Page=WD FS A|WD FS Page=WD FS B|WD FS Date=2026-01-01}}');
fsFlush();
$factory = new \SMW\Scribunto\LibraryFactory($store);
$raw = ['([[WD FS Parent A::WD FS never a parent target]] OR [[WD FS Parent B::WD FS never a parent target]] )', '?WD FS Parent A=pro', '?WD FS Parent B=con', 'limit=200', 'link=none'];
$parametersTable = static fn(array $values): Table => new Table(array_combine(range(1, count($values)), $values));
$rawTable = $parametersTable($raw);
$probe = $factory->newQueryResultFrom($raw);
check(EmptyParentQuery::certified($probe, $rawTable), 'Real typed empty parent union certifies unnecessary fallback reads');
$report['native_reads'] = ['empty_union' => ['description' => get_class($probe->getQuery()->getDescription()), 'errors' => count($probe->getErrors()),
	'count' => $probe->getCount(), 'further' => $probe->hasFurtherResults(), 'certified' => EmptyParentQuery::certified($probe, $rawTable)]];
$empty = $context->readSemantic($rawTable, 'debate-parents');
same(null, $empty->rows, 'Empty native union retains historical null result');
same(EmptyParentQuery::certified($probe, $rawTable), $empty->completeEmpty, 'Native adapter uses the real parsed-query proof');
fsSave('WD FS native parent', '[[WD FS Parent A::WD FS never a parent target]]');
fsFlush();
$populated = $context->readSemantic($rawTable, 'debate-parents');
check($populated->rows !== null && !$populated->completeEmpty, 'A newly persisted parent cancels the empty-union certificate');
same(Table::unwrap($context->smwAsk($rawTable)), $populated->rows, 'New parent output is identical in both adapters');
fsSave('WD FS native parent', 'Removed parent relation');
fsFlush();
check($context->readSemantic($rawTable, 'debate-parents')->completeEmpty, 'Removing the persisted relation restores certified emptiness');
foreach ([['limit=0', 3], ['offset=1', 5]] as [$parameter, $index]) {
	$restricted = $raw; $restricted[$index] = $parameter;
	check(!EmptyParentQuery::certified($factory->newQueryResultFrom($restricted), $parametersTable($restricted)), 'A limited or offset query cannot certify all parents absent');
}
$invalid = $factory->newQueryResultFrom($raw);
$invalid->addErrors(['Synthetic query error']);
check(!EmptyParentQuery::certified($invalid, $rawTable), 'Errors retain parent fallbacks');
// The original harmless delimiter warnings cannot hide any additional warning.
$extra = $factory->newQueryResultFrom($raw);
$extra->addErrors(['[2,"smw_unexpectedpart","other"]']);
check(!EmptyParentQuery::certified($extra, $rawTable), 'Additional parser warnings retain fallbacks');
$truncated = new \SMW\Query\QueryResult($probe->getPrintRequests(), $probe->getQuery(), [], $store, true);
check(!EmptyParentQuery::certified($truncated, $rawTable), 'A genuine QueryResult marked incomplete cannot certify emptiness');
$cached = new \SMW\Query\QueryResult($probe->getPrintRequests(), $probe->getQuery(), [], $store);
$cached->setFromCache(true);
check(!EmptyParentQuery::certified($cached, $rawTable), 'An empty cached union must retain potentially fresher fallback queries');
$malformed = $factory->newQueryResultFrom($raw);
$malformed->addErrors(['{"a":2,"b":"smw_unexpectedpart","c":"("}']);
check(!EmptyParentQuery::certified($malformed, $rawTable), 'Malformed warning data is conservatively rejected');
$simple = ['[[WD FS Parent A::WD FS never a parent target]]', 'limit=200', 'link=none'];
check(!EmptyParentQuery::certified($factory->newQueryResultFrom($simple), $parametersTable($simple)), 'Single branch cannot certify an empty union');
$differentTargets = $raw;
$differentTargets[0] = str_replace('Parent B::WD FS never a parent target', 'Parent B::WD FS a different target', $raw[0]);
check(!EmptyParentQuery::certified($factory->newQueryResultFrom($differentTargets), $parametersTable($differentTargets)), 'Different target pages retain parent fallbacks');
$reordered = $rawTable->values;
unset($reordered[1]); $reordered[1] = $raw[0];
check(!EmptyParentQuery::certified($probe, new Table($reordered)), 'Unexpected parameter order retains parent fallbacks');
$readQueries = [
	['[[WD FS Text::updated]]', '?WD FS Text=text', '?WD FS Number=number', '?WD FS Page=target', 'mainlabel=page', 'link=none', 'limit=10'],
	['[[WD FS Text::first]]', '?WD FS Text=text', '?WD FS Number=number', '?WD FS Date=date', 'mainlabel=page', 'link=none', 'limit=10'],
	$raw, ['[[WD FS Text::updated]]', '?WD FS Text=1', '?WD FS Number=01', 'limit=10'],
	['[[WD FS native values]]', '?WD FS Text=text', '?WD FS Number=numbers', '?WD FS Flag=flag', '?WD FS Page=pages', '?WD FS Date=date', 'limit=10']
];
foreach ($readQueries as $parameters) {
	$query = Table::wrap($parameters);
	$legacy = $context->smwAsk($query);
	$native = $context->readSemantic($query, 'other');
	same(Table::unwrap($legacy), $native->rows, 'Native SMW rows exactly match the existing formatter and one-based conversion');
}
same(false, $native->rows[1]['flag'], 'Native formatter retains semantic boolean false');
check(is_array($native->rows[1]['numbers']) && in_array(0, $native->rows[1]['numbers'], true) && in_array(12.5, $native->rows[1]['numbers'], true), 'Native formatter retains multivalued numeric zero and decimal');
check(is_array($native->rows[1]['pages']) && count($native->rows[1]['pages']) === 2, 'Native formatter retains multivalued formatted pages');
$report['native_reads']['formatter_comparisons'] = count($readQueries);
// A reduced limit gives a small real-store counterexample to union = two branches.
// At the production limit of 200, the same issue occurs beyond 200 distinct parents.
fsSave('WD FS limit parent A', '[[WD FS Parent A::WD FS limit target]]');
fsSave('WD FS limit parent B', '[[WD FS Parent B::WD FS limit target]]');
fsFlush();
$union = $factory->newQueryResultFrom(['[[WD FS Parent A::WD FS limit target]] OR [[WD FS Parent B::WD FS limit target]]', 'limit=1', 'link=none']);
$a = $factory->newQueryResultFrom(['[[WD FS Parent A::WD FS limit target]]', 'limit=1', 'link=none']);
$b = $factory->newQueryResultFrom(['[[WD FS Parent B::WD FS limit target]]', 'limit=1', 'link=none']);
same(1, $union->getCount(), 'Union limit applies to both parent branches together');
check($union->hasFurtherResults(), 'Limited parent union is incomplete in the genuine store');
same(2, $a->getCount() + $b->getCount(), 'Separate queries retain one result per branch');
$report['native_reads']['parent_union_limit_counterexample'] = ['limit' => 1, 'union_count' => $union->getCount(), 'union_further' => $union->hasFurtherResults(), 'separate_count' => $a->getCount() + $b->getCount()];
echo 'Native SMW reads: exact formatted values; empty union proof ' . ($empty->completeEmpty ? 'accepted' : 'conservatively rejected') . "\n";
