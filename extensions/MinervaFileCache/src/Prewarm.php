<?php

namespace MediaWiki\Extension\MinervaFileCache;

/** Small, MediaWiki-independent helpers for the prewarming maintenance command. */
class Prewarm {
	private const USER_AGENTS = [
		'android' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36',
		'iphone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 Version/17.6 Mobile/15E148 Safari/604.1',
	];

	public static function userAgent(string $device): string {
		if (!isset(self::USER_AGENTS[$device])) {
			throw new \InvalidArgumentException('Device must be android or iphone');
		}
		return self::USER_AGENTS[$device];
	}

	public static function number($value, string $option, int $min, int $max): int {
		if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string)$value)) {
			throw new \InvalidArgumentException("$option must be an integer between $min and $max");
		}
		$value = filter_var((string)$value, FILTER_VALIDATE_INT);
		if ($value === false || $value < $min || $value > $max) {
			throw new \InvalidArgumentException("$option must be an integer between $min and $max");
		}
		return $value;
	}

	/** A checkpoint is bound to a wiki, origin, language, device and namespace filter. */
	public static function checkpointPath(
		string $directory, string $wiki, string $origin, string $language, string $device, ?int $namespace
	): string {
		$key = hash('sha256', json_encode([$wiki, $origin, $language, $device, $namespace]));
		return rtrim($directory, '/') . '/prewarm-' . $key . '.checkpoint';
	}

	public static function readCheckpoint(string $path): int {
		if (!is_file($path)) {
			return 0;
		}
		$data = @file_get_contents($path);
		if ($data === false || !preg_match('/^(0|[1-9][0-9]*)\n?$/D', $data)) {
			throw new \RuntimeException('Malformed prewarm checkpoint: ' . $path);
		}
		return self::number(trim($data), 'checkpoint', 0, PHP_INT_MAX);
	}

	/** Atomic replacement prevents a half-written checkpoint after an interruption. */
	public static function saveCheckpoint(string $path, int $lastId): void {
		if ($lastId < 0) {
			throw new \InvalidArgumentException('A checkpoint ID cannot be negative');
		}
		$dir = dirname($path);
		$temp = @tempnam($dir, '.prewarm-');
		if ($temp === false || dirname($temp) !== $dir) {
			throw new \RuntimeException('Cannot create temporary prewarm checkpoint');
		}
		try {
			$value = $lastId . "\n";
			if (@file_put_contents($temp, $value) !== strlen($value) ||
				!@chmod($temp, 0600) || !@rename($temp, $path)) {
				throw new \RuntimeException('Cannot save prewarm checkpoint');
			}
		} finally {
			if (is_file($temp)) {
				@unlink($temp);
			}
		}
	}

	/** Never interpret an upstream page as a cache success without our own header. */
	public static function responseState(int $httpStatus, ?string $header): string {
		if ($httpStatus !== 200) {
			return 'HTTP_' . $httpStatus;
		}
		if ($header === null || trim($header) === '') {
			return 'NO_HEADER';
		}
		$state = trim(explode(';', $header, 2)[0]);
		return in_array($state, ['MISS', 'HIT', 'BYPASS'], true) ? $state : 'UNKNOWN_HEADER';
	}
}
