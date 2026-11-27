<?php
namespace MediaWiki\Extension\DebatePHP;

/** Ordered semantic properties: a scalar or a zero-based list of values per property. */
final class SemanticProperties {
	public static function add(array &$properties, mixed $key, mixed $value, bool $multiple): void {
		if ($key === null || $key === false || $key === '' || $value === null) {
			return;
		}
		if (is_string($value)) {
			$value = trim($value, NativeText::ASCII_WHITESPACE);
			if ($value === '') {
				return;
			}
		}
		if (!$multiple) {
			$properties[$key] = $value;
			return;
		}
		if (!isset($properties[$key])) {
			$properties[$key] = is_array($value) ? $value : [$value];
			return;
		}
		if (!is_array($properties[$key])) {
			$properties[$key] = [$properties[$key]];
		}
		if (is_array($value)) {
			foreach ($value as $item) {
				$properties[$key][] = $item;
			}
		} else {
			$properties[$key][] = $value;
		}
	}

	public static function merge(array &$destination, array $source): void {
		foreach ($source as $key => $value) {
			self::add($destination, $key, $value, is_array($value));
		}
	}

	/** Omit missing properties without touching ordered lists or explicit zero. */
	public static function present(array $properties): array {
		foreach ($properties as $key => $value) {
			if ($value === null) {
				unset($properties[$key]);
			}
		}
		return $properties;
	}
}
