"""Compare immutable Lua graphs with the intentional native SEO evolution.

Only the specialized page, its Question/Claim and its explicitly superseded
relations are projected. Other nodes, citations and unknown fields remain exact.
The real-parser SEO tests separately check exhaustive lists against input data,
head descriptions and actual stored timestamps. This is not a schema validator.
"""
import copy
import html
import re
from urllib.parse import urlsplit

def require(condition, message):
	if not condition:
		raise ValueError(message)

def legacy_graph(value):
	if isinstance(value, dict):
		return {key: legacy_graph(item) for key, item in value.items()}
	if isinstance(value, list):
		return [legacy_graph(item) for item in value]
	if isinstance(value, str):
		return value.replace('|', '&#124;').replace('=', '&#61;').replace('}}', '} }').replace('&', '&amp;').replace('<', '&lt;')
	return value

def equal(old, new):
	return old == new or old == legacy_graph(new)

def plain(text):
	text = re.sub(r'<!--.*?-->|<ref\b[^>]*>.*?</ref>|<ref\b[^>]*/>', ' ', text, flags=re.S | re.I)
	text = re.sub(r'\[\[(?:[^\]|]*\|)?([^\]]+)\]\]', r'\1', text)
	text = re.sub(r'\[https?://[^\s\]]+\s+([^\]]+)\]', r'\1', text)
	text = text.replace("'''", '').replace("''", '')
	text = html.unescape(re.sub(r'<[^>]*>', '', text))
	return re.sub(r'\s+', ' ', text).strip()

def text_matches(old, new, fallback=''):
	# HTMLTags escaped the serialized Lua string, before the native parser
	# began emitting JSON directly. Try only these explicit text forms.
	return any(equal(old_text, new) for old_text in (
		plain(old) or fallback, plain(html.unescape(old)) or fallback,
		plain(html.unescape(html.unescape(old))) or fallback))

def url(value):
	if not isinstance(value, str):
		return False
	p = urlsplit(value)
	return p.scheme in ('http', 'https') and bool(p.netloc)

def subsequence(old, new, label):
	index = 0
	for item in new:
		if index < len(old) and equal(old[index], item):
			index += 1
	require(index == len(old), 'Lost or reordered historical ' + label)

def education(lang, debate):
	require(lang in ('fr', 'en'), 'Unexpected resource language')
	fr = lang == 'fr'
	critical = 'Esprit critique' if fr else 'Critical thinking'
	name = 'Débat' if fr else 'Debate'
	return {
		'learningResourceType': name if debate else 'Argumentation',
		'educationalUse': [name, critical] if debate else [
			'Analyse' if fr else 'Analysis', 'Argumentation', critical],
		'educationalLevel': ['Enseignement secondaire', 'Enseignement supérieur'] if fr else [
			'Secondary education', 'Higher education'],
		'audience': [
			{'@type': 'Audience', 'audienceType': 'Grand public' if fr else 'General public'},
			{'@type': 'EducationalAudience', 'educationalRole': ['Élève', 'Étudiant', 'Enseignant'] if fr else [
				'Pupil', 'Student', 'Teacher']}
		]
	}

def parts_ids(parts, page, debate):
	require(isinstance(parts, list), 'Page parts must be a list')
	if not debate and len(parts) == 1 and set(parts[0]) == {'@id'}:
		require(url(parts[0]['@id']) and parts[0]['@id'].endswith('#qa'), 'Invalid dedicated debate')
		return [parts[0]['@id']]
	keys = ['pro', 'con'] if debate else ['justifications', 'objections']
	labels = ['Arguments pour', 'Arguments contre'] if page['inLanguage'] == 'fr' else ['Arguments for', 'Arguments against']
	if not debate:
		labels = ['Justifications', 'Objections']
	prefix = page['url'] + '#schema-'
	allowed = {prefix + key + '-section': (prefix + key + '-list', label) for key, label in zip(keys, labels)}
	ids = []
	last = -1
	for part in parts:
		require(set(part) == {'@type', '@id', 'name', 'mainEntity'} and part['@type'] == 'WebPageElement',
			'Invalid section shape')
		require(part['@id'] in allowed, 'Invalid section identity')
		order = list(allowed).index(part['@id'])
		require(order > last, 'Duplicate or reordered section')
		last = order
		list_id, label = allowed[part['@id']]
		items = part['mainEntity']
		require(part['name'] == label and set(items) == {'@type', '@id', 'name', 'numberOfItems', 'itemListElement'}
			and items['@type'] == 'ItemList' and items['@id'] == list_id and items['name'] == label, 'Invalid list identity or label')
		elements = items['itemListElement']
		require(isinstance(elements, list) and len(elements) > 0 and len(elements) == items['numberOfItems'], 'Invalid list count')
		for position, item in enumerate(elements, 1):
			require(set(item) == {'@type', 'position', 'name', 'item'} and item['@type'] == 'ListItem'
				and type(item['position']) is int and item['position'] == position
				and isinstance(item['name'], str) and item['name'].strip(), 'Invalid list item')
			target = item['item']
			require(set(target) == {'@type', '@id'} and target['@type'] == 'Claim'
				and url(target['@id']) and target['@id'].endswith('#claim'), 'Invalid Claim reference')
			ids.append(target['@id'])
	return ids

def compare_documents(legacy, native):
	"""Raise on unexpected differences; do not mutate either input."""
	if equal(legacy, native):
		return
	old, new = copy.deepcopy(legacy), copy.deepcopy(native)
	require(old.get('@context') == new.get('@context') == 'https://schema.org', 'Unexpected context')
	a, b = old.get('@graph'), new.get('@graph')
	require(isinstance(a, list) and isinstance(b, list) and len(a) == len(b), 'Graph node count')
	indexes = [i for i, node in enumerate(a) if node.get('@type') in ('QAPage', 'WebPage')]
	require(len(indexes) == 1, 'Expected exactly one historical page')
	index = indexes[0]
	op, np = a[index], b[index]
	debate = op['@type'] == 'QAPage'
	require(np.get('@type') == ['WebPage', 'LearningResource'], 'Native page resource types')
	np['@type'] = op['@type']
	lang = np['inLanguage']
	credit = 'Contributeurs de Wikidébats' if lang == 'fr' else 'Wikidebates contributors'
	require(np.pop('author', None) == {'@type': 'Organization', 'name': credit}, 'Page author')
	require(np.pop('creditText', None) == credit, 'Page credit')
	for key in ('author', 'creditText'):
		if key in op:
			require(equal(op.pop(key), {'@type': 'Organization', 'name': credit} if key == 'author' else credit), 'Changed existing ' + key)
	for key, value in education(lang, debate).items():
		require(np.pop(key, None) == value, 'Native educational metadata: ' + key)
		op.pop(key, None)
	for key in ('datePublished', 'dateModified'):
		if key in np:
			require(isinstance(np[key], str) and bool(re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ', np[key])), 'Invalid page timestamp')
			np.pop(key)
		op.pop(key, None)
	description = np.pop('description', None)
	if description is not None:
		require(isinstance(description, str) and bool(description.strip()), 'Invalid page description')
	op.pop('description', None)
	parts = parts_ids(np.pop('hasPart', []), np, debate)
	if debate:
		oq, nq = op['mainEntity'], np['mainEntity']
		answers = oq.pop('suggestedAnswer', [])
		count = oq.pop('answerCount', 0)
		require(count == len(answers), 'Invalid historical answer count')
		subsequence([answer['about']['@id'] for answer in answers], parts, 'debate argument references')
		require('datePublished' not in nq and 'answerCount' not in nq and 'suggestedAnswer' not in nq, 'Legacy Question properties on native graph')
		oq.pop('datePublished', None)
		require(text_matches(oq.pop('text'), nq.pop('text'), np['name']), 'Question text changed')
		if 'relatedLink' in np:
			links = np.pop('relatedLink')
			require(isinstance(links, list) and all(url(item) for item in links), 'Invalid relatedLink URLs')
			subsequence([item['@id'].removesuffix('#qa') for item in op.pop('isRelatedTo', [])], links, 'related debates')
	else:
		claims = [i for i, node in enumerate(a) if node.get('@type') == 'Claim']
		require(len(claims) == 1, 'Expected one main Claim')
		oc, nc = a[claims[0]], b[claims[0]]
		require(nc.pop('appearance', None) == {'@id': np['@id']}, 'Claim appearance identity')
		for key in ('discussionUrl', 'publisher'):
			require(equal(oc.pop(key), np.pop(key)), 'Page-level ' + key + ' changed')
		require(equal(oc.pop('breadcrumb'), np['breadcrumb']), 'Claim breadcrumb differed from page')
		for key in ('learningResourceType', 'educationalUse', 'educationalLevel', 'audience', 'datePublished'):
			require(key not in nc, 'Legacy Claim field still present: ' + key)
			oc.pop(key, None)
		subsequence([item['@id'] for item in oc.pop('hasPart', [])], parts, 'argument relation references')
		abstract = nc.pop('abstract', '')
		require(text_matches(oc.pop('abstract', ''), abstract), 'Claim abstract changed')
		if abstract and description is not None:
			require(abstract == description, 'Claim abstract and page description disagree')
	require(equal(old, new), 'Unapproved graph difference (identity, citation, relation or other property)')
