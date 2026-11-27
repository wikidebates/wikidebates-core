<?php

namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\Linker\Linker;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SMW\DataValueFactory;
use SMW\DataItems\Property;
use SMW\DataItems\WikiPage;
use SMW\Services\ServicesFactory;

/** Source-independent map reads with a conservative, guarded direct path. */
final class ArgumentMapData {
	public static function read(Title $title, array $properties): array {
		if (MediaWikiServices::getInstance()->getMainConfig()->get('WikidebatesArgumentMapDirectRead')) {
			$direct = self::readDirect($title, $properties);
			if ($direct !== null) {
				return $direct;
			}
		}
		return self::readQuery($title, $properties);
	}

	/** Restricted fast path. Null means the original singleton query is required.
	 * Only plain main-namespace subjects and exact, single Text properties are
	 * certified. Keep the query path for redirects, ambiguous syntax, unusual
	 * types/cardinality and other SMW versions. Never build SQL from input.
	 */
	public static function readDirect(Title $title, array $properties, ?string &$ineligibleReason = null): ?array {
		$ineligibleReason = null;
		if (!defined('SMW_VERSION') || !in_array(SMW_VERSION, ['7.3.1', '7.4.1'], true) ||
			!NativeSemanticApi::available() || !class_exists(Property::class) || !class_exists(WikiPage::class)) {
			$ineligibleReason = 'unsupported-smw';
			return null;
		}
		if (empty($GLOBALS['smwgQEnabled'])) {
			$ineligibleReason = 'queries-disabled';
			return null;
		}
		if ($title->isExternal() || $title->getNamespace() !== NS_MAIN ||
			!preg_match('/^[\\p{L}\\p{N}][^:\\[\\]{}|<>#]*$/uD', $title->getDBkey())) {
			$ineligibleReason = 'unsupported-title';
			return null;
		}
		if (!$title->exists()) {
			$ineligibleReason = 'missing-page';
			return null;
		}
		if ($title->isRedirect()) {
			$ineligibleReason = 'redirect';
			return null;
		}
		if ($properties === []) {
			$ineligibleReason = 'empty-properties';
			return null;
		}
		// Only the five literal map fields, in the two historical vocabularies.
		$names = MessageCatalog::json('resources/wiki-names.json')['rendering'];
		$allowed = [];
		foreach (['arg_map', 'pro_list', 'con_list', 'justifs_list', 'objs_list'] as $key) {
			foreach ($names['ArgumentMap.props.' . $key] as $name) {
				$allowed[$name] = true;
			}
		}
		$store = ServicesFactory::getInstance()->getStore();
		$subject = WikiPage::newFromTitle($title);
		$factory = DataValueFactory::getInstance();
		$linker = null;
		$row = [];
		foreach ($properties as $alias => $name) {
			if (!isset($allowed[$name]) || !in_array($alias, ['map', 'left', 'right'], true)) {
				$ineligibleReason = 'unsupported-property';
				return null;
			}
			$property = Property::newFromUserLabel($name);
			if (!$property->isUserDefined() || $property->isInverse() ||
				$property->getLabel() !== $name || $property->findPropertyValueType() !== '_txt') {
				$ineligibleReason = 'noncanonical-text-property';
				return null;
			}
			$items = $store->getPropertyValues($subject, $property);
			if (count($items) > 1) {
				// Query printouts may apply different ordering or limits.
				$ineligibleReason = 'multiple-values';
				return null;
			}
			$row[$alias] = null;
			if ($items !== []) {
				$value = $factory->newDataValueByItem(reset($items), $property);
				if (!$value->isValid() || $value->getTypeID() !== '_txt') {
					$ineligibleReason = 'invalid-text-value';
					return null;
				}
				$row[$alias] = $value->getShortText(SMW_OUTPUT_WIKI, $linker ??= new Linker());
			}
		}
		return $row;
	}

	public static function readQuery(Title $title, array $properties): array {
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
