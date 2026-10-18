<?php
require __DIR__ . '/bootstrap.php';

use MediaWiki\Extension\DebatePHP\I18n;
use MediaWiki\Extension\DebatePHP\LiteralData;

foreach ([false, true] as $memoize) {
 $i18n = I18n::bundled($memoize);
 if ($i18n->message('Common.labels', 'fr', 'edit_label') !== 'Modifier'
  || $i18n->message('Common.labels', 'en', 'edit_label') !== 'Edit') {
  throw new RuntimeException('Bundled translations differ');
 }
}
if (class_exists(LiteralData::class, false)) {
	throw new RuntimeException('Runtime loaded the offline Lua literal reader');
}
echo "Native PHP table, FR/EN, no wiki-source requirement and absence of literal reader: passed\n";
