<?php
namespace MediaWiki\Extension\DebatePHP;

/** Only mw.html methods actually used by Debate. Attribute insertion order is retained. */
final class HtmlBuilder {
	private string $name;
	private array $attrs = [];
	private array $children = [];
	public static function create(string $name): self { return new self($name); }
	public function __construct(string $name) { $this->name = $name; }
	public function attr(string $key, string $value): self { $this->attrs[$key] = $value; return $this; }
	public function addClass(string $class): self {
		$this->attrs['class'] = isset($this->attrs['class']) ? $this->attrs['class'] . ' ' . $class : $class; return $this;
	}
	public function node($child): self { $this->children[] = $child; return $this; }
	public function wikitext(string $text): self { $this->children[] = $text; return $this; }
	public function __toString(): string {
		$s = '<' . $this->name;
		foreach ($this->attrs as $k => $v) {
			// Restore strip-marker delimiters after escaping, as mw.html does (T110143).
			$v = strtr($v, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;']);
			$v = str_replace(["\x7f'&quot;`UNIQ-", "-QINU`&quot;'\x7f"], ["\x7f'\"`UNIQ-", "-QINU`\"'\x7f"], $v);
			$s .= ' ' . $k . '="' . $v . '"';
		}
		return $s . '>' . implode('', $this->children) . '</' . $this->name . '>';
	}
}
