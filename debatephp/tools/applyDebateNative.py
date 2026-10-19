"""Native field and JSON-LD boundaries for the supplied Debate port."""
from pathlib import Path
import re
root=Path(__file__).resolve().parent.parent
p=root/'src/ReferenceRenderer.php';s=p.read_text()
s,count=re.subn(r"\(\$this->context\)->preprocess\((\$(?:rawPro|rawCon|raw|proRaw|conRaw|neuRaw))\)",r"$this->context->expandField(\1)",s)
assert count==13,count
s=s.replace("$this->tagHtmlTag('script', $this->wkProtectJsonLdForParser($json), new Table(['type' => 'application/ld+json', 'class' => 'navigation-not-searchable']))", "$this->context->jsonLd($json)")
for method in ['tag','tagHtmlTag','wkProtectJsonLdForParser']:
	start=s.index('\tprotected function '+method+'(')
	end=s.index('\n\tprotected function ',start+1)
	s=s[:start]+s[end:]
assert '->preprocess(' not in s and '{{#tag:' not in s
p.write_text(s)
from simplifyNative import simplify
simplify(p, False)
print('Applied native Debate fields and JSON-LD')
