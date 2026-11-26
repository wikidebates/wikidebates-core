<?php
namespace MediaWiki\Extension\DebatePHP;

/** Shared warning-label resolution. Localized labels and aliases map to canonical keys. */
trait WarningLabels {
	/** @param list<string> $keys @return array<string, string> */
	protected function buildSummaryLabelLookup($lang = null, array $keys = []): array {
		$lookup = [];
		foreach ($keys as $key) {
			$label = trim($this->subMsg($lang, $key . '_label'), NativeText::ASCII_WHITESPACE);
			if ($label !== '') {
				$lookup[$label] = $key;
			}
		}
		return $lookup;
	}

	/** @param list<string> $keys @return array<string, string> */
	protected function buildBannerLabelLookup($lang = null, array $keys = []): array {
		$lookup = [];
		foreach ($keys as $key) {
			$label = trim($this->bannerMsg($lang, $key . '_label'), NativeText::ASCII_WHITESPACE);
			if ($label !== '') {
				$lookup[$label] = $key;
			}
		}
		return $lookup;
	}

	/** Subsection warnings also accept aliases and exact canonical keys. */
	protected function resolveKeyFromLookup($raw = null, array $lookup = [], array $allowedKeys = [], array $aliases = []): string {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if ($raw === '') {
			return '';
		}
		$key = $lookup[$raw] ?? '';
		if ($key !== '') {
			return $key;
		}
		if (isset($aliases[$raw])) {
			return $aliases[$raw];
		}
		return in_array($raw, $allowedKeys, true) ? $raw : '';
	}

	/** Page-level banners accept localized labels only. */
	protected function resolveBannerKeyFromLookup($raw = null, array $lookup = []): string {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		return $raw === '' ? '' : ($lookup[$raw] ?? '');
	}
}
