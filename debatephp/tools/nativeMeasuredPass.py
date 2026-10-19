"""Measured local fragment reductions; keep shared Table boundaries and wiki effects."""
import re


def optimize(source, argument):
	def replace(name, transform):
		nonlocal source
		start = source.index('\tprotected function ' + name + '(')
		following = re.search(r'\n\t(?:protected|public) function ', source[start + 1:])
		end = start + 1 + following.start()
		source = source[:start] + transform(source[start:end]) + source[end:]

	def fragments(s, variables):
		for variable in variables:
			v = '$' + variable
			s = s.replace(v + ' = new Table([]);', v + ' = [];')
			s = s.replace(v + '[(Lua::len(' + v + ') + 1)]', v + '[]')
			s = s.replace('Lua::len(' + v + ')', 'count(' + v + ')')
			s = re.sub(r'Lua::insert\(' + re.escape(v) + r', (.*)\);', lambda m: v + '[] = ' + m[1] + ';', s)
			s = re.sub(r'Lua::concat\(' + re.escape(v) + r"(?:, ('[^']*'|\$this->\w+))?\)", lambda m: 'implode(' + (m[1] or "''") + ', ' + v + ')', s)
		return s

	def fused(s):
		loops = list(re.finditer(r'\t\tforeach \(\(\$items\)->sequence\(\) as (?:\$_|\$i) => \$it\) \{\n(.*?)\n\t\t}', s, re.S))
		assert len(loops) == (3 if argument else 4), len(loops)
		variables = ['listLines', 'argsVarParts', 'hoverLines'] if argument else ['listLines', 'argsVarParts', 'mapLines', 'hoverLines']
		prefix = s[:s.index('\t\t$listLines =')]
		declarations = ''.join('\t\t$' + v + ' = [];\n' for v in variables)
		body = '\n'.join(m[1] for m in loops)
		# In the compatibility Table, inserting nil removes the destination slot.
		body = body.replace("Lua::insert($argsVarParts, $it['page']);", "if ($it['page'] !== null) { Lua::insert($argsVarParts, $it['page']); }")
		assignments = ''.join(m[0] + '\n' for m in re.finditer(r'\t\t\$\w+ = Lua::concat\([^)]*\);', s))
		result = prefix + declarations + '\t\tforeach (($items)->sequence() as $i => $it) {\n' + body + '\n\t\t}\n' + assignments + s[s.index('\t\treturn new Table('):]
		return fragments(result, variables)

	replace('wkBuildArgumentData', fused)
	if not argument:
		for name, variables in [('wkRenderIntroSubsection', ['out', 'banners']), ('wkRenderIntroSubsectionsBlock', ['out']), ('renderReferences', ['wrap', 'content']), ('renderArgsList', ['lis'])]:
			replace(name, lambda s, variables=variables: fragments(s, variables))
	else:
		# Plain titles avoid allocation; only expensive textual decoding is memoized.
		source = source.replace('\tprivate ?RenderData $renderData = null;', '\tprivate ?RenderData $renderData = null;\n\tprivate ?FifoCache $cardTitleCache = null;')
		source = source.replace('$this->renderData = null;', '$this->renderData = null;\n\t\t$this->cardTitleCache = null;')
		def title_cache(s):
			return s[:s.index('\n')] + r'''
		$s = trim(Lua::str($s === null || $s === false ? '' : $s), " \t\r\n\v\f");
		if ($s === '' || ($s[0] !== ':' && strpbrk($s, '[<&') === false)) { return $s; }
		$key = $s;
		$cache = $this->cardTitleCache ??= new FifoCache(200);
		$hit = $cache->get($key);
		if ($hit !== null) { return $hit; }
		$s = preg_replace('/^:[\x09-\x0d ]*/', '', $s);
		if (preg_match('/^\[\[([^\]|]+)\|[^\n]*?\]\]$/D', $s, $match)
			|| preg_match('/^\[\[([^\]]+)\]\]$/D', $s, $match)) { $s = $match[1]; }
		$s = preg_replace('/^:[\x09-\x0d ]*/', '', trim($s, " \t\r\n\v\f"));
		// str_replace applies pairs sequentially, preserving nested entity behavior.
		if (str_contains($s, '&')) {
			$s = str_replace(['&nbsp;', '&#160;', '&amp;', '&quot;', '&#39;', '&lt;', '&gt;'], [' ', ' ', '&', '"', "'", '<', '>'], $s);
		}
		$s = trim($s, " \t\r\n\v\f");
		if (strlen($key) <= 1024) { $cache->put($key, $s); }
		return $s;
	}
'''

		replace('normalizeTitleForCard', title_cache)
	return source
