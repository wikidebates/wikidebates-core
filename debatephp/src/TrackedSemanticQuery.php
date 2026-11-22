<?php

namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Wikidebates\Reading\PageLocalCachePolicy;
use MediaWiki\Parser\Parser;
use SMW\DataItems\WikiPage as SemanticWikiPage;
use SMW\ParserData;
use SMW\Query\Query;
use SMW\Query\QueryContext;
use SMW\Query\QueryProcessor;
use SMW\Query\QueryResult;
use SMW\Services\ServicesFactory as SemanticServices;
use SMW\Store;

/**
 * Executes the SemanticScribunto 2.3.3 query without Lua while preserving
 * #ask's query provenance and _ASK annotations for SMW 7.3.1.
 *
 * Keep the original SPECIAL_PAGE query context, printouts and Lua result
 * processor: switching to INLINE_QUERY silently changes result formatting.
 */
final class TrackedSemanticQuery {
	public const OUTPUT_MARKER = 'wikidebates-semantic-query-tracked';

	public static function execute(Parser $parser, array $rawParameters, Store $store): QueryResult|string {
		// Page-local files deliberately keep the first rendered query results.
		// Do not require _ASK profiles or disable parser caching if tracing
		// cannot certify their dependencies.
		if (PageLocalCachePolicy::enabled()) {
			return (new \SMW\Scribunto\LibraryFactory($store))->newQueryResultFrom($rawParameters);
		}
		$title = $parser->getTitle();
		$output = $parser->getOutput();
		$services = SemanticServices::getInstance();
		$settings = $services->getSettings();
		$request = RequestContext::getMain()->getRequest();
		$action = $request->getVal('action', 'view');

		// A preview is not a stored revision: never register its transient
		// queries against the published page in smw_query_links.
		$preview = in_array($action, ['edit', 'submit', 'stashedit'], true) ||
			($action === 'parse' && $request->getVal('text') !== null) ||
			$request->getVal('oldid') !== null || $request->getVal('revid') !== null;
		// Historical revisions and text supplied to action=parse are not the
		// canonical page version and must not write _ASK to its semantic store.
		$canTrace = !$preview && $title !== null && $title->canExist() &&
			$title->exists() && $services->getNamespaceExaminer()->isSemanticEnabled($title->getNamespace()) &&
			$settings->get('smwgEnabledQueryDependencyLinksStore') &&
			$settings->get('smwgQueryProfiler') !== false;

		if (!$canTrace) {
			// Never cache a page whose semantic reads cannot be invalidated.
			$output->updateCacheExpiry(0);
			return (new \SMW\Scribunto\LibraryFactory($store))->newQueryResultFrom($rawParameters);
		}

		// Match SemanticScribunto 2.3.3, including addThisPrintout and its
		// SPECIAL_PAGE context. Attach the page BEFORE the SQLStore lookup,
		// since the AfterQueryResultLookupComplete hook runs during lookup.
		[$queryString, $params, $printouts] = QueryProcessor::getComponentsFromFunctionParams(
			$rawParameters, false
		);
		QueryProcessor::addThisPrintout($printouts, $params);
		$processed = QueryProcessor::getProcessedParams($params, $printouts);
		$subject = SemanticWikiPage::newFromTitle($title);
		$query = QueryProcessor::createQuery(
			$queryString, $processed, QueryContext::SPECIAL_PAGE, '', $printouts, $subject
		);
		$query->setOption(Query::PROC_CONTEXT, 'SSC.LibraryFactory');

		$result = $store->getQueryResult($query);
		if (!$result instanceof QueryResult) {
			$output->updateCacheExpiry(0);
			return $result;
		}

		// QueryDependencyLinksStore alone is not sufficient: the validator
		// also reads the stored _ASK query profiles of the embedding page.
		$parserData = new ParserData($title, $output);
		if ($parserData->hasAnnotationBlock()) {
			$output->updateCacheExpiry(0);
			return $result;
		}
		if (!$settings->isFlagSet('smwgQueryProfiler', SMW_QPRFL_DUR)) {
			$query->setOption(Query::PROC_QUERY_TIME, 0);
		}
		if ($settings->isFlagSet('smwgQueryProfiler', SMW_QPRFL_PARAMS)) {
			$query->setOption(Query::OPT_PARAMETERS, true);
		}
		$profile = $services->getQueryFactory()->newProfileAnnotatorFactory()->newProfileAnnotator(
			$query, $processed['format']->getValue()
		);
		$profile->pushAnnotationsTo($parserData->getSemanticData());
		$parserData->copyToParserOutput();
		$output->setExtensionData(self::OUTPUT_MARKER, true);

		return $result;
	}
}
