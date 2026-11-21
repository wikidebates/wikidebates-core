-- Losing this table only disables completed-page coalescing.
CREATE TABLE /*_*/wd_smw_purge_state (
	page_id INTEGER NOT NULL PRIMARY KEY,
	fingerprint BLOB NOT NULL,
	generation BLOB NOT NULL
);
