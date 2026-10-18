<?php
namespace MediaWiki\Extension\DebatePHP;

/** Boundary between the renderer and MediaWiki, also used by the differential harness. */
interface Context {
	public function arguments(): Table;
	public function language(Table $args): string;
	public function message(string $domain, string $lang, string $key, ...$args): string;
	public function pageVars(): Table;
	public function dbKey(string $page): string;
	public function preprocess(string $text): string;
	public function expandField(string $text): string;
	public function jsonLd(string $json): string;
	public function shortDescription(string $text): string;
	public function htmlTag(string $name, string $content, Table $attributes, bool $xml = false): string;
	public function nowiki(string $text): string;
	public function jsonEncode(Table $data): string;
	public function uriEncode(string $text, string $mode = 'QUERY'): string;
	public function fullUrl(string $page, ?Table $query = null): ?string;
	public function localUrl(string $page, ?Table $query = null): ?string;
	public function smwSet(Table $data): void;
	public function seoSet(Table $data): void;
}
