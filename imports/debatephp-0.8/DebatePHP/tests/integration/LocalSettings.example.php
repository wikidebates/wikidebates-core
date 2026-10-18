<?php
// ONLY in an empty disposable wiki used with the supplied public snapshot.
// NEVER add the semantic double or capture hook to a real SMW installation.
$debatePhpDirectory = __DIR__ . '/../..';
$wgAutoloadClasses['MediaWiki\\Extension\\DebatePHP\\Tests\\CaptureLibrary'] = $debatePhpDirectory . '/tests/integration/CaptureLibrary.php';
require_once $debatePhpDirectory . '/tests/integration/SemanticDouble.php';
$wgHooks['ScribuntoExternalLibraries'][] = static function($engine, &$libraries) {
	if ($engine === 'lua') {
		$libraries['mw.debateTest'] = ['class' => \MediaWiki\Extension\DebatePHP\Tests\CaptureLibrary::class, 'deferLoad' => false];
	}
};
