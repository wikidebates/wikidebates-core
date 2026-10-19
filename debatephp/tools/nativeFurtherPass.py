"""Five further native paths; legacy parser helpers remain callable and shared."""
import re


def optimize(source, argument):
	def replace(name, transform):
		nonlocal source
		start = source.index('\tprotected function ' + name + '(')
		following = re.search(r'\n\t(?:protected|public) function ', source[start + 1:])
		end = start + 1 + following.start()
		source = source[:start] + transform(source[start:end]) + source[end:]

	if argument:
		replace('smwKeyFromArray', lambda _: '''	protected function smwKeyFromArray($q = null) {
		return CacheKey::semantic($q);
	}
''')

	def fragments(s):
		for variable in ['out', 'ref', 'b']:
			v = '$' + variable
			s = s.replace(v + ' = new Table([]);', v + ' = [];')
			s = s.replace(v + '[(Lua::len(' + v + ') + 1)]', v + '[]')
			s = s.replace('Lua::len(' + v + ')', 'count(' + v + ')')
			s = re.sub(r'Lua::insert\(' + re.escape(v) + r', (.*)\);', lambda m: v + '[] = ' + m[1] + ';', s)
			s = s.replace('Lua::concat(' + v + ')', "implode('', " + v + ')').replace('Lua::concat(' + v + ", '')", "implode('', " + v + ')')
		# Native consumers avoid wrappers; legacy callers can still pass their Table.
		return s.replace('$item = $item->values;', '$item = $item instanceof Table ? $item->values : $item;')

	for name in ['wkRenderOneBiblioLi', 'wkRenderOneWeblioLi', 'wkRenderOneVideoLi'] + (['wkRenderOneQuoteHtml'] if argument else []):
		replace(name, fragments)

	def banners(s):
		s = s.replace('$out = null)', '&$out = null)')
		s, count = re.subn(r'Lua::insert\(\$out, (.*)\);', r"$fragment = \1;\n\t\t\t\tif ($out instanceof Table) { Lua::insert($out, $fragment); }\n\t\t\t\telseif ($fragment !== null) { $out[] = $fragment; }", s)
		assert count == 1
		return s
	# Both array accumulators and compatibility Tables observe banner additions.
	replace('renderReferenceItemBanners', banners)

	formats = [('Biblio', 'bibliography'), ('Weblio', 'webliography'), ('Video', 'videography')]
	if argument:
		formats.append(('Quote', 'quotes'))
	for short, kind in formats:
		parse = '$this->wkParse' + short + 'Markers'
		values = "$this->nativeReferenceValues('" + kind + "', "
		if argument and kind != 'quotes':
			replace('wkRenderReferencesFromRaw', lambda s, parse=parse, values=values: re.sub(r'\((' + re.escape(parse) + r'\((\$\w+)\))\)->sequence\(\)', lambda m: values + m[2] + ')', s))
		elif not argument:
			name = 'wkRender' + short + 'ListFromArg'
			replace(name, lambda s, short=short, kind=kind: s.replace("$this->wkRenderLiListFromArg($raw, [$this, 'wkParse" + short + "Markers'],", "$this->wkRenderNativeReferenceList($raw, '" + kind + "',"))
		if argument and kind == 'quotes':
			replace('wkRenderQuotesFromSerialized', lambda s: s.replace('$this->wkParseQuoteMarkers($raw)', "$this->nativeReferenceValues('quotes', $raw)").replace('Lua::len($items)', 'count($items)').replace('$items->values', '$items'))
		name = 'wkBuildClaimCitationsFrom' + {'bibliography': 'Bibliography', 'webliography': 'Webliography', 'videography': 'Videography', 'quotes': 'Quotes'}[kind]
		replace(name, lambda s, parse=parse, values=values: s.replace(parse + '($raw)', values + '$raw)').replace('$this->wkBuildSingleNodeFromFirstItem(', '$this->wkBuildSingleNodeFromNativeItems(').replace('\t\t$item = $item->values;\n', ''))

	helpers = '''	protected function nativeReferenceValues(string $format, ?string $raw): array {
		return ($this->decodedReferences ??= new DecodedReferences)->values($format, $raw);
	}

	protected function wkBuildSingleNodeFromNativeItems(array $items, callable $builder): Table {
		foreach ($items as $item) {
			$node = $builder($item);
			if (Lua::truth($node)) { return new Table([1 => $node]); }
'''
	if argument:
		helpers += '\t\t\tbreak; // Argument uses the first item; Debate scans to the first valid node.\n'
	helpers += '''		}
		return new Table;
	}

'''
	if not argument:
		helpers += '''	protected function wkRenderNativeReferenceList(?string $raw, string $format, callable $builder): string {
		$out = [];
		foreach ($this->nativeReferenceValues($format, $raw) as $item) {
			$li = $builder($item);
			if (Lua::truth($li) && $li !== '') { $out[] = $li; }
		}
		return implode("\\n", $out);
	}

'''
	source = source.replace('\tprotected function splitCSV(', helpers + '\tprotected function splitCSV(')
	# Hints protect genuinely reused keywords/sections when the cache is under pressure.
	start = source.index('\tpublic function render(')
	render = source[start:]
	render = render.replace('$lang = $this->detectLang($args);', "$this->renderData = new RenderData;\n\t\t$this->renderData->expectReuse([$args['keywords'], $args['sections']]);\n\t\t$lang = $this->detectLang($args);")
	render = render.replace("return implode('', $pieces);", "if ($this->context instanceof PreparationProfilingContext) {\n\t\t\t$this->context->profilePreparation($this->renderData->statistics());\n\t\t}\n\t\treturn implode('', $pieces);")
	source = source[:start] + render
	return source
