#!/usr/bin/env python3
"""Compare render, JSON-LD, metadata and boundary traces; retain only explicit normalizations."""
import json,sys,re,collections,statistics,urllib.parse,argparse
from html.parser import HTMLParser

def url_key(url):
	p=urllib.parse.urlsplit(url)
	if not p.query:return url
	# Order of distinct query keys is not meaningful. Retain duplicate-value order.
	q=urllib.parse.parse_qsl(p.query,keep_blank_values=True)
	q=sorted(enumerate(q),key=lambda x:(x[1][0],x[0]))
	return urllib.parse.urlunsplit((p.scheme,p.netloc,p.path,urllib.parse.urlencode([v for _,v in q]),p.fragment))

def canonical(v):
	return json.dumps(v,ensure_ascii=False,sort_keys=True,separators=(',',':'))

class DOM(HTMLParser):
	def __init__(self):
		super().__init__(convert_charrefs=True);self.events=[];self.script=False
	def handle_starttag(self,tag,attrs):
		self.script=tag=='script' and dict(attrs).get('type')=='application/ld+json'
		attrs=[(k,url_key(v) if k in ['href','data-href'] and v is not None else v) for k,v in attrs]
		self.events.append(('open',tag,sorted(attrs)))
	def handle_endtag(self,tag):
		self.events.append(('close',tag));self.script=False
	def handle_data(self,data):
		if self.script:data=canonical(json.loads(data))
		if self.events and self.events[-1][0]=='text':self.events[-1]=('text',self.events[-1][1]+data)
		else:self.events.append(('text',data))
	def handle_comment(self,text):self.events.append(('comment',text))
	def handle_startendtag(self,tag,attrs):self.handle_starttag(tag,attrs);self.handle_endtag(tag)

def html_key(s):
	p=DOM();p.feed(s);p.close();return p.events

def trace_input(s):
	if s.startswith('{{#tag:htmltag|') and '|tagname=script|' in s:
		a,b=s[len('{{#tag:htmltag|'):].rsplit('|tagname=script|',1)
		return ['jsonld',json.loads(a),b]
	if s.startswith('<htmltag '):
		return html_key(s)
	return s

def check(rows):
	failures=[];report=[]
	for row in rows:
		base=row['variants']['lua'];status={}
		if not row.get('full_stack') and not base['semantic']:
			failures.append([row['title'],'lua','missing semantic capture']);continue
		if 'scribunto-error' in base['html'] or 'Lua error' in base['html']:
			failures.append([row['title'],'lua','oracle error']);continue
		for mode in [m for m in row['variants'] if m!='lua']:
			other=row['variants'][mode];bad=[]
			if html_key(base['html'])!=html_key(other['html']):bad.append('HTML/JSON-LD')
			for key in ['semantic_snapshot','links','categories','properties','templates','externalLinks','languageLinks','modules','flags','expiry']:
				a,b=base.get(key),other.get(key)
				if key in ['links','flags','modules','languageLinks']:a,b=sorted(a),sorted(b)
				if a!=b:bad.append(key)
			if [collections.Counter(v) for v in base['semantic']]!=[collections.Counter(v) for v in other['semantic']]:bad.append('SMW inputs')
			a,b=base['trace'],other['trace']
			if mode=='legacy-php':a,b=[],[] # Uninstrumented archived production callback.
			if len(a)!=len(b):bad.append('expansion count')
			else:
				for i,(x,y) in enumerate(zip(a,b)):
					if trace_input(x['input'])!=trace_input(y['input']):bad.append('expansion input '+str(i))
					if x['output']!=y['output']:
					# Native protected HTML uses MediaWiki's item marker instead of htmltag.
					# Compare their resolved DOM/JSON values; keep exact raw comparison otherwise.
						resolved=('output_resolved' in x and 'output_resolved' in y
						and '\x7f' in x['output'] and '\x7f' in y['output'])
						if not resolved or html_key(x['output_resolved'])!=html_key(y['output_resolved']):
							bad.append('expansion output '+str(i))
					for key in ['volatile','ttl']:
						if key in x and x[key]!=y[key]:bad.append('expansion '+key+' '+str(i))
			if bad:failures.append([row['title'],mode,bad])
			status[mode]={'pass':not bad,'differences':bad}
		timing={}
		for mode,values in row.get('timings_ms',{}).items():
			values=sorted(values);n=len(values)
			timing[mode]={'n':n,'median_ms':round(statistics.median(values),3),'p95_ms':round(values[min(n-1,int(n*.95))],3),'min_ms':round(values[0],3),'max_ms':round(values[-1],3)}
		calls={mode:{'preprocess':sum(not x.get('kind','').startswith('native-') for x in v['trace']),
		'native':sum(x.get('kind','').startswith('native-') for x in v['trace'])}
		for mode,v in row['variants'].items()}
		report.append({'title':row['title'],'results':status,'expansions':len(base['trace']),'calls':calls,'timing':timing})
	return {'cases':report,'failures':failures,'all_passed':not failures}

if __name__=='__main__':
	parser=argparse.ArgumentParser()
	parser.add_argument('input');parser.add_argument('output',nargs='?');parser.add_argument('--oracle')
	args=parser.parse_args();rows=json.load(open(args.input))
	if args.oracle:
		oracle={r['title']:r for r in json.load(open(args.oracle))}
		for row in rows:
			original=oracle[row['title']]
			if row.get('source_sha256')!=original.get('source_sha256'):raise ValueError('Corpus source mismatch')
			row['variants']['lua']=original['variants']['lua']
	result=check(rows)
	text=json.dumps(result,ensure_ascii=False,indent=2)
	if args.output:open(args.output,'w').write(text+'\n')
	print(text)
	sys.exit(0 if result['all_passed'] else 1)
