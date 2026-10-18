"""Apply reviewed native boundaries after mechanical translation. No Lua at runtime."""
from pathlib import Path
root=Path(__file__).resolve().parent.parent
p=root/'src/ArgumentReferenceRenderer.php';s=p.read_text()
def replace_method(name,body):
 global s
 start=s.index('\tprotected function '+name+'(')
 stop=s.index('\n\tprotected function ',start+1)
 s=s[:start]+body.rstrip()+'\n'+s[stop:]
replace_method('wkRunQueryHtmlTagButton','''	protected function wkRunQueryHtmlTagButton($formName = null, $label = null, $tooltip = null, $query = null) {
		$formName = Lua::str($formName ?? '');
		if ($formName === '') { return ''; }
		$tooltip = $this->normalizeTooltipText(Lua::str($tooltip ?? ''));
		$query = $query instanceof Table ? $query : new Table;
		$href = Lua::str($this->context->localUrl($this->wkRunQueryPath($formName), $query));
		$attributes = new Table(['href' => 'javascript:void(0)', 'data-href' => $this->escapeAttr($href), 'class' => 'wk-btn__a wk-js-nav']);
		if ($tooltip !== '') { $attributes['title'] = $this->escapeAttr($tooltip); }
		return $this->context->htmlTag('a', '＋ ' . Lua::str($label ?? ''), $attributes, true);
	}''')
s=s.replace("Lua::str(($this->context)->preprocess('{{CURRENTYEAR}}-{{CURRENTMONTH}}-{{CURRENTDAY2}}'))", '$this->context->currentDate()')
s=s.replace("($this->context)->preprocess(('{{SHORTDESC: ' . $args['established-name'] . '}}'))", "$this->context->shortDescription($args['established-name'])")
s=s.replace('function($_frame, $text) { return $this->context->preprocess($text); }, $this->context, $q', 'function($text) { return $this->context->expandQuotes($text); }, $q')
a=s.index("\t\t\t$src = ('{{#autoedit:")
b=s.index('\n\t\t\treturn ($orphanBanner',a)
s=s[:a]+'''			$params = new Table(['form' => $formName, 'target' => $parentPage, 'query string' => $qs,
				'link text' => $yesText, 'link type' => 'button', 'tooltip' => $yesTooltip, 'ok text' => $okText]);
			if ($summary !== '') { $params['summary'] = $summary; }
			$params['reload'] = true;
			[$ok, $r] = Lua::pcall(function() use ($params) { return $this->context->autoEdit($params); });
			$auto = $ok && Lua::truth($r) ? $r : '';
''' +s[b:]
s=s.replace("$this->tagHtmlTag('script', $this->wkProtectJsonLdForParser($json), new Table(['type' => 'application/ld+json', 'class' => 'navigation-not-searchable']))", "$this->context->jsonLd($json)")
for method in ['tagHtmlTag','wkProtectJsonLdForParser']:
 start=s.index('\tprotected function '+method+'(')
 end=s.index('\n\tprotected function ',start+1)
 s=s[:start]+s[end:]
assert '->preprocess(' not in s
assert '{{#autoedit:' not in s and '{{#tag:' not in s and '<htmltag' not in s
p.write_text(s)
from simplifyNative import simplify
simplify(p, True)
print('Applied native Argument operations')
