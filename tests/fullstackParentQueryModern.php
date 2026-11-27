<?php

/** Exercise the real empty-parent proof against the production SMW data-item classes. */
use MediaWiki\Extension\DebatePHP\EmptyParentQuery;
use MediaWiki\Extension\DebatePHP\NativeSemanticApi;

foreach (['A', 'B'] as $suffix) {
	fsSave('Property:WD Modern Parent ' . $suffix, '[[Has type::Page]]');
}
fsSave('WD Modern Target', 'Target');
fsFlush();
$raw = ['([[WD Modern Parent A::WD Modern Target]] OR [[WD Modern Parent B::WD Modern Target]] )',
	'?WD Modern Parent A=pro', '?WD Modern Parent B=con', 'limit=200', 'link=none'];
$query = array_combine(range(1, count($raw)), $raw);
$probe = static function() use ($store, $raw): \SMW\Query\QueryResult {
	$store->clear();
	return NativeSemanticApi::query($store, $raw);
};
$empty = $probe();
same(0, $empty->getCount(), 'No persisted parent initially');
check(EmptyParentQuery::certified($empty, $query),
	'Modern SMW certifies an empty parent union: ' . json_encode($empty->getErrors(), JSON_THROW_ON_ERROR));

foreach (['A', 'B'] as $suffix) {
	fsSave('WD Modern Parent ' . $suffix, '[[WD Modern Parent ' . $suffix . '::WD Modern Target]]');
	fsFlush();
}
$populated = $probe();
same(2, $populated->getCount(), 'Both persisted parent branches returned');
check(!EmptyParentQuery::certified($populated, $query), 'Populated union must never certify emptiness');

foreach (['A', 'B'] as $suffix) {
	fsSave('WD Modern Parent ' . $suffix, 'Relation removed');
	fsFlush();
}
$empty = $probe();
same(0, $empty->getCount(), 'Removed parent relations no longer returned');
check(EmptyParentQuery::certified($empty, $query), 'Modern SMW certifies emptiness after updates');

$cached = new \SMW\Query\QueryResult($empty->getPrintRequests(), $empty->getQuery(), [], $store);
$cached->setFromCache(true);
check(!EmptyParentQuery::certified($cached, $query), 'Cached emptiness retains the fallback');
$truncated = new \SMW\Query\QueryResult($empty->getPrintRequests(), $empty->getQuery(), [], $store, true);
check(!EmptyParentQuery::certified($truncated, $query), 'Incomplete emptiness retains the fallback');
$invalid = $probe();
$invalid->addErrors(['Synthetic query error']);
check(!EmptyParentQuery::certified($invalid, $query), 'Query errors retain the fallback');
echo "OK: modern SMW parent union proof and safety guards\n";
