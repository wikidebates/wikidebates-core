# Migration to native PHP: behavioural baseline

The corrected pre-migration reference is core commit
`2439e0749bcdc9ab81aeb20a902f8b6845a744e4` (parent fixes #127 and #128).
Do not regenerate production renderers from Lua when the migration begins.

## Validation

The native PHP contract runs a single test driver against the pinned PHP
checkout and the candidate checkout in separate PHP processes, so that their
classes, autoloaders and message resources are not mixed. It compares per-case
and per-mode SHA-256 fingerprints of the exact renderer output and ordered
preprocessing, semantic, SEO and variable effects. Expected fixture failures
are distinguished from unexpected exceptions. The four historical Lua snapshots
are also compared byte-for-byte against that checkout.

The native contract complements, not replaces, the existing Lua differential,
SQL failure, native collection, integration, SEO, security, cache and browser
tests. A deliberate behaviour change needs its own review and explicit
regression assertion; never silence unexplained differences by moving the
baseline. The baseline checkout is immutable by commit SHA.

The 87 existing argument fixtures and 42 generated debate/argument fixtures
cover small and large inputs, language parameters, serialised references,
parent queries and empty pages. The isolated context implements historical
French/English fallback; passing all six language parameters does not establish
full six-wiki translation correctness. The real MediaWiki tests remain essential.

## Reproduce the isolated contract

From a Git checkout of the extension (fetch the baseline commit if absent):

```sh
git worktree add --detach /tmp/wd-native-baseline 2439e0749bcdc9ab81aeb20a902f8b6845a744e4
php debatephp/tests/nativeCorpus.php --root=/tmp/wd-native-baseline > /tmp/wd-native-before.json
php debatephp/tests/nativeCorpus.php > /tmp/wd-native-after.json
diff -u /tmp/wd-native-before.json /tmp/wd-native-after.json
```

## Isolated benchmark protocol

Run on the same PHP build and machine, with no competing benchmark processes.
Retain the full per-case samples; report medians, dispersion and outliers.
Compare the same inputs and mode, reject mismatching behaviour fingerprints,
and repeat in alternating checkout order if the difference is small.

```sh
for opcache in 0 1; do
	php -d opcache.enable_cli=$opcache debatephp/tests/nativeCorpus.php --root=/tmp/wd-native-baseline --warmup=3 --repeats=10 > /tmp/wd-before-$opcache.json
	php -d opcache.enable_cli=$opcache debatephp/tests/nativeCorpus.php --warmup=3 --repeats=10 > /tmp/wd-after-$opcache.json
done
```

The CI artifact preserves raw wall-clock nanoseconds, process CPU microseconds,
additional peak PHP memory (not RSS or allocation counts), simulated semantic
reads and writes, and preprocess counts. It records the PHP version and actual
CLI OPcache status. No performance threshold is inferred from noisy runner
samples and no isolated result is a production or SQL benchmark.

For full-stack validation, extend the existing `tests/integration/profile_render.php`
and comparison tools, then run with the exact MediaWiki 1.43.12, SMW and PHP 8.4
dependencies on a representative non-production wiki. Compare identical
database snapshots and content, separately record warm/cold conditions and
real SQL statements, and distinguish rendering from deferred writes.
Do not change the production caches or extension dependencies during this phase.

## Next gates

1. Validate the native contract on both PHP versions before retiring
   the renderer generation requirement and historical build entry points.
2. Refactor renderer functions with narrow reviewed changes and native
   arrays, preserving public IDs, null/false/zero and list ordering.
3. Migrate context interfaces, semantic adapters and variable handling with
   real MediaWiki integration and SQL error propagation tests.
4. Only after inventorying real wiki revisions and dependencies, consider
   disabling the legacy Lua extensions and update the farm pin.
