# Native PHP validation and benchmark protocol

Production renderers are hand-maintained PHP. The corrected pre-migration
reference is `2439e0749bcdc9ab81aeb20a902f8b6845a744e4`. Historical tools,
the exact four reviewed redundant query removals, and the provenance of the
migration remain available in Git. The last complete tree with the historical
comparison driver is [Core 6df94238](https://github.com/wikidebates/wikidebates-core/tree/6df94238bdf2e5567b3f79c2ec9de1c63bd867ef).
Older optimization reports describe historical measurements and are not current
maintenance instructions. To reproduce them, use their matching historical
checkout; do not restore the old implementation in the current source.

## Current frozen contracts

```sh
php tests/run.php
python3 debatephp/tests/checkNativeCorpus.py
python3 debatephp/tests/argument_contract.py php
python3 debatephp/tests/serialized_contract.py php
python3 debatephp/tests/i18n_contract.py php
php debatephp/tools/nativeInventory.php --assert-native
```

The 129-case isolated renderer contract (105 Argument, 24 Debate) stores 13
SHA-256 blocks, with exact output and ordered preprocessing, semantic, SEO and
variable effects, including the complete ordered query traces. The reference
was captured only after the pinned independent historical comparison passed
on PHP 8.3 and 8.4, in Core #183. The fixture records that provenance.
Core #181 similarly froze 10,500 deterministic boundary cases in 105 blocks.
Neither verifier regenerates expectations. An intentional behavior change
requires a reviewed regression assertion and a separately justified reference
update; never replace a failing fixture merely to make CI pass.

The fullstack contract on MediaWiki 1.43.12 / SMW 7.4.1 validates real rendered
output, persisted semantic state, typed reads and Schema.org independently of
the synthetic renderer context. It retains the original 144 render cases.
The native ArgumentMap and cache suites also exercise real SMW. Retain the
inert historical content handler for old wiki revisions. See
[the current-slot audit](../../docs/lua-retirement-audit.md).

## Current isolated performance measurements

Use the same PHP build and machine with no competing benchmark processes.
Record raw wall-clock nanoseconds, CPU microseconds, additional peak PHP
memory (not RSS), simulated semantic read/write counts and preprocess counts.
The absolute benchmark validates the same frozen fingerprints before reporting
sums of per-case medians and median per-case dispersion.

```sh
mkdir -p /tmp/wd-native-benchmarks
for opcache in 0 1; do
	php -d opcache.enable_cli=$opcache debatephp/tests/nativeCorpus.php --warmup=3 --repeats=10 > /tmp/wd-native-benchmarks/native-opcache-$opcache.json
done
python3 debatephp/tests/summarizeNativeBenchmark.py /tmp/wd-native-benchmarks
```

For paired measurements, use a second modern native checkout:

```sh
git worktree add --detach /tmp/wd-native-base 6df94238bdf2e5567b3f79c2ec9de1c63bd867ef
python3 debatephp/tests/benchmarkNativeContexts.py --base-root=/tmp/wd-native-base --output=/tmp/wd-native-context-benchmarks
```

The current driver compares the two native revisions under two test contexts:
`native` (the frozen trace) and `native-schema` (production schema filtering
without actual parser enrichment or strip insertion). Compare each context only
with itself across revisions. The first three old context labels were identical
for modern source and are consolidated. Both OPcache settings are verified;
checkout order alternates. The comparison rejects changes to the output/effect
fingerprints and ordered read trace. No noisy timing threshold is imposed.

The synthetic provider cannot certify complete empty parent unions and does
not measure live SQL, HTTP, cache or parser latency. Production comparisons
require representative identical content/database snapshots, cold/warm
conditions, real query counts and separation of rendering from deferred writes.
Historical gains remain documented in the earlier benchmark reports, but
CI no longer fetches and executes the pre-migration source.
