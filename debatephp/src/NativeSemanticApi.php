<?php

namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\Linker\Linker;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use SMW\Query\Language\ValueDescription;
use SMW\ParameterProcessorFactory;
use SMW\Query\QueryContext;
use SMW\Query\QueryResult;
use SMW\Services\ServicesFactory;
use SMW\Store;
use SMWQuery;
use SMWQueryProcessor;

/**
 * Direct SMW integration for MediaWiki 1.43.
 *
 * Query and printout semantics adapted from SemanticScribunto 2.3.3
 * (James Hong Kong / mwjames and Tobias Oetterer, GPL-2.0-or-later).
 * Reviewed upstream revision af2e61df59f4dca2384636345d182e2897067189:
 * https://github.com/SemanticMediaWiki/SemanticScribunto/tree/af2e61df59f4dca2384636345d182e2897067189/src
 *
 * @license GPL-2.0-or-later
 */
final class NativeSemanticApi {
	public static function available(): bool {
		return class_exists(ServicesFactory::class) &&
			class_exists(SMWQueryProcessor::class) &&
			class_exists(ParameterProcessorFactory::class);
	}

	/** Preserve parameter order, parser context and the established query option. */
	public static function query(Store $store, array $raw): QueryResult {
		return $store->getQueryResult(self::buildQuery($raw));
	}

	/**
	 * The hover endpoint accepts titles, never arbitrary semantic query syntax.
	 * Confirm the parsed description is exactly the requested singleton before
	 * executing it, including for titles resembling SMW operators.
	 */
	public static function querySubject(Store $store, Title $subject, array $printouts): ?QueryResult {
		// SMW 6.0 uses DIWikiPage; SMW 7.3 moved the concrete class.
		$wikiPage = class_exists(\SMW\DataItems\WikiPage::class)
			? \SMW\DataItems\WikiPage::newFromTitle($subject)
			: \SMW\DIWikiPage::newFromTitle($subject);
		$value = new ValueDescription($wikiPage);
		$query = self::buildQuery([$value->getQueryString(), ...$printouts,
			'mainlabel=-', 'headers=hide', 'link=none', 'limit=1']);
		$description = $query->getDescription();
		if (!$description instanceof ValueDescription || !$description->isSingleton() ||
			!$description->getDataItem()->equals($value->getDataItem())) {
			return null;
		}
		return $store->getQueryResult($query);
	}

	private static function buildQuery(array $raw): SMWQuery {
		[$queryString, $parameters, $printouts] = SMWQueryProcessor::getComponentsFromFunctionParams($raw, false);
		SMWQueryProcessor::addThisPrintout($printouts, $parameters);
		$query = SMWQueryProcessor::createQuery(
			$queryString, SMWQueryProcessor::getProcessedParams($parameters, $printouts),
			QueryContext::SPECIAL_PAGE, '', $printouts
		);
		// Keep the existing SMW context identity, not a dependency on the former class.
		if (defined('SMWQuery::PROC_CONTEXT')) {
			$query->setOption(SMWQuery::PROC_CONTEXT, 'SSC.LibraryFactory');
		}
		return $query;
	}

	/** The real SMW parser function owns deferred persistence and literal values. */
	public static function write(Parser $parser, array $raw) {
		return ServicesFactory::getInstance()->newParserFunctionFactory()
			->newSetParserFunction($parser)->parse(ParameterProcessorFactory::newFromArray($raw));
	}

	/** Preserve formatted values, scalar/multiple cardinality, aliases and row order. */
	public static function format(QueryResult $result): ?array {
		if (!$result->getCount()) {
			return null;
		}
		// Most map printouts are text. Resolve boolean translations only when needed.
		$trueWords = null;
		$linker = null;
		$rows = [];
		$numericIndex = 1;
		while ($fields = $result->getNext()) {
			$row = [];
			foreach ($fields as $field) {
				$request = $field->getPrintRequest();
				$key = $request->getLabel() !== '' ? $request->getText(SMW_OUTPUT_WIKI) : $numericIndex++;
				$values = [];
				while (($value = $field->getNextDataValue()) !== false) {
					switch ($value->getTypeID()) {
						case '_boo':
							$trueWords ??= explode(',', wfMessage('smw_true_words')->text() . ',true,t,yes,y');
							$values[] = in_array(strtolower($value->getWikiValue() ?? 'null'), $trueWords);
							break;
						case '_num':
							$number = $value->getNumber();
							$values[] = $number == (int)$number ? (int)$number : $number;
							break;
						default:
							// Keep wiki output formatting (including links), not raw DataItems.
							$values[] = $value->getShortText(SMW_OUTPUT_WIKI, $linker ??= new Linker());
					}
				}
				$row[$key] = match (count($values)) {
					0 => null,
					1 => $values[0],
					default => $values
				};
			}
			$rows[] = $row;
		}
		return $rows;
	}
}
