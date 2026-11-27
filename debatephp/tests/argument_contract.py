#!/usr/bin/env python3
"""Verify the frozen, Lua-validated Argument contract without a Lua runtime."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
FIXTURES = ROOT / 'fixtures' / 'argument-cases.json'
SNAPSHOT = ROOT / 'fixtures' / 'argument-contract.json'
BASELINE = 'ad37f75b2257b3bf0cb16d47ecf67fd20383a26e'
EFFECTS = {'preprocess', 'ask', 'set', 'seo', 'variables'}


def digest(value):
	return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True,
		separators=(',', ':'), allow_nan=False).encode('utf-8')).hexdigest()


def load_cases():
	return json.loads(FIXTURES.read_text(encoding='utf-8'))


def fingerprint(result):
	if set(result) != {'text', 'effects'} or set(result['effects']) != EFFECTS:
		raise ValueError('Unexpected renderer result or effects: ' + repr(list(result)))
	return {'text': digest(result['text']),
		**{name: digest(value) for name, value in result['effects'].items()}}


def capture_snapshot(original, enriched, actual):
	"""Capture only after independent historical comparisons pass."""
	if len(original) != len(enriched) or len(enriched) != len(actual):
		raise ValueError('Unexpected fixture count')
	for source, case, row in zip(original, enriched, actual, strict=True):
		entry = {'name': source['name'], 'input_sha256': digest(source),
			'answers': case['answers'], 'publication_date': case['publication_date'],
			'expected': fingerprint(row[0])}
		print('WD_ARG_SNAPSHOT ' + json.dumps(entry, ensure_ascii=True,
			sort_keys=True, separators=(',', ':')))


def verify(php):
	sources = load_cases()
	snapshot = json.loads(SNAPSHOT.read_text(encoding='utf-8'))
	if snapshot['baseline'] != BASELINE or snapshot['format'] != 1:
		raise ValueError('Unexpected contract provenance or format')
	entries = snapshot['cases']
	if not sources or len(sources) != len(entries):
		raise ValueError('Fixture count differs from the frozen contract')
	corpus = []
	seen = set()
	for source, entry in zip(sources, entries, strict=True):
		name = source['name']
		if name in seen or entry['name'] != name or entry['input_sha256'] != digest(source):
			raise ValueError('Fixture identity or input changed: ' + name)
		seen.add(name)
		corpus.append({**source, 'answers': entry['answers'],
			'publication_date': entry['publication_date']})
	proc = subprocess.run([php, '-n', str(ROOT / 'argument_context.php')],
		input=json.dumps(corpus, ensure_ascii=False), text=True, capture_output=True, check=True)
	actual = json.loads(proc.stdout)
	if len(actual) != len(corpus) or any(len(row) != 1 for row in actual):
		raise ValueError('Expected exactly one result per fixture')
	for entry, row in zip(entries, actual, strict=True):
		got = fingerprint(row[0])
		if got != entry['expected']:
			diffs = sorted(key for key in got if got[key] != entry['expected'].get(key))
			raise ValueError(entry['name'] + ': changed output/effects: ' + ', '.join(diffs))
	print(f'PASS: {len(corpus)} frozen Argument cases; HTML/JSON-LD, preprocessing, SMW reads/writes, SEO and variables')


if __name__ == '__main__':
	verify(sys.argv[1] if len(sys.argv) > 1 else 'php')
