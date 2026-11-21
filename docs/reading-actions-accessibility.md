# Shared clipboard actions and keyboard navigation

Baseline: `e76e6b74ef620fd6b2621ef556695b4f7111f791` (PR #14).
Target: MediaWiki 1.43. The maintainer reports PHP 8.4 in production and
`NOCACHE` already removed from the Argument template as of 21 November 2026.
No production templates or server settings are changed or audited by this patch.
PHP 8.3 in CI remains an additional compatibility check, not a statement about
the production runtime or a recommendation to downgrade it.

## Clipboard

The Vector and Minerva adapters share `modules/reading/actions.js`, bundled in
the existing reading core ResourceLoader module. No new network dependency is
required. An explicit copy action invokes `navigator.clipboard.writeText` in
the user gesture; its Promise must resolve before a success notice is displayed.
Rejection, a missing API or a synchronous exception uses the existing legacy
copy mechanism, whose boolean result is now checked. The temporary textarea is
removed in a finally block, restoring focus and the previous input/document
selection without scrolling the viewport.

If neither method succeeds, the still-open action disclosure presents a labelled,
read-only URL field, selected for manual copying. It does not announce success.
The URL and label are inserted with DOM properties, not interpolated HTML.
Repeated activation during the same pending copy does not send another request.

Opening another argument or closing the reader invalidates the originating UI
operation. Late failures cannot focus a temporary field or create a notice in
the new argument. An old operation also cannot clear the pending state of a
new copy using the same persistent mobile toolbar. A clipboard write already
submitted to the browser cannot itself be cancelled by this UI guard.

## Keyboard and menu lifecycle

Parent navigation and copy commands are native buttons. Vector's action trigger
is now a button as well; Minerva already used one. Both skins expose a disclosure
with `aria-expanded` and `aria-controls` and ordinary links/buttons inside it.
This is not an ARIA menu widget and does not pretend to implement menu arrow-key
semantics. Enter/Space activation and Tab traversal use native browser behavior.

Escape closes the open action disclosure first, then the open mobile contents
sheet, then the reading panel, returning focus to the relevant trigger. An event
already handled by another component is respected. Opening/navigation moves
focus into the labelled reading region before replacing the old controls;
asynchronous page responses do not move it again. Closing restores the original
opener when it is still connected. No new modal focus trap is imposed on Vector's
split layout or on the existing mobile gestures.

The collapsed mobile contents sheet is inert and hidden from accessibility APIs;
its controls no longer remain in the tab order while off screen. Keyboard opening
focuses its first navigation control. Re-created adapter objects recover the
already-bound sheet methods, so Escape and history-back can close that sheet.

Action menus use one pair of delegated document handlers for outside clicks and
focus changes, instead of retaining a new document listener for every recreated
mobile header. Toggling Vector's already-open trigger now closes it instead of
having an earlier capture handler close it before the trigger reopens it.

Button reset/focus styles preserve the existing reading layout. The workflow,
cache eligibility, lifetime, invalidation, revision visibility, parsing queues
and unbounded browser fragment cache are unchanged.

## Checks

```sh
npm ci --prefix tests --ignore-scripts
npm test --prefix tests
npm run test:browser --prefix tests
php tools/buildI18n.php --check
python3 tests/checkI18n.py
node tests/i18n.js
```

Seventeen new Node/jsdom tests cover delayed success, rejected or unavailable
clipboard APIs, legacy failure/exception cleanup, selection restoration, literal
manual URL fields, duplicate clicks, late navigation/closure, reused toolbar
operations, menu toggling, Escape, focus restoration and document-listener counts.
Five new Chromium tests exercise native keyboard activation/Tab traversal,
clipboard completion/failure in both skins and mobile parent navigation.
Clipboard responses in these tests are controlled doubles; they do not certify
real browser/OS permission prompts or physical iOS/Android behavior. This is not
a complete screen-reader or WCAG conformance audit.

Production installation needs no database migration, npm installation, change
of PHP version or change of cache policy. Deploy through the normal extension
update process after reviewing the PR. There is no production timing benchmark
or claimed server speedup for this interface-focused change.
