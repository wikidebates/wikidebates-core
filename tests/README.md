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

The fixed Minerva toolbar and scrolling heading have real layout tests using an installed
Chromium executable (no browser download is performed by npm):

```sh
npm run test:browser --prefix tests
# If Chromium is installed elsewhere:
CHROMIUM_PATH=/path/to/chromium npm run test:browser --prefix tests
```

The Minerva toolbar is a constant-height 56 CSS pixel overlay. It reserves no empty
strip: the complete, unclipped `h1` starts at the top of the scrollable content,
between the close and menu buttons. Both buttons have 44 by 44 CSS pixel touch
targets, share a vertical center and remain available when the toolbar background
is transparent. The full heading uses the compact title's font size and first-line
position; only the full heading wraps. The toolbar background, separator and
compact, ellipsized duplicate title fade in over 32 scroll pixels after the full heading leaves the viewport;
scrolling back reverses the fade. The duplicate is hidden from accessibility tools.
No heading height or scroll position is adjusted, and the decorative overlay lets
touch input reach the reading content underneath.

Browser assertions track an actual line of reading text: its viewport movement
plus the change in native `scrollTop` must remain within 0.25 CSS pixels. They also
check constant toolbar/title heights, viewport origin and scroll range, slow
reversals, idle behavior, very long/unbroken headings, non-scrolling and short pages
(including a partially faded title at the bottom), resize, argument replacement,
observer cleanup, loading, toolbar actions, sibling previews/navigation, parent
navigation, the contents sheet and an unchanged Vector header. Toolbar checks also
cover transparency, hit testing and button/icon alignment at multiple viewport and
font sizes, including a late, important wiki-wide heading rule reproducing the
reported 24.3px full title versus 18px compact title. Sibling previews include the
same full heading, without a duplicate toolbar reserving space above it.

Synthetic touch sequences explicitly set scrolling to cover tiny position changes,
scroll-event ordering, cancellation of an already prepared sideways preview and
release/cancellation. They preserve the vertical-scroll lock until finger release,
including direction changes and reaching an edge. A separate CDP
`Input.dispatchTouchEvent` sequence drives native Chromium touch scrolling through
the fade, reverses direction with the finger down, then moves sideways. It samples
rendered frames to verify native scrolling, a constant toolbar, no extra line
movement and no lateral preview/navigation. The test never sets `scrollTop` during
that real browser gesture (only its initial position). Another native touch test
scrolls away from the top, reverses all the way back to it, then moves far enough
sideways to trigger horizontal navigation without the scroll lock. It checks both
directions, starting at and below the top, while the finger stays down: no lateral
preview, parent pull, navigation or extra line movement is allowed.

These automated checks use the real reading UI/CSS and fixture content; they do
not replace a physical phone. Before production deployment, check slow and diagonal
drags, reversals, momentum, browser edge bounce, expanding/collapsing browser chrome,
portrait/landscape and large system text on iOS Safari and Android browsers. Include
long titles, short arguments, parent/sibling navigation, actions and the contents
sheet. No physical-device result is claimed by the automated suite.

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
