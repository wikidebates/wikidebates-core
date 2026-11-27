#!/usr/bin/env python3
"""Migration-only study; report exact differences before freezing the no-Lua contract."""
from collections import Counter
from copy import deepcopy
import json
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit
from pathlib import Path
import sys

from check_results import html_key

historical, native, output = map(Path, sys.argv[1:])
before = json.loads(historical.read_text(encoding='utf-8'))
after = json.loads(native.read_text(encoding='utf-8'))
if len(before) != 144 or len(after) != len(before):
	raise ValueError('Full-stack corpus size changed')

counts = Counter()
differences = []
examples = {}

def first_diff(x, y, path=''):
	if type(x) is not type(y):
		return path, repr(x)[:500], repr(y)[:500]
	if isinstance(x, dict):
		for key in sorted(x.keys() | y.keys(), key=str):
			if key not in x or key not in y:
				return path + '/' + str(key), repr(x.get(key))[:500], repr(y.get(key))[:500]
			if x[key] != y[key]:
				return first_diff(x[key], y[key], path + '/' + str(key))
	elif isinstance(x, (list, tuple)):
		for i, (a, b) in enumerate(zip(x, y)):
			if a != b:
				return first_diff(a, b, path + '/' + str(i))
		return path + '/length', len(x), len(y)
	elif isinstance(x, str):
		i = next((i for i, (a, b) in enumerate(zip(x, y)) if a != b), min(len(x), len(y)))
		return path + '/offset=' + str(i), x[max(0, i-100):i+300], y[max(0, i-100):i+300]
	return path, repr(x)[:500], repr(y)[:500]
FORM_NAMES = {'New argument title', "Nouveau titre d'argument"}
NUMBER_PROPERTIES = {'Argument_number', 'Debate_number', "Numéro_d'argument", 'Numéro_de_débat'}


def own_form_id(url, page_id):
	if not isinstance(url, str):
		return url
	parts = urlsplit(url)
	if parts.path != '/w/index.php':
		return url
	pairs = parse_qsl(parts.query, keep_blank_values=True)
	form_names = {name.replace(' ', '_') for name in FORM_NAMES}
	if not any(k == 'title' and any(v == 'Special:RunQuery/' + name for name in form_names) for k, v in pairs):
		return url
	# Only a verified reference to this fixture's own page ID is laboratory-specific.
	keys = {name + '[ID]' for name in FORM_NAMES}
	replaced = [(k, '@subject' if k in keys and v == str(page_id) else v) for k, v in pairs]
	return urlunsplit((parts.scheme, parts.netloc, parts.path, urlencode(replaced), parts.fragment))


def html_contract(html, page_id):
	events = []
	for event in html_key(html):
		if event[0] == 'open':
			tag, attrs = event[1:]
			attrs = [(k, own_form_id(v, page_id) if k in ('href', 'data-href') else v) for k, v in attrs]
			event = ('open', tag, attrs)
		events.append(event)
	return events


def semantic_contract(snapshot, page_id):
	result = deepcopy(snapshot)
	for key, values in result.get('properties', {}).items():
		if key not in NUMBER_PROPERTIES:
			continue
		for item in values:
			if item == [9, str(page_id) + '#0##']:
				item[1] = '@subject#0##'
	return result


for old, new in zip(before, after, strict=True):
	name = old.get('name', old['title'])
	if old['title'] != new['title'] or name != new.get('name', new['title']):
		raise ValueError('Full-stack corpus identity changed: ' + name)
	if set(new['variants']) != {'optimized'}:
		raise ValueError('Native-only suite executed unexpected variants: ' + name)
	a, b = old['variants']['optimized'], new['variants']['optimized']
	if a.keys() != b.keys():
		raise ValueError('Result fields changed: ' + name)
	old_id, new_id = old['subject_id'], new['subject_id']
	if not isinstance(old_id, int) or not isinstance(new_id, int) or min(old_id, new_id) <= 0:
		raise ValueError('Missing fixture page identity: ' + name)
	changed = []
	for key in a:
		if key == 'milliseconds':
			continue
		x, y = a[key], b[key]
		if key == 'html':
			x, y = html_contract(x, old_id), html_contract(y, new_id)
		elif key in ('semantic_snapshot', 'stored_semantics'):
			x, y = semantic_contract(x, old_id), semantic_contract(y, new_id)
		elif key == 'templates':
			x, y = ({ns: sorted(pages) for ns, pages in (v or {}).items()} for v in (x, y))
		elif key == 'links':
			x, y = (sorted((link[0], link[1]) for link in v) for v in (x, y))
		if x != y:
			counts[key] += 1
			changed.append(key)
			if len(examples.setdefault(key, [])) < 4:
				examples[key].append({'case': name, 'difference': (x, y) if key == 'modules' else first_diff(x, y)})
	if changed:
		differences.append({'case': name, 'fields': changed})

report = {'cases': len(after), 'equal_cases': len(after) - len(differences),
	'field_differences': dict(counts), 'differences': differences,
	'scope': 'Diagnostic only: only lab-specific link/template numeric identities and verified self page IDs in form URLs and semantic number fields are projected; other differences remain visible.'}
output.write_text(json.dumps(report, ensure_ascii=False, indent='\t') + '\n', encoding='utf-8')
print(json.dumps({k: v for k, v in report.items() if k != 'differences'}, ensure_ascii=False))
for key, rows in examples.items():
	for row in rows:
		print('NATIVE_DIFF ' + key + ' ' + json.dumps(row, ensure_ascii=True))
