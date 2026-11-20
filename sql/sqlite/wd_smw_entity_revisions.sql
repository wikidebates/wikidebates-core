-- Incremented in the same SQL transaction as SMW semantic data updates.
CREATE TABLE /*_*/wd_smw_entity_revisions (
	entity_id INTEGER NOT NULL PRIMARY KEY,
	revision INTEGER NOT NULL DEFAULT 0
);
