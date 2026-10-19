<?php
namespace MediaWiki\Extension\DebatePHP;

/** Mutable, one-based table. Empty tables and "0" must stay truthy. */
final class Table implements \ArrayAccess, \IteratorAggregate, \Countable {
	public array $values;
	public function __construct(array $values = []) { $this->values = $values; }
	public function offsetExists($key): bool { return isset($this->values[$key]); }
	public function offsetGet($key): mixed { return $this->values[$key] ?? null; }
	public function offsetSet($key, $value): void {
		if ($value === null) { unset($this->values[$key]); }
		else { $this->values[$key] = $value; }
	}
	public function offsetUnset($key): void { unset($this->values[$key]); }
	public function getIterator(): \Traversable { return new \ArrayIterator($this->values); }
	public function count(): int { return count($this->values); }
	public static function wrap($value) {
		if (!is_array($value)) { return $value; }
		return new self(array_map([self::class, 'wrap'], $value));
	}
	public static function unwrap($value) {
		return $value instanceof self ? array_map([self::class, 'unwrap'], $value->values) : $value;
	}
}
