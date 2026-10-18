#!/usr/bin/env python3
"""Compare Argument's PHP renderers with the supplied Lua, across a mocked MW boundary.
Requires Python lupa and PHP 8.1+. This is not a full MediaWiki integration test.
"""
import json, re, sys, subprocess, hashlib, urllib.parse, pathlib, difflib, random
from lupa.lua51 import LuaRuntime
ROOT=pathlib.Path(__file__).resolve().parent.parent

def oracle(case):
	lua=LuaRuntime(unpack_returned_tuples=True)
	def table(value):
		if isinstance(value,dict): return lua.table_from({k:table(v) for k,v in value.items()})
		if isinstance(value,list): return lua.table_from([table(v) for v in value])
		return value
	def plain(value):
		if lua.eval('type')(value)=='table':
			d={k:plain(v) for k,v in value.items()}
			if d and set(d)==set(range(1,len(d)+1)):return list(d.values()) if list(d)==list(range(1,len(d)+1)) else [d[i] for i in range(1,len(d)+1)]
			return d if d else []
		return value
	effects={'preprocess':[],'ask':[],'set':[],'seo':[],'variables':{}}
	answers={}
	def preprocess(text):
		effects['preprocess'].append(text)
		if text==case.get('preprocess_fail'):raise RuntimeError('expansion failed')
		return text.replace('{{CURRENTYEAR}}-{{CURRENTMONTH}}-{{CURRENTDAY2}}','2026-10-18')
	def encode(text,mode='QUERY'):
		if mode=='WIKI':return urllib.parse.quote(text.replace(' ','_'),safe='!$()*,./:;@~_-')
		return urllib.parse.quote_plus(text,safe='') if mode=='QUERY' else urllib.parse.quote(text,safe='')
	def url(page,query=None):
		q=plain(query) if query is not None else {}
		return 'https://wiki.example/wiki/'+encode(page,'WIKI')+('?' + urllib.parse.urlencode(q) if q else '')
	def ask(q):
		q=plain(q); effects['ask'].append(q)
		if case.get('ask_error'):raise RuntimeError('query failed')
		key='\n'.join(q)
		res=[]
		if any('=pro' in s or '=con' in s for s in q):res=case.get('debate_rows',[])
		elif any('=justif' in s or '=obj' in s for s in q):res=case.get('argument_rows',[])
		elif any('=pdeb' in s for s in q):res=case.get('inherited_rows',[])
		elif any('=chain' in s for s in q):res=case.get('breadcrumb_rows',[])
		elif any('=map' in x for x in q):res=case.get('dedicated_rows',[])
		elif 'limit=1' in q:res=case.get('init_rows',[])
		elif any('argument' in s.lower() and s.startswith('?') for s in q):res=case.get('dedicated_rows',[])
		if not res:
			for needle,rows in case.get('query_rules',[]):
				if any(needle in s for s in q):res=rows;break
		answers[key]=res
		return table(res)
	def setvar(name,value):effects['variables'][name]=value.strip()
	lua.globals().py_preprocess=preprocess
	lua.globals().py_encode=encode
	lua.globals().py_url=url
	lua.globals().py_json=lambda t:json.dumps(plain(t),ensure_ascii=False,separators=(',',':'))
	lua.globals().py_ask=ask
	lua.globals().py_set=lambda t:effects['set'].append(plain(t))
	lua.globals().py_seo=lambda t:effects['seo'].append(plain(t))
	lua.globals().py_setvar=setvar
	lua.globals().py_getvar=lambda name:effects['variables'].get(name)
	lua.globals().testargs=table(case['args'])
	lua.globals().testtitle=case['title']
	lua.globals().testlang=case.get('lang','fr')
	lua.execute('''
local function html(tag)
 local o={attrs={},keys={},children={}}
 function o:attr(k,v) if self.attrs[k]==nil then table.insert(self.keys,k) end self.attrs[k]=v return self end
 function o:addClass(c) return self:attr('class',self.attrs['class'] and self.attrs['class']..' '..c or c) end
 function o:wikitext(s) table.insert(self.children,s) return self end
 function o:node(c) table.insert(self.children,c) return self end
 return setmetatable(o,{__tostring=function(self)
  local out={'<'..tag}
  for _,k in ipairs(self.keys) do
   local v=tostring(self.attrs[k]):gsub('&','&amp;'):gsub('<','&lt;'):gsub('>','&gt;'):gsub('"','&quot;')
   out[#out+1]=' '..k..'="'..v..'"'
  end
  out[#out+1]='>'..table.concat(self.children)..'</'..tag..'>' return table.concat(out)
 end})
end
frame={args=testargs}
function frame:getParent() return {args={}} end
function frame:preprocess(s) return py_preprocess(s) end
mw={getCurrentFrame=function() return frame end,
 text={trim=function(s) return (s:gsub('^%s+',''):gsub('%s+$','')) end,
 gsplit=function(s,sep,plain)
  local pos=1;return function()
   if not pos then return nil end
   local a,b=s:find(sep,pos,plain)
   if a then local v=s:sub(pos,a-1);pos=b+1;return v end
   local v=s:sub(pos);pos=nil;return v
  end
 end,
 nowiki=function(s) return '<nowiki>'..s..'</nowiki>' end,
 jsonEncode=function(t) return py_json(t) end},
 html={create=html},
 title={new=function(s) return {prefixedText=s:gsub('_',' ')} end,
 getCurrentTitle=function() return {prefixedText=testtitle,id=42} end},
 uri={encode=function(s,m) return py_encode(s,m or 'QUERY') end,fullUrl=function(p,q) return py_url(p,q) end,
 localUrl=function(p,q) return py_url(p,q):sub(#'https://wiki.example'+1) end},
 smw={ask=function(q) return py_ask(q) end,set=function(t) py_set(t) end},
 hash={hashValue=function(_,s) return s end},
 ext={seo={set=function(t) py_seo(t) end},VariablesLua={vardefine=function(k,v) py_setvar(k,v) end,var=function(k) return py_getvar(k) end}},
 ustring={lower=string.lower},getContentLanguage=function() return {getCode=function() return testlang end} end}
''')
	if not case.get('smw',True):lua.execute('mw.smw=nil')
	if not case.get('vars',True):lua.execute('mw.ext.VariablesLua=nil')
	if not case.get('seo',True):lua.execute('mw.ext.seo=nil')
	data=lua.execute((ROOT/'resources/WD-I18N-Data.snapshot.lua').read_text())
	lua.globals().testData=data
	lua.execute('mw.loadData=function() return testData end')
	i18n=lua.execute((ROOT/'resources/WD-I18N.reference.lua').read_text())
	lua.globals().testI18N=i18n
	lua.execute('require=function(name) return testI18N end')
	module=lua.execute((ROOT/'resources/Module-Argument.reference.lua').read_text())
	text=module.render(lua.globals().frame)
	case['answers']=answers
	# JSON arrays are represented by PHP's Table's one-based keys before JSON encoding.
	effects['ask']=[{str(i+1):v for i,v in enumerate(q)} for q in effects['ask']]
	return {'text':text,'effects':effects}

def normalize(value):
	if isinstance(value,dict):return {k:normalize(v) for k,v in value.items()}
	if isinstance(value,list):return [normalize(v) for v in value]
	if isinstance(value,str):
	# Query parameter order does not change link destinations (Lua pairs is unordered).
		def canonical_url(m):
			url=m.group(0); separator='&amp;' if '&amp;' in url else '&'
			base,query=url.split('?',1)
			return base+'?'+separator.join(sorted(query.split(separator)))
		value=re.sub(r'''(?:https?://|/wiki/)[^\s"<>]*\?[^\s"<>]+''',canonical_url,value)
		# Object order in Lua's JSON encoder is deliberately unspecified.
		def schema(m):
			raw=m.group(1).replace('&#124;','|').replace('&#61;','=')
			return 'SCHEMA:'+json.dumps(json.loads(raw),ensure_ascii=False,sort_keys=True,separators=(',',':'))
		return re.sub(r'\{\{#tag:htmltag\|(.*?)\|tagname=script.*?\}\}',schema,value,flags=re.S)
	return value

def cases():
	base={'title':'Un argument de démonstration','args':{}}
	out=[]
	def add(name,args=None,**kw):out.append(dict(base,name=name,args=args or {},**kw))
	add('empty-fr'); add('empty-en',{'lang':'en'});add('extensions-absent',smw=False,vars=False,seo=False)
	add('summary',{'summary':"Un résumé avec [[Lien|libellé]], = et |.", 'keywords':'Énergie, Climat, Énergie','sections':'Sciences, Politique','creation-date':'2025-03-12','established-name':'Autre nom','interlanguage':'[[en:Example]]'})
	add('whitespace',{'summary':' \t\n','quotes':' ','keywords':'  ','sections':' ','creation-date':' '})
	add('zero',{'summary':'0','keywords':'0','established-name':'0','creation-date':'0'})
	add('dedicated',{'dedicated-debate':'[[Débat_dédié|libellé]]','summary':'Un résumé'})
	add('init-today',{'initialization':'Argument pour@123','creation-date':'2026-10-18'})
	for lang in ['fr','en']:
		add('init-'+lang,{'lang':lang,'initialization':('Argument pour' if lang=='fr' else 'Pro argument')+'@id-123','creation-date':'2025-01-01'},init_rows=[{'fulltext':'Débat parent'}])
		add('warnings-'+lang,{'lang':lang,'title-warnings':'title_non_standard,title_to_explain,title_to_simplify','argument-warnings':'argument_orphan,argument_redundant,argument_to_split','summary':'Texte','summary-warnings':'summary_unclear,summary_too_long','justification-warnings':'justifications_to_reorganize','objection-warnings':'objections_to_reorganize'})
	add('arguments',{'justifications':'⟭Justification A⟬Titre A⟬displayed_title_unclear⟭Justification B⟬⟬','objections':'⟭Objection A⟬Contre⟬displayed_title_too_long'})
	add('one-item-without-separator',{'justifications':'Page⟬Titre⟬avertissement'})
	add('references',{'bibliography':'⟭Alice, Bob⟬Article⟬Livre⟬2⟬5⟬Section⟬12-15⟬Éditeur⟬Paris⟬2020⟬https://example.org/a?x=1&y=2⟬dead_link','webliography':'⟭Page⟬Site⟬https://example.org⟬Chloé⟬2024⟬reference_incomplete','videography':'⟭Vidéo⟬https://example.org/v⟬David⟬reference_quality_insufficient'})
	quote='⟬⟬⟬'.join(['Texte de citation','Alice, Bob','Article','Livre','2','3','Lieu','12','Éditeur','Paris','2020','https://example.org','quote_translated'])
	add('quote',{'quotes':quote})
	add('quotes-many',{'quotes':'⟭⟭⟭'.join([quote]*6)})
	add('quotes-no-variables',{'quotes':quote},vars=False)
	add('references-english',{'lang':'en','bibliography':'⟭⟬⟬Un livre⟬⟬⟬⟬⟬⟬⟬2020⟬⟬reference_incomplete'})
	add('parents',debate_rows=[{'fulltext':'Débat parent','pro':['Un argument de démonstration'],'con':[]}],argument_rows=[{'fulltext':'Argument parent','justif':{'fulltext':'Un argument de démonstration'},'obj':[]}],breadcrumb_rows=[{'chain':'Débat parent⟭Argument parent'}])
	add('inherited',argument_rows=[{'fulltext':'Parent','obj':['Un argument de démonstration']}],inherited_rows=[{'chain':['Débat racine⟭Parent'],'pdeb':[{'fulltext':'Débat racine'}]}],breadcrumb_rows=[{'chain':['Débat racine⟭Parent','Débat racine⟭A⟭B']}])
	add('long-chain',breadcrumb_rows=[{'chain':'Débat racine⟭A⟭B⟭C'}])
	add('multiple-chains',breadcrumb_rows=[{'chain':['Racine A⟭Parent','Racine B⟭A⟭B⟭C','Racine A']}])
	add('queries-fail',ask_error=True)
	# Real translated labels (main banners deliberately accept labels, not raw keys).
	l=LuaRuntime(unpack_returned_tuples=True)
	d=l.execute((ROOT/'resources/WD-I18N-Data.snapshot.lua').read_text())
	l.globals().testData=d
	l.execute('mw={loadData=function() return testData end,ustring={lower=string.lower}}')
	i=l.execute((ROOT/'resources/WD-I18N.reference.lua').read_text())
	warning_groups={
	'title-warnings':('banners',['title_incomplete','title_disadvantageous','title_unclear','title_too_long']),
	'argument-warnings':('banners',['argument_sensitive','argument_fanciful','argument_potentially_illegal','argument_generated_by_ai']),
	'summary-warnings':('subsection_banners',['summary_disadvantageous','summary_to_be_written','summary_unclear','summary_too_long','summary_to_separate','summary_style_to_review']),
	'justification-warnings':('subsection_banners',['justifications_to_reorganize']),
	'objection-warnings':('subsection_banners',['objections_to_reorganize'])}
	for lang in ['fr','en']:
		args={'lang':lang,'summary':'Résumé avec \"guillemets\" et apostrophes'}
		for param,(section,keys) in warning_groups.items():
			args[param]=','.join(i.msg('Argument.'+section,lang,k+'_label') for k in keys)
		add('translated-banners-'+lang,args)
		for typ,formkey in [('pro','arg_pro'),('con','arg_con'),('justification',None),('objection',None)]:
			val=i.msg('Argument.forms',lang,formkey) if formkey else i.msg('Argument.params',lang,typ+'_type_label')
			add('initialization-'+lang+'-'+typ,{'lang':lang,'initialization':val+'@123','creation-date':'2025-01-01'},init_rows=[{'fulltext':'Parent réel'}])
	for title in ['Court','Deux mots','Cela est vrai.','Ça suffit','Au contraire, cet argument est faux','Un titre etc.','[[Titre|libellé]]','Un titre &amp; des accents éàç','Un titre/avec ? # fragment']:
		out.append(dict(base,name='title-'+title,title=title,args={'keywords':'A,B','sections':'[[Injection]],Normal,{{Template}},Valide'}))
	add('fallback-parents',query_rules=[('[[Argument pour::',[{'fulltext':'Débat fallback'}]),('[[Objection::',[{'fulltext':'Parent fallback'}])])
	add('dedicated-map',{'dedicated-debate':'Débat dédié','justifications':'⟭A⟬Titre A⟬'},dedicated_rows=[{'map':['<div>Carte</div>']}])
	add('preprocess-failure',{'quotes':'FAIL'},preprocess_fail='FAIL')
	add('both-parent-types',debate_rows=[{'fulltext':'Parent débat','pro':['Un argument de démonstration'],'con':[{'fulltext':'Un argument de démonstration'}]}],argument_rows=[{'fulltext':'Parent argument','justif':['Un argument de démonstration'],'obj':['Un argument de démonstration']}])
	rng=random.Random(4304)
	values=['','0','Texte','éàç漢字','[[Lien|Texte]]','A & B','A=B','A|B','<ref>x</ref>']
	for index in range(40):
		args={'lang':rng.choice(['fr','en']),'summary':rng.choice(values),'keywords':','.join(rng.choices(values,k=3)),'sections':'Science,Test','creation-date':'2025-01-01'}
		for param,fields,sep,fs in [('quotes',13,'⟭⟭⟭','⟬⟬⟬'),('bibliography',12,'⟭','⟬'),('webliography',6,'⟭','⟬'),('videography',4,'⟭','⟬'),('justifications',3,'⟭','⟬'),('objections',3,'⟭','⟬')]:
			if rng.random()<.7:args[param]=sep+sep.join(fs.join(rng.choices(values,k=fields)) for _ in range(rng.randrange(1,4)))
		add('serialized-'+str(index),args)
	return out

if __name__=='__main__':
	corpus=cases(); expected=[]
	for c in corpus: expected.append(oracle(c))
	proc=subprocess.run([sys.argv[1] if len(sys.argv)>1 else 'php','-n',str(ROOT/'tests/argument_context.php')],input=json.dumps(corpus),text=True,capture_output=True,check=True)
	actual=json.loads(proc.stdout);bad=[]
	for c,want,modes in zip(corpus,expected,actual):
		for mode,got in zip(['reference','optimized'],modes):
			if normalize(want)!=normalize(got):
				bad.append((c['name'],mode))
				print(c['name'],mode)
				print('\n'.join(list(difflib.unified_diff(json.dumps(normalize(want),ensure_ascii=False,indent=2).splitlines(),json.dumps(normalize(got),ensure_ascii=False,indent=2).splitlines()))[:25]))
				break
		if bad:break
	report={'cases':len(corpus),'renderer_comparisons':len(corpus)*2,'passed':not bad,'failures':bad,'scope':'Lua 5.1 versus PHP, mocked MediaWiki boundary; not a live wiki benchmark'}
	if len(sys.argv) > 2:
		pathlib.Path(sys.argv[2]).write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
	print(json.dumps(report));sys.exit(bool(bad))
