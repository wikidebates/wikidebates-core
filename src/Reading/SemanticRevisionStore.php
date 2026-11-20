<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use SMW\DataItems\Property;
use SMW\DataItems\WikiPage;
use SMW\SQLStore\SQLStore;
use SMW\Store;

/**
 * Durable, transactionally updated semantic mutation watermarks.
 *
 * SMW's smw_touched timestamps have only second resolution. Recording one
 * monotonically increasing revision per changed entity, inside the same SMW
 * SQLStore section transaction, detects edits made during the same second
 * and independently of the asynchronous HTML purge jobs.
 */
final class SemanticRevisionStore {
	public const TABLE = 'wd_smw_entity_revisions';

	/** Install the ledger through the standard MediaWiki 1.43 update.php path. */
	public static function onLoadExtensionSchemaUpdates($updater): void {
		$type = $updater->getDB()->getType();
		if (!in_array($type, ['mysql', 'sqlite'], true)) {
			throw new \RuntimeException('SMW semantic cache tracking supports MariaDB and SQLite');
		}
		$updater->addExtensionTable(
			self::TABLE,
			dirname(__DIR__, 2) . '/sql/' . $type . '/wd_smw_entity_revisions.sql'
		);
	}

	/** Increment every changed entity once, as part of SMW's own transaction. */
	public static function record(Store $store, array $entityIds): void {
		if (!$store instanceof SQLStore) {
			throw new \RuntimeException('Semantic tracking requires the SMW SQLStore');
		}
		$ids = SemanticCacheHooks::changedIds($entityIds);
		if ($ids === []) {
			return;
		}

		$db = $store->getConnection('mw.db');
		foreach (array_chunk($ids, 128) as $chunk) {
			$rows = [];
			foreach ($chunk as $id) {
				$rows[] = ['entity_id' => $id, 'revision' => 0];
			}

			// A new row starts at 0, then every actual semantic update
			// advances it. INSERT IGNORE is supported by MediaWiki on both
			// MariaDB and SQLite. UPDATE is atomic with the SMW data changes.
			$db->newInsertQueryBuilder()
				->insertInto(self::TABLE)
				->ignore()
				->rows($rows)
				->caller(__METHOD__)
				->execute();
			$db->newUpdateQueryBuilder()
				->update(self::TABLE)
				->set(['revision = revision + 1'])
				->where(['entity_id' => $chunk])
				->caller(__METHOD__)
				->execute();
		}
	}

	/**
	 * A compact revision signature of the entities linked to _ASK queries
	 * embedded by this wiki page. It changes only when linked entities are
	 * modified or the dependency graph changes, not after unrelated edits.
	 *
	 * Each row captures the exact query ID, dependency ID and increasing
	 * semantic revision. Hashing sorted tuples distinguishes additions,
	 * removals and substitutions that COUNT/SUM aggregates would miss.
	 */
	public static function fingerprint(Store $store, WikiPage $subject): string {
		if (!$store instanceof SQLStore) {
			throw new \RuntimeException('Semantic tracking requires the SMW SQLStore');
		}
		$fetcher = $store->getPropertyTableInfoFetcher();
		$tableId = $fetcher->findTableIdForProperty(new Property('_ASK'));
		$tables = $store->getPropertyTables();
		if (!isset($tables[$tableId])) {
			throw new \RuntimeException('SMW _ASK profile table missing');
		}

		$db = $store->getConnection('mw.db');
		// Dependency revisions are a consistency boundary: a lagging
		// replica could wrongly validate an obsolete fragment.
		$rows = $db->newPrimarySelectQueryBuilder()
			->select([
				'query_id' => 'query.smw_id',
				'dependency_id' => 'links.o_id',
				'dependency_revision' => 'watermarks.revision'
			])
			->from($tables[$tableId]->getName(), 'ask_profile')
			->join(SQLStore::ID_TABLE, 'page', ['ask_profile.s_id=page.smw_id'])
			->join(SQLStore::ID_TABLE, 'query', ['ask_profile.o_id=query.smw_id'])
			->leftJoin(SQLStore::QUERY_LINKS_TABLE, 'links', ['links.s_id=query.smw_id'])
			->leftJoin(self::TABLE, 'watermarks', ['watermarks.entity_id=links.o_id'])
			->where([
				'page.smw_hash' => $db->escape_bytea($subject->getSha1()),
				$db->expr('page.smw_iw', '!=', SMW_SQL3_SMWIW_OUTDATED),
				$db->expr('page.smw_iw', '!=', SMW_SQL3_SMWDELETEIW),
			])
			->caller(__METHOD__)
			->fetchResultSet();

		// Hash the exact dependency graph, not a COUNT/SUM aggregate:
		// replacing one entity with another of the same revision could keep
		// those aggregates unchanged while changing the rendered result.
		$links = [];
		foreach ($rows as $row) {
			if ($row->dependency_id === null) {
				throw new \RuntimeException('An embedded SMW query has no dependency links');
			}
			$links[] = $row->query_id . ':' . $row->dependency_id . ':' .
				($row->dependency_revision === null ? 'missing' : $row->dependency_revision);
		}
		sort($links, SORT_STRING);
		return hash('sha256', implode("\n", $links));
	}
}
