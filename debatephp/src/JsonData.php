<?php
namespace MediaWiki\Extension\DebatePHP;

/** Internal one-based tree conversion; preserve the generic value() contract. */
final class JsonData {
	public static function value($value, ?SeoTextContext $context = null) {
		return self::convert($value, $context, false);
	}

	/** Only native SEO graphs omit empty optional metadata. */
	public static function schemaValue($value, ?SeoTextContext $context = null) {
		return self::convert($value, $context, true);
	}

	private static function convert($value, ?SeoTextContext $context, bool $schema) {
		if ($value instanceof Table) { $value = $value->values; }
		if (!is_array($value)) {
			return is_string($value) && $context !== null ? $context->resolveSeoMarkers($value) : $value;
		}
		$out = []; $sequence = true; $zeroKeys = true; $next = 1;
		foreach ($value as $key => $item) {
			$out[$key] = self::convert($item, $context, $schema);
			$sequence = $sequence && $key === $next++;
			$zeroKeys = $zeroKeys && is_string($key) && (float)$key === 0.0;
		}
		if ($sequence) { return $schema ? self::schemaList($out) : array_values($out); }
		if ($zeroKeys) { return $schema ? self::schemaObject($out) : $out; }
		ksort($out, SORT_NUMERIC); $sequence = true; $next = 1;
		foreach ($out as $key => $_) {
			if ($sequence) { $sequence = (is_int($key) || (is_string($key) && ctype_digit($key))) && $next++ === (int)$key; }
		}
		return $sequence ? ($schema ? self::schemaList($out) : array_values($out))
			: ($schema ? self::schemaObject($out) : $out);
	}

	private static function schemaList(array $items): ?array {
		// Reindex after dropping empty secondary entities, never drop "0".
		$items = array_values(array_filter($items, static fn($item) => $item !== null));
		return $items !== [] ? $items : null;
	}

	private static function schemaObject(array $node): ?array {
		$type = $node['@type'] ?? null;
		foreach ($node as $key => $value) {
			if ($key === '@id' || $key === '@type' || $key === '@graph') {
				continue;
			}
			if ($value === null || (is_string($value) &&
				in_array($key, ['name', 'description', 'alternateName', 'abstract',
					'value', 'volumeNumber', 'issueNumber'], true) &&
				preg_match('/^\s*$/uD', $value))) {
				unset($node[$key]);
			}
		}
		// Explicit identities and any remaining substantive information survive.
		if (isset($node['@id'])) {
			return $node;
		}
		if ($type === 'PropertyValue' && !isset($node['value'])) {
			return null;
		}
		if (count($node) === 1 && in_array($type, ['Person', 'Organization', 'Thing',
			'Periodical', 'WebSite', 'CreativeWork', 'PublicationIssue', 'PublicationVolume'], true)) {
			return null;
		}
		return $node;
	}
}
