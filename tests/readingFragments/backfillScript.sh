#!/bin/sh
# Test only the wrapper control flow, never real MediaWiki or HTTP.
set -eu
repo=$(CDPATH= cd "$(dirname "$0")/../.." && pwd -P)
temp=$(mktemp -d)
trap 'rm -rf "$temp"' EXIT HUP INT TERM
mkdir -p "$temp/home" "$temp/bin"
echo 0 > "$temp/count"

cat > "$temp/bin/php-stub" <<'STUB'
#!/bin/sh
set -eu
if [ "$1" = "-r" ]; then
	exec php "$@"
fi
case " $* " in
	*" Wikidebates:prepareDailyCaches "*" --wiki=fr "*" --execute "*)
		;;
	*)
		echo "Unexpected maintenance arguments: $*" >&2
		exit 2
		;;
esac
count=$(cat "$WD_BACKFILL_TEST_COUNT")
echo $((count + 1)) > "$WD_BACKFILL_TEST_COUNT"
case "$count" in
	0) reason=limit ;;
	1) reason=cycle-complete ;;
	*) reason=retry-required ;;
esac
printf '{"type":"wikidebates-daily-cache-preparation","wiki":"frwikidebates","mode":"execute","stop_reason":"%s","pages":[]}\n' "$reason"
STUB
chmod 700 "$temp/bin/php-stub"

export HOME="$temp/home"
export WD_BACKFILL_PHP="$temp/bin/php-stub"
export WD_BACKFILL_TEST_COUNT="$temp/count"
sh "$repo/maintenance/backfillDailyCaches.sh" fr android > "$temp/success.log"
grep -q '^BACKFILL_COMPLETE wiki=fr ' "$temp/success.log"
test "$(cat "$temp/count")" -eq 2

if sh "$repo/maintenance/backfillDailyCaches.sh" fr android > "$temp/retry.log" 2>&1; then
	echo "Backfill incorrectly accepted a retry-required stop" >&2
	exit 1
fi
grep -q '^BACKFILL_STOP wiki=fr reason=retry-required' "$temp/retry.log"
test "$(cat "$temp/count")" -eq 3

if sh "$repo/maintenance/backfillDailyCaches.sh" unexpected android > "$temp/bad.log" 2>&1; then
	echo "Backfill accepted an unknown wiki" >&2
	exit 1
fi
test "$(cat "$temp/count")" -eq 3
echo '{"result":"PASS","check":"backfill bounded resume, complete and fail-stop"}'
