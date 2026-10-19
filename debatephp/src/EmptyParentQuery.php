<?php
namespace MediaWiki\Extension\DebatePHP;

/** A proven empty, untruncated union cannot gain parents by querying its two branches. */
final class EmptyParentQuery {
	public static function certified(\SMW\Query\QueryResult $result, Table $parameters): bool {
		if (array_keys($parameters->values) !== [1, 2, 3, 4, 5] || $parameters[4] !== 'limit=200' || $parameters[5] !== 'link=none') { return false; }
		// A cached union can predate newer data visible to its individual fallback queries.
		if ($result->getCount() !== 0 || $result->hasFurtherResults() || $result->isFromCache()) { return false; }
		$query = $result->getQuery();
		if ($query->querymode !== \SMWQuery::MODE_INSTANCES || $query->getOffset() !== 0 || $query->getLimit() !== 200) { return false; }
		// Restrict this proof to the exact internal two-parent query, not arbitrary ask syntax.
		if (!is_string($parameters[1]) || !preg_match('/^\(\[\[[^\[\]{}|<>]+::[^\[\]{}|<>]+\]\] OR \[\[[^\[\]{}|<>]+::[^\[\]{}|<>]+\]\] \)$/uD', $parameters[1])) { return false; }
		$errors = $result->getErrors();
		if ($errors) {
			// SMW 6 parses this historical syntax as the intended union but warns about
			// its two outer parentheses. No other error/warning may be ignored.
			$warnings = [];
			foreach ($errors as $error) {
				$warning = is_string($error) ? json_decode($error, true) : null;
				if (!is_array($warning) || !array_is_list($warning) || count($warning) !== 3 || $warning[0] !== 2
					|| $warning[1] !== 'smw_unexpectedpart' || !in_array($warning[2], ['(', ')'], true)) { return false; }
				$warnings[] = $warning[2];
			}
			sort($warnings);
			if ($warnings !== ['(', ')']) { return false; }
		}
		$description = $query->getDescription();
		if (!$description instanceof \SMW\Query\Language\Disjunction) { return false; }
		$branches = $description->getDescriptions();
		if (count($branches) !== 2) { return false; }
		$target = null;
		foreach ($branches as $branch) {
			if (!$branch instanceof \SMW\Query\Language\SomeProperty) { return false; }
			$value = $branch->getDescription();
			if (!$value instanceof \SMW\Query\Language\ValueDescription || $value->getComparator() !== SMW_CMP_EQ
				|| !$value->getDataItem() instanceof \SMW\DIWikiPage) { return false; }
			$page = $value->getDataItem()->getSerialization();
			if ($target !== null && $target !== $page) { return false; }
			$target = $page;
		}
		return true;
	}
}
