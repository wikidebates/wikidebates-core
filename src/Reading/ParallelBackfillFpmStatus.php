<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/** Strictly parse the public-free PHP-FPM status JSON, without logging bodies. */
final class ParallelBackfillFpmStatus {
	/** @return array{active:int,idle:int,listen_queue:int}|null */
	public static function parse(string $json): ?array {
		if ($json === '' || strlen($json) > 8192) {
			return null;
		}
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return null;
		}
		$fields = [
			'active' => 'active processes',
			'idle' => 'idle processes',
			'listen_queue' => 'listen queue'
		];
		$result = [];
		foreach ($fields as $target => $source) {
			$value = $data[$source] ?? null;
			if (!is_int($value) || $value < 0) {
				return null;
			}
			$result[$target] = $value;
		}
		return $result;
	}
}
