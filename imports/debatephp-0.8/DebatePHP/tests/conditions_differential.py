#!/usr/bin/env python3
"""Truth, operand values, lazy evaluation and side effects against Lua 5.1.

Tests the actual AST emitters in both builders, not a second implementation of
their logic. Loading their definition prefix avoids writing generated files.
"""
import json, pathlib, subprocess, sys
from lupa.lua51 import LuaRuntime
from luaparser import ast

root = pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0, str(root/'tools'))
lua = LuaRuntime(unpack_returned_tuples=True)
lua.execute('function touch(label,value) table.insert(trace,label);return value end')
values = [None, False, True, 0, 1, '', '0', 'alpha', {'test_table': True}]
expressions = ['a and b', 'a or b', 'not a', 'not (a or b)', '(a and b) or a',
               '(a == b) and (b ~= nil)', '(a ~= nil) or (b == false)',
               'touch("a",a) and touch("b",b)', 'touch("a",a) or touch("b",b)',
               'not (touch("a",a) and touch("b",b))',
               '(touch("a",a) == false) and (touch("b",b) ~= nil)',
               '(a == nil and touch("b",b)) or touch("a",a)']
nodes = [ast.parse('return '+expression).body.body[0].values[0] for expression in expressions]
cases, expected = [], []
for builder in ['buildArgument.py', 'buildReference.py']:
    path = root/'tools'/builder
    scope = {'__file__': str(path)}
    exec(path.read_text().split("header='''", 1)[0], scope)
    for expression, node in zip(expressions, nodes):
        for context in ['value', 'condition']:
            php = scope['e'](node) if context == 'value' else scope['condition'](node, scope['e'])
            php = php.replace('Lua::', '\\MediaWiki\\Extension\\DebatePHP\\Lua::')
            for a in values:
                for b in values:
                    lua.globals().a = lua.table() if isinstance(a,dict) else a
                    lua.globals().b = lua.table() if isinstance(b,dict) else b
                    lua.globals().trace = lua.table()
                    value = lua.execute('return '+(expression if context == 'value' else 'not not ('+expression+')'))
                    if lua.eval('type')(value) == 'table': value = {'test_table': True}
                    trace = list(lua.globals().trace.values())
                    cases.append(dict(builder=builder, expression=expression, context=context, php=php, a=a, b=b))
                    expected.append(dict(value=value, trace=trace))
run = subprocess.run([sys.argv[1] if len(sys.argv)>1 else 'php', str(root/'tests/conditions_native.php')],
                     input=json.dumps(cases), text=True, capture_output=True, check=True)
actual = json.loads(run.stdout)
# JSON/Python equate false and 0, so use serialized values for strict comparisons.
failures = [dict(case=case, expected=a, actual=b) for case,a,b in zip(cases,expected,actual)
            if json.dumps(a,sort_keys=True) != json.dumps(b,sort_keys=True)]
report = dict(comparisons=len(cases), passed=len(actual)==len(cases) and not failures, failures=failures[:5])
print(json.dumps(report, ensure_ascii=False))
sys.exit(not report['passed'])
