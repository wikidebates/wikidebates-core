# Migration to native PHP: behavioural baseline

The corrected pre-migration reference is core commit
`2439e0749bcdc9ab81aeb20a902f8b6845a744e4` (parent fixes #127 and #128).
Production renderers are now hand-maintained PHP. Do not regenerate them from Lua.

## Historical generator archive

The last complete pre-retirement tree is commit
`8556c6a12c1f649edaeff5284c8a8488c6693431`.
It preserves the 15 translation/rewriting scripts, the former byte-for-byte
regeneration check and the three compiler-specific test files. To study or
reproduce those historical tests (requires `luaparser` and `lupa`), use a
separate detached worktree of that commit, never the current production tree.
The older optimization reports describe that historical workflow, not current
maintenance instructions.

`python3 debatephp/tests/regenerate.py` now runs the native PHP contract and
checks all four Lua snapshots without rewriting PHP. The legacy filename is
kept for callers. The generator-only tests of AST conditions and closure
captures remain reproducible in the archived worktree; the native rendering
contract and existing runtime tests govern the current implementation.
Translation compilation and Lua differential oracles are not renderer generators
and are intentionally retained.

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

The unused `allDebates` computation has been removed. The only allowed
behaviour difference is one exact historical SMW read in each of the four
fixtures `parents`, `inherited`, `fallback-parents` and `both-parent-types`,
in each renderer mode. `nativeContract.py` enumerates the exact requests,
checks that each occurs once in the pinned baseline, and compares the remaining
ordered read trace and every other effect. Candidate reads and timed read
counts are recorded without filtering. The live breadcrumb selection, direct
parent reads, semantic writes, HTML and JSON-LD remain in the strict contract.
This removes a query and its failure opportunity; it is not a change to the
error propagation policy for the remaining queries.

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
python3 debatephp/tests/regenerate.py --baseline-root=/tmp/wd-native-baseline
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

1. Native contract validated on PHP 8.3 and 8.4 in PR #130. Retire the
   historical build entry points while keeping the native contract in CI.
2. Refactor renderer functions with narrow reviewed changes and native
   arrays, preserving public IDs, null/false/zero and list ordering.
3. Migrate context interfaces, semantic adapters and variable handling with
   real MediaWiki integration and SQL error propagation tests.
4. Only after inventorying real wiki revisions and dependencies, consider
   disabling the legacy Lua extensions and update the farm pin.
