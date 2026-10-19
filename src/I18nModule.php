<?php

namespace MediaWiki\Extension\Wikidebates;

use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\DerivativeContext;
use MediaWiki\ResourceLoader\FileModule;

/** Normalize supported regional variants for this extension without altering core fallbacks. */
final class I18nModule extends FileModule {
	protected function getMessageBlob(Context $context) {
		if (preg_match('/^(fr|en)-/i', $context->getLanguage(), $match)) {
			$context = new DerivativeContext($context);
			$context->setLanguage(strtolower($match[1]));
		}
		return parent::getMessageBlob($context);
	}
}
