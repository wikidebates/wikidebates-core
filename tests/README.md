# Performance regression checks

From the repository root, with Node.js 18 or later:

```sh
npm ci --prefix tests --ignore-scripts
npm test --prefix tests
```

The JavaScript tests execute the actual modules with jQuery and jsdom. They cover
request sharing (including mobile sibling prefetch), response cache eviction,
retry after failure, unchanged page parse parameters and extraction boundaries,
coalesced content notifications, incremental form summary updates and synchronous
submission. jsdom does not measure real browser layout or server latency.

The progressive Minerva header also has real layout tests using an installed
Chromium executable (no browser download is performed by npm):

```sh
npm run test:browser --prefix tests
# If Chromium is installed elsewhere:
CHROMIUM_PATH=/path/to/chromium npm run test:browser --prefix tests
```

The browser tests cover continuous/reversible collapse, an idle title that
stops updating, short-page stability, single-line titles, viewport changes,
argument replacement and observer cleanup. Synthetic touch sequences also check
that vertical scrolling keeps control until release or cancellation, while fresh
horizontal gestures and vertical edge actions remain available. They execute the
actual reading UI and CSS in Chromium with fixture content; scrolling during the
synthetic sequences is set explicitly. Testing on physical iOS/Android devices
remains useful for native touch scrolling and browser-specific behavior.

`php/RecentDebateChangesTest.php` is an integration smoke test for MediaWiki 1.43.
Run it only on a **disposable SQLite wiki** whose database name ends in
`_performance_test`, without Semantic MediaWiki tables. It creates minimal SMW
fixtures, accounts and pages; never use a production database. Configure namespace
102 as `Attribut` (or `Property`) and use an in-process cache for the clock test:

```php
$wgMainCacheType = 'performance-test';
$wgObjectCaches['performance-test'] = [ 'class' => HashBagOStuff::class ];
$wgExtraNamespaces[102] = 'Attribut';
$wgExtraNamespaces[103] = 'Discussion_attribut';
```

With the extension available as `extensions/Wikidebates`, run from MediaWiki's root:

```sh
php maintenance/run.php "$PWD/extensions/Wikidebates/tests/php/RecentDebateChangesTest.php" --conf /path/to/disposable/LocalSettings.php
```

This checks the real SQL query against MediaWiki's tables, exclusion of Wikirobot
and ChatGPT before LIMIT, retention of other bot accounts and anonymous users,
60-second cache expiry using a simulated clock, uncached errors and parser-cache
freshness. It does not replace validation against a full Semantic MediaWiki site.

# Cache behavior

Argument reads keep `action=parse&page=...&prop=text`, without `section` or supplied
wikitext. MediaWiki can therefore reuse its page parser cache. Both skins share
an LRU cache of 50 full API responses and pending requests; the existing extracted
fragment cache remains in the reading core. Cache keys for API responses include
language, skin and other request parameters. This cache lasts for the current
page session, matching the previous mobile cache behavior.

Recent debate changes use the configured MediaWiki WAN cache for at most 60 seconds
when a working backend is available. No additional parser or browser result cache
extends that period. The currently displayed list is not automatically refreshed.
The explicit account exclusion replaces filtering by membership in the bot group.
