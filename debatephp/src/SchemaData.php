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
		Table $maps, array $sides, string $pageUrl, string $lang, callable $urlForTitle
	): array {
		$parts = [];
		foreach ($sides as $side => $messageKey) {
			$items = $maps[$side]['items'] ?? null;
			if (!$items instanceof Table || count($items) === 0) {
				continue;
			}
			$elements = [];
			foreach ($items->sequence() as $item) {
				$title = trim((string)($item['page'] ?? ''));
				if ($title === '') {
					continue;
				}
				$url = self::pageUrl($urlForTitle($title));
				if ($url === '') {
					continue;
				}
				$label = NativeText::seoPlainText((string)($item['title'] ?? ''));
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
		Table $args, Table $maps, string $pageUrl, string $lang, callable $urlForTitle
	): array {
		$dedicated = trim((string)($args['dedicated-debate'] ?? ''));
		if ($dedicated !== '') {
			// The visible argument page links to the dedicated debate instead
			// of displaying the locally retained justifications/objections.
			$url = self::pageUrl($urlForTitle($dedicated));
			return $url === '' ? [] : [1 => ['@id' => $url . '#qa']];
		}
		return self::sections($maps, ['justif' => 'justifications', 'obj' => 'objections'],
			$pageUrl, $lang, $urlForTitle);
	}
}
