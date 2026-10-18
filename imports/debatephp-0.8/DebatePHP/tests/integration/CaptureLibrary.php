<?php
namespace MediaWiki\Extension\DebatePHP\Tests;

/** Test-only SemanticScribunto input recorder. Never loaded by extension.json. */
final class CaptureLibrary extends \MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase {
	public static array $semantic = [];
	public static array $trace = [];
	public function register() {
		return $this->getEngine()->registerInterface(__DIR__ . '/capture.lua', ['set' => [$this, 'set'], 'trace' => [$this, 'trace']], []);
	}
	public function set($data) { self::$semantic[] = self::flatten($data); return [true]; }
	public function trace($input, $output) {
		$frames = (new \ReflectionProperty(\MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine::class, 'currentFrames'))->getValue($this->getEngine());
		$f = $frames['current'];
		self::$trace[] = ['input' => $input, 'output' => $output, 'volatile' => $f->isVolatile(), 'ttl' => $f->getTTL()]; return [];
	}
	public static function flatten(array $data): array {
		$a = []; foreach ($data as $key => $v) { foreach (is_array($v) ? $v : [$v] as $item) { $a[] = $key . '=' . $item; } } return $a;
	}
}
