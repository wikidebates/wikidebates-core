"""Reviewed PR #3 transformations, applied after the original native port."""
import re


def optimize(source, argument):
	def bounds(name):
		start = source.index('\tprotected function ' + name + '(')
		following = re.search(r'\n\t(?:protected|public) function ', source[start + 1:])
		return start, start + 1 + following.start()

	def replace(name, transform):
		nonlocal source
		start, end = bounds(name)
		source = source[:start] + transform(source[start:end]) + source[end:]

	context = 'ArgumentContext' if argument else 'Context'
	source = source.replace(f'\tprotected {context} $context;', f'\tprivate ?DecodedReferences $decodedReferences = null;\n\tprotected {context} $context;')
	formats = [('wkParseBiblioMarkers', 'bibliography'), ('wkParseWeblioMarkers', 'webliography'), ('wkParseVideoMarkers', 'videography')]
	if argument:
		formats.append(('wkParseQuoteMarkers', 'quotes'))
	for name, kind in formats:
		replace(name, lambda _, name=name, kind=kind: f"\tprotected function {name}($raw = null) {{\n\t\treturn ($this->decodedReferences ??= new DecodedReferences)->parse('{kind}', $raw);\n\t}}\n")
	source = source.replace('public function render($frame = null) {', 'public function render($frame = null) {\n\t\t$this->decodedReferences = null;')
	start = source.index('\tpublic function render(')
	end = source.index('\n\tprotected function ', start)
	render = source[start:end].replace('[$pieces, $cats] = [new Table([]), new Table([])];', '[$pieces, $cats] = [[], new Table([])];')
	render, count = re.subn(r'^(\t*)Lua::insert\(\$pieces, (.*)\);$', r'\1$pieces[] = \2;', render, flags=re.M)
	assert count > 15, count
	render = render.replace("return Lua::concat($pieces, '');", "return implode('', $pieces);")
	source = source[:start] + render + source[end:]
	if not argument:
		replace('wkRenderLiListFromArg', lambda s: s.replace('$lis = new Table([]);', '$lis = [];').replace('$lis[(Lua::len($lis) + 1)]', '$lis[]').replace("return Lua::concat($lis, '\n');", 'return implode("\\n", $lis);'))
		return source

	# Constructor allocations are redundant with the resets at render entry.
	start = source.index('\tpublic function __construct(')
	end = source.index('\n\tprotected function ', start)
	constructor = source[start:end]
	for name in ['SMW_ASK_CACHE', 'SMW_TITLES_CACHE', 'CARD_CACHE', 'SEO_CACHE']:
		constructor, count = re.subn(r'^\t\t\$this->' + name + r' = [^\n]+;\n', '', constructor, flags=re.M)
		assert count == 1, name
	source = source[:start] + constructor + source[end:]
	source = source.replace('protected function smwAskTitles($conditions = null, $limit = null)', "protected function smwAskTitles($conditions = null, $limit = null, string $origin = 'other')")
	source = source.replace('protected function smwAskSafe($query)', "protected function smwAskSafe($query, string $origin = 'other')")
	replace('smwAskTitles', lambda s: s.replace('return $hit;', "$this->smwCacheHit($origin, 'titles');\n\t\t\treturn $hit;").replace('$this->smwAskSafe($q)', '$this->smwAskSafe($q, $origin)'))
	for name, origin in [('ensureDebateParents', 'debate-parents'), ('ensureArgumentParents', 'argument-parents'), ('ensureDebateParentsFinal', 'breadcrumb'), ('ensureBreadcrumbSelection', 'breadcrumb'), ('renderDebateDetailed', 'cards')]:
		def attribute(s, origin=origin, name=name):
			s = s.replace('$this->smwAskSafe($q)', f"$this->smwAskSafe($q, '{origin}')").replace('$this->smwAskSafe($qq)', f"$this->smwAskSafe($qq, '{origin}')")
			s = s.replace(']), 200)', f"]), 200, '{origin}')")
			return s.replace("4 => 'link=none']));", "4 => 'link=none']), 'cards');") if name == 'renderDebateDetailed' else s
		replace(name, attribute)
	replace('cardRendered', lambda s: s.replace('return $cached;', "$this->smwCacheHit('cards', 'card');\n\t\t\treturn $cached;"))
	source = source.replace("if ($hit !== null) { return $hit === false ? null : $hit; }\n\t\t$result = $this->context->smwAsk($query);", "if ($hit !== null) { $this->smwCacheHit($origin, 'query'); return $hit === false ? null : $hit; }\n\t\t$result = $this->smwAskUncached($query, $origin);")
	source = source.replace('\tprotected function getArgs(', "\tprotected function smwAskUncached(Table $query, string $origin): ?Table {\n\t\treturn $this->context instanceof SemanticProfilingContext\n\t\t\t? $this->context->profiledSmwAsk($query, $origin) : $this->context->smwAsk($query);\n\t}\n\tprotected function smwCacheHit(string $origin, string $layer): void {\n\t\tif ($this->context instanceof SemanticProfilingContext) { $this->context->smwCacheHit($origin, $layer); }\n\t}\n\tprotected function getArgs(")
	source = source.replace("[$ok, $res] = Lua::pcall(($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null),", "[$ok, $res] = Lua::pcall(function($query) { return $this->smwAskUncached($query, 'initialization'); },")
	replace('breadcrumbLenByteSafe', lambda _: '''	protected function breadcrumbLenByteSafe($ch = null) {
		if (!is_string($ch) || $ch === '') { return 0; }
		$count = 0;
		foreach (explode('⟭', $ch) as $part) {
			if (trim($part, " \\t\\r\\n\\v\\f") !== '' && ++$count >= 6) { return $count; }
		}
		return $count;
	}
''')
	replace('uniqList', lambda s: s.replace('[$out, $seen] = [new Table([]), new Table([])];', '[$out, $seen] = [[], []];').replace('!(Lua::truth($seen[$x]))', '!isset($seen[$x])').replace('Lua::insert($out, $x);', '$out[count($out) + 1] = $x;').replace('return $out;', 'return new Table($out);'))
	replace('wkRenderQuotesFromSerialized', lambda s: s.replace('$out = new Table([]);', '$out = [];').replace('foreach (Lua::ipairs($items)', 'foreach ($items->values').replace('$out[(Lua::len($out) + 1)]', '$out[]').replace("Lua::concat($out, '')", "implode('', $out)"))
	def references(s):
		s = s.replace('[new Table([]), new Table([]), new Table([])]', '[[], [], []]')
		for var in ['b', 'w', 'v']:
			s = s.replace(f'${var}[(Lua::len(${var}) + 1)]', f'${var}[]').replace(f'Lua::len(${var})', f'count(${var})')
		start = s.index('\t\t$out = ')
		end = s.index('\n\t\t$out = Lua::trim', start)
		return s[:start] + '\t\t$out = implode("\\n", [implode("\\n", $b), implode("\\n", $w), implode("\\n", $v)]);' + s[end:]
	replace('wkRenderReferencesFromRaw', references)
	return source
