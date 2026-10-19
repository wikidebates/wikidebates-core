<?php

/** Rebuild compatibility messages and ResourceLoader declarations from the canonical sources. */
$root = dirname(__DIR__);
$map = json_decode(file_get_contents($root . '/resources/i18n-map.json'), true, 512, JSON_THROW_ON_ERROR);
$names = json_decode(file_get_contents($root . '/resources/wiki-names.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents($root . '/extension.json'), true, 512, JSON_THROW_ON_ERROR);
$check = in_array('--check', $argv, true);
$write = static function(string $file, array $data) use ($check): void {
	$text = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	$text = preg_replace_callback('/^ +/m', static fn($match) => str_repeat("\t", intdiv(strlen($match[0]), 4)), $text) . "\n";
	if ($check) {
		if (!is_file($file) || file_get_contents($file) !== $text) { throw new RuntimeException('Stale generated file: ' . $file); }
	} else {
		if (!is_dir(dirname($file))) { mkdir(dirname($file), 0755, true); }
		file_put_contents($file, $text);
	}
};
foreach (['en', 'fr'] as $language) {
	$messages = json_decode(file_get_contents($root . '/i18n/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
	$compat = ['@metadata' => ['authors' => ['Wikidébats']]] + $names['site'][$language];
	foreach ($map['browser_aliases'] as $alias => $canonical) { $compat[$alias] = $messages[$canonical]; }
	$write($root . '/i18n/compat/' . $language . '.json', $compat);
}
$documentation = ['@metadata' => ['authors' => ['Wikidébats']]];
foreach ($names['site']['en'] as $key => $_) {
	$documentation[$key] = 'Generated compatibility default from resources/wiki-names.json. Technical wiki identifier; always selected in the content language, independently of the user interface language. Existing MediaWiki namespace overrides remain supported.';
}
foreach ($map['browser_aliases'] as $alias => $canonical) { $documentation[$alias] = 'Generated compatibility alias of ' . $canonical . '. Translate that canonical message instead.'; }
$write($root . '/i18n/compat/qqq.json', $documentation);
$browser = array_keys(array_filter($messages, static fn($key) => str_starts_with($key, 'wk-'), ARRAY_FILTER_USE_KEY));
$browser = array_merge($browser, array_keys($map['browser_aliases']));
sort($browser);
$manifest['ResourceModules']['ext.wikidebates.common.i18n']['messages'] = $browser;
$manifest['ResourceModules']['ext.wikidebates.common.i18n']['class'] = 'MediaWiki\\Extension\\Wikidebates\\I18nModule';
foreach (['en', 'fr'] as $language) {
	// Keep the old module IDs as message-only compatibility modules.
	$manifest['ResourceModules']['ext.wikidebates.i18n.' . $language] = [
		'class' => 'MediaWiki\\Extension\\Wikidebates\\I18nModule', 'messages' => $browser
	];
}
$manifest['MessagesDirs'] = ['Wikidebates' => ['i18n', 'i18n/compat']];
$write($root . '/extension.json', $manifest);
echo $check ? "Generated i18n files are current\n" : "Generated compatibility messages and ResourceLoader declarations\n";
