#!/usr/bin/env bash
# Safe daily/manual runner for a single wiki. Run as the normal MediaWiki CLI user.
set -Eeuo pipefail
umask 077

if (( $# < 1 || $# > 2 )) || [[ "$1" != "fr" && "$1" != "en" ]]; then
	echo "Usage: bash runReadingPreparation.sh fr|en [first-run-after-page-id]" >&2
	exit 64
fi
if (( EUID == 0 )); then
	echo "Run as the MediaWiki CLI account (webmaster), not root." >&2
	exit 77
fi

wiki="$1"
initial_after="${2-}"
if [[ -n "$initial_after" && ! "$initial_after" =~ ^[0-9]+$ ]]; then
	echo "Initial page ID must be a nonnegative integer." >&2
	exit 64
fi

mediawiki_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd -P)"
state_dir="${WD_READING_PREWARM_DIR:-$HOME/wikidebates-reading-logs}"
php_bin="${WD_READING_PHP_BIN:-/usr/bin/php}"

if [[ -L "$state_dir" ]]; then
	echo "Refusing a symlinked state directory." >&2
	exit 73
fi
mkdir -p -m 0700 "$state_dir"
if [[ ! -d "$state_dir" || ! -w "$state_dir" ||
	"$(stat -c %u "$state_dir")" != "$(id -u)" ]]; then
	echo "State directory must be writable and owned by the CLI user." >&2
	exit 73
fi
chmod 0700 "$state_dir"
if [[ ! -x "$php_bin" ]]; then
	echo "PHP binary is not executable: $php_bin" >&2
	exit 69
fi

lock_file="$state_dir/reading-$wiki.lock"
resume_file="$state_dir/reading-$wiki.resume.json"
if [[ -L "$lock_file" ]]; then
	echo "Refusing a symlinked lock file." >&2
	exit 73
fi
exec 9>"$lock_file"
if ! flock -n 9; then
	echo "Reading preparation already running for wiki=$wiki" >&2
	exit 75
fi

args=(
	"$mediawiki_root/maintenance/run.php"
	Wikidebates:prepareReadingCache
	"--wiki=$wiki"
	"--resume-file=$resume_file"
	--max-seconds=6900
	--batch-size=100
	--sleep-ms=100
	--progress-every=100
	--json
)
if [[ -n "$initial_after" ]]; then
	args+=("--after=$initial_after")
fi

echo "=== PREWARM START wiki=$wiki UTC=$(date -u +%FT%TZ) ==="
if [[ -f "$resume_file" ]]; then
	echo "Existing checkpoint: $resume_file"
fi
set +e
timeout --signal=TERM --kill-after=10s 7200s "$php_bin" "${args[@]}"
result=$?
set -e
echo "=== PREWARM END wiki=$wiki status=$result UTC=$(date -u +%FT%TZ) ==="
if (( result == 124 )); then
	echo "Hard time limit reached; run again without an initial page ID to resume." >&2
fi
exit "$result"
