#!/usr/bin/env python3
"""Compare visible output/metadata exactly; account for the removed HTMLTags JSON escaping.
Only the legacy oracle's JSON string encoding is reproduced, not normalized away on both sides.
"""
import json,re,sys,pathlib,importlib.util
spec=importlib.util.spec_from_file_location('base',pathlib.Path(__file__).with_name('check_results.py'));base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
pattern=re.compile(r'(<script\b[^>]*\btype="application/ld\+json"[^>]*>)(.*?)(</script>)',re.S|re.I)
def scripts(html):return [json.loads(m.group(2)) for m in pattern.finditer(html)]
def visible(html):return pattern.sub(lambda m:m.group(1)+'{}'+m.group(3),html)
def legacy_graph(x):
 if isinstance(x,dict):return {k:legacy_graph(v) for k,v in x.items()}
 if isinstance(x,list):return [legacy_graph(v) for v in x]
 if isinstance(x,str):return x.replace('|','&#124;').replace('=','&#61;').replace('}}','} }').replace('&','&amp;').replace('<','&lt;')
 return x
rows=json.loads(pathlib.Path(sys.argv[1]).read_text());bad=[];corrections=0;tested=0
for row in rows:
 name=row.get('name',row['title']);want=row['variants']['lua']
 if 'scribunto-error' in want['html']:bad.append([name,'lua','oracle error']);continue
 for mode in ['reference','optimized']:
  tested+=1;got=row['variants'][mode];dif=[]
  if base.html_key(visible(want['html']))!=base.html_key(visible(got['html'])):dif.append('visible HTML')
  try:
   a,b=scripts(want['html']),scripts(got['html'])
   if a!=b:
    if a==legacy_graph(b):corrections+=1
    else:dif.append('JSON-LD beyond expected legacy escaping')
  except Exception as e:dif.append('Invalid JSON-LD: '+str(e))
  for key in ['categories','properties','templates','links','externalLinks','languageLinks','modules','flags','expiry']:
   a,b=want[key],got[key]
   if key=='templates':
    # Only the obsolete source reads disappear. Keep every actual template dependency.
    a,b=a or {},b or {}
    a={ns:{k:v for k,v in pages.items() if not (str(ns)=='828' and k in ['Debate','Argument','WD/I18N','WD/I18N/Data'] and k not in b.get(ns,{}))} for ns,pages in a.items()}
    a={ns:pages for ns,pages in a.items() if pages}
   if key in ['languageLinks','modules','flags']:a,b=sorted(a),sorted(b)
   if a!=b:dif.append(key)
  if 'semantic' in want:
   from collections import Counter
   if [Counter(x) for x in want['semantic']]!=[Counter(x) for x in got['semantic']]:dif.append('SMW values')
  if dif:bad.append([name,mode,dif])
report={'cases':len(rows),'comparisons':tested,'passed':not bad,'failures':bad,'jsonld_encoding_corrections':corrections,
 'comparison':'Visible output and metadata unchanged except removal of obsolete Lua source dependencies; JSON nodes checked against Lua after reproducing its old escaping on the new raw values'}
text=json.dumps(report,ensure_ascii=False,indent=2)+'\n';print(text)
if len(sys.argv)>2:pathlib.Path(sys.argv[2]).write_text(text)
sys.exit(bool(bad))
