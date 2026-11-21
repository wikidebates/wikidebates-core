# Audit read-only: SMW 7.3.1 and caches after removing `__NOCACHE__`

Scope: Wikidebates on MediaWiki 1.43, PHP 8.4, SMW 7.3.1 and
SemanticScribunto 2.3.3. The production Argument template has **already**
been changed by the site administrator; this work does not reintroduce
`__NOCACHE__` or change caching rules.

## What the isolated regression actually measures

`tests/fullstackSemanticBaseline.php` creates a temporary MediaWiki and SMW
schema (SQLite or a specifically opted-in disposable MariaDB Unix socket).
It uses the existing native PHP semantic query path, not the experimental
tracking code in PR #11. It checks the **direct** SMW results after:

- a parent query going from zero matches to one;
- a child changing from parent A to parent B;
- a matching child's page being renamed;
- the semantic relation being removed;
- the matching page being deleted.

After the edits, the test also *observes* the parser's cached HTML and the
private reading fragment cache. These observations are emitted as structured
JSON, including `safety` flags. A stale cached result deliberately produces
a clear `WARNING`; the test still completes as a diagnostic. A green CI job
means the audit ran and direct SMW updates succeeded. **It does not certify
fresh parser/fragment caches if `all_checked_layers_fresh` is false.**

The test records `_ASK` profile and SMW query-link counts for one custom native
query, a genuine `#argument:render` and a genuine `#debate:render`. No
experimental schema, triggers, cache invalidation or SMW dependency hooks are
installed. All test data is disposable and synthetic, not a copy of production.

## Reproduced finding in the isolated lab (2026-11-21)

With MediaWiki 1.43.11, PHP 8.4.28, SMW 7.3.1 and
SemanticScribunto 2.3.3, **both SQLite and MariaDB** showed the same result:

| Observation | SQLite | MariaDB |
| --- | --- | --- |
| Direct query sees the inserted parent | Yes | Yes |
| Native query page `_ASK` profiles | 0 | 0 |
| Native Argument `_ASK` profiles | 0 | 0 |
| Registered query-link IDs for these pages | 0 | 0 |
| Parser cache remains at 0 after 0 -> 1 | Yes | Yes |
| Reader fragment repeats old 0 after 0 -> 1 | Yes | Yes |
| Initial parser cache expiry (seconds) | 86400 | 86400 |

The lab explicitly enables SMW's query-dependency links, sets the profiler to
`0` and disables SMW's independent query-result cache, matching the previously
reported site configuration. An initial read returns the newly generated parser
output; a second read hits `fragment-cache`. After the *other* page is edited,
that fragment still gives the old result. This is a real reproduced **cache
freshness risk** in the current native-reader architecture; it is **not proof**
that every production page is stale or that an actual CDN response behaves the
same way.

The test also refreshes a query page itself while a matching relation exists,
then deletes the related page to check a meaningful 1 -> 0 transition. This
distinguishes a genuinely refreshed cached value from one that happened to
be 0 before and after the operation.

Do not deploy the unfinished mechanism in PR #11 based only on this finding.
It needs a staged, reversible evaluation with real model dependencies.

## Production source inventory (read-only)

After updating the extension, the following command scans at most 200 records
from namespaces 0 and 10 of the French wiki:

```sh
cd /home/users/webmaster/www/farm.wikidebates.org/w
php maintenance/run.php Wikidebates:AuditSemanticFreshness --wiki=fr --limit=200
```

Scan the English wiki separately:

```sh
php maintenance/run.php Wikidebates:AuditSemanticFreshness --wiki=en --limit=200
```

Useful options: `--summary-only`, `--namespaces=0`, `--ids=123,456` and
`--after-id=456` for pagination. Use a small limit on production first.
The script reads current page sources, stored `_ASK` values and query-link
identifiers, and prints JSON lines. It does **not** save, parse, purge, change
settings or consume jobs, and does not print source wikitext or semantic
values. The `resume_after` field identifies the next page boundary.

`argument_without_ask_profile` is a **risk signal**, not definitive proof
of stale HTML: the source marker may be inside an inactive template branch,
and some pages may depend on semantic queries indirectly. Conversely,
`ask_profiles > 0` does **not** prove that every query is tracked, and a
nonzero query-link count does not guarantee fresh CDN/browser responses.
The source-only `raw_nocache_marker` does not reveal magic words inherited
through templates. A newly created or not-yet-rebuilt page may legitimately
lack profiles pending SMW jobs. Replica lag can also affect observations.

## Decisions and remaining work

Do **not** automatically enable the unfinished semantic revision ledger of
PR #11 or change `ParserCacheType`, SMW settings, HTML file caches, or the
reader's fragments based only on a green workflow. If the observed freshness
flags fail, investigate SMW dependency registration for native PHP queries and
the invalidation boundary *before* changing production caching.

Only a staging copy with the site's actual French/English templates,
SemanticScribunto/SMW versions, page relationships, real file caches,
Cloudflare and multiple PHP workers can determine whether every relevant
layer remains fresh in the deployment. This diagnostic cannot perform that
verification against the live server.
