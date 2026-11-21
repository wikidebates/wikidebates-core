<?php

namespace MediaWiki\Extension\Wikidebates\Reading;

use SMW\DataItems\Property;
use SMW\DataItems\WikiPage;
use SMW\SQLStore\SQLStore;

/**
 * Durable receipts for completed page purges, never claims for pending work.
 * Losing/overwriting a receipt only causes another purge. No scan is skipped.
 */
final class SemanticPurgeState {
	public const TABLE = 'wd_smw_purge_state';
	private SQLStore $store;

	public function __construct(SQLStore $store) {
		$this->store = $store;
	}

	/** Old installations still run their jobs before the optional schema update. */
	public function available(): bool {
		return $this->store->getConnection('mw.db')->tableExists(self::TABLE, __METHOD__);
	}

	/** Capture after record(), inside the same semantic transaction. */
	public static function revisions(SQLStore $store, array $ids): array {
		$ids = SemanticCacheHooks::changedIds($ids);
		if ($ids === []) {
			return [];
		}
		$rows = $store->getConnection('mw.db')->newPrimarySelectQueryBuilder()
			->select(['entity_id', 'revision'])
			->from(SemanticRevisionStore::TABLE)
			->where(['entity_id' => $ids])
			->caller(__METHOD__)
			->fetchResultSet();
		$revisions = [];
		foreach ($rows as $row) {
			$revisions[(int)$row->entity_id] = (string)$row->revision;
		}
		if (count($revisions) !== count($ids)) {
			throw new \RuntimeException('Missing semantic revision when queuing a purge');
		}
		ksort($revisions, SORT_NUMERIC);
		return $revisions;
	}

	public static function revisionToken(array $revisions, array $ids): string {
		$ids = SemanticCacheHooks::changedIds($ids);
		$selected = array_intersect_key($revisions, array_flip($ids));
		if (count($selected) !== count($ids)) {
			throw new \RuntimeException('Incomplete semantic revision token');
		}
		ksort($selected, SORT_NUMERIC);
		return hash('sha256', json_encode($selected, JSON_THROW_ON_ERROR));
	}

	/** @return array<int,object> Fresh identities, not Title/SMW replica caches. */
	public function pages(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$rows = $this->store->getConnection('mw.db')->newPrimarySelectQueryBuilder()
			->select(['page_id', 'page_namespace', 'page_title', 'page_latest'])
			->from('page')
			->where(['page_id' => $ids])
			->caller(__METHOD__)
			->fetchResultSet();
		$pages = [];
		foreach ($rows as $row) {
			$pages[(int)$row->page_id] = $row;
		}
		return $pages;
	}

	/**
	 * One primary read for all pages in a batch. The complete _ASK graph is
	 * hashed, not just this job's dependencies and not COUNT/SUM aggregates.
	 * Missing profiles/links cannot yield a reusable receipt.
	 *
	 * @param array<int,object> $pages Primary page rows
	 * @return array<int,string>
	 */
	public function snapshots(array $pages, array $requiredQueries = []): array {
		if ($pages === []) {
			return [];
		}
		$db = $this->store->getConnection('mw.db');
		$tableId = $this->store->getPropertyTableInfoFetcher()
			->findTableIdForProperty(new Property('_ASK'));
		$tables = $this->store->getPropertyTables();
		if (!isset($tables[$tableId])) {
			throw new \RuntimeException('SMW _ASK profile table missing');
		}
		$hashes = [];
		$owners = [];
		foreach ($pages as $id => $page) {
			$subject = new WikiPage($page->page_title, (int)$page->page_namespace);
			$hashes[] = $db->escape_bytea($subject->getSha1());
			$owners[$page->page_namespace . ':' . $page->page_title] = $id;
		}
		$rows = $db->newPrimarySelectQueryBuilder()
			->select([
				'subject.smw_title', 'subject.smw_namespace',
				'query_id' => 'query.smw_id',
				'dependency_id' => 'links.o_id',
				'dependency_revision' => 'watermarks.revision'
			])
			->from(SQLStore::ID_TABLE, 'subject')
			->join($tables[$tableId]->getName(), 'profile', ['profile.s_id=subject.smw_id'])
			->join(SQLStore::ID_TABLE, 'query', ['profile.o_id=query.smw_id'])
			->leftJoin(SQLStore::QUERY_LINKS_TABLE, 'links', ['links.s_id=query.smw_id'])
			->leftJoin(SemanticRevisionStore::TABLE, 'watermarks', ['watermarks.entity_id=links.o_id'])
			->where(['subject.smw_hash' => $hashes, 'subject.smw_iw' => ''])
			->caller(__METHOD__)
			->fetchResultSet();
		$tuples = $registered = [];
		$unsafe = [];
		foreach ($rows as $row) {
			$id = $owners[$row->smw_namespace . ':' . $row->smw_title] ?? null;
			if ($id === null) {
				continue;
			}
			$registered[$id][(int)$row->query_id] = true;
			if ($row->dependency_id === null) {
				$unsafe[$id] = true;
				continue;
			}
			$tuples[$id][] = $row->query_id . ':' . $row->dependency_id . ':' .
				($row->dependency_revision === null ? 'missing' : $row->dependency_revision);
		}
		$snapshots = [];
		foreach ($tuples as $id => $links) {
			if (isset($unsafe[$id]) || array_diff($requiredQueries[$id] ?? [], array_keys($registered[$id])) !== []) {
				continue;
			}
			sort($links, SORT_STRING);
			$page = $pages[$id];
			$snapshots[$id] = hash('sha256', json_encode([
				1, $id, (string)$page->page_latest, (int)$page->page_namespace,
				$page->page_title, $links
			], JSON_THROW_ON_ERROR));
		}
		return $snapshots;
	}

	/** @return array<int,object> */
	public function receipts(array $ids): array {
		$rows = $this->store->getConnection('mw.db')->newPrimarySelectQueryBuilder()
			->select(['page_id', 'fingerprint', 'generation'])
			->from(self::TABLE)
			->where(['page_id' => $ids])
			->caller(__METHOD__)
			->fetchResultSet();
		$result = [];
		foreach ($rows as $row) {
			$result[(int)$row->page_id] = $row;
		}
		return $result;
	}

	/** No lock/lease: concurrent workers may repeat work, never suppress it. */
	public function remember(array $rows): void {
		if ($rows !== []) {
			// A disposable receipt has no foreign-key consumers. REPLACE is
			// portable across the supported MW MariaDB/SQLite backends.
			$this->store->getConnection('mw.db')->replace(
				self::TABLE, ['page_id'], $rows, __METHOD__
			);
		}
	}
}
