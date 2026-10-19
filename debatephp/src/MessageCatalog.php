<?php

namespace MediaWiki\Extension\DebatePHP;

/** Shared JSON sources; technical wiki names never follow the visitor's language. */
final class MessageCatalog {
	private static array $files = [];
	private static ?array $legacy = null;
	private static ?string $root = null;

	public static function json(string $path): array {
		// Follow the resource location when historical comparison tools namespace copies of src/.
		$root = self::$root ??= dirname(realpath(__DIR__ . '/../resources/I18nData.php'), 3);
		return self::$files[$path] ??= json_decode(file_get_contents($root . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
	}

	public static function language(string $language): string {
		return str_starts_with(strtolower(trim($language)), 'fr') ? 'fr' : 'en';
	}

	private static function entry(string $domain, string $key): ?array {
		[$component, $section] = array_pad(explode('.', $domain, 2), 2, '');
		return self::json('resources/i18n-map.json')['entries'][$component][$section][$key] ?? null;
	}

	private static function localized($value, string $language): string {
		return is_array($value) ? ($value[$language] ?? $value['en'] ?? $value['fr']) : $value;
	}

	public static function legacyTemplate(string $text): string {
		preg_match_all('/\$([1-9][0-9]*)/', $text, $matches);
		$positions = array_map('intval', $matches[1]);
		$sequential = $positions && $positions === range(1, count($positions));
		$replacements = [];
		foreach ($positions as $position) {
			$replacements['$' . $position] = $sequential ? '%s' : '%' . $position . '$s';
		}
		return strtr($positions ? str_replace('%', '%%', $text) : $text, $replacements);
	}

	public static function substitute(string $text, array $parameters): string {
		$replacements = [];
		$parameters = array_values($parameters);
		preg_match_all('/\$([1-9][0-9]*)/', $text, $matches);
		foreach (array_unique($matches[1]) as $position) {
			if (array_key_exists((int)$position - 1, $parameters)) { $replacements['$' . $position] = (string)$parameters[(int)$position - 1]; }
		}
		return strtr($text, $replacements);
	}

	public static function template(string $domain, string $language, string $key, string $fallback): string {
		$entry = self::entry($domain, $key);
		if ($entry === null) { return $fallback; }
		$language = self::language($language);
		if (isset($entry['wiki'])) {
			return self::localized(self::json('resources/wiki-names.json')['rendering'][$entry['wiki']], $language);
		}
		$messageKey = $entry['message'];
		if (defined('MEDIAWIKI') && function_exists('wfMessage')) {
			$message = wfMessage($messageKey)->inLanguage($language);
			if ($message->exists()) { return $message->plain(); }
		}
		return self::json('i18n/' . $language . '.json')[$messageKey]
			?? self::json('i18n/en.json')[$messageKey] ?? $fallback;
	}

	/** Historical table shape, for offline consumers and the independent Lua comparisons. */
	public static function legacyData(): array {
		if (self::$legacy !== null) { return self::$legacy; }
		$map = self::json('resources/i18n-map.json');
		$data = [];
		foreach ($map['entries'] as $component => $sections) {
			foreach ($sections as $section => $entries) {
				foreach ($entries as $key => $entry) {
					if (isset($entry['legacy_alias'])) { $value = $entry['legacy_alias']; }
					elseif (isset($entry['wiki'])) { $value = self::json('resources/wiki-names.json')['rendering'][$entry['wiki']]; }
					else {
						$value = [];
						foreach (['fr', 'en'] as $language) { $value[$language] = self::legacyTemplate(self::json('i18n/' . $language . '.json')[$entry['message']]); }
					}
					$data[$component][$section][$key] = $value;
				}
			}
		}
		return self::$legacy = $map['provenance'] + ['data' => $data];
	}

	/** Content-language names, including the historical configurable semantic-property keys. */
	public static function siteNames(string $language): array {
		$language = self::language($language);
		$names = self::json('resources/wiki-names.json')['site'][$language];
		if (defined('MEDIAWIKI') && function_exists('wfMessage')) {
			foreach ($names as $key => &$value) {
				$message = wfMessage($key)->inLanguage($language);
				if ($message->exists()) { $value = $message->plain(); }
			}
			unset($value);
		}
		return $names;
	}
}
