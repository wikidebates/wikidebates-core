<?php
namespace MediaWiki\Extension\DebatePHP;

interface ArgumentContext extends Context, NativeSemanticContext {
	public function currentDate(): string;
	public function publicationDate(): ?string;
	public function autoEdit(array $params): string;
	public function expandQuotes(string $text): string;
	public function smwAvailable(): bool;
	public function titleInfo(string $text): ?array;
	public function variableSet(string $name, string $value): void;
	public function variableGet(string $name): ?string;
}
