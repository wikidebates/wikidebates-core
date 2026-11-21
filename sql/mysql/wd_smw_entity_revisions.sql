-- Incremented in the same SQL transaction as SMW semantic data updates.
CREATE TABLE /*_*/wd_smw_entity_revisions (
	entity_id INT UNSIGNED NOT NULL PRIMARY KEY,
	revision BIGINT UNSIGNED NOT NULL DEFAULT 0
) /*$wgDBTableOptions*/;
