<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\FauxRequest;

class WarmFragmentsJob extends \Job {
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
			FragmentService::read($context, $this->title->getPrefixedText(), 'vector');
		} catch (\PermissionsError $e) { return true; }
		catch (\Throwable $e) { FragmentService::log($e); $this->setLastError(get_class($e)); return false; }
		finally { ReadingHooks::$warming = false; }
		return true;
	}
}
