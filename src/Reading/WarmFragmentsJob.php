<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\FauxRequest;

class WarmFragmentsJob extends \Job {
	private array $preparation = ['status' => 'skipped', 'source' => null];

	/** Counts for the nightly preparation report, never included in API output. */
	public function getPreparation(): array {
		return $this->preparation;
	}

	public function __construct($title, array $params = []) {
		parent::__construct('wikidebatesWarmReading', $title, $params);
		$this->removeDuplicates = true;
	}
	public function run() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		if (!$config->get('WikidebatesReadingFragments') || !$config->get('WikidebatesReadingPrewarm') ||
			!$this->title->exists() || $this->title->isRedirect()) { return true; }
		// Never prepare shared HTML inside an authenticated/personalized web job.
		$main = RequestContext::getMain();
		if ($main->getUser()->isRegistered() || $main->getRequest()->getHeader('Cookie')) { return true; }
		try { FragmentService::store(); }
		catch (\Throwable $e) { FragmentService::log($e); return true; }
		$context = new RequestContext();
		$context->setRequest(new class(['action' => 'wikidebatesreading']) extends FauxRequest {
			public function getProtocol() {
				return parse_url(MediaWikiServices::getInstance()->getMainConfig()->get('Server'), PHP_URL_SCHEME) ?: 'http';
			}
		});
		$context->setUser($services->getUserFactory()->newAnonymous());
		$context->setLanguage($services->getContentLanguage());
		$context->setTitle($this->title);
		ReadingHooks::$warming = true;
		try {
			// A short web fill can finish while nightly preparation reaches the
			// same page. Retry only in this background preparation, never in
			// the ordinary visitor request path.
			for ($attempt = 0; $attempt < 4; $attempt++) {
				FragmentService::read($context, $this->title->getPrefixedText(), 'vector', null, $this->preparation, false);
				if (($this->preparation['status'] ?? null) !== 'busy') { break; }
				if ($attempt < 3) { usleep(200000); }
			}
		} catch (\PermissionsError $e) { return true; }
		catch (\Throwable $e) {
			$this->preparation = ['status' => 'failed', 'source' => null];
			FragmentService::log($e);
			$this->setLastError(get_class($e));
			return false;
		}
		finally { ReadingHooks::$warming = false; }
		return true;
	}
}
