<?php
namespace MediaWiki\Extension\DebatePHP;

/** Per-render parent relation state. Null means not loaded; [] means loaded and empty. */
final class ParentRelations {
	/** @var list<string>|null */
	public ?array $proDebates = null;
	/** @var list<string>|null */
	public ?array $conDebates = null;
	/** @var list<string>|null */
	public ?array $justificationParents = null;
	/** @var list<string>|null */
	public ?array $objectionParents = null;
	/** @var list<string>|null */
	public ?array $allDebates = null;
	/** @var list<string>|null */
	public ?array $allDebateCards = null;
	/** @var list<string>|null */
	public ?array $proDebateCards = null;
	/** @var list<string>|null */
	public ?array $conDebateCards = null;
	/** @var list<string>|null */
	public ?array $justificationCards = null;
	/** @var list<string>|null */
	public ?array $objectionCards = null;
	/** @var list<string>|null */
	public ?array $breadcrumbChains = null;
	/** @var list<string>|null */
	public ?array $breadcrumbDebates = null;
}
