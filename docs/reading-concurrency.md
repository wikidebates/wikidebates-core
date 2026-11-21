# Reading concurrency and foreground priority

Target: MediaWiki 1.43. Baseline: `bc8a0af510fc3a6707946b105903908d29f4bd48`
(the squash merge of PR #12). No change to cache eligibility, revision visibility,
generation identity, cache duration, file format, PHP parse rendering or the
unbounded browser fragment cache. No production deployment or SQL migration.

## Disk store

Warm reads hold a shared page lock through reading, decoding and validation.
Publication and invalidation still hold an exclusive lock. On first use, a reader
releases its shared lock, acquires the exclusive lock and rechecks the generation
before creating it; concurrent initializers do not overwrite one another.
That first read retains the exclusive lock, avoiding an unsafe lock upgrade or
an unlocked generation snapshot. Ordinary subsequent reads share the lock.

Publication serializes, compresses and writes its private temporary payload
before acquiring the exclusive page lock. Under that lock it rechecks generation
and expiry, then renames the fully written payload. Every failed publication
cleans up its temporary file. Lock files and directories are never replaced by
invalidation. A purge during compression prevents the stale publisher from
committing. An entry that has expired while waiting is not published.

This remains a private local filesystem cache with advisory locks. It does not
establish safety for an arbitrary distributed/NFS filesystem or uncooperative
writers. It does not coalesce all parser renders on a cache miss: the existing
fill-lock fallback behavior is unchanged.

## Browser request queue

`wkParsePage(params, { prefetch: true })` marks a speculative request. Calls without
that option remain foreground requests. Parameters, cache keys, the 50-response
LRU and promises shared by identical requests remain compatible.

At most two page requests are active, including endpoint fallback, and at most
one starts as speculative work. Foreground requests are FIFO ahead of queued
prefetches; a click on a queued neighbor promotes the same job and promise.
An active prefetch remains counted as background until it settles, even if a
reader joins it. No active requests are aborted. This limits page reads, not the
separate existing wikitext-parse pool or other extensions' network requests.

Before a new reading navigation, queued speculative jobs are discarded except
for the destination being promoted. Closing the reader also discards queued
speculation. Promoted jobs, shared active requests, valid cached responses and
stored fragments are not cancelled. Prefetch failures are silent and retryable.
Delayed prefetch timers on a detached/closed mobile panel do not restart work.

The queue keeps a copy of the API parameters, so caller mutation cannot put one
variant's response under another variant's key. Cleanup happens before settlement
and handles synchronous test responses, API exceptions, rejection details and
fallback to `action=parse` on older backends.

## Automated checks

`php tests/run.php` now includes process-based storage concurrency tests. They use
marked private temporary directories, real OS locks and pipe barriers, not a
production wiki or filesystem. A test-only namespaced gzip wrapper in a separate
worker pauses compression to verify that a concurrent purge completes before
compression resumes and that the prepared stale entry cannot be published.

The existing CI runs PHP standalone tests on 8.3 and 8.4. The MediaWiki 1.43.11
integration now runs on both 8.3 and 8.4. Translation validation and a dedicated
Chromium job run on every PR and main push. Chromium is installed using the CLI
from the exact Playwright version in the existing lockfile; no unpinned npx
package or production npm install is added. A missing browser fails the job.
The workflow is JSON-formatted YAML so it can use tabs for indentation.

New JavaScript tests cover slot limits, priority, promotion and shared promises,
cancellation, fallback, failures, synchronous responses and parameter snapshots.
A real Minerva browser test opens a queued neighbor while another prefetch is
still pending, then checks that the obsolete queued neighbor is never sent.

## Reproducible synthetic measurements

```sh
git show bc8a0af510fc3a6707946b105903908d29f4bd48:src/Reading/FragmentStore.php > /tmp/wd-baseline-store.php
git show bc8a0af510fc3a6707946b105903908d29f4bd48:modules/common/parse.js > /tmp/wd-baseline-parse.js
php tests/readingFragments/concurrencyBenchmark.php /tmp/wd-baseline-store.php
php tests/readingFragments/concurrencyBenchmark.php
node tests/pageSchedulingStudy.cjs /tmp/wd-baseline-parse.js
```

Raw results and source hashes: `tests/results/reading-concurrency-study.json`.
The storage test runs 80 reads per worker on one 612,000-byte synthetic fragment,
with one and four independent PHP CLI processes. Process startup and cache seeding
are outside the timing window; dispatch, reads and process completion are inside.
Two runs per version, each with three samples per concurrency, are performed in
before/after/after/before order. This primarily exercises decompression, JSON and
lock contention on a warm local filesystem, not SQL or HTTP.

The request study uses the actual JavaScript module, two simulated server slots,
60 ms service time and a click 5 ms after two neighboring prefetches. Eight
before/after pairs alternate order. The improved reader cancels one obsolete
queued neighbor, while the baseline sends all three requests. This deliberately
reproduces contention and does not estimate normal visitor latency or traffic.

Observed medians in this environment (six samples per storage scenario):

| Synthetic scenario | Baseline | New code |
| --- | ---: | ---: |
| One worker, 80 reads | 65.5 ms | 70.6 ms |
| Four workers, 320 reads total | 274.6 ms | 85.3 ms |
| Click behind speculative neighbors | 116.3 ms | 61.2 ms |

The single-worker run was slightly slower; no single-reader speedup is claimed.
The concurrent improvement reflects parallel decoding in this environment.
The queue study saves one obsolete speculative request in its chosen scenario.

Measurements are observations from this isolated environment, not performance
thresholds asserted by CI and not a promised production speedup. Physical mobile
devices, Semantic MediaWiki dependencies and real production load remain outside
this change. `__NOCACHE__` is deliberately unchanged.
