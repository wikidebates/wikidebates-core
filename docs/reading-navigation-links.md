# Reading navigation: per-occurrence action links and literal percent titles

Baseline: `5fc7d99dcd0eca98c8e019388e3bf94ba2269905` (PR #15).
Target: MediaWiki 1.43, with PHP 8.4 in production. This change implements only
the approved parent-action context and URL-title decoding fixes. It does not
change error recovery for additional content/recent changes, hover caches,
NOCACHE, server caches, semantic settings or any production PHP file.

## Action context

Every argument occurrence in the reading chain carries its own displayed-title
edit URL and original detail URL. They are captured from the source DOM, including
ancestors of a directly opened nested argument. A sibling retains the current
parent list's edit context but uses its own detail destination. There is no global
page-to-edit-URL map: one argument can occur in multiple different parent lists.

All openings synchronize the shared action state, panel data and existing header
links before pushing browser history or dispatching a load. Returning to a parent,
retrying and restoring history therefore cannot copy/open the former child's URL.
A retained detail URL must still resolve to the requested page; otherwise the
canonical URL is generated. The underlying render-request and clipboard sequence
guards continue rejecting stale UI updates.

History version 3 snapshots the context on each chain entry. Version 2 history
can still restore its current entry's old top-level context if the stored detail
destination matches that page. Unknown ancestor edit context is not guessed: it
falls back to the existing `#` placeholder, never the previous child's editor.
A fresh navigation from the debate captures the full context. Reloading open tabs
after deployment is recommended to leave old JavaScript and history behind.

## Title decoding

The `title` query parameter returned by URLSearchParams is already decoded.
The redundant decodeURIComponent call is removed only on that branch. The path
branch still decodes once. Underscores still become spaces, query `+` still means
space and encoded `%2B` remains a literal plus. Literal percent sequences such as
`%2F` are no longer decoded a second time into a different title.

## Regression coverage

Ten Node/jQuery/jsdom tests cover both skins, nested DOM openings, parent/sibling
navigation, real back/forward traversal, different occurrences of one page,
legacy history, immediate action updates while loading, clipboard destinations,
retry and late responses, percent titles, accents, plus signs and path handling.
Eight fail on the unchanged baseline; two assert pre-existing URL compatibility.

Two Chromium tests add the full parent/copy/back/forward scenario using a local
HTTP fixture and real URL/history APIs, including the percent-title opening.
No production service is contacted. Clipboard completion and API replies remain
controlled test doubles. An older toolbar-only test supplies its artificial URLs
after opening instead of relying on obsolete global state before navigation;
its original action, scrolling and closure assertions are retained.

```sh
npm ci --prefix tests --ignore-scripts
npm test --prefix tests
npm run test:browser --prefix tests
```

A browser that blocks all HTTP navigation cannot execute the two localhost
fixtures; the GitHub Actions browser job must confirm them before merge. PHP 8.4
integration and the existing compatibility checks run in the normal CI.

No SQL migration, Composer update, production npm installation or cache-policy
change is required. Deploy the complete merged extension, not individual files.
In a MediaWiki farm, also commit the `extensions/Wikidebates` gitlink after the
server checkout is verified; updating the extension alone does not update the
parent repository's recorded revision. Do not update unrelated submodules.
