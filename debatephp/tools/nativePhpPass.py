"""Second, reproducible native pass. Keep shared Tables at adapter boundaries."""
import re


def optimize(source, argument):
	def replace(name, transform):
		nonlocal source
		start = source.index('\tprotected function ' + name + '(')
		following = re.search(r'\n\t(?:protected|public) function ', source[start + 1:])
		end = start + 1 + following.start()
		source = source[:start] + transform(source[start:end]) + source[end:]

	source = source.replace('\tprivate ?DecodedReferences $decodedReferences = null;', '\tprivate ?DecodedReferences $decodedReferences = null;\n\tprivate ?RenderData $renderData = null;')
	source = source.replace('$this->decodedReferences = null;', '$this->decodedReferences = null;\n\t\t$this->renderData = null;')
	replace('splitCSV', lambda s: s.replace('SerializedData::csv($s, $sep)', '($this->renderData ??= new RenderData)->csv($s, $sep)'))
	if argument:
		replace('cacheMake', lambda _: '''	protected function cacheMake($max = null) {
		return new FifoCache($max);
	}
''')
		replace('cacheGet', lambda _: '''	protected function cacheGet($C = null, $k = null) {
		return $C->get($k);
	}
''')
		replace('cachePut', lambda _: '''	protected function cachePut($C = null, $k = null, $v = null) {
		$C->put($k, $v);
	}
''')
		# This alias map contains translated labels and must not survive a render/language.
		source = source.replace('$this->renderData = null;', '$this->renderData = null;\n\t\t$this->SUMMARY_WARNING_ALIASES = null;')

	def native_lists(s, variables):
		for variable in variables:
			v = '$' + variable
			s = s.replace(v + ' = new Table([]);', v + ' = [];')
			s = s.replace(v + '[(Lua::len(' + v + ') + 1)]', v + '[count(' + v + ') + 1]')
			s = s.replace('Lua::len(' + v + ')', 'count(' + v + ')')
			s = re.sub(r'Lua::insert\(' + re.escape(v) + r', (.*)\);', lambda m: v + '[count(' + v + ') + 1] = ' + m[1] + ';', s)
			s = s.replace('return ' + v + ';', 'return new Table(' + v + ');')
		return s

	def seo(s):
		s = native_lists(s, ['out', 'seen'])
		return s.replace('!(Lua::truth($seen[$v]))', '!isset($seen[$v])').replace("Lua::concat($out, ', ')", "implode(', ', $out)")
	replace('seoJoinList', seo)
	replace('buildSeoKeywords', lambda s: native_lists(s, ['items']).replace('$this->seoJoinList($items)', '$this->seoJoinList(new Table($items))'))
	for name in ['wkBuildKeywords', 'wkBuildQuoteAuthorValue']:
		replace(name, lambda s: native_lists(s, ['out']))
	replace('wkBuildAboutAndMentions', lambda s: native_lists(s, ['about', 'mentions']).replace('return [$about, $mentions];', 'return [new Table($about), new Table($mentions)];'))
	replace('buildSummaryLabelLookup', lambda s: native_lists(s, ['lookup']))
	if argument:
		replace('buildSummaryAliases', lambda s: native_lists(s, ['a']))

	# Only builders consuming normalized records from the literal marker decoders.
	names = re.findall(r'\tprotected function (wkBuildClaimCitations\w+)\(', source)
	pattern = r"Lua::trim\(Lua::str\(\(Lua::truth\((\$v\d+) = (\$item\['\w+'\])\) \? \1 : ''\)\)\)"
	plain_pattern = r"Lua::trim\(\(Lua::truth\((\$v\d+) = (\$item\['\w+'\])\) \? \1 : ''\)\)"
	for name in names:
		replace(name, lambda s: re.sub(pattern, r'\2', s).replace('function($item = null) {\n', 'function($item = null) {\n\t\t$item = $item->values;\n'))
	for name in ['wkRenderOneBiblioLi', 'wkRenderOneWeblioLi', 'wkRenderOneVideoLi'] + (['wkRenderOneQuoteHtml'] if argument else []):
		def normalized_record(s):
			s = re.sub(pattern, r'\2', s)
			s = re.sub(plain_pattern, r'\2', s)
			return s.replace(' {\n', ' {\n\t\t$item = $item->values;\n', 1)
		replace(name, normalized_record)

	# Snapshot iteration: no intermediate one-based copy, and no ArrayAccess per item.
	needle = 'Lua::ipairs('
	while needle in source:
		start = source.index(needle)
		argument_start = start + len(needle)
		depth = 1
		quote = None
		escaped = False
		for end in range(argument_start, len(source)):
			char = source[end]
			if quote:
				if escaped:
					escaped = False
				elif char == '\\':
					escaped = True
				elif char == quote:
					quote = None
			elif char in "'\"":
				quote = char
			elif char == '(':
				depth += 1
			elif char == ')':
				depth -= 1
				if depth == 0:
					break
		else:
			raise AssertionError('Unclosed ipairs argument')
		source = source[:start] + '(' + source[argument_start:end] + ')->sequence()' + source[end + 1:]
	return source
