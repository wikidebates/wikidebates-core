#!/bin/sh
# Scheduled historical cache audit in bounded, resumable 100-page batches.
# Never launch until BOTH one-time parallel backfills report complete.
# Existing direct-edit queue crons remain separate.
set -eu
umask 077

if [ "$#" -ne 1 ]; then
	echo "Usage: sh maintenance/scanCacheWindow.sh fr|en" >&2
	exit 2
fi
wiki=$1
case "$wiki" in
	fr|en) ;;
	*) echo "Unsupported wiki" >&2; exit 2 ;;
esac

php_binary=${WD_CACHE_WINDOW_PHP:-php}
window_seconds=${WD_CACHE_WINDOW_SECONDS:-2700}
max_load=${WD_CACHE_WINDOW_MAX_LOAD:-10}
case "$window_seconds" in
	''|*[!0-9]*) echo "Invalid window duration" >&2; exit 2 ;;
esac
if [ "$window_seconds" -lt 900 ] || [ "$window_seconds" -gt 14400 ]; then
	echo "Window must be 900..14400 seconds" >&2
	exit 2
fi
if ! command -v "$php_binary" >/dev/null 2>&1 ||
	! command -v flock >/dev/null 2>&1 || [ -z "${HOME:-}" ]; then
	echo "PHP, flock and private HOME are required" >&2
	exit 2
fi
lock_dir=${WD_CACHE_WINDOW_LOCK_DIR:-"$HOME/.cache/wikidebates-cache-windows"}
if [ -L "$lock_dir" ] || { [ -e "$lock_dir" ] && [ ! -d "$lock_dir" ]; }; then
	echo "Unsafe cache-window lock directory" >&2
	exit 2
fi
mkdir -p -m 700 "$lock_dir"
chmod 700 "$lock_dir"
lock_file="$lock_dir/$wiki.lock"
if [ -L "$lock_file" ]; then
	echo "Unsafe cache-window lock file" >&2
	exit 2
fi
exec 9>"$lock_file"
if ! flock -n 9; then
	echo "CACHE_WINDOW_SKIP wiki=$wiki reason=already-running"
	exit 0
fi

root=$(CDPATH= cd "$(dirname "$0")/../../.." && pwd -P)
cd "$root"

# Do not let a finished FR sweep race an unfinished EN initial backfill.
for required_wiki in fr en; do
	if status=$("$php_binary" maintenance/run.php Wikidebates:prepareParallelCaches \
		"--wiki=$required_wiki" --status 2>&1); then
		:
	else
		echo "CACHE_WINDOW_ERROR wiki=$wiki reason=status-command-failed" >&2
		exit 1
	fi
	if printf '%s\n' "$status" | "$php_binary" -r '
		$raw = trim(stream_get_contents(STDIN));
		$data = json_decode($raw, true);
		if (!is_array($data) ||
			($data["type"] ?? null) !== "wikidebates-parallel-backfill" ||
			($data["wiki"] ?? null) !== $argv[1] ||
			($data["mode"] ?? null) !== "status" ||
			!array_key_exists("checkpoint", $data)) {
			exit(2);
		}
		exit(($data["checkpoint"]["state"] ?? null) === "complete" ? 0 : 1);
	' "$required_wiki""wikidebates"; then
		:
	else
		code=$?
		if [ "$code" -eq 1 ]; then
			echo "CACHE_WINDOW_SKIP wiki=$wiki reason=initial-backfill-incomplete"
			exit 0
		fi
		echo "CACHE_WINDOW_ERROR wiki=$wiki reason=invalid-checkpoint-report" >&2
		exit 1
	fi
done

started=$(date +%s)
deadline=$((started + window_seconds))
echo "CACHE_WINDOW_START wiki=$wiki window_seconds=$window_seconds"

while :; do
	now=$(date +%s)
	if [ $((deadline - now)) -lt 900 ]; then
		echo "CACHE_WINDOW_END wiki=$wiki reason=window-exhausted"
		exit 0
	fi
	if output=$("$php_binary" maintenance/run.php Wikidebates:prepareDailyCaches \
		"--wiki=$wiki" --execute --limit=100 --queue-limit=10 \
		--batch-size=50 --max-seconds=900 "--max-load=$max_load" \
		--minerva-devices=android,iphone --minerva-delay-ms=250 \
		--delay-ms=250 --json 2>&1); then
		:
	else
		echo "CACHE_WINDOW_ERROR wiki=$wiki reason=cache-command-failed" >&2
		exit 1
	fi
	if report=$(printf '%s\n' "$output" | "$php_binary" -r '
		$data = json_decode(trim(stream_get_contents(STDIN)), true);
		if (!is_array($data) ||
			($data["type"] ?? null) !== "wikidebates-daily-cache-preparation" ||
			($data["wiki"] ?? null) !== $argv[1] ||
			($data["mode"] ?? null) !== "execute" ||
			!is_string($data["stop_reason"] ?? null)) {
			exit(2);
		}
		$fields = ["queue_processed", "scan_processed", "scan_deferred", "last_page_id"];
		foreach ($fields as $field) {
			if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
				exit(2);
			}
		}
		$reason = $data["stop_reason"];
		if (!in_array($reason, ["limit", "time-budget", "cycle-complete",
			"system-load"], true)) {
			exit(2);
		}
		echo implode(" ", [$reason, $data["queue_processed"],
			$data["scan_processed"], $data["scan_deferred"], $data["last_page_id"]]);
	' "$wiki""wikidebates"); then
		:
	else
		echo "CACHE_WINDOW_ERROR wiki=$wiki reason=invalid-batch-report" >&2
		exit 1
	fi
	set -- $report
	reason=$1
	queue_count=$2
	scan_count=$3
	deferred_count=$4
	after=$5
	echo "CACHE_WINDOW_BATCH wiki=$wiki reason=$reason queue=$queue_count scanned=$scan_count deferred=$deferred_count cursor=$after"
	case "$reason" in
		cycle-complete)
			echo "CACHE_WINDOW_END wiki=$wiki reason=cycle-complete"
			exit 0 ;;
		system-load)
			echo "CACHE_WINDOW_END wiki=$wiki reason=system-load"
			exit 0 ;;
		limit|time-budget)
			if [ $((queue_count + scan_count + deferred_count)) -eq 0 ]; then
				echo "CACHE_WINDOW_END wiki=$wiki reason=no-progress"
				exit 0
			fi ;;
	esac
done
