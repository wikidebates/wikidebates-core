<?php

namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\Title\Title;
use SMW\Services\ServicesFactory;

/** Source-independent reads of stored map properties. The singleton SMW query
 * and formatted values remain the reference until direct lookup is certified.
 */
final class ArgumentMapData {
	public static function read(Title $title, array $properties): array {
		if (!NativeSemanticApi::available()) {
			return [];
		}
		$printouts = [];
		foreach ($properties as $alias => $property) {
			if ($property !== '') {
				$printouts[] = '?' . $property . '=' . $alias;
			}
		}
		if ($printouts === []) {
			return [];
		}
		$result = NativeSemanticApi::querySubject(ServicesFactory::getInstance()->getStore(), $title, $printouts);
		return $result === null ? [] : (NativeSemanticApi::format($result)[0] ?? []);
	}

	public static function field(mixed $value): string {
		if (is_array($value)) {
			return implode(',', array_filter($value, static fn($item) => is_string($item) || is_int($item) || is_float($item)));
		}
		return is_string($value) ? $value : '';
	}
}
