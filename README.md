# Wikidebates

Wikidebates integrates the complete imported DebatePHP 0.6.0 sources into one
MediaWiki extension. The existing reading interfaces, recent contributions,
parent linking and authentication hooks remain registered. DebatePHP lives in
`debatephp/` as an internal component; no second extension needs to be loaded.

Requirements: MediaWiki 1.43 or later, PHP 8.1 or later, and Scribunto. The
integration suite targets MediaWiki 1.43. The registered parser functions are
`#debate:render`, `#argument:render` and `#recentdebatechanges`.

The DebatePHP renderers retain their integrations with Semantic MediaWiki and
SemanticScribunto, WikiSEO, Variables/VariablesLua, ShortDescription and Page
Forms. These extensions remain necessary for the corresponding semantic,
metadata, shared variable and form features. The bundled `htmltag` implementation
preserves an existing HTML Tags hook when one is already registered.

## Configuration

The original configuration names are retained. Existing values continue to work.

| Setting | Default | Purpose |
| --- | --- | --- |
| `$wgDebatePHPMode` | `reference` | Choose `reference` or `optimized` for both renderers. |
| `$wgDebatePHPProfiling` | `false` | Per-invocation timing on the `DebatePHP` debug channel. |
| `$wgDebatePHPDiagnostics` | `false` | Integration diagnostics on the same channel. |
| `$wgDebatePHPHTMLAttributes` | `null` | Override the HTML Tags attribute policy. |

A null attribute policy uses `$wgHTMLTagsAttributes` when defined, otherwise the
bundled policy for `a` and `script`. Both modes use native PHP rendering and
translations. `optimized` retains expansion order and only changes the imported
pure string/table operations and invocation-local lookup caches. An unknown mode
is rejected.

The wiki pages `Module:Debate`, `Module:Argument`, `Module:WD/I18N` and
`Module:WD/I18N/Data` must match the snapshots in `debatephp/resources/`. Source
checks and parser dependencies are deliberately retained in both modes. The
French template snapshots in that directory demonstrate the parser-function
calls; installing this extension does not write or replace wiki pages.

Do not load the former standalone DebatePHP extension alongside Wikidebates.
This repository does not modify a site's `LocalSettings.php`, migrate wiki
content, or perform a deployment.

## Tests

Run the independent translation, synchronization, escaping and telemetry checks:

```sh
php tests/run.php
```

Run the actual MediaWiki parser against both modes using an independent checkout
with its matching `vendor/` dependencies and `extensions/Scribunto/`:

```sh
MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawiki.php
```

The harness creates its own temporary SQLite database and uses
`MW_CONFIG_CALLBACK`; it never reads or writes `LocalSettings.php` or connects
to an installed wiki. PHP's PDO SQLite extension is required in addition to
MediaWiki's normal PHP extensions. The temporary database is removed on exit.

To include installed optional extensions in the isolated tests:

```sh
WD_TEST_EXTENSIONS=PageForms,ShortDescription,WikiSEO,Variables MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawiki.php
```

The parser tests compare HTML, page properties, categories and template/module
dependencies. Cases cover French/English debate and argument pages, the supplied
French templates, references, quotes, argument maps, warnings, initialization,
Unicode, zero values and parameter delimiters. They also exercise existing
parser hooks, HTML Tags coexistence and policies, invalid modes and source
guards. Optional Page Forms checks verify button generation without editing a
page; ShortDescription checks verify its page property, and WikiSEO checks
verify its pre-add hook and page properties. Semantic MediaWiki and VariablesLua
still require validation with the versions and data installed by the consuming
wiki.

The source import in commit `1ee0cfe` contained no upstream tests or revision
manifest. The tests here were added for this integration; they do not claim to
reconstruct the absent upstream test suite or establish Lua/PHP equivalence.

## Translation synchronization

`debatephp/resources/I18nData.php` contains the complete imported native
translation table. Regenerate it offline from reviewed snapshots with:

```sh
php tools/compileI18n.php debatephp/resources/WD-I18N-Data.snapshot.lua debatephp/resources/WD-I18N.reference.lua > /tmp/I18nData.php
```

Review the generated file and rerun the tests before replacing the bundled table.
The compiler reads literal Lua data and never executes Lua or edits wiki pages.
Updating the module logic also requires reviewing its PHP implementation; source
hash updates alone do not establish equivalent behavior.

## Attribution and licenses

The combined extension is distributed under GPL-3.0-or-later because its
DebatePHP component is GPL-3.0-or-later. Existing Wikidebates sources retain their
original MIT licensing. Original Lua/template snapshots retain their own
licenses. See [the retained DebatePHP notice](debatephp/NOTICE) and
[GPLv3 license](debatephp/LICENSE). The imported notice refers to upstream
documentation and `tests/fixtures/revisions.json` that were not included in the
source import; those historical revision identifiers have not been invented.
