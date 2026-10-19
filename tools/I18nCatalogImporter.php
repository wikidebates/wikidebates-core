<?php

namespace MediaWiki\Extension\DebatePHP\Maintenance;

/** Explicit offline import of a legacy table into the canonical JSON sources. */
final class I18nCatalogImporter {
	public static function prepare(string $root, array $compiled): array {
		$read = static fn($path) => json_decode(file_get_contents($root . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
		$map = $read('resources/i18n-map.json');
		$names = $read('resources/wiki-names.json');
		$catalogs = [];
		foreach (['en', 'fr', 'qqq'] as $language) { $catalogs[$language] = $read('i18n/' . $language . '.json'); }
		$oldDocumentation = $catalogs['qqq'];
		foreach ($map['entries'] as $sections) {
			foreach ($sections as $entries) {
				foreach ($entries as $entry) {
					if (isset($entry['message'])) {
						foreach ($catalogs as &$catalog) { unset($catalog[$entry['message']]); }
						unset($catalog);
					}
				}
			}
		}
		$flat = [];
		foreach ($compiled['data'] as $component => $sections) {
			foreach ($sections as $section => $entries) {
				foreach ($entries as $key => $value) { $flat[$component . '.' . $section . '.' . $key] = $value; }
			}
		}
		$technical = static function(string $path): bool {
			[$component, $section, $key] = explode('.', $path);
			return in_array($section, ['props', 'params', 'forms', 'categories', 'urls', 'files', 'values'], true)
				|| preg_match('/_(category|color|icon|image)$/D', $key);
		};
		$resolve = static function(string $path, bool $stopAtTechnical = false) use ($flat, $technical): string {
			$seen = [];
			while (isset($flat[$path]) && is_string($flat[$path]) && str_starts_with($flat[$path], '@')) {
				$target = substr($flat[$path], 1);
				if (!isset($flat[$target]) || ($stopAtTechnical && $technical($target))) { break; }
				if (isset($seen[$target])) { throw new \RuntimeException('Circular translation alias: ' . $path); }
				$seen[$target] = true;
				$path = $target;
			}
			return $path;
		};
		$map['entries'] = [];
		$names['rendering'] = [];
		foreach ($flat as $path => $value) {
			[$component, $section, $key] = explode('.', $path);
			$entry = [];
			if ($technical($path)) {
				$entry['wiki'] = $path;
				$names['rendering'][$path] = $flat[$resolve($path)];
			} else {
				$target = $resolve($path, true);
				$message = 'wikidebates-' . str_replace(['.', '_'], '-', strtolower($target));
				$entry['message'] = $message;
				$text = $flat[$resolve($target)];
				foreach (['en', 'fr'] as $language) {
					$translation = is_array($text) ? ($text[$language] ?? $text['en'] ?? $text['fr']) : $text;
					$position = 0;
					$catalogs[$language][$message] = preg_replace_callback('/%s/', static function() use (&$position): string { return '$' . ++$position; }, $translation);
				}
				$catalogs['qqq'][$message] = $oldDocumentation[$message] ?? 'Rendering message for ' . $target . '. Preserve HTML/wikitext and positional parameters ($1, $2, etc.).';
			}
			if (is_string($value) && str_starts_with($value, '@')) { $entry['legacy_alias'] = $value; }
			$map['entries'][$component][$section][$key] = $entry;
		}
		$map['provenance'] = array_intersect_key($compiled, array_flip(['sha256', 'logic_sha256']));
		$files = ['resources/i18n-map.json' => $map, 'resources/wiki-names.json' => $names];
		foreach ($catalogs as $language => $catalog) { $files['i18n/' . $language . '.json'] = $catalog; }
		return $files;
	}

	public static function write(string $root, array $compiled): void {
		$files = self::prepare($root, $compiled);
		foreach ($files as $path => $data) {
			$text = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			$text = preg_replace_callback('/^ +/m', static fn($match) => str_repeat("\t", intdiv(strlen($match[0]), 4)), $text) . "\n";
			I18nCompiler::writeAtomically($root . '/' . $path, $text);
		}
	}
}
