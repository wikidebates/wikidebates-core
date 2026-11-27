<?php

namespace MediaWiki\Extension\DebatePHP;

/**
 * Shared Schema.org metadata for the two native renderers.
 * Use the already decoded direct argument lists; never fetch linked content.
 * Lists retain the internal one-based representation until JSON encoding.
 */
final class SchemaData {
	private static function message(string $key, string $lang): string {
		return MessageCatalog::plainMessage('wikidebates-seo-' . $key, $lang);
	}

	public static function education(string $lang, string $kind): array {
		$msg = static fn(string $key): string => self::message($key, $lang);
		$uses = $kind === 'debate'
			? [1 => $msg('educational-use-debate'), 2 => $msg('educational-use-critical-thinking')]
			: [1 => $msg('educational-use-analysis'), 2 => $msg('educational-use-argumentation'),
				3 => $msg('educational-use-critical-thinking')];
		return [
			'learningResourceType' => $msg('learning-type-' . $kind),
			'educationalUse' => $uses,
			'educationalLevel' => [
				1 => $msg('educational-level-secondary'),
				2 => $msg('educational-level-higher')
			],
			'audience' => [
				1 => ['@type' => 'Audience', 'audienceType' => $msg('audience-general')],
				2 => ['@type' => 'EducationalAudience', 'educationalRole' => [
					1 => $msg('educational-role-pupil'),
					2 => $msg('educational-role-student'),
					3 => $msg('educational-role-teacher')
				]]
			]
		];
	}


	/**
	 * Normalize one documentary reference, not the enclosing page.
	 * Preserve the reference's visible data; never fetch a remote URL.
	 */
	public static function reference(array $node, ?Context $context = null): array {
		if ($context instanceof SeoTextContext) {
			$node = self::resolveMarkerTree($node, $context);
		}
		$type = $node['@type'];
		$volume = $node['volumeNumber'] ?? '';
		$number = $node['issueNumber'] ?? '';
		unset($node['volumeNumber'], $node['issueNumber']);
		if ($type === 'Article' && isset($node['isPartOf'])) {
			$container = $node['isPartOf'];
			if ($volume !== '') {
				$container = ['@type' => 'PublicationVolume', 'volumeNumber' => $volume, 'isPartOf' => $container];
			}
			if ($number !== '') {
				$container = ['@type' => 'PublicationIssue', 'issueNumber' => $number, 'isPartOf' => $container];
			}
			$node['isPartOf'] = $container;
		} elseif ($type === 'Book') {
			if ($volume !== '') {
				$node['@type'] = [1 => 'Book', 2 => 'PublicationVolume'];
				$node['volumeNumber'] = $volume;
			}
			if ($number !== '') {
				// A book's number may identify its series or collection; do not
				// assert that the book is an issue of a periodical.
				$node['identifier'] = ['@type' => 'PropertyValue', 'propertyID' => 'number', 'value' => $number];
			}
		}
		if (isset($node['datePublished'])) {
			$date = self::referenceDate((string)$node['datePublished']);
			if ($date === null) { unset($node['datePublished']); }
			else { $node['datePublished'] = $date; }
		}
		if (isset($node['url'])) {
			$url = trim((string)$node['url']);
			if (!self::referenceUrl($url)) { unset($node['url']); }
			else { $node['url'] = $url; }
		}
		return $node;
	}

	/** No guessed day/month, no date for an approximate or invalid value. */
	public static function referenceDate(string $value): ?string {
		$value = trim($value);
		$year = $month = $day = null;
		if (preg_match('/^([0-9]{4})(?:[-\/]([0-9]{1,2})(?:[-\/]([0-9]{1,2}))?)?$/D', $value, $m)) {
			$year = (int)$m[1];
			$month = isset($m[2]) ? (int)$m[2] : null;
			$day = isset($m[3]) ? (int)$m[3] : null;
		} elseif (preg_match('/^([0-9]{1,2})[\/.-]([0-9]{1,2})[\/.-]([0-9]{4})$/D', $value, $m)) {
			$a = (int)$m[1]; $b = (int)$m[2];
			$year = (int)$m[3];
			if ($a > 12 && $b <= 12) { $day = $a; $month = $b; }
			elseif ($b > 12 && $a <= 12) { $day = $b; $month = $a; }
			elseif ($a === $b) { $day = $a; $month = $b; }
			else { return null; }
		} else {
			$months = [
				'janvier' => 1, 'january' => 1, 'février' => 2, 'fevrier' => 2, 'february' => 2,
				'mars' => 3, 'march' => 3, 'avril' => 4, 'april' => 4, 'mai' => 5, 'may' => 5,
				'juin' => 6, 'june' => 6, 'juillet' => 7, 'july' => 7, 'août' => 8, 'aout' => 8,
				'august' => 8, 'septembre' => 9, 'september' => 9, 'octobre' => 10, 'october' => 10,
				'novembre' => 11, 'november' => 11, 'décembre' => 12, 'decembre' => 12, 'december' => 12
			];
			// Only these two accented capitals occur in the supported month names.
			// Keep the pure renderer test executable under php -n as well.
			$value = strtr(strtolower($value), ['É' => 'é', 'Û' => 'û']);
			if (preg_match('/^(?:(\d{1,2})(?:er)?\s+)?(\p{L}+)\s+(\d{4})$/uD', $value, $m)) {
				$day = $m[1] !== '' ? (int)$m[1] : null;
				$month = $months[$m[2]] ?? null;
				$year = (int)$m[3];
			} elseif (preg_match('/^(\p{L}+)\s+(\d{1,2}),?\s+(\d{4})$/uD', $value, $m)) {
				$day = (int)$m[2];
				$month = $months[$m[1]] ?? null;
				$year = (int)$m[3];
			}
			if ($month === null) { return null; }
		}
		if (!$year || ($month !== null && ($month < 1 || $month > 12)) ||
			($day !== null && !checkdate($month ?? 0, $day, $year))) {
			return null;
		}
		return sprintf('%04d', $year) . ($month === null ? '' : sprintf('-%02d', $month)) .
			($day === null ? '' : sprintf('-%02d', $day));
	}

	/** Absolute HTTP(S) URL, not a wiki link, strip marker or executable scheme. */
	public static function referenceUrl(string $url): bool {
		if (!preg_match('~^https?://~i', $url) || preg_match('~[\x00-\x20\x7f<>"\\\\]~', $url) ||
			preg_match('/%(?![0-9a-f]{2})/i', $url)) {
			return false;
		}
		$parts = parse_url($url);
		return is_array($parts) && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) &&
			filter_var($url, FILTER_VALIDATE_URL) !== false;
	}

	/** Copy only the selected reference; leave original renderer data intact. */
	private static function resolveMarkerTree(mixed $value, SeoTextContext $context): mixed {
		if (is_string($value)) {
			return $context->resolveSeoMarkers($value);
		}
		if (is_array($value)) {
			return array_map(static fn($item) => self::resolveMarkerTree($item, $context), $value);
		}
		return $value;
	}

	private static function pageUrl(string $url): string {
		$url = explode('#', $url, 2)[0];
		return preg_match('~^https?://[^/\s]+(?:/|$)~i', $url) ? $url : '';
	}

	/**
	 * Each nonempty visible section is a WebPageElement whose main entity
	 * is an ItemList. The displayed label belongs to the ListItem, not to
	 * the referenced Claim, which has its own canonical name.
	 */
	public static function sections(
		array $maps, array $sides, string $pageUrl, string $lang, callable $urlForTitle, ?Context $context = null
	): array {
		$parts = [];
		foreach ($sides as $side => $messageKey) {
			$items = $maps[$side]['items'] ?? [];
			if ($items === []) {
				continue;
			}
			$elements = [];
			foreach ($items as $item) {
				$title = trim((string)($item['page'] ?? ''));
				if ($title === '') {
					continue;
				}
				$url = self::pageUrl($urlForTitle($title));
				if ($url === '') {
					continue;
				}
				$label = NativeText::seoPlainText((string)($item['title'] ?? ''), $context);
				$position = count($elements) + 1;
				$elements[$position] = [
					'@type' => 'ListItem',
					'position' => $position,
					'name' => $label !== '' ? $label : $title,
					'item' => ['@type' => 'Claim', '@id' => $url . '#claim']
				];
			}
			if ($elements === []) {
				continue;
			}
			$name = self::message('list-' . $messageKey, $lang);
			$parts[count($parts) + 1] = [
				'@type' => 'WebPageElement',
				'@id' => $pageUrl . '#schema-' . $messageKey . '-section',
				'name' => $name,
				'mainEntity' => [
					'@type' => 'ItemList',
					'@id' => $pageUrl . '#schema-' . $messageKey . '-list',
					'name' => $name,
					'numberOfItems' => count($elements),
					'itemListElement' => $elements
				]
			];
		}
		return $parts;
	}

	public static function argumentParts(
		array $args, array $maps, string $pageUrl, string $lang, callable $urlForTitle, ?Context $context = null
	): array {
		$dedicated = trim((string)($args['dedicated-debate'] ?? ''));
		if ($dedicated !== '') {
			// The visible argument page links to the dedicated debate instead
			// of displaying the locally retained justifications/objections.
			$url = self::pageUrl($urlForTitle($dedicated));
			return $url === '' ? [] : [1 => ['@id' => $url . '#qa']];
		}
		return self::sections($maps, ['justif' => 'justifications', 'obj' => 'objections'],
			$pageUrl, $lang, $urlForTitle, $context);
	}
}
