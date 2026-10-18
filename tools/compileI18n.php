<?php

// Offline compiler: parses literal data only, never executes Lua or edits wiki pages.
require_once __DIR__ . '/../debatephp/src/LiteralData.php';

use MediaWiki\Extension\DebatePHP\LiteralData;

if ($argc !== 3) {
	fwrite(STDERR, "Usage: php tools/compileI18n.php data.lua logic.lua > I18nData.php\n");
	exit(1);
}
$data = file_get_contents($argv[1]);
$logic = file_get_contents($argv[2]);
if ($data === false || $logic === false) { throw new RuntimeException('Cannot read translation sources'); }
$compiled = [
	'sha256' => hash('sha256', $data),
	'logic_sha256' => hash('sha256', rtrim($logic, "\r\n")),
	'data' => (new LiteralData)->read($data)
];
$export = '';
// Convert code indentation only. Multiline translation strings keep every byte.
foreach (token_get_all('<?php ' . var_export($compiled, true)) as $token) {
	if (is_array($token)) {
		if ($token[0] === T_OPEN_TAG) { continue; }
		$export .= $token[0] === T_WHITESPACE
			? preg_replace_callback('/^ +/m', static fn(array $m): string => str_repeat("\t", intdiv(strlen($m[0]), 2)), $token[1])
			: $token[1];
	} else { $export .= $token; }
}
echo "<?php\n// Native PHP translations, compiled offline; wiki SHA-256 guards synchronization.\nreturn $export;\n";
