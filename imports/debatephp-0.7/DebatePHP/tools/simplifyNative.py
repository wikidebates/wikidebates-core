"""Reviewed, shared native implementations. Applied by both reproducible builders."""
from pathlib import Path

def simplify(path: Path, argument: bool):
    source = path.read_text()
    def method(name, signature, expression):
        nonlocal source
        start = source.index('\tprotected function ' + name + '(')
        end = source.index('\n\tprotected function ', start + 1)
        source = source[:start] + f'\tprotected function {name}({signature}) {{\n\t\treturn {expression};\n\t}}\n' + source[end:]

    method('splitCSV', '$s = null, $sep = null', 'SerializedData::csv($s, $sep)')
    if argument:
        method('splitByLiteral', '$s = null, $sep = null, $maxParts = null', 'SerializedData::split($s, $sep, $maxParts)')
        method('wkExtractItemsFromSerializedData', '$raw = null', 'SerializedData::argumentItems($raw, true)')
    else:
        method('wkExtractArgumentItemsFromData', '$raw = null', 'SerializedData::argumentItems($raw, false)')
        for name, separator, field_separator, fields, required in [
            ('wkParseIntroSubsections', '⟭⟭⟭', '⟬⟬⟬', ['title', 'content', 'warnings'], True),
            ('wkParseBiblioMarkers', '⟭', '⟬', ['authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings'], False),
            ('wkParseWeblioMarkers', '⟭', '⟬', ['page', 'site', 'link', 'authors', 'date', 'warnings'], False),
            ('wkParseVideoMarkers', '⟭', '⟬', ['title', 'link', 'authors', 'warnings'], False),
        ]:
            method(name, '$raw = null', f'SerializedData::records($raw, {separator!r}, {field_separator!r}, {fields!r}, {str(required).lower()})')
        # Distinct call sites make the production log useful for optimization.
        import re
        reasons = iter(['debate.arguments.pro', 'debate.arguments.con', 'debate.introduction', 'debate.wikipedia',
                        *[f'debate.{kind}.{side}' for kind in ['bibliography', 'webliography', 'videography'] for side in ['pro', 'con', 'neutral']]])
        source, count = re.subn(r'->expandField\((\$\w+)\)', lambda m: f"->expandField({m[1]}, '{next(reasons)}')", source)
        assert count == 13, count
    path.write_text(source)
