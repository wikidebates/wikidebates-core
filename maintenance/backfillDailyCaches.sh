#!/bin/sh
# One-time, resumable preparation of every eligible anonymous cache page.
# The MediaWiki command owns per-wiki locks and durable cursors. This wrapper
# only repeats bounded batches until a complete sweep has been reported.
set -eu
umask 077

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
	echo "Usage: sh extensions/Wikidebates/maintenance/backfillDailyCaches.sh fr|en [android|iphone|android,iphone]" >&2
	exit 2
fi
wiki=$1
devices=${2:-android,iphone}
case "$wiki" in
	fr|en) ;;
	*) echo "Unsupported wiki: $wiki" >&2; exit 2 ;;
esac
case "$devices" in
	android|iphone|android,iphone) ;;
	*) echo "Unsupported Minerva variant selection: $devices" >&2; exit 2 ;;
esac

php_binary=${WD_BACKFILL_PHP:-php}
if ! command -v "$php_binary" >/dev/null 2>&1; then
	echo "PHP executable not found: $php_binary" >&2
	exit 2
fi
if ! command -v flock >/dev/null 2>&1; then
	echo "The flock command is needed to prevent overlapping full scans." >&2
	exit 2
fi
if [ -z "${HOME:-}" ]; then
	echo "A private HOME directory is required for the backfill lock." >&2
	exit 2
fi
lock_dir=${WD_BACKFILL_LOCK_DIR:-"$HOME/.cache/wikidebates-backfill"}
if [ -L "$lock_dir" ]; then
	echo "Refusing a symlinked backfill lock directory." >&2
	exit 2
fi
mkdir -p -m 700 "$lock_dir"
chmod 700 "$lock_dir"
lock_file="$lock_dir/$wiki.lock"
if [ -L "$lock_file" ]; then
	echo "Refusing a symlinked backfill lock file." >&2
	exit 2
fi
exec 9>"$lock_file"
if ! flock -n 9; then
	echo "A full backfill is already active for $wiki." >&2
	exit 2
fi

root=$(CDPATH= cd "$(dirname "$0")/../../.." && pwd -P)
cd "$root"
echo "BACKFILL_START wiki=$wiki minerva_devices=$devices at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"

while :; do
	if report=$("$php_binary" maintenance/run.php Wikidebates:prepareDailyCaches \
		"--wiki=$wiki" --execute --limit=100 --queue-limit=10 \
		--batch-size=50 --max-seconds=1800 \
		"--max-load=${WD_BACKFILL_MAX_LOAD:-4}" \
		"--minerva-devices=$devices" \
		"--minerva-delay-ms=${WD_BACKFILL_MINERVA_DELAY_MS:-1500}" \
		"--delay-ms=${WD_BACKFILL_PAGE_DELAY_MS:-1500}" \
		--json 2>&1); then
		printf '%s\n' "$report"
	else
		printf '%s\n' "$report" >&2
		echo "BACKFILL_STOP wiki=$wiki reason=command-failed" >&2
		exit 1
	fi

	# A missing, malformed or surprising response must never be treated as
	# success. No extra process silently skips failed cache preparation.
	reason=$(printf '%s\n' "$report" | "$php_binary" -r '
		$report = json_decode(trim(stream_get_contents(STDIN)), true);
		if (!is_array($report) ||
			($report["type"] ?? null) !== "wikidebates-daily-cache-preparation" ||
			($report["mode"] ?? null) !== "execute" ||
			!is_string($report["stop_reason"] ?? null)) {
			fwrite(STDERR, "Invalid backfill report\n");
			exit(3);
		}
		echo $report["stop_reason"];
	') || {
		echo "BACKFILL_STOP wiki=$wiki reason=invalid-report" >&2
		exit 1
	}

	case "$reason" in
		cycle-complete)
			echo "BACKFILL_COMPLETE wiki=$wiki at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
			exit 0
			;;
		limit|time-budget)
			# Resume from the durable cursor; never reset it manually.
			;;
		system-load)
			echo "BACKFILL_PAUSE wiki=$wiki reason=system-load"
			sleep 120
			;;
		*)
			echo "BACKFILL_STOP wiki=$wiki reason=$reason; inspect the last JSON report" >&2
			exit 1
			;;
	esac
done
