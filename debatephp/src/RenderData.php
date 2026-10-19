<?php
namespace MediaWiki\Extension\DebatePHP;

/** Pure, bounded preprocessing, discarded at every render entry. No messages or effects. */
final class RenderData {
	private FifoCache $csv;

	public function __construct() {
		$this->csv = new FifoCache(256);
	}

	public function csv(?string $text, ?string $separator = null): Table {
		$text ??= '';
		$separator ??= ',';
		$key = strlen($separator) . ':' . $separator . $text;
		$values = $this->csv->get($key);
		if ($values === null) {
			$values = SerializedData::csv($text, $separator)->values;
			$this->csv->put($key, $values);
		}
		// A fresh wrapper prevents callers from mutating the cached list.
		return new Table($values);
	}
}
