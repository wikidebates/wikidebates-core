#!/usr/bin/env python3
"""Frozen independently validated contract for native text and list methods."""
from collections import defaultdict
import hashlib
import json
from pathlib import Path
import random
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
BASELINE = 'ad37f75b2257b3bf0cb16d47ecf67fd20383a26e'


def fixtures():
	cases = []
	rng = random.Random(707)
	for module in ['Argument', 'Debate']:
		names = ['splitCSV', 'wkParseBiblioMarkers', 'wkParseWeblioMarkers', 'wkParseVideoMarkers',
		'anchorId', 'normalizeTooltipText', 'escapeAttr', 'wkSplit2']
		names += ['splitByLiteral', 'wkExtractItemsFromSerializedData', 'wkParseQuoteMarkers', 'wkEncodePathSegment', 'breadcrumbLenByteSafe'] if module == 'Argument' else ['wkExtractArgumentItemsFromData', 'wkParseIntroSubsections']
		def add(method, args):
			cases.append(dict(module=module, method=method, args=args))
		if module == 'Argument':
			add('breadcrumbLenByteSafe', ['A⟭' * 100000])
		samples = ['', '0', 'simple', ' ⟭ ', 'A⟬B', 'A⟬⟭', '⟭A⟬B⟬C⟬D⟭', '\tA\n⟬ B⟭', 'é⟭漢字⟬0⟭', '⟭⟭⟭Titre⟬⟬⟬Contenu⟬⟬⟬warn⟭⟭⟭',
		'&apos; &#039; &#39; &amp; "quoted"', 'A\rB\r\nC\tD\vE\fF', 'A\x00B', '[a]{b}|c#d<e>"f', '%25%2F/?#', "L’argument d'Alice", '\u00a0 A\u00a0B ']
		samples += [''.join(rng.choice(['A', '0', '⟬', '⟭', ' ', '\t', '\n', 'é']) for _ in range(rng.randrange(70))) for _ in range(140)]
		for method in names:
			if method in ['splitByLiteral', 'wkSplit2']:
				for text in ['', 'A⟬B⟬C', '⟬A⟬', 'aXXbXXc', '0']:
					for separator in ['', '⟬', 'XX']:
						if method == 'wkSplit2': add(method, [text, separator])
						else:
							for limit in [None, False, 0, 1, 2, 6]: add(method, [text, separator, limit])
			else:
				for text in samples: add(method, [text])
				if method in ['anchorId','normalizeTooltipText','escapeAttr','wkEncodePathSegment','breadcrumbLenByteSafe']:
					for value in [None, False, True, 0, 1]: add(method, [value])
	return cases


def digest(value):
	return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True,
		separators=(',', ':'), allow_nan=False).encode('utf-8')).hexdigest()


def fingerprints(cases, actual):
	if len(cases) != len(actual):
		raise ValueError('Native response length differs')
	groups = defaultdict(list)
	for case, value in zip(cases, actual, strict=True):
		groups[case['module'] + '/' + case['method']].append([case['args'], value])
	return {name: {'count': len(rows), 'sha256': digest(rows)} for name, rows in groups.items()}


def verify(php):
	contract = json.loads((ROOT / 'fixtures' / 'serialized-contract.json').read_text(encoding='utf-8'))
	if contract['baseline'] != BASELINE or contract['format'] != 1:
		raise ValueError('Invalid contract provenance')
	cases = fixtures()
	result = subprocess.run([php, str(ROOT / 'serialized_native.php')],
		input=json.dumps(cases, ensure_ascii=False), text=True, capture_output=True, check=True)
	actual = fingerprints(cases, json.loads(result.stdout))
	if actual != contract['groups']:
		diff = sorted(key for key in actual.keys() | contract['groups'].keys()
			if actual.get(key) != contract['groups'].get(key))
		raise ValueError('Changed serialized inputs/results: ' + ', '.join(diff))
	print(f'PASS: {len(cases)} serialized cases in {len(actual)} method groups without Lua')


if __name__ == '__main__':
	verify(sys.argv[1] if len(sys.argv) > 1 else 'php')
