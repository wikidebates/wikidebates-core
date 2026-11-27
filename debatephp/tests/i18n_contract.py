#!/usr/bin/env python3
"""Verify the frozen historical translation and substitution contract without Lua."""
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
BASELINE = 'ad37f75b2257b3bf0cb16d47ecf67fd20383a26e'


def verify(php):
	contract = json.loads((ROOT / 'fixtures' / 'i18n-contract.json').read_text(encoding='utf-8'))
	if contract['baseline'] != BASELINE or contract['format'] != 1:
		raise ValueError('Invalid contract provenance')
	rows = contract['cases']
	queries = [row['query'] for row in rows]
	expected = [row['expected'] for row in rows]
	if len(queries) != contract['count'] or len(set(map(tuple, queries))) != len(queries) or not queries:
		raise ValueError('Invalid or duplicated translation fixtures')
	result = subprocess.run([php, str(ROOT / 'i18n_native.php')],
		input=json.dumps(queries, ensure_ascii=False), text=True, capture_output=True, check=True)
	actual = json.loads(result.stdout)
	if len(actual) != 2 or any(len(row) != len(expected) for row in actual):
		raise ValueError('Unexpected translation response shape')
	for mode, values in zip(('uncached', 'memoized'), actual, strict=True):
		for query, want, got in zip(queries, expected, values, strict=True):
			if want != got:
				raise ValueError(f'{mode}: {query!r}: expected {want!r}, got {got!r}')
	print(f'PASS: {len(queries)} translation cases, both memoization policies, without Lua')


if __name__ == '__main__':
	verify(sys.argv[1] if len(sys.argv) > 1 else 'php')
