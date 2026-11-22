#!/usr/bin/env bash
# Run without any live MediaWiki/database dependency.
set -Eeuo pipefail
umask 077

if (( EUID == 0 )); then
	echo "SKIP: runner smoke test expects a non-root CI account"
	exit 0
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
temporary="$(mktemp -d)"
trap 'rm -rf "$temporary"' EXIT

export WD_READING_PREWARM_DIR="$temporary/state"
export WD_READING_PHP_BIN="$temporary/fake-php"

cat > "$WD_READING_PHP_BIN" <<'EOF'
#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" >> "$WD_READING_PREWARM_DIR/calls"
printf '%s\n' '{"type":"wikidebates-reading-preparation","complete":false,"stop_reason":"limit"}'
EOF
chmod 0700 "$WD_READING_PHP_BIN"

bash -n "$root/maintenance/runReadingPreparation.sh"

bash "$root/maintenance/runReadingPreparation.sh" fr 1973 > "$temporary/fr.log"
grep -Fq -- '--wiki=fr' "$WD_READING_PREWARM_DIR/calls"
grep -Fq -- '--after=1973' "$WD_READING_PREWARM_DIR/calls"
grep -Fq -- '--resume-file=' "$WD_READING_PREWARM_DIR/calls"
grep -Fq -- '--max-seconds=6900' "$WD_READING_PREWARM_DIR/calls"
grep -Fq -- 'status=0' "$temporary/fr.log"

# Separate wikis have separate locks, even while one is held.
exec 8>"$WD_READING_PREWARM_DIR/reading-fr.lock"
flock -n 8
bash "$root/maintenance/runReadingPreparation.sh" en 1808 > "$temporary/en.log"
grep -Fq -- '--wiki=en' "$WD_READING_PREWARM_DIR/calls"
grep -Fq -- '--after=1808' "$WD_READING_PREWARM_DIR/calls"

before="$(wc -l < "$WD_READING_PREWARM_DIR/calls")"
result=0
bash "$root/maintenance/runReadingPreparation.sh" fr > "$temporary/locked.log" 2>&1 || result=$?
test "$result" -eq 75
grep -Fq 'already running' "$temporary/locked.log"
test "$before" -eq "$(wc -l < "$WD_READING_PREWARM_DIR/calls")"

echo "OK: bounded reading-preparation runner arguments, wiki locks and overlap guard"
