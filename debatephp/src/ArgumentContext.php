<?php
namespace MediaWiki\Extension\DebatePHP;

interface ArgumentContext extends Context {
	public function currentDate(): string;
	public function autoEdit(Table $params): string;
	public function expandQuotes(string $text): string;
	public function smwAvailable(): bool;
	public function smwAsk(Table $query): ?Table;
	public function titleInfo(string $text): ?Table;
	public function variableSet(string $name, string $value): void;
	public function variableGet(string $name): ?string;
}
