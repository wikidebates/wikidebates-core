#!/usr/bin/env python3
"""Frozen real MediaWiki/SMW native render contract; no Lua or oracle dependency."""
from copy import deepcopy
import hashlib
import json
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit
import sys

from check_results import html_key

ROOT = Path(__file__).resolve().parents[1]
BASELINE = '2593e16944ef5b0c0c52f00dcbe748de2d71fd4f'
SNAPSHOT = ROOT / 'fixtures' / 'fullstack-contract.json'
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



def digest(value):
	return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True,
		separators=(',', ':'), allow_nan=False).encode('utf-8')).hexdigest()


def projected_fields(variant, page_id):
	result = {}
	for key, value in variant.items():
		if key == 'milliseconds':
			continue
		if key == 'html':
			value = html_contract(value, page_id)
		elif key in ('semantic_snapshot', 'stored_semantics'):
			value = semantic_contract(value, page_id)
		elif key == 'templates':
			value = {ns: sorted(pages) for ns, pages in (value or {}).items()}
		elif key == 'links':
			value = sorted((link[0], link[1]) for link in value)
		result[key] = value
	return result


def entry(row):
	page_id = row['subject_id']
	if type(page_id) is not int or page_id <= 0 or not row['full_stack']:
		raise ValueError('Invalid fixture identity')
	source = row['source_sha256']
	if len(source) != 64 or any(c not in '0123456789abcdef' for c in source):
		raise ValueError('Invalid fixture source digest')
	return {'name': row['name'], 'title': row['title'], 'source_sha256': source,
		'seo_fixture': row['seo_fixture'],
		'fields': {k: digest(v) for k, v in projected_fields(row['variants']['optimized'], page_id).items()}}


def validate_rows(rows):
	if len(rows) != 144:
		raise ValueError('Expected 144 real render fixtures')
	entries = [entry(row) for row in rows]
	if len({row['name'] for row in entries}) != len(entries):
		raise ValueError('Repeated fixture name')
	if sum('stored_semantics' in row['fields'] for row in entries) != 14:
		raise ValueError('Missing persisted semantic snapshots')
	return entries


def capture(historical_path, native_path, comparison_path, parity_path, schema_path):
	historical = json.loads(historical_path.read_text(encoding='utf-8'))
	native = json.loads(native_path.read_text(encoding='utf-8'))
	comparison = json.loads(comparison_path.read_text(encoding='utf-8'))
	parity = json.loads(parity_path.read_text(encoding='utf-8'))
	schema = json.loads(schema_path.read_text(encoding='utf-8'))
	if (comparison.get('passed') is not True or comparison.get('comparisons') != 144
		or parity.get('equal_cases') != 144 or parity.get('field_differences') != {}
		or schema.get('passed') is not True or schema.get('counts', {}).get('graphs') != 146):
		raise ValueError('Historical, native parity or vocabulary validation incomplete')
	if any(set(row['variants']) != {'optimized'} for row in native):
		raise ValueError('Unexpected native-only variant')
	expected, actual = validate_rows(historical), validate_rows(native)
	if expected != actual:
		raise ValueError('Frozen field contract does not match validated historical environment')
	for row in expected:
		print('WD_FULLSTACK_CONTRACT ' + json.dumps(row, ensure_ascii=True, sort_keys=True, separators=(',', ':')))


def verify(renders_path, snapshot_path=SNAPSHOT):
	contract = json.loads(snapshot_path.read_text(encoding='utf-8'))
	if contract['format'] != 1 or contract['baseline'] != BASELINE:
		raise ValueError('Invalid baseline provenance')
	rows = json.loads(renders_path.read_text(encoding='utf-8'))
	if any(set(row['variants']) != {'optimized'} for row in rows):
		raise ValueError('Unexpected native-only variant')
	actual = validate_rows(rows)
	expected = contract['cases']
	if len(expected) != len(actual):
		raise ValueError('Unexpected contract size')
	for want, got in zip(expected, actual, strict=True):
		if want != got:
			keys = sorted(k for k in want['fields'].keys() | got['fields'].keys()
				if want['fields'].get(k) != got['fields'].get(k))
			raise ValueError(f"{want['name']}: fixture identity or fields changed: {keys}")
	print('PASS: 144 frozen full-stack render cases, including 14 persisted semantic snapshots')


def self_test():
	url = '/w/index.php?New+argument+title%5BID%5D=31&title=Special%3ARunQuery%2FNew_argument_title'
	assert '%40subject' not in own_form_id(url, 32)
	assert '%40subject' in own_form_id(url, 31)
	assert own_form_id('/wiki/Test?New+argument+title%5BID%5D=31', 31).endswith('=31')
	data = {'properties': {'Argument_number': [[9, '31#0##']], 'Other': [[9, '31#0##']]}, 'subobjects': {}}
	assert semantic_contract(data, 31)['properties'] == {'Argument_number': [[9, '@subject#0##']], 'Other': [[9, '31#0##']]}
	assert semantic_contract(data, 32) == data
	assert digest(html_contract('<p>Changed</p>', 31)) != digest(html_contract('<p>Original</p>', 31))


if __name__ == '__main__':
	self_test()
	if len(sys.argv) == 7 and sys.argv[1] == '--capture':
		capture(*map(Path, sys.argv[2:]))
	elif len(sys.argv) == 2:
		verify(Path(sys.argv[1]))
	else:
		raise SystemExit('Usage: fullstack_contract.py renders.json')
