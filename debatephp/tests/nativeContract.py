"""Strict contract for the single reviewed removal of an unused SMW query.

The pinned baseline is unchanged. Only these exact historical requests may
disappear; every other query, its order and all other effects must match.
"""
RETIRED_PARENT_QUERIES = {
	'Argument/parents': 'Argument parent',
	'Argument/inherited': 'Parent',
	'Argument/fallback-parents': 'Parent fallback',
	'Argument/both-parent-types': 'Parent argument',
}
MODES = ('reference', 'optimized')


def retired_query(parent):
	return {
		'1': f'([[{parent}]])',
		'2': "?Fil d'Ariane=chain",
		'3': '?Débat parent=pdeb',
		'4': 'limit=200',
		'5': 'link=none',
	}


def compare_case(name, mode, before, after):
	label = f'{name}/{mode}'
	old_hashes = dict(before['sha256'])
	new_hashes = dict(after['sha256'])
	old_queries = list(before['ask_queries'])
	new_queries = after['ask_queries']
	if name in RETIRED_PARENT_QUERIES:
		query = retired_query(RETIRED_PARENT_QUERIES[name])
		if old_queries.count(query) != 1:
			raise ValueError(f'{label}: expected exactly one retired request in pinned baseline')
		old_queries.remove(query)
		# The full ordered ask trace is checked below, not just its count.
		del old_hashes['ask']
		del new_hashes['ask']
	if old_queries != new_queries:
		raise ValueError(f'{label}: unexpected semantic read trace difference: {old_queries!r} != {new_queries!r}')
	if old_hashes != new_hashes:
		raise ValueError(f'{label}: unexpected renderer effect difference')


def compare_corpora(before, after):
	if before.keys() != after.keys() or not RETIRED_PARENT_QUERIES.keys() <= before.keys():
		raise ValueError('Behavioural corpus differs or retired-query fixtures are missing')
	for name in before:
		if before[name].keys() != after[name].keys() or set(before[name]) != set(MODES):
			raise ValueError(f'{name}: renderer modes differ')
		for mode in MODES:
			compare_case(name, mode, before[name][mode], after[name][mode])


def check_sample_counts(row):
	for sample in row['samples']:
		if sample['semantic_read_calls'] != len(row['ask_queries']):
			raise ValueError('Timed semantic read count differs from the untimed trace')
