<?php

namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

/** Typed read-only transport; never accept arbitrary wikitext. */
final class ApiArgumentMap extends ApiBase {
	public function execute() {
		$params = $this->extractRequestParams();
		$this->getMain()->setCacheMode('private');
		foreach (['page', 'source'] as $key) {
			$value = $params[$key];
			if ($key === 'source' && $value === '') {
				continue;
			}
			$title = Title::newFromText($value);
			if (!$title || $title->isExternal() || $title->getNamespace() < 0 ||
				str_contains($value, "\0") || !preg_match('//u', $value)) {
				$this->dieWithError(['apierror-invalidtitle', $value], 'invalidtitle');
			}
			if (!$this->getContext()->getAuthority()->authorizeRead('read', $title)) {
				$this->dieWithError('apierror-permissiondenied', 'permissiondenied');
			}
		}
		$source = $params['mode'] === 'full' ? '' : $params['source'];
		if ($params['mode'] !== 'full' && trim($source) === '') {
			$this->dieWithError('apierror-invalidparammix', 'invalidparammix');
		}
		// Keep a single implementation of stored wikitext/link semantics.
		// Never reparse the returned markup as arbitrary template source.
		$text = '{{#argumentmap:render|' . rawurlencode($params['page']) . '|' .
			rawurlencode($source) . '|' . ($params['mode'] === 'debate' ? 'debate' : '') .
			'|encoding=uri' . (isset($params['lang']) ? '|lang=' . $params['lang'] : '') . '}}';
		$context = $this->getContext();
		$options = ParserOptions::newFromContext($context);
		$options->setRenderReason('api-parse');
		$options->setWrapOutputClass('mw-parser-output');
		$output = MediaWikiServices::getInstance()->getParserFactory()->create()
			->parse($text, Title::newFromText('API'), $options);
		$html = $output->runOutputPipeline($options, [
			'allowClone' => false, 'wrapperDivClass' => 'mw-parser-output',
			'userLang' => $context->getLanguage(), 'includeDebugInfo' => false
		])->getContentHolderText();
		$this->getResult()->addValue(null, $this->getModuleName(), ['html' => $html]);
	}

	public function getAllowedParams() {
		return [
			'page' => [ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true],
			'source' => [ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => ''],
			'mode' => [ParamValidator::PARAM_TYPE => ['full', 'debate', 'argument'], ParamValidator::PARAM_DEFAULT => 'full'],
			'lang' => [ParamValidator::PARAM_TYPE => ['fr', 'en']]
		];
	}
}
