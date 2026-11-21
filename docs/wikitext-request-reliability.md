# Wikitext requests: lifecycle, language isolation and mobile labels

Target: MediaWiki 1.43. Baseline: `2a956ff2a644898e446618f5099ee6d490179342`
(the squash merge of PR #13). This follow-up concerns the separate wikitext
POST pool used by hover information, additional content and recent changes.
The page-reading scheduler introduced in PR #13 is unchanged.

## Request lifecycle

An in-flight entry now owns its job and promise. It is registered before any
API dispatch and removed synchronously when that same job settles or is
cancelled. There is no later cleanup promise that can accidentally delete a
replacement created immediately after cancellation.

API construction, dispatch and response-validation exceptions settle the
consumer's promise and release their active slot exactly once. A pump guard
prevents synchronous jQuery callbacks from recursively draining the queue.
The existing two-active limit, hover priority, FIFO priority ties, native
Promise return type and legacy exports are retained. Cancellation affects only
queued jobs in the requested bucket with the requested text; active jobs and
unrelated consumers remain untouched.

A malformed successful response is rejected instead of being silently stored as
empty HTML. Valid empty text and the legacy `text['*']` wrapper are supported,
and HTML comments are still removed. API rejection values retain their existing
contract (the first failure argument). No new result cache is added for the
`latest` bucket; that bucket continues sharing pending work only.

## Language and failure memory

The user language is captured when the request is submitted, not when a queued
job eventually starts. Pending jobs, successful wikitext responses and failure
throttling are keyed by both text and language. Switching language during a
pending request cannot change that request's parameters or contaminate the other
language's cache. Queued cancellation removes all language variants of the same
bucket/text, as leaving a hover should not retain obsolete translations.

The existing 15-second failure throttle remains. Expired failure entries are
removed when the failure cache is consulted/updated, and at most 128 distinct
failure entries are retained. At the limit, the oldest failure may be retried
earlier; successful cached results are not evicted by failure traffic. This is
an entry-count bound, not a byte or heap-memory budget; no claim is made that
expired entries disappear without further requests.

The unbounded reader fragment cache and the 50-entry page-response cache are
unchanged, as are the existing per-bucket successful wikitext cache limits.

## Mobile sibling titles

Sibling labels are looked up with a static selector and literal `data-page`
comparisons. Backslashes and quotation marks in a page name cannot become CSS
escapes or change the selector. A missing/removed neighbor no longer inherits
the unrelated current argument's label: the preview can fall back to the actual
page name. The current label remains available for the actual current page.

## Validation

Fourteen new Node/jQuery/jsdom tests cover immediate cancellation and retry,
synchronous failures, queued and active language changes, language-specific
failure throttling, expiry, the failure-entry cap, malformed responses, empty
responses, legacy wrappers, priority, non-recursive pumping and literal labels.
Eleven fail against the unchanged baseline; all fourteen pass with this patch.
The other three check compatibility properties already satisfied by the baseline.

Two new Chromium tests use the actual Minerva swipe preview and real jQuery
callbacks. They check a quoted backslash page title and cache reuse across a
language switch. The existing fragment-retention test fixture now waits for the
expected DOM insertion instead of assuming two timer turns are sufficient.
Its assertions and cache-retention requirements are not relaxed.

```sh
npm ci --prefix tests --ignore-scripts
npm test --prefix tests
npm run test:browser --prefix tests
```

Local validation: 57 JavaScript tests and 28 Chromium tests pass, with none
skipped. Targeted PHP parent-link, invalidation and multiprocess concurrency
checks and the PHP/Python/JavaScript translation checks also pass. The local PHP
runtime lacks XML/DOM, intl and mbstring, so the complete PHP and MediaWiki
integration suites must be checked by the repository's existing six-job CI.
No physical iOS/Android test or production performance measurement is claimed.

No PHP production file, SQL schema, SMW setting, parser-cache eligibility,
revision-visibility check, cache lifetime or `__NOCACHE__` usage is changed.
This change requires neither a SQL migration nor npm installation in production.
