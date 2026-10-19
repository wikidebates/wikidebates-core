<?php
namespace MediaWiki\Extension\DebatePHP;

/** Read-only records, owned by one render. Parsing never executes wiki/variable effects. */
final class DecodedReferences {
	private const MAX_LISTS = 64;
	private const MAX_BLOCKS = 512;
	private const MAX_LIST_RECORDS = 2048;
	private const MAX_STRING_BYTES = 1048576;
	private const FORMATS = [
		'bibliography' => ['authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings'],
		'webliography' => ['page', 'site', 'link', 'authors', 'date', 'warnings'],
		'videography' => ['title', 'link', 'authors', 'warnings'],
		'quotes' => ['quote', 'authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings']
	];
	private array $lists = [];
	private array $blocks = [];
	private array $legacyLists = [];
	private array $legacyBlocks = [];
	private int $decodedBlocks = 0;
	private int $reusedBlocks = 0;
	private int $listCount = 0;
	private int $blockCount = 0;
	private int $listRecords = 0;
	private int $stringBytes = 0;

	public function parse(string $format, ?string $raw): Table {
		$raw = trim($raw ?? '', " \t\r\n\v\f");
		if (isset($this->legacyLists[$format][$raw])) { return $this->legacyLists[$format][$raw]; }
		$records = $this->values($format, $raw);
		$out = [];
		if ($records) {
			foreach (explode($format === 'quotes' ? '⟭⟭⟭' : '⟭', $raw) as $block) {
				$block = trim($block, " \t\r\n\v\f");
				if ($block === '') { continue; }
				$index = count($out) + 1;
				$out[$index] = $this->legacyBlocks[$format][$block] ??= new Table($records[$index]);
			}
		}
		return $this->legacyLists[$format][$raw] = new Table($out);
	}

	/** Native records are returned by value; PHP copy-on-write isolates callers. */
	public function values(string $format, ?string $raw): array {
		$fields = self::FORMATS[$format] ?? throw new \InvalidArgumentException('Unknown reference format');
		$raw = trim($raw ?? '', " \t\r\n\v\f");
		if (isset($this->lists[$format][$raw])) { return $this->lists[$format][$raw]; }
		$isQuote = $format === 'quotes';
		$separator = $isQuote ? '⟭⟭⟭' : '⟭';
		$fieldSeparator = $isQuote ? '⟬⟬⟬' : '⟬';
		$out = [];
		if ($raw !== '' && ($isQuote ? str_contains($raw, $fieldSeparator)
			: (str_contains($raw, $separator) || str_contains($raw, $fieldSeparator)))) {
			foreach (explode($separator, $raw) as $block) {
				$block = trim($block, " \t\r\n\v\f");
				if ($block === '') { continue; }
				if (isset($this->blocks[$format][$block])) {
					$this->reusedBlocks++;
					$record = $this->blocks[$format][$block];
				}
				else {
					$record = SerializedData::recordValues($block, $fieldSeparator, $fields);
					$this->decodedBlocks++;
					// Fields are trimmed substrings: twice the source bounds key + values.
					$bytes = 2 * strlen($block);
					if ($this->blockCount < self::MAX_BLOCKS && $bytes <= self::MAX_STRING_BYTES - $this->stringBytes) {
						$this->blocks[$format][$block] = $record;
						$this->blockCount++;
						$this->stringBytes += $bytes;
					}
				}
				$out[count($out) + 1] = $record;
			}
		}
		// Admission only: a large unique input cannot evict reusable small records.
		// Charge the source and all decoded strings conservatively, including shared ones.
		$bytes = 2 * strlen($raw);
		if ($this->listCount < self::MAX_LISTS && count($out) <= self::MAX_LIST_RECORDS - $this->listRecords
			&& $bytes <= self::MAX_STRING_BYTES - $this->stringBytes) {
			$this->lists[$format][$raw] = $out;
			$this->listCount++;
			$this->listRecords += count($out);
			$this->stringBytes += $bytes;
		}
		return $out;
	}

	/** Native retained payload only; PHP array overhead and legacy identity tables are separate. */
	public function cacheStatistics(): array {
		return ['lists' => $this->listCount, 'blocks' => $this->blockCount, 'list_records' => $this->listRecords, 'string_bytes' => $this->stringBytes];
	}

	/** Test/benchmark observations; contains no reference content. */
	public function statistics(): array {
		return ['decoded_blocks' => $this->decodedBlocks, 'reused_blocks' => $this->reusedBlocks];
	}
}
