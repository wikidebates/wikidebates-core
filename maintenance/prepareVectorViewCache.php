<?php

use MediaWiki\Context\RequestContext;
use MediaWiki\Debug\MWDebug;
use MediaWiki\MainConfigNames;
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
		$this->addOption('json', 'Print a machine-readable status report.');
	}

	public function finalSetup(SettingsBuilder $settingsBuilder) {
		$this->enabled = (bool)$settingsBuilder->getConfig()->get(MainConfigNames::UseFileCache);
		// The maintenance process captures native HTML explicitly. Avoid reading
		// existing file-cache HTML or writing files as a side effect of Article::view.
		$settingsBuilder->putConfigValue(MainConfigNames::UseFileCache, false);
		$this->getServiceContainer()->getReadOnlyMode()->setReason('Preparing anonymous Vector file cache');
		MWDebug::deinit();
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
		$dbr = $this->getReplicaDB();
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

		$context = new RequestContext();
		$context->setTitle($title);
		$context->setSkin($services->getSkinFactory()->makeSkin($skinName));
		$article = Article::newFromTitle($title, $context);
		$context->setWikiPage($article->getPage());
		RequestContext::getMain()->setTitle($title);
		if (!$article->isFileCacheable(HTMLFileCache::MODE_REBUILD)) {
			$this->report($id, 'SKIPPED_NONPUBLIC');
			return true;
		}
		$view = new HTMLFileCache($title, 'view');
		$touched = (string)$row->page_touched;
		if ($view->isCacheGood($touched)) {
			$this->report($id, 'REUSED');
			return true;
		}
		if ($this->hasOption('dry-run')) {
			$this->report($id, 'MISSING_FILE');
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
		$view->saveToFileCache($html);
		$latest = $dbr->selectRow('page', ['page_latest', 'page_touched'], ['page_id' => $id], __METHOD__);
		if (!$latest || (int)$latest->page_latest !== (int)$row->page_latest ||
			(string)$latest->page_touched !== $touched) {
			$view->clearCache();
			$this->report($id, 'RETRY_PAGE_CHANGED');
			return false;
		}
		if (!$view->isCacheGood($touched)) {
			$this->report($id, 'WRITE_FAILED');
			return false;
		}
		$this->report($id, 'CREATED', ['bytes' => strlen($html)]);
		return true;
	}
}

$maintClass = PrepareVectorViewCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
