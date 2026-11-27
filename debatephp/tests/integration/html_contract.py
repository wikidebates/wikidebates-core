"""Canonical DOM and JSON-LD comparison for the frozen native render contract."""
import json
import urllib.parse
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

