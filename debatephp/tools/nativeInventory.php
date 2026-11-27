<?php
/**
 * Read-only lexical inventory of the production DebatePHP source.
 * Run: php debatephp/tools/nativeInventory.php
 * Counts Lua emulation, Table identifiers and direct Lua-extension references.
 * These are static counts, not runtime allocation or call measurements.
 */
$root = dirname(__DIR__) . '/src';
$files = glob($root . '/*.php');
sort($files, SORT_STRING);
$rows = [];
$total = ['lua_calls' => 0, 'table_constructions' => 0, 'table_identifiers' => 0, 'lua_extension_refs' => 0];
foreach ($files as $file) {
	$tokens = array_values(array_filter(token_get_all(file_get_contents($file)), static function($token) {
		return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
	}));
	$row = ['lua_calls' => 0, 'table_constructions' => 0, 'table_identifiers' => 0, 'lua_extension_refs' => 0, 'methods' => []];
	$identifier = static function($token, string $name): bool {
		return is_array($token) && $token[0] === T_STRING && strcasecmp($token[1], $name) === 0;
	};
	foreach ($tokens as $i => $token) {
		if (is_array($token) && (
			stripos($token[1], 'SMW\\Scribunto\\') !== false ||
			stripos($token[1], 'MediaWiki\\Extension\\Scribunto\\') !== false ||
			($token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($token[1], "'\""), ['SemanticScribunto', 'VariablesLua', 'Scribunto'], true))
		)) {
			$row['lua_extension_refs']++;
		}
		if ($identifier($token, 'Table')) {
			$row['table_identifiers']++;
			if (is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_NEW) {
				$row['table_constructions']++;
			}
		}
		if ($identifier($token, 'Lua') && is_array($tokens[$i + 1] ?? null)
			&& $tokens[$i + 1][0] === T_DOUBLE_COLON && is_array($tokens[$i + 2] ?? null)
			&& $tokens[$i + 2][0] === T_STRING && ($tokens[$i + 3] ?? null) === '(') {
			$method = $tokens[$i + 2][1];
			$row['lua_calls']++;
			$row['methods'][$method] = ($row['methods'][$method] ?? 0) + 1;
		}
	}
	ksort($row['methods']);
	foreach ($total as $key => $_) {
		$total[$key] += $row[$key];
	}
	$rows[basename($file)] = $row;
}
echo json_encode(['files' => $rows, 'total' => $total], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
if (in_array('--assert-native', $argv, true)) {
	foreach (['ArgumentReferenceRenderer.php', 'ReferenceRenderer.php', 'Renderer.php'] as $legacy) {
		if (is_file($root . '/' . $legacy)) {
			fwrite(STDERR, "Obsolete renderer in runtime source: $legacy\n");
			exit(1);
		}
	}
	if (array_sum($total) !== 0 || is_file($root . '/Lua.php') || is_file($root . '/Table.php') || is_file($root . '/LiteralData.php')) {
		fwrite(STDERR, "Legacy emulation or Lua-extension reference in runtime source\n");
		exit(1);
	}
}
