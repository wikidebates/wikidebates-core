<?php

namespace MediaWiki\Extension\MinervaFileCache;

use MediaWiki\MediaWikiServices;

/** Shared by Article, CategoryPage and ImagePage, preserving their native behavior. */
trait CacheView {
	private function diagnostic(string $state, string $reason = ''): void {
		if ($this->getContext()->getConfig()->get('MinervaFileCacheDebugHeaders') && !headers_sent()) {
			header('X-Minerva-File-Cache: ' . $state . ($reason === '' ? '' : '; reason=' . $reason));
		}
	}

	private function bypass(string $reason): bool {
		$this->diagnostic('BYPASS', $reason);
		return false;
	}

	/** Never put these responses into an upstream shared cache during rollout. */
	private function privateResponse(): void {
		header('Cache-Control: private, no-store, max-age=0');
		header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
	}

	/**
	 * MW 1.43 compatibility adapter. Its public callback accessor is deprecated,
	 * so support is deliberately bounded to 1.43. No hook is removed or replaced.
	 * Unknown/wrapped MobileFrontend handlers fail closed instead of being skipped.
	 */
	private function otherExtensionsAllowCache(): bool {
		$hooks = MediaWikiServices::getInstance()->getHookContainer();
		$skipped = 0;
		// Suppress just the expected accessor deprecation, not warnings from handlers.
		$previous = null;
		$previous = set_error_handler(static function ($severity, $message, $file, $line) use (&$previous) {
			if ($severity === E_USER_DEPRECATED && str_starts_with($message,
				'Use of MediaWiki\\HookContainer\\HookContainer::getHandlerCallbacks was deprecated in MediaWiki 1.41.')) {
				return true;
			}
			return $previous ? $previous($severity, $message, $file, $line) : false;
		});
		try { $callbacks = $hooks->getHandlerCallbacks('HTMLFileCache::useFileCache'); }
		finally { restore_error_handler(); }
		foreach ($callbacks as $callback) {
			if (is_array($callback) && is_object($callback[0]) &&
				get_class($callback[0]) === 'MobileFrontendHooks' &&
				$callback[1] === 'onHTMLFileCache__useFileCache') {
				$skipped++;
				continue;
			}
			$result = $callback($this->getContext());
			if ($result !== null && $result !== true) { return false; }
		}
		return $skipped === 1 && $hooks->run('IsFileCacheable', [$this]);
	}

	protected function tryFileCache() {
		if (!\MobileContext::singleton()->shouldDisplayMobileView()) {
			return parent::tryFileCache();
		}
		try { return $this->tryMobileFileCache(); }
		catch (\Throwable $e) {
			Hooks::logFailure('read', $e);
			return $this->bypass('storage-error');
		}
	}

	private function tryMobileFileCache(): bool {
		$context = $this->getContext();
		$config = $context->getConfig();
		$request = $context->getRequest();
		$user = $context->getUser();
		if (!$config->get('MinervaFileCacheEnabled')) { return false; }
		if ($request->getMethod() !== 'GET') { return $this->bypass('method'); }
		if ($user->isRegistered() || $request->getSession()->isPersistent() ||
			!empty($_SERVER['REMOTE_USER']) || !empty($_SERVER['PHP_AUTH_USER']) ||
			$request->getHeader('Authorization')) { return $this->bypass('session'); }
		if ($request->getHeader('Cookie')) { return $this->bypass('cookie'); }
		$query = $request->getValues();
		if (array_diff(array_keys($query), ['title', 'curid', 'action']) ||
			($query['action'] ?? 'view') !== 'view') { return $this->bypass('query'); }
		if ($request->getHeader('Range') || $request->getHeader('X-Requested-With') ||
			preg_match('/no-cache|no-store/i', (string)$request->getHeader('Cache-Control'))) {
			return $this->bypass('request-headers');
		}
		if ($context->getLanguage()->getCode() !== $config->get('LanguageCode') ||
			$context->getSkin()->getSkinName() !== 'minerva') { return $this->bypass('variant'); }
		if (!$this->getTitle()->exists() || $this->getTitle()->isRedirect() || $this->getRedirectedFrom() ||
			MediaWikiServices::getInstance()->getTalkPageNotificationManager()->userHasNewMessages($user)) {
			return $this->bypass('page');
		}
		if (!$this->otherExtensionsAllowCache()) { return $this->bypass('extension-veto'); }

		// Article::view has already authorized reading and checked the current revision.
		$identity = ['revision' => (int)$this->mPage->getLatest(), 'touched' => $this->mPage->getTouched(),
			'epoch' => (string)$config->get('MinervaFileCacheEpoch'), 'coreEpoch' => $config->get('CacheEpoch'),
			'format' => 'MinervaFileCache-0.1.0'];
		$variant = json_encode([$request->getRequestURL(), $request->getProtocol(),
			$config->get('Server'), $config->get('LanguageCode'),
			$request->getHeader('Host'), $request->getHeader('User-Agent'),
			$request->getHeader('Accept-Language'), $request->getHeader('X-Subdomain')]);
		$store = Hooks::store();
		$expires = 0;
		$expiryScope = MediaWikiServices::getInstance()->getHookContainer()->scopedRegister('ParserOutputPostCacheTransform',
			static function ($output) use (&$expires, $identity) {
				if ($output->getCacheRevisionId() === $identity['revision'] && $output->isCacheable()) {
					$expires = (int)wfTimestamp(TS_UNIX, $output->getCacheTime()) + $output->getCacheExpiry();
				}
			});
		$page = $this->getTitle()->getPrefixedDBkey();
		$snapshot = $store->read($page, $variant, $identity);
		if ($snapshot['entry']) {
			$entry = $snapshot['entry'];
			foreach ($entry['headers'] as $name => $values) {
				foreach ($values as $i => $value) { header($name . ': ' . $value, $i === 0); }
			}
			$this->privateResponse();
			$this->diagnostic('HIT');
			if ($config->get('MinervaFileCacheDebugHeaders')) {
				header('X-Minerva-File-Cache-Age: ' . max(0, time() - $entry['created']));
			}
			$context->getOutput()->disable();
			// Let the normal MediaWiki/PHP output handler compress this plaintext once.
			echo $entry['body'];
			return true;
		}
		$fill = $store->startFill($page);
		if (!$fill) { return $this->bypass('busy'); }
		$this->diagnostic('MISS');
		ob_start(function ($html, $phase) use ($store, $page, $variant, $identity, $snapshot, $fill, $context, &$expires, $expiryScope) {
			try {
				if (!($phase & PHP_OUTPUT_HANDLER_FINAL) || ($phase & PHP_OUTPUT_HANDLER_CLEAN)) {
					return $html;
				}
				$headers = ResponsePolicy::headersForStorage($html, headers_list(), $reason);
				if (headers_sent() || http_response_code() !== 200 || $headers === null ||
					$expires <= time() || $context->getOutput()->getRedirect() !== '' || !$context->getOutput()->couldBePublicCached()) {
					$this->diagnostic('BYPASS', $reason ?? 'response');
					return $html;
				}
				if (!$store->publish($page, $variant, $identity, $snapshot['generation'], $html, $headers, $expires)) {
					$this->diagnostic('BYPASS', 'not-stored');
				} else {
					$this->privateResponse();
				}
			} catch (\Throwable $e) {
				Hooks::logFailure('write', $e);
				$this->diagnostic('BYPASS', 'storage-error');
			} finally {
				if (($phase & PHP_OUTPUT_HANDLER_FINAL) && is_resource($fill)) { fclose($fill); }
			}
			return $html;
		});
		return false;
	}
}
