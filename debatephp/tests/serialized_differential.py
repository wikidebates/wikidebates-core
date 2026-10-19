#!/usr/bin/env python3
"""Check the reviewed native string methods against the original local Lua functions."""
import json, pathlib, random, re, subprocess, sys
from lupa.lua51 import LuaRuntime
root = pathlib.Path(__file__).resolve().parent.parent
cases, expected = [], []
rng = random.Random(707)
for module in ['Argument', 'Debate']:
	lua = LuaRuntime(unpack_returned_tuples=True)
	lua.execute('''
mw = {getCurrentFrame=function() return {} end, text={}, html={}, title={}, uri={}}
mw.text.trim=function(s) return (s:gsub('^%s+',''):gsub('%s+$','')) end
mw.text.gsplit=function(s,sep)
 local pos=1; return function()
  if not pos then return nil end
  local a,b=s:find(sep,pos,true)
  if a then local value=s:sub(pos,a-1);pos=b+1;return value end
  local value=s:sub(pos);pos=nil;return value
 end
end
require=function() return {} end
''')
	names = ['splitCSV', 'wkParseBiblioMarkers', 'wkParseWeblioMarkers', 'wkParseVideoMarkers',
	'anchorId', 'normalizeTooltipText', 'escapeAttr', 'wkSplit2']
	names += ['splitByLiteral', 'wkExtractItemsFromSerializedData', 'wkParseQuoteMarkers', 'wkEncodePathSegment', 'breadcrumbLenByteSafe'] if module == 'Argument' else ['wkExtractArgumentItemsFromData', 'wkParseIntroSubsections']
	source = (root / 'resources' / f'Module-{module}.reference.lua').read_text()
	source = re.sub(r'return p\s*$', 'return {' + ','.join(f'{name}={name}' for name in names) + '}', source)
	methods = lua.execute(source)
	def plain(value):
		if isinstance(value, tuple): return [plain(item) for item in value]
		if lua.eval('type')(value) != 'table': return value
		entries = {k: plain(v) for k, v in value.items()}
		return [entries[i] for i in range(1, len(entries)+1)] if not entries or set(entries) == set(range(1,len(entries)+1)) else entries
	def add(method, args):
		cases.append(dict(module=module, method=method, args=args))
		value = methods[method](*args)
		# gsub may also return a replacement count; renderer call sites use only
		# its string result. wkSplit2 deliberately uses both returned strings.
		if isinstance(value, tuple) and method != 'wkSplit2': value = value[0]
		expected.append(plain(value))
	samples = ['', '0', 'simple', ' ⟭ ', 'A⟬B', 'A⟬⟭', '⟭A⟬B⟬C⟬D⟭', '\tA\n⟬ B⟭', 'é⟭漢字⟬0⟭', '⟭⟭⟭Titre⟬⟬⟬Contenu⟬⟬⟬warn⟭⟭⟭',
	'&apos; &#039; &#39; &amp; "quoted"', 'A\rB\r\nC\tD\vE\fF', 'A\x00B', '[a]{b}|c#d<e>"f', '%25%2F/?#', "L’argument d'Alice", '\u00a0 A\u00a0B ']
	samples += [''.join(rng.choice(['A', '0', '⟬', '⟭', ' ', '\t', '\n', 'é']) for _ in range(rng.randrange(70))) for _ in range(140)]
	for method in names:
		if method in ['splitByLiteral', 'wkSplit2']:
			for text in ['', 'A⟬B⟬C', '⟬A⟬', 'aXXbXXc', '0']:
				for separator in ['', '⟬', 'XX']:
					if method == 'wkSplit2': add(method, [text, separator])
					else:
						for limit in [None, False, 0, 1, 2, 6]: add(method, [text, separator, limit])
		else:
			for text in samples: add(method, [text])
			if method in ['anchorId','normalizeTooltipText','escapeAttr','wkEncodePathSegment']:
				for value in [None, False, True, 0, 1]: add(method, [value])
result = subprocess.run([sys.argv[1] if len(sys.argv)>1 else 'php', str(root/'tests/serialized_native.php')], input=json.dumps(cases, ensure_ascii=False), text=True, capture_output=True, check=True)
actual = json.loads(result.stdout)
failures = [dict(case=case, expected=a, actual=b) for case,a,b in zip(cases,expected,actual) if a != b]
report = dict(comparisons=len(cases), passed=len(actual)==len(cases) and not failures, failures=failures[:10])
print(json.dumps(report, ensure_ascii=False))
sys.exit(not report['passed'])
