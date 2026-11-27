<?php

/** Compare the runtime PHP adapter with the installed independent historical oracle. */
use MediaWiki\Extension\DebatePHP\NativeSemanticApi;

$raw = ['[[WD FS Text::updated]]', '?WD FS Text=text', '?WD FS Number=number',
	'?WD FS Page=target', 'mainlabel=page', 'link=none', 'limit=10'];
$factory = new \SMW\Scribunto\LibraryFactory($store);
$reference = $factory->newLuaAskResultProcessor($factory->newQueryResultFrom($raw))->getProcessedResult();
$direct = NativeSemanticApi::format(NativeSemanticApi::query($store, $raw));
same($reference, $direct, 'Direct SMW query and native formatter exactly reproduce the typed probe');
$report['direct_api_study'] = ['typed_read_probe_matches' => true, 'runtime_adapter_changed' => true,
	'formatter' => 'NativeSemanticApi; independent SemanticScribunto oracle retained only in tests'];
echo "Direct SMW PHP query and formatter: exact typed read probe matches\n";
