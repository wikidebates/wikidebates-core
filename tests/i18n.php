<?php

require_once __DIR__ . '/bootstrap.php';

use MediaWiki\Extension\DebatePHP\I18n;
use MediaWiki\Extension\DebatePHP\MessageCatalog;

foreach ([false, true] as $memoize) {
	$messages = I18n::bundled($memoize);
	foreach (['Debate', 'Argument'] as $kind) {
		same('https://en.wikidebates.org/w/resources/assets/logo-wikidebates-twitter.png',
			$messages->message($kind . '.seo', 'en', 'image'), 'Exact accessible EN social image');
	}
	same('Modifier', $messages->message('Common.labels', 'fr-ca', 'edit_label'), 'French regional rendering language');
	same('Pro argument', $messages->message('Argument.props', 'en', 'arg_pro'), 'Stable technical property name');
	same('Modifier', $messages->message('Argument.text', 'fr', 'edit_label'), 'Common message alias');
	same('missing', $messages->message('Missing', 'en', 'missing'), 'Unknown key fallback');
}
same('Title then $2', MessageCatalog::substitute('$2 then $1', ['$2', 'Title']), 'Substitution does not reinterpret parameter content');
same('100%: A', MessageCatalog::substitute('100%: $1', ['A']), 'Literal percent is preserved');
same('No parameters', MessageCatalog::substitute('No parameters', [new stdClass]), 'Unused arguments are not converted');
same('TEN', MessageCatalog::substitute('$10', [...array_fill(0, 9, new stdClass), 'TEN']), 'Multi-digit parameters do not consume unused arguments');
same('B / A', sprintf(MessageCatalog::legacyTemplate('$2 / $1'), 'A', 'B'), 'Deferred legacy parameter order');

echo "OK: canonical catalogue, regional rendering, stable names and parameters\n";
