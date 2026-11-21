# Parent links, reading errors and non-creating cache invalidation

Target: MediaWiki 1.43. This patch does not change parser-cache eligibility,
revision visibility checks, the PageUpdater compare-and-swap guard, the fragment
cache lifetime, or the unbounded browser fragment cache.

## Automatic parent links

Child records are inspected separately. An explicit `page` is compared with the
new child's MediaWiki-normalized title; a matching displayed label on a different
page is not a duplicate. Label fallback is retained only for the legacy form
where the `page` parameter is absent, not explicitly empty. Duplicate named
parameters use their last occurrence, like MediaWiki.

`TemplateSyntax` finds literal template boundaries while masking comments and
nowiki blocks at the same byte offsets. It distinguishes nested templates,
triple-brace parameters and links. It does not expand templates. Ambiguous runs
of opening braces and unbalanced structures are refused rather than edited.
This is deliberately not a replacement for MediaWiki's complete preprocessor.

Only the effective target parameter is changed. Other values, whitespace,
comments and text following the template retain their original bytes. Existing
concurrent-edit protection and its bounded retry remain in place.

## Reading errors

A failed request replaces the previous argument with an explicit alert, a retry
button and a link to the full page. A successful response with no usable extract
has a separate message and does not offer to create a supposedly missing page.
Only the API's `missingtitle` error keeps that creation notice.

Retry preserves the reading chain without adding another browser-history entry.
It discards only the failed page's cached response, not other responses, stored
fragments or shared pending requests. The existing opening-sequence guard also
protects error rendering and detached retry buttons. Requests that complete after
a different navigation or panel closure cannot replace the current display.

New messages are translated in English and French, documented in qqq and included
in the generated ResourceLoader declarations. Notice text uses DOM textContent.

## Cache invalidation

Checking whether a page is known no longer creates its cache directory.
Invalidation opens an existing store and page directory without creating either.
Missing optional caches do not generate warning logs.

**Existing entries are still invalidated while fragment reads are disabled.**
Skipping these invalidations altogether could revive a stale entry after
re-enabling the feature. The disabled state is therefore not a promise of zero
filesystem calls: necessary invalidations remain, but unused cache directories
are not created.

Writers create a page directory before taking a generation snapshot. An
invalidation of an existing directory retains the lock files and advances the
generation, so a writer with an older snapshot cannot republish its result.
Lock concurrency and compression placement are unchanged by this patch.

## Regression checks

Standalone parent and invalidation checks, without a MediaWiki installation:

```sh
php tests/autoParentLinkSyntax.php
php tests/readingFragments/invalidation.php
```

The complete standalone suite also includes the fragment store/extractor tests
and requires the existing PHP XML/DOM, mbstring and intl dependencies:

```sh
php tests/run.php
php tools/buildI18n.php --check
python3 tests/checkI18n.py
npm ci --prefix tests --ignore-scripts
npm test --prefix tests
npm run test:browser --prefix tests
```

The browser suite uses an installed Chromium and exercises the actual Vector and
Minerva reading panels, including visible errors, stale-content replacement and
successful retry. It is not a physical-device or production-network test.

The same parent syntax/identity checks run against the real MediaWiki Title class
inside `tests/mediawikiParentCAS.php`. A real PageSaveComplete fixture verifies
that a child sharing another record's label is linked correctly even when the
parent contains comments and nowiki delimiters. The existing conflict test stays
in the suite. Run the integration only with the disposable laboratory described
in the main README, never by substituting a production database.

No SQL migration, npm installation on the production server, cache-policy change
or removal of `__NOCACHE__` is required. Normal ResourceLoader versioning handles
the JavaScript and message changes. Follow the site's normal PHP OPcache reload
procedure when deploying changed PHP files.
