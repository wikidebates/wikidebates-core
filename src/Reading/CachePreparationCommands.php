<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Sequential, bounded subprocesses for a single page. No shell is involved.
 * Each command still performs its own authorization and cacheability checks.
 */
final class CachePreparationCommands {
	private string $entrypoint;

	public function __construct(
		string $installRoot,
		private string $wiki,
		private int $minervaDelayMs = 6000,
		private int $timeoutSeconds = 100,
		private array $minervaDevices = ['android']
	) {
		$path = realpath(rtrim($installRoot, '/') . '/maintenance/run.php');
		if ($path === false || !is_file($path) ||
			!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $wiki) ||
			$minervaDelayMs < 250 || $minervaDelayMs > 60000 ||
			$timeoutSeconds < 10 || $timeoutSeconds > 180 ||
			$minervaDevices === [] || count($minervaDevices) !== count(array_unique($minervaDevices)) ||
			array_diff($minervaDevices, ['android', 'iphone'])) {
			throw new \InvalidArgumentException('Invalid private cache preparation runner configuration');
		}
		$this->entrypoint = $path;
	}

	/** @return array{ok:bool,status:string} */
	public function prepare(string $phase, int $pageId, bool $forceExistingVectorView = false): array {
		if ($pageId < 1 || !in_array($phase, CachePreparationCoordinator::PHASES, true) ||
			($forceExistingVectorView && $phase !== 'vector')) {
			throw new \InvalidArgumentException('Unknown cache target');
		}
		if ($phase === 'minerva') {
			$statuses = [];
			$previousMadeHttpRequest = false;
			foreach ($this->minervaDevices as $device) {
				// The targeted Minerva CLI no longer waits after its lone GET.
				// When preparing two variants, pace only between actual HTTP
				// requests. LOCAL_HIT uses disk and needs no network throttle.
				if ($previousMadeHttpRequest) {
					usleep($this->minervaDelayMs * 1000);
				}
				$args = [
					'MinervaFileCache:prewarm', '--page-id=' . $pageId,
					'--device=' . $device, '--skip-valid',
					'--delay-ms=' . $this->minervaDelayMs, '--timeout=30', '--retries=0'
				];
				$command = [PHP_BINARY, $this->entrypoint, ...$args, '--wiki=' . $this->wiki];
				$result = $this->execute($command);
				if (!$result['ok']) {
					return ['ok' => false, 'status' => $result['status']];
				}
				$outcome = CachePreparationResults::interpret($phase, $pageId, 0, $result['stdout']);
				if (!$outcome['ok']) { return $outcome; }
				$statuses[$device] = $outcome['status'];
				$previousMadeHttpRequest = !in_array($outcome['status'],
					['LOCAL_HIT', 'SKIPPED_SMW_ARTICLE', 'SKIPPED_FLOW_BOARD'], true);
			}
			if (count($statuses) === 1) {
				return ['ok' => true, 'status' => reset($statuses)];
			}
			$parts = [];
			foreach ($statuses as $device => $status) { $parts[] = $device . '=' . $status; }
			return ['ok' => true, 'status' => implode(';', $parts)];
		}
		$args = $phase === 'vector'
			? ['Wikidebates:prepareVectorViewCache', '--page-id=' . $pageId,
				...($forceExistingVectorView ? ['--force-view', '--existing-only'] : ['--repair-missing-toc']),
				'--json']
			: ['Wikidebates:prepareReadingCache', '--page-id=' . $pageId,
				'--json', '--sleep-ms=0', '--progress-every=0', '--max-seconds=70'];
		$command = [PHP_BINARY, $this->entrypoint, ...$args, '--wiki=' . $this->wiki];
		$result = $this->execute($command);
		if (!$result['ok']) {
			// The Vector command reports certain intentional refusals with
			// exit 1 and a small JSON status on stdout. Preserve only those
			// allowlisted diagnostics; never expose arbitrary child output.
			if ($phase === 'vector' && $result['status'] === 'subprocess-exit-1') {
				$reason = CachePreparationResults::knownVectorFailure(
					$pageId, $result['stdout']
				);
				if ($reason !== null) {
					return ['ok' => false, 'status' => $reason];
				}
			}
			return ['ok' => false, 'status' => $result['status']];
		}
		return CachePreparationResults::interpret($phase, $pageId, 0, $result['stdout']);
	}

	/**
	 * Never assemble a shell command. Drain stdout/stderr concurrently and
	 * reject noisy, blocking or overlong child processes.
	 *
	 * @param string[] $command
	 * @return array{ok:bool,status:string,stdout:string}
	 */
	private function execute(array $command): array {
		$process = @proc_open($command, [
			0 => ['file', '/dev/null', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		], $pipes);
		if (!is_resource($process)) {
			return ['ok' => false, 'status' => 'subprocess-unavailable', 'stdout' => ''];
		}
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$outputs = [1 => '', 2 => ''];
		$started = microtime(true);
		$status = null;
		$exitCode = null;
		$failure = null;
		try {
			while (true) {
				$read = [];
				foreach ([1, 2] as $index) {
					if (!feof($pipes[$index])) { $read[] = $pipes[$index]; }
				}
				if ($read !== []) {
					$write = $except = null;
					if (@stream_select($read, $write, $except, 0, 100000) !== false) {
						foreach ($read as $pipe) {
							$index = $pipe === $pipes[1] ? 1 : 2;
							$chunk = @fread($pipe, 8192);
							if ($chunk !== false) { $outputs[$index] .= $chunk; }
						}
					}
				}
				if (strlen($outputs[1]) > 65536 || strlen($outputs[2]) > 65536) {
					$failure = 'subprocess-output-too-large';
					break;
				}
				$status = proc_get_status($process);
				if (!$status['running'] && $exitCode === null && $status['exitcode'] >= 0) {
					$exitCode = (int)$status['exitcode'];
				}
				if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) {
					break;
				}
				if (microtime(true) - $started >= $this->timeoutSeconds) {
					$failure = 'subprocess-time-limit';
					break;
				}
			}
			if ($failure !== null) {
				@proc_terminate($process, 15);
				usleep(100000);
				@proc_terminate($process, 9);
				return ['ok' => false, 'status' => $failure, 'stdout' => ''];
			}
			$exit = $exitCode ?? -1;
			return $exit === 0
				? ['ok' => true, 'status' => 'complete', 'stdout' => $outputs[1]]
				: ['ok' => false, 'status' => 'subprocess-exit-' . $exit, 'stdout' => ''];
		} finally {
			fclose($pipes[1]);
			fclose($pipes[2]);
			@proc_close($process);
		}
	}
}
