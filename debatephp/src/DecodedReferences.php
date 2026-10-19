<?php
namespace MediaWiki\Extension\DebatePHP;

/** Read-only records, owned by one render. Parsing never executes wiki/variable effects. */
final class DecodedReferences {
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

	public function parse(string $format, ?string $raw): Table {
		$raw = trim($raw ?? '', " \t\r\n\v\f");
		if (isset($this->legacyLists[$format][$raw])) { return $this->legacyLists[$format][$raw]; }
		$records = $this->values($format, $raw);
		$out = [];
		if ($records) {
			foreach (explode($format === 'quotes' ? '⟭⟭⟭' : '⟭', $raw) as $block) {
				$block = trim($block, " \t\r\n\v\f");
				if ($block === '') { continue; }
				$out[count($out) + 1] = $this->legacyBlocks[$format][$block] ??= new Table($this->blocks[$format][$block]);
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
				if (isset($this->blocks[$format][$block])) { $this->reusedBlocks++; }
				else {
					$this->blocks[$format][$block] = SerializedData::recordValues($block, $fieldSeparator, $fields);
					$this->decodedBlocks++;
				}
				$out[count($out) + 1] = $this->blocks[$format][$block];
			}
		}
		return $this->lists[$format][$raw] = $out;
	}

	/** Test/benchmark observations; contains no reference content. */
	public function statistics(): array {
		return ['decoded_blocks' => $this->decodedBlocks, 'reused_blocks' => $this->reusedBlocks];
	}
}
