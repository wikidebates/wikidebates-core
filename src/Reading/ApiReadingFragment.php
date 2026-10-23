<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use MediaWiki\Api\ApiBase;
use Wikimedia\ParamValidator\ParamValidator;

class ApiReadingFragment extends ApiBase {
	public function execute() {
		$params = $this->extractRequestParams();
		$this->getMain()->setCacheMode('private');
		try { $result = FragmentService::read($this->getContext(), $params['page'], $params['useskin']); }
		catch (\InvalidArgumentException $e) { $this->dieWithError('apierror-invalidtitle', 'invalidtitle'); }
		unset($result['_expires']);
		$this->getResult()->addValue(null, $this->getModuleName(), $result);
	}
	public function getAllowedParams() {
		return ['page' => [ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_REQUIRED => true],
			'useskin' => [ParamValidator::PARAM_TYPE => ['vector', 'minerva'], ParamValidator::PARAM_DEFAULT => 'vector']];
	}
}
