#!/usr/bin/env python3
"""Check complete real-parser outputs from argument_render.php."""
import pathlib,sys,json,importlib.util
p=pathlib.Path(__file__).with_name('check_results.py')
s=importlib.util.spec_from_file_location('base',p);base=importlib.util.module_from_spec(s);s.loader.exec_module(base)
rows=json.loads(pathlib.Path(sys.argv[1]).read_text());failures=[]
for row in rows:
 want=row['variants']['lua']
 if 'scribunto-error' in want['html']:failures.append([row['name'],'lua','oracle error']);continue
 for mode in ['reference','optimized']:
  got=row['variants'][mode];bad=[]
  if 'scribunto-error' in got['html']:bad.append('render error')
  try:
   if base.html_key(want['html'])!=base.html_key(got['html']):bad.append('HTML/JSON-LD')
  except Exception as e:bad.append('invalid HTML/JSON-LD: '+str(e))
  for key in ['categories','properties','templates','links','externalLinks','languageLinks','modules','flags','expiry']:
   a,b=want[key],got[key]
   if key in ['languageLinks','modules','flags']:a,b=sorted(a),sorted(b)
   if a!=b:bad.append(key)
  if bad:failures.append([row['name'],mode,bad])
report={'cases':len(rows),'comparisons':len(rows)*2,'passed':not failures,'failures':failures,
 'scope':'Real MediaWiki parser, LuaStandalone, Cite, ParserFunctions, ShortDescription and PageForms; no production SMW store'}
text=json.dumps(report,ensure_ascii=False,indent=2)+'\n';print(text)
if len(sys.argv)>2:pathlib.Path(sys.argv[2]).write_text(text)
sys.exit(bool(failures))
