#!/usr/bin/env python3
"""Migration-only study; report exact differences before freezing the no-Lua contract."""
from collections import Counter
import json
from pathlib import Path
import sys

from fullstack_contract import projected_fields

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
	xfields, yfields = projected_fields(a, old_id), projected_fields(b, new_id)
	changed = []
	for key, x in xfields.items():
		y = yfields[key]
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
