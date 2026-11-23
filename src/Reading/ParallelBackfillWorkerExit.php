<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

/**
 * Decide whether a finished parallel cache worker needs to be restarted.
 *
 * A PHP process status exitcode of -1 is NOT an exit(1). It can indicate
 * termination by signal or an unavailable wait status. On PHP >= 8.3,
 * proc_close() may provide the exit code even after proc_get_status().
 *
 * Never infer that a scan lane succeeded from an unknown process exit:
 * only the independently persisted lane checkpoint proves completion.
 */
final class ParallelBackfillWorkerExit {
	/**
	 * @param array<string,mixed> $status Final proc_get_status() snapshot
	 * @return array{action:string,reason:string,exit_code:int,signal:int}
	 */
	public static function decide(
		array $status, int $closeExit, bool $needed, int $previousRestarts,
		int $maxRestarts = 2
	): array {
		if (($status['running'] ?? null) !== false ||
			!is_int($status['exitcode'] ?? null) ||
			!is_bool($status['signaled'] ?? null) ||
			!is_int($status['termsig'] ?? null) ||
			$previousRestarts < 0 || $maxRestarts < 0 || $maxRestarts > 10) {
			throw new \InvalidArgumentException('Invalid completed parallel worker status');
		}
		$signaled = $status['signaled'];
		// An uncaught signal is never a successful child exit, even when the
		// second process API appears to return zero.
		$code = $status['exitcode'];
		if (!$signaled && $code < 0 && $closeExit >= 0) {
			$code = $closeExit;
		}
		$signal = $signaled ? max(0, $status['termsig']) : 0;
		$reason = $signaled ?
			'worker-signal-' . $signal :
			($code < 0 ? 'worker-exit-unknown' :
				($code === 0 ? 'worker-exit-normal' : 'worker-exit-' . $code));
		// This lane already committed an authoritative "done" checkpoint,
		// or the controller has deliberately stopped the whole session.
		if (!$needed) {
			return [
				'action' => 'finished', 'reason' => $reason,
				'exit_code' => $code, 'signal' => $signal
			];
		}
		// Even an exit(0) is premature without a "done" checkpoint.
		// Re-spawn with the SAME session and lane; no cursor is advanced.
		return [
			'action' => $previousRestarts < $maxRestarts ? 'restart' : 'fatal',
			'reason' => $code === 0 && !$signaled ? 'worker-premature-exit' : $reason,
			'exit_code' => $code, 'signal' => $signal
		];
	}
}
