<?php

namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Title\Title;
use UtfNormal\Validator;

/** Read-only argument map for the on-demand hover parser requests. */
final class ArgumentMap {
	public static function render(Parser $parser, PPFrame $frame, array $nodes): array {
		$entry = trim(isset($nodes[0]) ? $frame->expand($nodes[0]) : '');
		if ($entry !== 'render') {
			return self::output('');
		}
		unset($nodes[0]);
		$args = $frame->newChild($nodes, $frame->getTitle())->getArguments();
		$encoded = ($args['encoding'] ?? '') === 'uri';
		$page = self::input($args[1] ?? '', $encoded);
		$source = self::input($args[2] ?? '', $encoded);
		$target = self::localTitle($page);
		if (!$target || !$parser->incrementExpensiveFunctionCount()) {
			return self::output('');
		}
		// Each map message is read at most once; no per-render message memo is needed.
		$i18n = I18n::bundled();
		$lang = $i18n->language($args, MediaWikiServices::getInstance()->getContentLanguage()->getCode());
		$property = static fn(string $key): string => $i18n->message('ArgumentMap.props', $lang, $key);
		$text = static fn(string $key): string => $i18n->message('ArgumentMap.text', $lang, $key);

		// A semantic subject is a page identity, not a fragment or a query.
		$target = Title::makeTitle($target->getNamespace(), $target->getDBkey());
		if ($source === '') {
			$row = ArgumentMapData::read($target, ['map' => $property('arg_map')]);
			return self::output(ArgumentMapData::field($row['map'] ?? null));
		}

		$sourceTitle = self::localTitle($source);
		// Resolve source existence only if a card actually matches its identity.
		// Share the answer across both columns, including a negative lookup.
		$sourceExists = null;
		$debate = trim($args[3] ?? '', NativeText::ASCII_WHITESPACE) !== '';
		if ($debate) {
			$row = ArgumentMapData::read($target, ['left' => $property('pro_list'), 'right' => $property('con_list')]);
			$separator = '-;-';
			$heads = ['arg_map_pro_label', 'arg_map_con_label'];
			$empty = ['none_pro_args_msg_short', 'none_con_args_msg_short'];
		} else {
			$row = ArgumentMapData::read($target, ['left' => $property('justifs_list'), 'right' => $property('objs_list')]);
			$separator = '⟭';
			$heads = ['justifs_label', 'objs_label'];
			$empty = ['none_justifications_msg', 'none_objections_msg'];
		}
		$columns = [];
		foreach (['left', 'right'] as $i => $key) {
			$cards = self::cards(ArgumentMapData::field($row[$key] ?? null), $separator, $sourceTitle, $sourceExists);
			$columns[] = '<div class="wk-arg-map__col ' . ($i === 0 ? 'is-pro' : 'is-con') . '">'
				. '<div class="wk-arg-map__th">' . $text($heads[$i]) . '</div>'
				. '<div class="wk-arg-map__td">' . ($cards !== '' ? $cards
					: '<div class="carte-vide">' . $text($empty[$i]) . '</div>') . '</div></div>';
		}
		return self::output('<div class="wk-arg-map navigation-not-searchable">' . implode('', $columns) . '</div>');
	}

	private static function input(string $value, bool $encoded): string {
		return trim($encoded ? rawurldecode($value) : $value, NativeText::ASCII_WHITESPACE);
	}

	private static function localTitle(string $value): ?Title {
		$title = $value !== '' && preg_match('//u', $value) && !str_contains($value, "\0")
			? Title::newFromText($value) : null;
		return $title && !$title->isExternal() && $title->getNamespace() >= 0 ? $title : null;
	}

	private static function cards(string $list, string $separator, ?Title $source, ?bool &$sourceExists): string {
		$cards = [];
		foreach (SerializedData::mapItems($list, $separator) as $item) {
			$title = self::localTitle($item['page']);
			if (!$title) {
				continue;
			}
			$display = $item['title'];
			// Distinct live titles have distinct IDs. Do not follow redirects.
			if ($source && $title->equals($source) && ($sourceExists ??= $source->exists())) {
				$link = "'''" . $display . "'''";
			} else {
				$target = $title->getFullText();
				if (in_array($title->getNamespace(), [NS_FILE, NS_CATEGORY], true)) {
					$target = ':' . $target;
				}
				$link = '[[' . $target . '|' . $display . ']]';
			}
			$cards[] = ArgumentMapMarkup::card($link);
		}
		return implode('', $cards);
	}

	private static function output(string $text): array {
		// Keep MediaWiki's normal link processing and HTML sanitization.
		return [Validator::cleanUp($text), 'noparse' => true, 'isHTML' => false];
	}
}
