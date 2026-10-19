<?php
namespace MediaWiki\Extension\DebatePHP;

/** Pure preprocessing, discarded per render; no messages, expansions or SMW effects. */
final class RenderData {
	private array $csv = [];
	private array $seen = [];
	private int $bytes = 0;
	private int $peakBytes = 0;
	private int $calls = 0;
	private int $hits = 0;
	private int $misses = 0;
	private int $bypassed = 0;
	private int $admitted = 0;
	private int $evicted = 0;
	private int $failures = 0;

	public function __construct(private int $maxEntries = 256, private int $maxBytes = 131072, private int $maxEntryBytes = 16384) {
		if (min($maxEntries, $maxBytes, $maxEntryBytes) < 0) { throw new \InvalidArgumentException('Negative preprocessing cache bound'); }
	}

	/** Keywords and sections have multiple consumers. Store only bounded fingerprints. */
	public function expectReuse(array $texts): void {
		foreach ($texts as $text) {
			if (is_string($text) && strlen($text) + 3 > 1024 && 2 * (strlen($text) + 3) <= min($this->maxBytes, $this->maxEntryBytes)) {
				$this->remember(hash('xxh128', '1:,' . $text, true));
			}
		}
	}

	private function remember(string $fingerprint): void {
		if ($this->maxEntries === 0) { return; }
		$this->seen[$fingerprint] = true;
		if (count($this->seen) > $this->maxEntries) { unset($this->seen[array_key_first($this->seen)]); }
	}

	public function csv(?string $text, ?string $separator = null): Table {
		$this->calls++;
		$text ??= ''; $separator ??= ',';
		if ($separator === '') { $this->failures++; throw new \ValueError('Empty CSV separator'); }
		if ($text === '') { $this->bypassed++; return new Table; }
		$key = strlen($separator) . ':' . $separator . $text;
		if (isset($this->csv[$key])) {
			$this->hits++;
			return new Table($this->csv[$key]);
		}
		if ($this->maxEntries === 0 || strlen($text) > $this->maxEntryBytes || $this->maxBytes === 0) {
			$this->bypassed++;
			return new Table(SerializedData::csvValues($text, $separator));
		}
		// Decoded strings total at most the source length. This is a conservative
		// string-payload bound, not PHP container overhead (bounded by entry count).
		$bytes = 2 * strlen($key);
		if ($bytes > min($this->maxBytes, $this->maxEntryBytes)) {
			$this->bypassed++;
			return new Table(SerializedData::csvValues($text, $separator));
		}
		$this->misses++;
		$values = SerializedData::csvValues($text, $separator);
		if (!$values) { return new Table; }
		// Under pressure, a large unknown text must be reread before evicting hot data.
		$pressure = count($this->csv) >= $this->maxEntries || $this->bytes + $bytes > $this->maxBytes;
		if ($pressure && strlen($key) > 1024) {
			$fingerprint = hash('xxh128', $key, true);
			if (!isset($this->seen[$fingerprint])) {
				$this->remember($fingerprint);
				return new Table($values);
			}
		}
		// Digest collisions affect admission only; results always use the full key.
		while ($this->csv && (count($this->csv) >= $this->maxEntries || $this->bytes + $bytes > $this->maxBytes)) {
			$old = array_key_first($this->csv);
			$this->bytes -= 2 * strlen($old);
			unset($this->csv[$old]);
			$this->evicted++;
		}
		$this->csv[$key] = $values;
		$this->bytes += $bytes;
		$this->peakBytes = max($this->peakBytes, $this->bytes);
		$this->admitted++;
		return new Table($values);
	}

	/** Counts and retained string-payload bytes only; never exposes text or keys. */
	public function statistics(): array {
		return ['calls' => $this->calls, 'hits' => $this->hits, 'misses' => $this->misses,
			'bypassed' => $this->bypassed, 'admitted' => $this->admitted, 'evicted' => $this->evicted, 'failures' => $this->failures,
			'entries' => count($this->csv), 'admission_entries' => count($this->seen),
			'payload_bytes' => $this->bytes, 'peak_payload_bytes' => $this->peakBytes];
	}
}
