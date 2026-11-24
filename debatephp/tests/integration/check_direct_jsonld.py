#!/usr/bin/env python3
"""Compare visible output/metadata exactly; account for the removed HTMLTags JSON escaping.
Only the legacy oracle's JSON string encoding is reproduced, not normalized away on both sides.
"""
import json,re,sys,pathlib,importlib.util
from schema_projection import compare_documents, resolve_markers, plain, MARKER
spec=importlib.util.spec_from_file_location('base',pathlib.Path(__file__).with_name('check_results.py'));base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
pattern=re.compile(r'(<script\b[^>]*\btype="application/ld\+json"[^>]*>)(.*?)(</script>)',re.S|re.I)
def scripts(html):return [json.loads(m.group(2)) for m in pattern.finditer(html)]
def visible(html):return pattern.sub(lambda m:m.group(1)+'{}'+m.group(3),html)
def legacy_graph(x):
	if isinstance(x,dict):return {k:legacy_graph(v) for k,v in x.items()}
	if isinstance(x,list):return [legacy_graph(v) for v in x]
	if isinstance(x,str):return x.replace('|','&#124;').replace('=','&#61;').replace('}}','} }').replace('&','&amp;').replace('<','&lt;')
	return x
# Only these exact legacy page_props moved to the native extension payload.
SEO_FIELDS = {'title', 'title_mode', 'title_separator', 'description', 'keywords',
	'author', 'image', 'image_alt', 'type', 'section', 'site_name', 'twitter_site',
	'locale', 'robots', 'googlebot'}
RETIRED_CATEGORIES = {'Articles_sans_date_de_création', 'Articles_without_creation_date'}

def compare_parser_metadata(key, a, b, row, got):
	fixture = row.get('seo_fixture')
	if fixture is not None:
		if key == 'categories':
			a = {k:v for k,v in (a or {}).items() if k not in RETIRED_CATEGORIES}
		if key == 'properties':
			a, b = dict(a or {}), dict(b or {})
			payload = got.get('native_seo')
			if not isinstance(payload, dict):
				raise ValueError('Missing native SEO payload')
			markers = row['variants']['lua'].get('seo_marker_map') or {}
			plain_fields = set(payload.get('wd_plain_fields', []))
			if plain_fields - {'title', 'description', 'image_alt', 'wd_summary'}:
				raise ValueError('Unexpected pre-normalized SEO fields')
			for field in SEO_FIELDS:
				if field in a:
					expected = a[field]
					if isinstance(expected, str) and MARKER.search(expected):
						if field in {'title','description','image_alt'}:
							if field not in plain_fields:
								raise ValueError('Missing plain field marker: ' + field)
							expected = plain(expected)
						expected = resolve_markers(expected, markers)
					if field in b or payload.get(field) != expected:
						raise ValueError('Native SEO value/persistence differs: ' + field)
					del a[field]
				elif field in payload:
					raise ValueError('Unexpected native SEO field: ' + field)
			if set(payload) - SEO_FIELDS - {'wd_kind','wd_published_time','wd_summary','wd_plain_fields'}:
				raise ValueError('Unexpected native SEO payload keys')
			if 'wd_published_time' not in payload or payload.get('wd_kind') not in ('argument','debate'):
				raise ValueError('Invalid native SEO payload')
		if key == 'flags':
			a = list(a)
			required = 'vary-revision' if fixture['preview'] else 'vary-revision-timestamp'
			if required not in a:
				a.append(required)
	return a,b

def check_fixture_dates(row, got, documents):
	fixture = row.get('seo_fixture')
	if fixture is None:
		return
	payload = got.get('native_seo') or {}
	for doc in documents:
		pages = [n for n in doc['@graph'] if 'WebPage' in n.get('@type', [])]
		if len(pages) != 1 or pages[0].get('dateModified') != fixture['modified']:
			raise ValueError('Graph modification date does not match fixture revision')
		if pages[0].get('datePublished') != payload.get('wd_published_time'):
			raise ValueError('Graph publication date differs from native SEO payload')
		if fixture['preview'] != (payload.get('wd_published_time') is None):
			raise ValueError('Stored revision/preview publication date mismatch')

def walk_strings(value):
	if isinstance(value, str):
		yield value
	elif isinstance(value, dict):
		for item in value.values():
			yield from walk_strings(item)
	elif isinstance(value, list):
		for item in value:
			yield from walk_strings(item)

rows=json.loads(pathlib.Path(sys.argv[1]).read_text());bad=[];corrections=0;semantic_changes=0;tested=0
for row in rows:
	name=row.get('name',row['title']);want=row['variants']['lua']
	if 'scribunto-error' in want['html'] or 'Lua error' in want['html']:bad.append([name,'lua','oracle error']);continue
	for mode in ['reference','optimized']:
		tested+=1;got=row['variants'][mode];dif=[]
		if base.html_key(visible(want['html']))!=base.html_key(visible(got['html'])):dif.append('visible HTML')
		try:
			a,b=scripts(want['html']),scripts(got['html'])
			if any(MARKER.search(v) for v in walk_strings(b)) or any(MARKER.search(v) for v in walk_strings(got.get('native_seo'))):
				raise ValueError('Unresolved native SEO marker')
			check_fixture_dates(row, got, b)
			if a!=b:
				if a==legacy_graph(b):corrections+=1
				else:
					if len(a)!=len(b):dif.append('JSON-LD document count')
					else:
						for old,new in zip(a,b):
							compare_documents(old,new,want.get('seo_marker_map') or {})
						semantic_changes+=1
		except Exception as e:dif.append('Invalid JSON-LD: '+str(e))
		for key in ['categories','properties','templates','links','externalLinks','languageLinks','modules','flags','expiry']:
			a,b=want[key],got[key]
			try:
				a,b=compare_parser_metadata(key,a,b,row,got)
			except (ValueError, TypeError) as e:
				dif.append(key + ': ' + str(e));continue
			if key=='templates':
			# Only the obsolete source reads disappear. Keep every actual template dependency.
				a,b=a or {},b or {}
				a={ns:{k:v for k,v in pages.items() if not (str(ns)=='828' and k in ['Debate','Argument','WD/I18N','WD/I18N/Data'] and k not in b.get(ns,{}))} for ns,pages in a.items()}
				a={ns:pages for ns,pages in a.items() if pages}
			if key in ['languageLinks','modules','flags']:a,b=sorted(a),sorted(b)
			if a!=b:
				dif.append(key)
				if key in ('categories','properties','flags','modules') and row.get('seo_fixture') is not None:
					print('DIFF',name,mode,key,repr(a)[:1500],repr(b)[:1500],file=sys.stderr)
		if 'semantic' in want:
			from collections import Counter
			if [Counter(x) for x in want['semantic']]!=[Counter(x) for x in got['semantic']]:dif.append('SMW values')
		for key in ['semantic_snapshot','variables','stored_semantics']:
			if key in want or key in got:
				if want.get(key)!=got.get(key):dif.append(key)
		if dif:bad.append([name,mode,dif])
	# Both native modes must also match exactly, including newly added list entries.
	left,right=row['variants']['reference'],row['variants']['optimized']
	for key in ['html','categories','properties','templates','links','externalLinks','languageLinks','modules','flags','expiry','semantic_snapshot','variables','stored_semantics','native_seo']:
		if key=='html':
			if base.html_key(left[key])!=base.html_key(right[key]):bad.append([name,'native parity',key])
		elif left.get(key)!=right.get(key):bad.append([name,'native parity',key])
report={'cases':len(rows),'comparisons':tested,'passed':not bad,'failures':bad,'jsonld_encoding_corrections':corrections,'semantic_schema_changes':semantic_changes,
	'comparison':'Visible output and metadata unchanged except removal of obsolete Lua source dependencies; JSON checked against Lua using exact legacy escaping or explicit semantic projection; other differences fail'}
text=json.dumps(report,ensure_ascii=False,indent=2)+'\n';print(text)
if len(sys.argv)>2:pathlib.Path(sys.argv[2]).write_text(text)
sys.exit(bool(bad))
