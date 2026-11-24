#!/usr/bin/env python3
"""Check native graphs against a pinned official Schema.org release.

This deliberately enforces the expected domain and range for this project's
graph subset. It is not a universal JSON-LD parser or a Google eligibility
test. Unresolved HTTP(S) @id references are open-world links, not inferred
claims about their target type; no remote pages are fetched.
"""
import argparse
from collections import Counter
from datetime import date, datetime
from functools import lru_cache
import json
from pathlib import Path
import re
from urllib.parse import urlsplit

PREFIXES = ('https://schema.org/', 'http://schema.org/', 'schema:')
RDFS = ('http://www.w3.org/2000/01/rdf-schema#', 'rdfs:')
RDF = ('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:')
SCRIPT = re.compile(r'<script\b[^>]*\btype="application/ld\+json"[^>]*>(.*?)</script>', re.S | re.I)

def as_list(value):
	return value if isinstance(value, list) else [] if value is None else [value]

def name(value, prefixes=PREFIXES):
	if isinstance(value, dict):
		value = value.get('@id', '')
	if not isinstance(value, str):
		return None
	for prefix in prefixes:
		if value.startswith(prefix):
			return value[len(prefix):]
	return None

def terms(node, term, prefixes):
	return [n for prefix in prefixes for item in as_list(node.get(prefix + term))
		if (n := name(item)) is not None]

class Vocabulary:
	def __init__(self, data):
		self.parents = {}
		self.properties = {}
		for node in data['@graph']:
			key = name(node.get('@id'))
			kinds = as_list(node.get('@type'))
			if not key:
				continue
			if any(name(t, RDFS) == 'Class' or name(t) == 'DataType' for t in kinds):
				self.parents.setdefault(key, set()).update(terms(node, 'subClassOf', RDFS))
			if any(name(t, RDF) == 'Property' for t in kinds):
				domains, ranges = self.properties.setdefault(key, (set(), set()))
				domains.update(terms(node, 'domainIncludes', PREFIXES))
				ranges.update(terms(node, 'rangeIncludes', PREFIXES))
		if not {'Thing','CreativeWork','Claim','LearningResource','VideoObject'} <= self.parents.keys() \
			or not {'citation','educationalLevel','issueNumber','relatedLink'} <= self.properties.keys():
			raise ValueError('Incomplete vocabulary or unexpected release format')

	@lru_cache(maxsize=None)
	def ancestors(self, kind):
		seen, stack = set(), [kind]
		while stack:
			current = stack.pop()
			if current not in seen:
				seen.add(current)
				stack.extend(self.parents.get(current, ()))
		return seen

	def compatible(self, actual, expected):
		return any(self.ancestors(t) & expected for t in actual)

def absolute_url(value):
	if not isinstance(value, str) or re.search(r'[\x00-\x20\x7f<>"\\]|%(?![0-9a-fA-F]{2})', value):
		return False
	try:
		u = urlsplit(value)
		return u.scheme in ('http', 'https') and bool(u.hostname) and u.username is None and u.password is None
	except ValueError:
		return False

def iso_date(value):
	if not isinstance(value, str):
		return False
	m = re.fullmatch(r'(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?', value)
	if not m:
		return False
	try:
		date(int(m[1]), int(m[2] or 1), int(m[3] or 1))
		return True
	except ValueError:
		return False

def iso_datetime(value):
	if not isinstance(value, str) or not re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|[+-]\d\d:\d\d)', value):
		return False
	try:
		datetime.fromisoformat(value.replace('Z', '+00:00'))
		return True
	except ValueError:
		return False

def objects(value):
	if isinstance(value, dict):
		yield value
		for item in value.values():
			yield from objects(item)
	elif isinstance(value, list):
		for item in value:
			yield from objects(item)

def validate(document, vocab):
	errors, counts = [], Counter()
	used_types, used_properties = set(), set()
	if not isinstance(document, dict) or document.get('@context') != 'https://schema.org' \
		or not isinstance(document.get('@graph'), list) or not document['@graph']:
		return ['Invalid native graph envelope'], counts, used_types, used_properties
	graph = document['@graph']
	ids = {}
	for node in objects(graph):
		if isinstance(node.get('@id'), str):
			ids.setdefault(node['@id'], set()).update(t for t in as_list(node.get('@type')) if isinstance(t, str))
	def kinds(node):
		explicit = set(t for t in as_list(node.get('@type')) if isinstance(t, str))
		return explicit or ids.get(node.get('@id'), set())
	def fail(path, message):
		errors.append(path + ': ' + message)
	def walk(node, path):
		if not isinstance(node, dict):
			fail(path, 'Expected typed object')
			return
		counts['objects'] += 1
		explicit = as_list(node.get('@type'))
		types = kinds(node)
		if any(not isinstance(t, str) or t not in vocab.parents for t in explicit):
			fail(path, 'Unknown @type ' + repr(explicit))
		used_types.update(t for t in explicit if isinstance(t, str))
		if '@id' in node and not absolute_url(node['@id']):
			fail(path, 'Invalid @id')
		if not explicit and '@id' not in node:
			fail(path, 'Missing @type or @id')
		if '@id' not in node and len(node) == 1 and '@type' in node:
			fail(path, 'Empty typed entity')
		if node.get('@type') == 'PropertyValue' and 'value' not in node:
			fail(path, 'Identifier without a value')
		for prop, raw in node.items():
			if prop in ('@type','@id'):
				continue
			at = path + '.' + prop
			used_properties.add(prop)
			counts['properties'] += 1
			if prop not in vocab.properties:
				fail(at, 'Unknown Schema.org property')
				continue
			domains, ranges = vocab.properties[prop]
			if types and domains and not vocab.compatible(types, domains):
				fail(at, 'Unexpected domain ' + repr(sorted(types)))
			values = as_list(raw)
			if not values:
				fail(at, 'Empty property')
			for i, value in enumerate(values):
				vpath = at + '[' + str(i) + ']'
				if isinstance(value, dict):
					child = kinds(value)
					if child:
						if ranges and not vocab.compatible(child, ranges):
							fail(vpath, 'Unexpected range ' + repr(sorted(child)))
					elif '@id' in value and absolute_url(value['@id']):
						counts['unresolved_id_references'] += 1
					walk(value, vpath)
				elif isinstance(value, str):
					if not value.strip():
						fail(vpath, 'Blank text')
					elif not (('Text' in ranges) or ('URL' in ranges and absolute_url(value))
						or ('Date' in ranges and iso_date(value)) or ('DateTime' in ranges and iso_datetime(value))):
						fail(vpath, 'Unexpected string range; expected ' + repr(sorted(ranges)))
				elif type(value) is bool:
					if 'Boolean' not in ranges:
						fail(vpath, 'Unexpected Boolean')
				elif type(value) in (int, float):
					if not vocab.compatible({'Integer' if type(value) is int else 'Float'}, ranges):
						fail(vpath, 'Unexpected numeric range')
				else:
					fail(vpath, 'Unexpected JSON value')
	for i, node in enumerate(graph):
		walk(node, '@graph[' + str(i) + ']')
	return errors, counts, used_types, used_properties

def self_test(vocab):
	# A typed reference to a film is valid; an Issue property on Person and
	# a citation to an unrelated Thing are not within the expected vocabulary.
	good = {'@context':'https://schema.org','@graph':[
		{'@type':['WebPage','LearningResource'],'@id':'https://example.test/page','name':'Page',
			'mainEntity':{'@id':'https://example.test/page#claim'}},
		{'@type':'Claim','@id':'https://example.test/page#claim','name':'Claim',
			'citation':[{'@type':'VideoObject','name':'Film','url':'https://example.test/film'},
				{'@type':'Book','name':'Book','datePublished':'2020-02'}]}]}
	if validate(good, vocab)[0]:
		raise ValueError('Valid graph rejected: ' + repr(validate(good, vocab)[0]))
	bad = [
		{'@type':'Person','name':'Ada','issueNumber':'1'},
		{'@type':'Claim','name':'Claim','citation':{'@type':'Thing','name':'Thing'}},
		{'@type':'MadeUpType','name':'Name'},
		{'@type':'Thing','name':'Name','madeUpProperty':'Value'},
		{'@type':'Person','name':''},
		{'@type':'Book','name':'Book','datePublished':'2023-02-29'},
		{'@type':'VideoObject','name':'Film','url':'not a URL'},
		{'@type':'Book','name':'Book','identifier':{'@type':'PropertyValue','propertyID':'number'}},
		{'@type':'Claim','name':'Claim','citation':[]}]
	for node in bad:
		if not validate({'@context':'https://schema.org','@graph':[node]}, vocab)[0]:
			raise ValueError('Invalid fixture accepted: ' + repr(node))

def main():
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument('vocabulary', type=Path)
	parser.add_argument('renders', type=Path)
	parser.add_argument('output', type=Path)
	args = parser.parse_args()
	vocab = Vocabulary(json.loads(args.vocabulary.read_text(encoding='utf-8')))
	self_test(vocab)
	rows = json.loads(args.renders.read_text(encoding='utf-8'))
	counts, errors, types, props = Counter(), [], set(), set()
	for row in rows:
		for mode in ('reference','optimized'):
			for match in SCRIPT.finditer(row['variants'][mode]['html']):
				doc = json.loads(match[1])
				bad, stats, used_t, used_p = validate(doc, vocab)
				counts['graphs'] += 1
				counts.update(stats)
				types.update(used_t)
				props.update(used_p)
				errors.extend({'case':row.get('name', row['title']),'mode':mode,'error':e} for e in bad)
	if not counts['graphs']:
		errors.append({'error':'No native graphs validated'})
	report = {'vocabulary':'Schema.org 30.1','passed':not errors,'counts':dict(counts),
		'used_types':sorted(types),'used_properties':sorted(props),'errors':errors,
		'scope':'Expected domain/range and nonempty values in native corpus; unresolved @id targets are not fetched'}
	result = json.dumps(report, ensure_ascii=False, indent=2) + '\n'
	args.output.write_text(result, encoding='utf-8')
	print(result)
	return bool(errors)

if __name__ == '__main__':
	raise SystemExit(main())
