#!/usr/bin/env python3
"""Independent Lua 5.1 oracle for every i18n leaf; needs the Python lupa package."""
import json,subprocess,sys,pathlib
from lupa.lua51 import LuaRuntime
root=pathlib.Path(__file__).resolve().parent.parent
lua=LuaRuntime(unpack_returned_tuples=True)
data=lua.execute((root/'resources/WD-I18N-Data.snapshot.lua').read_text())
lua.globals().testData=data
lua.execute('mw={loadData=function() return testData end, ustring={lower=string.lower}, getContentLanguage=function() return {getCode=function() return "fr" end} end}')
i18n=lua.execute((root/'resources/WD-I18N.reference.lua').read_text())
queries=[];expected=[]
for domain in ['Common','Debate','Argument','ArgumentMap']:
    for section,items in data[domain].items():
        if lua.eval('type')(items)!='table':continue
        for key in items.keys():
            for lang in ['fr','en']:
                q=[domain+'.'+section,lang,key,'PAGE','SIDE','THIRD']
                queries.append(q);expected.append(i18n.msg(*q))
for domain in ['Missing','Debate.missing','Common.text']:
    for lang in ['fr-CA','EN','xx']:
        q=[domain,lang,'missing-key','PAGE'];queries.append(q);expected.append(i18n.msg(*q))
r=subprocess.run([sys.argv[1] if len(sys.argv)>1 else 'php',str(root/'tests/i18n_native.php')],input=json.dumps(queries),text=True,capture_output=True,check=True)
actual=json.loads(r.stdout)
for mode,values in zip(['reference','memoized'],actual):
    bad=[{'query':q,'lua':x,'php':y} for q,x,y in zip(queries,expected,values) if x!=y]
    if bad:print(json.dumps(bad[:10],ensure_ascii=False,indent=2));sys.exit(1)
    print(mode,len(values),'i18n comparisons passed')
