<?php

// Compatibility entry point. Edit i18n/*.json and resources/wiki-names.json.
require_once __DIR__ . '/../src/MessageCatalog.php';
return \MediaWiki\Extension\DebatePHP\MessageCatalog::legacyData();
