#!/usr/bin/env python3
"""Migration-only study; report exact differences before freezing the no-Lua contract."""
from collections import Counter
import json
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
for old, new in zip(before, after, strict=True):
	name = old.get('name', old['title'])
	if old['title'] != new['title'] or name != new.get('name', new['title']):
		raise ValueError('Full-stack corpus identity changed: ' + name)
	if set(new['variants']) != {'optimized'}:
		raise ValueError('Native-only suite executed unexpected variants: ' + name)
	a, b = old['variants']['optimized'], new['variants']['optimized']
	if a.keys() != b.keys():
		raise ValueError('Result fields changed: ' + name)
	changed = []
	for key in a:
		if key == 'milliseconds':
			continue
		x, y = a[key], b[key]
		if key == 'html':
			x, y = html_key(x), html_key(y)
		elif key == 'templates':
			x, y = ({ns: sorted(pages) for ns, pages in (v or {}).items()} for v in (x, y))
		elif key == 'links':
			x, y = (sorted((link[0], link[1]) for link in v) for v in (x, y))
		if x != y:
			counts[key] += 1
			changed.append(key)
	if changed:
		differences.append({'case': name, 'fields': changed})

report = {'cases': len(after), 'equal_cases': len(after) - len(differences),
	'field_differences': dict(counts), 'differences': differences,
	'scope': 'Diagnostic only: template and link identities compared without lab-specific numeric IDs; no differences are approved by this study.'}
output.write_text(json.dumps(report, ensure_ascii=False, indent='\t') + '\n', encoding='utf-8')
print(json.dumps(report, ensure_ascii=False))
