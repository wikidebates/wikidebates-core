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

	/** Only the existing context boundary still consumes one-based Table values. */
	public static function toLegacyTable(array $properties): Table {
		foreach ($properties as $key => $value) {
			if ($value === null) {
				unset($properties[$key]);
			} elseif (is_array($value)) {
				$properties[$key] = new Table($value === [] ? [] : array_combine(range(1, count($value)), $value));
			}
		}
		return new Table($properties);
	}
}
