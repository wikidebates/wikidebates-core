#!/bin/sh
# Standalone safety tests; never contact MediaWiki or public HTTP.
set -eu
repo=$(CDPATH= cd "$(dirname "$0")/../.." && pwd -P)
temp=$(mktemp -d)
trap 'rm -rf "$temp"' EXIT HUP INT TERM
mkdir -p "$temp/home" "$temp/bin"
echo 0 > "$temp/count"

cat > "$temp/bin/cache-window-php" <<'STUB'
#!/bin/sh
set -eu
if [ "$1" = "-r" ]; then
	exec php "$@"
fi
case "$2" in
	Wikidebates:prepareParallelCaches)
		case " $* " in
			*" --status "*) ;;
			*) echo 'Unexpected parallel command' >&2; exit 2 ;;
		esac
		case " $* " in
			*" --wiki=fr "*) wiki=fr ;;
			*" --wiki=en "*) wiki=en ;;
			*) echo 'Unexpected wiki' >&2; exit 2 ;;
		esac
		state=complete
		if [ "$wiki" = en ] && [ "${WD_CACHE_WINDOW_TEST_EN:-}" = incomplete ]; then
			state=running
		fi
		printf '{"type":"wikidebates-parallel-backfill","wiki":"%swikidebates","mode":"status","checkpoint":{"state":"%s"}}\n' "$wiki" "$state"
		;;
	Wikidebates:prepareDailyCaches)
		for expected in "--wiki=fr" "--execute" "--limit=100" "--max-seconds=900"; do
			case " $* " in
				*" $expected "*) ;;
				*) echo "Missing $expected" >&2; exit 2 ;;
			esac
		done
		count=$(cat "$WD_CACHE_WINDOW_TEST_COUNT")
		echo $((count + 1)) > "$WD_CACHE_WINDOW_TEST_COUNT"
		case "${WD_CACHE_WINDOW_TEST_MODE:-success}" in
			success)
				case "$count" in
					0) reason=limit ;;
					1) reason=time-budget ;;
					*) reason=cycle-complete ;;
				esac
				;;
			load) reason=system-load ;;
			retry) reason=retry-required ;;
			invalid) echo 'not-json'; exit 0 ;;
			*) exit 2 ;;
		esac
		if [ "$reason" = system-load ]; then
			handled=0
		else
			handled=10
		fi
		printf '{"type":"wikidebates-daily-cache-preparation","wiki":"frwikidebates","mode":"execute","stop_reason":"%s","queue_processed":0,"scan_processed":%s,"scan_deferred":0,"last_page_id":%s}\n' "$reason" "$handled" "$((count * 10 + handled))"
		;;
	*) echo 'Unexpected command' >&2; exit 2 ;;
esac
STUB
chmod 700 "$temp/bin/cache-window-php"

export HOME="$temp/home"
export WD_CACHE_WINDOW_LOCK_DIR="$temp/home/windows"
export WD_CACHE_WINDOW_PHP="$temp/bin/cache-window-php"
export WD_CACHE_WINDOW_TEST_COUNT="$temp/count"
export WD_CACHE_WINDOW_SECONDS=900

export WD_CACHE_WINDOW_TEST_EN=incomplete
sh "$repo/maintenance/scanCacheWindow.sh" fr > "$temp/incomplete.log"
grep -q 'reason=initial-backfill-incomplete' "$temp/incomplete.log"
test "$(cat "$temp/count")" -eq 0

unset WD_CACHE_WINDOW_TEST_EN
sh "$repo/maintenance/scanCacheWindow.sh" fr > "$temp/success.log"
grep -q 'reason=cycle-complete' "$temp/success.log"
test "$(cat "$temp/count")" -eq 3

export WD_CACHE_WINDOW_TEST_MODE=load
sh "$repo/maintenance/scanCacheWindow.sh" fr > "$temp/load.log"
grep -q 'reason=system-load' "$temp/load.log"
test "$(cat "$temp/count")" -eq 4

export WD_CACHE_WINDOW_TEST_MODE=retry
if sh "$repo/maintenance/scanCacheWindow.sh" fr > "$temp/retry.log" 2>&1; then
	echo 'Daily error incorrectly accepted' >&2
	exit 1
fi
grep -q 'reason=invalid-batch-report' "$temp/retry.log"
test "$(cat "$temp/count")" -eq 5

export WD_CACHE_WINDOW_TEST_MODE=invalid
if sh "$repo/maintenance/scanCacheWindow.sh" fr > "$temp/invalid.log" 2>&1; then
	echo 'Malformed maintenance report incorrectly accepted' >&2
	exit 1
fi
grep -q 'reason=invalid-batch-report' "$temp/invalid.log"

if sh "$repo/maintenance/scanCacheWindow.sh" unknown > "$temp/wiki.log" 2>&1; then
	echo 'Unknown wiki incorrectly accepted' >&2
	exit 1
fi
if WD_CACHE_WINDOW_SECONDS=1 sh "$repo/maintenance/scanCacheWindow.sh" fr > "$temp/budget.log" 2>&1; then
	echo 'Invalid budget incorrectly accepted' >&2
	exit 1
fi
ln -s "$temp/does-not-exist" "$temp/home/windows/en.lock"
if sh "$repo/maintenance/scanCacheWindow.sh" en > "$temp/symlink.log" 2>&1; then
	echo 'Symlinked lock incorrectly accepted' >&2
	exit 1
fi

echo '{"result":"PASS","check":"bounded scan, initial gate, resource stop, malformed reports, fail-safe lock"}'
