<?php
namespace MediaWiki\Extension\DebatePHP\Maintenance;

require_once __DIR__ . '/../src/LiteralData.php';

/** Offline compilation and same-directory atomic replacement, shared by both commands. */
final class I18nCompiler {
	public static function prepare(string $source, string $logic): array {
		$data = (new \MediaWiki\Extension\DebatePHP\LiteralData)->read($source);
		$result = ['sha256' => hash('sha256', $source),
			'logic_sha256' => hash('sha256', rtrim($logic, "\r\n")), 'data' => $data];
		$export = '';
		foreach (token_get_all('<?php ' . var_export($result, true)) as $token) {
			if (is_array($token)) {
				if ($token[0] === T_OPEN_TAG) { continue; }
				$export .= $token[0] === T_WHITESPACE
					? preg_replace_callback('/(?<=\n) +/', static fn(array $match): string => str_repeat("\t", intdiv(strlen($match[0]), 2)), $token[1])
					: $token[1];
			} else { $export .= $token; }
		}
		return ['data' => $data, 'compiled' => $result, 'php' => "<?php\n// Native PHP translations, compiled offline; source SHA-256 values are offline provenance.\nreturn " . $export . ";\n"];
	}
	public static function writeAtomically(string $path, string $text): void {
		$directory = dirname($path);
		if (!is_dir($directory) || !is_writable($directory) || (file_exists($path) && !is_writable($path))) {
			throw new \RuntimeException('Output is not writable: ' . $path);
		}
		$tmp = tempnam($directory, '.debate-i18n-');
		if ($tmp === false) { throw new \RuntimeException('Cannot create staging file'); }
		try {
			$original = file_exists($path) ? stat($path) : false;
			$temporary = stat($tmp);
			if ($original !== false &&
				(($original['uid'] !== $temporary['uid'] && !chown($tmp, $original['uid']))
				|| ($original['gid'] !== $temporary['gid'] && !chgrp($tmp, $original['gid'])))) {
				throw new \RuntimeException('Cannot preserve output ownership: run as its owner or deployment administrator.');
			}
			if (file_put_contents($tmp, $text) !== strlen($text)
				|| !chmod($tmp, $original !== false ? ($original['mode'] & 0777) : 0644)
				|| !rename($tmp, $path)) { throw new \RuntimeException('Cannot replace ' . $path); }
		} finally { if (file_exists($tmp)) { unlink($tmp); } }
	}
}
