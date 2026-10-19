"""Read-only native paths, with explicit legacy adapter and JSON fallbacks."""
import re


def optimize(source, argument):
	def replace(name, transform):
		nonlocal source
		start = source.index('\tprotected function ' + name + '(')
		following = re.search(r'\n\t(?:protected|public) function ', source[start + 1:])
		end = start + 1 + following.start()
		source = source[:start] + transform(source[start:end]) + source[end:]

	# Only direct, read-only loops. The legacy splitCSV helper still returns a fresh Table.
	source, loops = re.subn(r"\(\$this->splitCSV\(([^\n]+?)\)\)->sequence\(\)", r'$this->csvValues(\1)', source)
	assert loops >= 7, loops
	helper = '''	protected function csvValues(?string $text, ?string $separator = null): array {
		return ($this->renderData ??= new RenderData)->csvValues($text, $separator);
	}

'''
	source = source.replace('\tprotected function splitCSV(', helper + '\tprotected function splitCSV(')
	# These locals only count/index the CSV output; no mutation, identity or hole semantics.
	for name, variable in [('wkBuildKeywords', 'sections'), ('wkBuildAboutAndMentions', 'rawKeywords' if argument else 'keywords')]:
		replace(name, lambda s, variable=variable: s.replace('$this->splitCSV(', '$this->csvValues(').replace('Lua::len($' + variable + ')', 'count($' + variable + ')'))

	def fragments(s, variables):
		for variable in variables:
			v = '$' + variable
			s = s.replace(v + ' = new Table([]);', v + ' = [];')
			s = s.replace(v + '[(Lua::len(' + v + ') + 1)]', v + '[count(' + v + ') + 1]')
			s = s.replace('Lua::len(' + v + ')', 'count(' + v + ')')
			s = re.sub(r'Lua::insert\(' + re.escape(v) + r', (.*)\);', lambda m: v + '[count(' + v + ') + 1] = ' + m[1] + ';', s)
			s = s.replace('Lua::concat(' + v + ')', "implode('', " + v + ')').replace('Lua::concat(' + v + ", ', ')", "implode(', ', " + v + ')')
		return s

	for name, variables in [('renderKeywords', ['out', 'rendered']), ('renderRubriques', ['out'])] + ([('renderTitleWarnings', ['out'])] if argument else [('renderConnexes', ['items'])]):
		replace(name, lambda s, variables=variables: fragments(s, variables))

	# Root/page nodes are internal arrays; child helpers retain their legacy mode outside this operation.
	source = source.replace('\tprivate ?RenderData $renderData = null;', '\tprivate ?RenderData $renderData = null;\n\tprivate bool $nativeJson = false;')
	# Associative schema nodes have no shared mutations outside JSON construction.
	# List envelopes stay compatible; node envelopes are omitted only during the native operation.
	source = source.replace("new Table(['@", "$this->jsonNode(['@")
	helper = '''	protected function jsonNode(array $data): Table|array {
		return $this->nativeJson ? $data : new Table($data);
	}

'''
	source = source.replace('\tprotected function splitCSV(', helper + '\tprotected function splitCSV(')
	name = 'renderArgumentSchemaJSONLD' if argument else 'renderDebateSchemaJSONLD'
	def json_graph(s):
		for variable in ['pageNode', 'claimNode', 'qaNode', 'data']:
			s = re.sub(r'(\$' + variable + r' = \$this->jsonNode\([^\n]+);', lambda m: m[0].replace('$this->jsonNode(', '(').replace('new Table(', '('), s)
		old = '$json = $this->safeJsonEncode($data);' if argument else '$json = $this->context->jsonEncode($data);'
		assert old in s
		fallback = '$this->safeJsonEncode' if argument else '$this->context->jsonEncode'
		s = s.replace(old, 'if ($this->context instanceof NativeJsonContext) { return $this->context->jsonLdData($data); }\n\t\t$json = ' + fallback + '(Table::wrap($data));')
		start = s.index('\n') + 1
		end = s.rindex('\n\t}')
		body = s[start:end]
		return s[:start] + '''		$previousNativeJson = $this->nativeJson;
		$this->nativeJson = $this->context instanceof NativeJsonContext;
		try {
''' + '\n'.join('\t' + line if line else '' for line in body.split('\n')) + '''
		} finally { $this->nativeJson = $previousNativeJson; }''' + s[end:]
	replace(name, json_graph)
	if not argument:
		return source

	source = source.replace('\tprivate ?RenderData $renderData = null;', '\tprivate ?RenderData $renderData = null;\n\tprivate ?FifoCache $nativeSemanticCache = null;')
	source = source.replace('public function render($frame = null) {', 'public function render($frame = null) {\n\t\t$this->nativeSemanticCache = null;')
	# New result-cache lifetime follows the legacy query cache, including render reuse.
	assert '$this->nativeSemanticCache = null;' in source[source.index('\tpublic function render('):]

	def semantic_rows(s):
		s = re.sub(r'^(\t+)\$res = \$this->smwAskSafe\(([^\n]+)\);', lambda m: m[1] + '$semantic = $this->readSemantic(' + m[2] + ');\n' + m[1] + '$res = $semantic->rows;', s, flags=re.M)
		for variable in ['res', 'chain', 'pdeb', 'x', 'v']:
			v = '$' + variable
			s = s.replace("Lua::type(" + v + ") === 'table'", 'is_array(' + v + ')').replace("Lua::type(" + v + ") !== 'table'", '!is_array(' + v + ')')
			s = s.replace('(' + v + ')->sequence()', 'SemanticReadResult::sequence(' + v + ')')
			s = s.replace('Lua::len(' + v + ')', 'count(' + v + ')')
		# Unlike ArrayAccess, missing native-array fields must be read explicitly as null.
		s = s.replace("$res[1]['map']", "($res[1]['map'] ?? null)")
		for variable in ['row', 'x', 'res']:
			s = re.sub(r'\$' + variable + r"\[(?:'[^']+'|1)\]", lambda m: '(' + m[0] + ' ?? null)', s)
		# Parent fallbacks are suppressed only by an adapter-certified empty union.
		for a, b in [('debatsPour', 'debatsContre'), ('justifs', 'objs')]:
			condition = 'if (((Lua::len($' + a + ') === 0) && (Lua::len($' + b + ') === 0))) {'
			found = condition in s
			s = s.replace(condition, condition[:-3] + ' && !$semantic->completeEmpty) {')
			origin = 'debate-parents' if a == 'debatsPour' else 'argument-parents'
			needle = "200, '" + origin + "'));\n\t\t}"
			if found:
				assert needle in s, origin
				s = s.replace(needle, "200, '" + origin + "'));\n\t\t} elseif ($semantic->completeEmpty && $this->context instanceof NativeSemanticContext) {\n\t\t\t$this->context->skipEmptyParentFallback('" + origin + "');\n\t\t}")
		return s

	for name in ['smwAskTitles', 'ensureDebateParents', 'ensureArgumentParents', 'ensureDebateParentsFinal', 'ensureBreadcrumbSelection', 'renderDebateDetailed']:
		replace(name, semantic_rows)
	# Recursive title tests accept the native rows and retain their old Table entry point.
	def title_values(s):
		s = s.replace("if ((Lua::type($x) === 'table')) {", "if ($x instanceof Table) { $x = Table::unwrap($x); }\n\t\tif (is_array($x)) {")
		s = s.replace('($x)->sequence()', 'SemanticReadResult::sequence($x)')
		return re.sub(r"\$x\[(?:'[^']+')\]", lambda m: '(' + m[0] + ' ?? null)', s)
	replace('containsTitle', title_values)
	helper = '''	protected function readSemantic(Table $query, string $origin): SemanticReadResult {
		if (!$this->context instanceof NativeSemanticContext) {
			return new SemanticReadResult(Table::unwrap($this->smwAskSafe($query, $origin)));
		}
		if (!$this->hasSMW()) { return new SemanticReadResult(null); }
		$cache = $this->nativeSemanticCache ??= new FifoCache(250);
		$key = $this->smwKeyFromArray($query);
		$hit = $cache->get($key);
		if ($hit !== null) { $this->smwCacheHit($origin, 'query'); return $hit; }
		$result = $this->context->readSemantic($query, $origin);
		$cache->put($key, $result);
		return $result;
	}
'''
	source = source.replace('\tprotected function smwAskUncached(', helper + '\tprotected function smwAskUncached(')
	return source
