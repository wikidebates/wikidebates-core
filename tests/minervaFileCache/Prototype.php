<?php

/** Feasibility prototype for an isolated wiki; not a production extension. */
class MinervaStudyFileCache extends HTMLFileCache {
	protected function cacheDirectory() {
		return parent::cacheDirectory() . '/minerva-study-v1';
	}
}
class MinervaStudyArticle extends Article {
	protected function tryFileCache() {
		$context = $this->getContext();
		if (!MobileContext::singleton()->shouldDisplayMobileView()) {
			return parent::tryFileCache();
		}
		$request = $context->getRequest();
		$query = $request->getValues();
		$allowedQuery = array_diff(array_keys($query), ['title', 'curid', 'action']);
		$eligible = $request->getMethod() === 'GET'
			&& !$context->getUser()->isRegistered()
			&& !isset($_SERVER['HTTP_COOKIE'])
			&& !$allowedQuery && ($query['action'] ?? 'view') === 'view'
			&& $context->getLanguage()->getCode() === $context->getConfig()->get('LanguageCode')
			&& $context->getSkin()->getSkinName() === 'minerva'
			&& $this->getTitle()->exists() && !$this->getTitle()->isRedirect()
			&& !$this->getRedirectedFrom()
			&& !MediaWiki\MediaWikiServices::getInstance()->getTalkPageNotificationManager()
				->userHasNewMessages($context->getUser());
		if (!$eligible) {
			$request->response()->header('X-Wikidebates-Lab-Cache: BYPASS');
			return false;
		}
		// Article::view has already checked read authority and page_touched.
		$cache = new MinervaStudyFileCache($this->getTitle(), 'view');
		if ($cache->isCacheGood($this->mPage->getTouched())) {
			$request->response()->header('X-Wikidebates-Lab-Cache: HIT');
			$cache->loadFromFileCache($context);
			return true;
		}
		$request->response()->header('X-Wikidebates-Lab-Cache: MISS');
		ob_start(static function ($html) use ($cache, $context) {
			if (http_response_code() !== 200 || $context->getOutput()->getRedirect() !== '' ||
				!$context->getOutput()->couldBePublicCached() ||
				!preg_match('/<body[^>]*class="[^"]*\bskin-minerva\b/', $html)) {
				return $html;
			}
			return $cache->saveToFileCache($html);
		});
		return false;
	}
}
