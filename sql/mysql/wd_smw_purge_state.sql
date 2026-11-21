-- Disposable receipts, not revision watermarks or claims for pending work.
CREATE TABLE /*_*/wd_smw_purge_state (
	page_id INT UNSIGNED NOT NULL PRIMARY KEY,
	fingerprint VARBINARY(64) NOT NULL,
	generation VARBINARY(32) NOT NULL
) /*$wgDBTableOptions*/;
