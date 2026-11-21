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

## Minerva HTML: observable baseline without semantic tracking

The isolated real-HTTP suite `tests/fullstackSemanticHttp.php` now includes
a separate, synthetic Minerva query page observed with
`WikidebatesSemanticCacheTracking=false` in its HTTP workers, matching the
current opt-in default. A direct SMW query must see a newly added relation,
while `wikidebatesSemanticCachePurge` is deliberately held in the disposable
lab. The test records the first and second `X-Minerva-File-Cache` states,
the state after the external edit, and whether the full HTML still renders the
old value.

The HTTP lab normally adds an `X-WD-Semantic-Lab-Worker` response
header to identify its PHP workers. That unknown header rightly makes
MinervaFileCache return `BYPASS; reason=header-x-wd-semantic-lab-worker`,
which would invalidate a full-page cache test. The explicitly marked
file-cache probe therefore suppresses **only that test-generated response
header** and requires a genuine `HIT` on its second untracked mobile
request. The tracked mobile tests likewise require the precise
`BYPASS; reason=extension-veto` signal, not merely any bypass.

The `untracked_minerva_semantic_baseline` JSON section reports
`stale_minerva_hit_observed` and `stale_full_html_observed`. The second
indicator also catches stale parser-produced HTML after a MISS or BYPASS.
A stale result produces an explicit **WARNING** but does not fail the
diagnostic suite: the purpose is to reveal the current baseline, **not to
pretend that the disabled-by-default experimental tracking is active**.
Conversely, a fresh result in the disposable lab does not prove that every
SMW query or production Minerva variant remains fresh.

This additional experiment neither edits the production wiki nor changes
the runtime caching policies. The test-side semantic writer still exercises
the experimental code; only the independent HTTP readers disable it. This
is therefore a comparative diagnostic, not a perfect simulation of a
production deployment that never enabled tracking.

The existing active-tracking HTTP suite separately verifies that query-owning
pages bypass the complete Minerva file cache and display new relations even
while a targeted semantic purge remains queued. This is intentionally distinct
from verifying the disabled-tracking production baseline.

## Real native Argument migration: no _ASK, then a remote parent edit

The isolated HTTP suite now includes a test modeled on production page ID
1220, using only synthetic pages and a disposable SQLite or MariaDB store:

1. With the writer temporarily in **tracking OFF** mode, create a genuine
   native `#argument:render` page and verify its SMW subject has **no**
   `_ASK` profile. An unrelated genuine `#debate:render` page also starts
   without `_ASK`.
2. With tracking OFF in independent HTTP workers, confirm the Argument
   has a real Minerva file **HIT** showing an initially empty parent
   query. No other page is edited between warming the first two views.
3. Add a matching semantic parent relation on a **different page**;
   hold experimental `wikidebatesSemanticCachePurge` jobs. Verify that
   direct SMW sees one new result, while the Argument revision and
   `page_touched` are unchanged. Revisit with tracking OFF to
   demonstrate a stale Minerva **HIT** in the known-unprotected baseline.
4. Enable the **opt-in tracking mode only in the disposable HTTP
   workers**, without clearing the old Minerva file. A genuine mobile
   `BYPASS; reason=extension-veto` must prevent reuse of the old HTML;
   the full page must show the new semantic relation and the actual
   Argument renderer's new parent link. A second tracked request must
   remain fresh. Also exercise its separate reading API.
5. Confirm that a genuine debate-only mobile page still obtains a
   **MISS followed by HIT** with tracking enabled. This guards against
   mistakenly disabling Minerva's file cache globally.

The new `unprofiled_native_argument_migration` section of the CI JSON
report records observed cache states and post-render `_ASK` counts.
Unlike the **baseline warning** (expected staleness with tracking OFF),
the protection and debate-HIT requirements are **assertions that fail
CI** when broken. This protects the *freshness* and *selectivity* boundary
without enabling the experimental feature in production.

This is not a full deployment certification: it does not reproduce the
live site's complete template set, CDN rules, every semantic query,
concurrent external edits, or long-running migration/rebuild behavior.
The existing launch checklist and rollback safeguards still apply.

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

### Corrections to profile/dependency inventory

SMW 7.3.1 resolves _ASK property reads on redirect titles to the
**canonical target**. Therefore a redirect may appear to own several
_ASK profiles and exactly the same query IDs as its target. The audit
now reads `page_is_redirect`, skips those titles from semantic-page
counters, reports `redirects_skipped`, and (when not in summary mode)
prints `type=redirect` records instead. `scanned` still includes
redirects so its pagination cursor remains stable.

The former `query_links` field was **not a count of SQL dependency
edges**. It is the number of query ID references obtained by
`findEmbeddedQueryIdListBySubject()`, which reads _ASK profiles only.
This legacy field is retained for compatibility but must not be used
to certify actual registered links.

Each nonredirect page now also counts the actual SQL
`smw_query_links` rows where `s_id` matches its query IDs, using
one grouped read per profiled page. The new summary fields are:

- `query_ids`: number of resolvable, distinct positive SMW query IDs;
- `dependency_rows`: sum of actual registered SQL edges;
- `query_ids_without_dependency_rows`: count of query IDs with zero
  SQL edges;
- `pages_with_incomplete_dependencies`: number of pages with a
  profile but no usable query IDs, or with at least one ID missing edges;
- `profile_without_query_links`: now checks **actual** total SQL
  edges, rather than only whether an _ASK query ID was returned.

The corresponding page records expose `query_ids`,
`dependency_rows` and `query_ids_without_dependency_rows`.
A page with no _ASK profiles (for example an actual native PHP
Argument) still has zero recorded query IDs: its
`argument_without_ask_profile` signal remains crucial. **Zero
`query_ids_without_dependency_rows` is not a guarantee of freshness**:
existing edges can be incomplete or stale, and this audit does not
compare live semantic relations against rendered HTML, fragment or
Minerva caches.

Verifying edges performs extra **read-only SMW SQL queries**. Use bounded
`--limit` and `--after-id` scans on live wikis; no pages, caches,
jobs or data are altered. The disposable CLI regression covers
redirect exclusion and a synthetic loss of SQL edges.

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
