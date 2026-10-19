<?php
namespace MediaWiki\Extension\DebatePHP;

/** Exact WD/I18N lookup, alias and fallback rules. State is confined to one renderer. */
final class I18n {
	private array $data;
	private array $domains = [];
	private array $aliases = [];
	private array $messages = [];
	private bool $memoize;
	private bool $catalog = false;
	public function __construct(array $data, bool $memoize = false) { $this->data = $data; $this->memoize = $memoize; }
	/** Standard JSON messages with the historical formatter and invocation-local cache. */
	public static function bundled(bool $memoize = false): self {
		$instance = new self([], $memoize);
		$instance->catalog = true;
		return $instance;
	}
	public static function normalize(string $code): string {
		$code = Lua::trim($code); preg_match('/^[a-zA-Z]+/', $code, $m); return strtolower($m[0] ?? $code);
	}
	public function language(Table $args, string $contentLanguage): string {
		$param = self::normalize($args['lang'] ?? $args['language'] ?? '');
		return str_starts_with($param !== '' ? $param : self::normalize($contentLanguage), 'fr') ? 'fr' : 'en';
	}
	private function path($root, string $path) {
		foreach (explode('.', $path) as $part) {
			if ($part === '') { continue; }
			if (!is_array($root)) { return null; } $root = $root[$part] ?? null;
		} return $root;
	}
	private function resolve($v, int $depth = 0) {
		if (!is_string($v) || !str_starts_with($v, '@')) { return $v; }
		if (isset($this->aliases[$v])) { return $this->aliases[$v]; }
		if ($depth > 12 || !preg_match('/^@([^.]+)\.(.+)$/s', $v, $m)) { return $this->aliases[$v] = $v; }
		$entry = $this->path($this->data[$m[1]] ?? null, $m[2]);
		return $this->aliases[$v] = $entry === null ? $v : $this->resolve($entry, $depth + 1);
	}
	public function message(string $domain, string $lang, string $key, ...$args): string {
		$lang = str_starts_with(self::normalize($lang), 'fr') ? 'fr' : 'en';
		$cacheKey = $domain . "\0" . $lang . "\0" . $key;
		if ($this->memoize && isset($this->messages[$cacheKey])) { $text = $this->messages[$cacheKey]; }
		else {
			if ($this->catalog) { $text = MessageCatalog::template($domain, $lang, $key, $key); }
			else {
				$dom = $this->domains[$domain] ??= $this->path($this->data, $domain) ?? false;
				$entry = is_array($dom) ? ($dom[$key] ?? null) : null;
				$entry = $this->resolve($entry ?? ($this->data['Common'][$key] ?? null));
				$text = is_string($entry) ? $entry : (is_array($entry) ? ($entry[$lang] ?? $entry['en'] ?? $entry['fr'] ?? null) : null);
				$text ??= $key;
			}
			if ($this->memoize) { $this->messages[$cacheKey] = $text; }
		}
		if ($this->catalog) { return $args ? MessageCatalog::substitute($text, $args) : MessageCatalog::legacyTemplate($text); }
		return $args ? Lua::format($text, ...$args) : $text;
	}
}
