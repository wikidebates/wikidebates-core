<?php

use MediaWiki\Cache\CacheKeyHelper;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Wikidebates\Reading\AnonymousCacheSafety;
use MediaWiki\Extension\Wikidebates\Reading\VectorCacheEligibility;
use MediaWiki\Debug\MWDebug;
use MediaWiki\MainConfigNames;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\ParserOutputFlags;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\Title\Title;
use Wikimedia\AtEase\AtEase;

require_once (getenv('MW_INSTALL_PATH') ?: dirname(__DIR__, 3)) . '/maintenance/Maintenance.php';

/**
 * Write the genuine native Vector ?action=view HTML cache for exactly one page.
 * Unlike rebuildFileCache.php, never render or write ?action=history.
 */
class PrepareVectorViewCache extends Maintenance {
	private bool $enabled = true;

	public function __construct() {
		parent::__construct();
		$this->addDescription('Prepare one eligible anonymous native Vector view file without history HTML.');
		$this->requireExtension('Wikidebates');
		$this->addOption('page-id', 'Required positive MediaWiki page_id.', true, true);
		$this->addOption('all', 'Permit non-content namespaces if the native cache policy allows them.');
		$this->addOption('dry-run', 'Check eligibility without creating or modifying the HTML file.');
		$this->addOption('force-view',
			'Explicitly rebuild an eligible native ?action=view file even when already valid.');
		$this->addOption('existing-only',
			'With --force-view, skip pages that have no native view file (one-time migration).');
		$this->addOption('repair-missing-toc',
			'For Vector 2022, replace a valid prewarmed view only if its expected sidebar TOC is missing.');
		$this->addOption('json', 'Print a machine-readable status report.');
	}

	public function finalSetup(SettingsBuilder $settingsBuilder) {
		$this->enabled = (bool)$settingsBuilder->getConfig()->get(MainConfigNames::UseFileCache);
		// The maintenance process captures native HTML explicitly. Avoid reading
		// existing file-cache HTML or writing files as a side effect of Article::view.
		$settingsBuilder->putConfigValue(MainConfigNames::UseFileCache, false);
		// Maintenance::finalSetup runs before MediaWiki services are ready.
		// Apply read-only mode and disable debug output in execute().
		parent::finalSetup($settingsBuilder);
	}

	private function report(int $pageId, string $state, array $extra = []): void {
		$data = ['pageId' => $pageId, 'state' => $state] + $extra;
		if ($this->hasOption('json')) {
			$this->output(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
		} else {
			$this->output('Vector view page_id=' . $pageId . ': ' . $state .
				(isset($extra['bytes']) ? ' bytes=' . $extra['bytes'] : '') . "\n");
		}
	}

	public function execute() {
		// MediaWiki initialization has completed; service access is now safe.
		$this->getServiceContainer()->getReadOnlyMode()->setReason('Preparing anonymous Vector file cache');
		MWDebug::deinit();
		if (!$this->enabled) {
			$this->fatalError('The native Vector cache must be enabled ($wgUseFileCache).');
		}
		$rawId = (string)$this->getOption('page-id', '');
		if ($rawId === '' || !ctype_digit($rawId) || (int)$rawId < 1 ||
			(string)(int)$rawId !== ltrim($rawId, '0')) {
			$this->fatalError('Invalid --page-id (expected a positive decimal page ID).');
		}
		$id = (int)$rawId;
		$services = $this->getServiceContainer();
		$config = $services->getMainConfig();
		$skinName = (string)$config->get('DefaultSkin');
		if (!in_array($skinName, ['vector', 'vector-2022'], true)) {
			$this->fatalError('The native shared HTML cache requires a Vector default skin.');
		}
		// A targeted page ID must resolve to the current title on PRIMARY;
		// replica lag after a move could otherwise generate HTML for an old URL.
		$dbr = $services->getConnectionProvider()->getPrimaryDatabase();
		$row = $dbr->selectRow('page',
			['page_id', 'page_namespace', 'page_title', 'page_is_redirect', 'page_latest', 'page_touched'],
			['page_id' => $id], __METHOD__);
		if (!$row) {
			$this->report($id, 'MISSING');
			return true;
		}
		$title = Title::makeTitleSafe((int)$row->page_namespace, (string)$row->page_title);
		if (!$title || (int)$row->page_is_redirect !== 0) {
			$this->report($id, 'SKIPPED_REDIRECT_OR_INVALID');
			return true;
		}
		if (!$this->hasOption('all') &&
			!in_array($title->getNamespace(), $services->getNamespaceInfo()->getContentNamespaces(), true)) {
			$this->report($id, 'SKIPPED_NONCONTENT');
			return true;
		}

		$publicIdentity = AnonymousCacheSafety::fingerprint($id);
		if ($publicIdentity === null) {
			$this->report($id, 'SKIPPED_NONPUBLIC');
			return true;
		}

		// Semantic MediaWiki replaces Article on Property/Concept pages and
		// adds live semantic listings. These are not safe to snapshot using
		// the generic Vector renderer: they may yield a short redirect/error
		// document instead of complete view HTML, or stale semantic results.
		// Exclude only the SMW special handlers, not ordinary content pages.
		if (VectorCacheEligibility::hasSmwSpecialArticle($title->getNamespace())) {
			$this->report($id, 'SKIPPED_SMW_ARTICLE');
			return true;
		}

		$context = new RequestContext();
		$context->setTitle($title);
		// RequestContext::setSkin() CLONES the Skin. On MediaWiki 1.43
		// the clone retains a component registry bound to the ORIGINAL
		// Skin, whose OutputPage has no parsed TOC. The cached Vector
		// 2022 HTML then omits #mw-panel-toc even with section headings.
		// Let this request build its own Skin, binding its components to
		// the same OutputPage used by Article::view().
		$actualSkin = $context->getSkin();
		if ($actualSkin->getSkinName() !== $skinName) {
			$this->fatalError('The isolated prewarm context did not select the configured Vector skin.');
		}
		$article = Article::newFromTitle($title, $context);
		$context->setWikiPage($article->getPage());
		RequestContext::getMain()->setTitle($title);
		if (!$article->isFileCacheable(HTMLFileCache::MODE_REBUILD)) {
			// A public page may still be vetoed by semantic freshness or another
			// HTMLFileCache hook. Preserve every veto without calling it nonpublic.
			$this->report($id, 'SKIPPED_CACHE_POLICY');
			return true;
		}
		// MediaWiki's native HTMLFileCache stores the URL-encoded full
		// page key in a single filename component. Very long/multibyte
		// titles can exceed Linux NAME_MAX even when MediaWiki accepts
		// the title. Other caches (Minerva, Reading) remain eligible.
		if (VectorCacheEligibility::nativeViewFilenameTooLong(
			CacheKeyHelper::getKeyForPage($title), (bool)$config->get(MainConfigNames::UseGzip)
		)) {
			$this->report($id, 'SKIPPED_FILENAME_TOO_LONG');
			return true;
		}
		$view = new HTMLFileCache($title, 'view');
		$touched = (string)$row->page_touched;
		$forceView = $this->hasOption('force-view');
		$existingOnly = $this->hasOption('existing-only');
		if ($existingOnly && !$forceView) {
			$this->fatalError('--existing-only requires --force-view.');
		}
		if ($forceView && $this->hasOption('repair-missing-toc')) {
			$this->fatalError('--force-view and --repair-missing-toc are mutually exclusive.');
		}
		$exists = $view->isCached();
		$valid = $view->isCacheGood($touched);
		if ($forceView && $existingOnly && !$exists) {
			$this->report($id, 'SKIPPED_MISSING_VIEW');
			return true;
		}
		$repairToc = false;
		if ($valid && $this->hasOption('repair-missing-toc') && $skinName === 'vector-2022') {
			// Native HTML prepared by an earlier Skin clone could contain the
			// actual page body but omit the Vector 2022 sidebar TOC. Do not
			// rebuild a truly TOC-less page; ask ParserOutput whether the
			// current public revision has a real table of contents.
			$existing = $view->fetchText();
			if (str_contains($existing, 'vector-toc-not-available') &&
				!str_contains($existing, 'id="mw-panel-toc"')) {
				$parsed = $article->getParserOutput();
				if ($parsed instanceof ParserOutput) {
					$toc = $parsed->getTOCData();
					$repairToc = $toc !== null && count($toc->getSections()) > 0 &&
						!$parsed->getOutputFlag(ParserOutputFlags::NO_TOC);
				}
			}
		}
		if ($valid && !$repairToc && !$forceView) {
			$this->report($id, 'REUSED');
			return true;
		}
		if ($this->hasOption('dry-run')) {
			$this->report($id, $forceView && $exists ? 'WOULD_REBUILD_VIEW' :
				($repairToc ? 'MISSING_TOC' : 'MISSING_FILE'));
			return true;
		}

		// Match the safe native rebuild technique, but capture only action=view.
		$_SERVER['HTTP_ACCEPT_ENCODING'] = 'bgzip';
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
		AtEase::suppressWarnings();
		ob_start();
		try {
			$article->view();
			$context->getOutput()->output();
			$context->getOutput()->clearHTML();
			$html = ob_get_clean();
		} catch (\Throwable $e) {
			ob_end_clean();
			throw $e;
		} finally {
			AtEase::restoreWarnings();
		}
		if (!is_string($html) || strlen($html) < 512) {
			$this->report($id, 'REJECTED_SHORT_OUTPUT');
			return false;
		}
		// The page may have been hidden, moved or restricted while rendering.
		// Do not publish that output just because page_latest stayed constant.
		if (AnonymousCacheSafety::fingerprint($id) !== $publicIdentity) {
			$this->report($id, 'RETRY_PUBLIC_IDENTITY_CHANGED');
			return false;
		}
		// If the current renderer still cannot supply the expected TOC,
		// preserve the previously valid file rather than publish a broken
		// replacement or delete it. The next bounded scan may retry.
		if ($repairToc && (!str_contains($html, 'id="mw-panel-toc"') ||
			!str_contains($html, 'vector-toc-available'))) {
			$this->report($id, 'RETRY_MISSING_TOC');
			return false;
		}
		$view->saveToFileCache($html);
		$latest = $dbr->selectRow('page', ['page_latest', 'page_touched'], ['page_id' => $id], __METHOD__);
		if (!$latest || (int)$latest->page_latest !== (int)$row->page_latest ||
			(string)$latest->page_touched !== $touched ||
			AnonymousCacheSafety::fingerprint($id) !== $publicIdentity) {
			$view->clearCache();
			$this->report($id, 'RETRY_PAGE_CHANGED');
			return false;
		}
		if (!$view->isCacheGood($touched)) {
			$this->report($id, 'WRITE_FAILED');
			return false;
		}
		$this->report($id, 'CREATED',
			['bytes' => strlen($html)] +
			($repairToc ? ['repaired_toc' => true] : []) +
			($forceView && $exists ? ['rebuilt_view' => true] : []));
		return true;
	}
}

$maintClass = PrepareVectorViewCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
