"""Reviewed, shared native implementations. Applied by both reproducible builders."""
from pathlib import Path
import re

def simplify(path: Path, argument: bool):
	source = path.read_text()
	def method(name, signature, expression):
		nonlocal source
		start = source.index('\tprotected function ' + name + '(')
		following = re.search(r'\n\t(?:protected|public) function ', source[start + 1:])
		assert following is not None, name
		end = start + 1 + following.start()
		source = source[:start] + f'\tprotected function {name}({signature}) {{\n\t\treturn {expression};\n\t}}\n' + source[end:]

	method('splitCSV', '$s = null, $sep = null', 'SerializedData::csv($s, $sep)')
	method('anchorId', '$s = null', 'NativeText::anchorId($s)')
	method('normalizeTooltipText', '$s = null', 'NativeText::tooltip($s)')
	method('escapeAttr', '$s = null', 'NativeText::attribute($s)')
	method('wkSplit2', '$s = null, $sep = null', 'NativeText::splitOnce($s, $sep)')
	for name, fields in [
	('wkParseBiblioMarkers', ['authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings']),
	('wkParseWeblioMarkers', ['page', 'site', 'link', 'authors', 'date', 'warnings']),
	('wkParseVideoMarkers', ['title', 'link', 'authors', 'warnings']),
	]:
		method(name, '$raw = null', f"SerializedData::records($raw, '⟭', '⟬', {fields!r})")
	if argument:
		method('splitByLiteral', '$s = null, $sep = null, $maxParts = null', 'SerializedData::split($s, $sep, $maxParts)')
		method('wkExtractItemsFromSerializedData', '$raw = null', 'SerializedData::argumentItems($raw, true)')
		method('wkParseQuoteMarkers', '$raw = null', 'SerializedData::quotes($raw)')
		method('wkEncodePathSegment', '$s = null', 'NativeText::pathSegment($s)')
		# These caches belonged to skipped Lua wrappers. Their real PHP services
		# own invocation-local caches already; no generated method reads them.
		for name in ['EXPAND_CACHE', 'TITLE_CACHE', 'I18N_DOMAIN', 'I18N_MSG']:
			assert source.count('$this->' + name) == 2, name
			source = re.sub(r'^\tprotected \$' + name + r';\n', '', source, flags=re.M)
			source = re.sub(r'^\t\t\$this->' + name + r' = [^\n]+;\n', '', source, flags=re.M)
			# Preserve the cache table shape and FIFO rules; remove boolean bridges
			# around PHP comparisons and the redundant temporary default values.
		method('cacheMake', '$max = null', "new Table(['data' => new Table, 'order' => new Table, 'max' => $max === null || $max === false ? 200 : $max])")
		start = source.index('\tprotected function cachePut(')
		end = source.index('\n\tprotected function ', start + 1)
		source = source[:start] + '''\tprotected function cachePut($C = null, $k = null, $v = null) {
\t\tif ($C['data'][$k] !== null) { $C['data'][$k] = $v; return; }
\t\t$C['data'][$k] = $v;
\t\tLua::insert($C['order'], $k);
\t\tif (count($C['order']) > $C['max']) {
\t\t\t$old = Lua::remove($C['order'], 1);
\t\t\tif ($old !== null) { $C['data'][$old] = null; }
\t\t}
\t}
''' + source[end:]
		# The normalizer touches only the argument table, rather than capturing
		# every render variable and leaking its local temporary by reference.
		source, count = re.subn(r'\$norm = function\(\$k = null\) use \([^\n]+\) \{.*?\n\t\t\};', '''$norm = static function($key) use ($args): void {
\t\t\tif (!is_string($args[$key])) { return; }
\t\t\t$value = Lua::trim($args[$key]);
\t\t\t$args[$key] = $value === '' ? null : $value;
\t\t};''', source, flags=re.S)
		assert count == 1, count
	else:
		method('wkExtractArgumentItemsFromData', '$raw = null', 'SerializedData::argumentItems($raw, false)')
		method('wkParseIntroSubsections', '$raw = null', "SerializedData::records($raw, '⟭⟭⟭', '⟬⟬⟬', ['title', 'content', 'warnings'], true)")
		# Distinct call sites make the production log useful for optimization.
		reasons = iter(['debate.arguments.pro', 'debate.arguments.con', 'debate.introduction', 'debate.wikipedia',
		*[f'debate.{kind}.{side}' for kind in ['bibliography', 'webliography', 'videography'] for side in ['pro', 'con', 'neutral']]])
		source, count = re.subn(r'->expandField\((\$\w+)\)', lambda m: f"->expandField({m[1]}, '{next(reasons)}')", source)
		assert count == 13, count
	assert '\tpublic function render(' in source, 'Native simplification removed the entry point'
	from nativeOptimizations import optimize
	from nativePhpPass import optimize as optimize_php
	path.write_text(optimize_php(optimize(source, argument), argument))
