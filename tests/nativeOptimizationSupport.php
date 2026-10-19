<?php

/** Optional, isolated historical source loader for before/after comparisons. */
$nativeBaselinePrefix = 'MediaWiki\\Extension\\DebatePHPBefore\\';
if (getenv('WD_OPTIMIZATION_BASELINE')) {
	$baseline = realpath(getenv('WD_OPTIMIZATION_BASELINE'));
	check($baseline !== false && $baseline !== realpath(dirname(__DIR__)), 'Distinct baseline checkout required');
	check(is_file($baseline . '/debatephp/src/ArgumentReferenceRenderer.php'), 'Baseline has renderer sources');
	$target = $temporary . '/native-before';
	mkdir($target . '/src', 0700, true);
	symlink($baseline . '/debatephp/resources', $target . '/resources');
	foreach (glob($baseline . '/debatephp/src/*.php') as $file) {
		$source = str_replace('namespace MediaWiki\\Extension\\DebatePHP;', 'namespace ' . rtrim($nativeBaselinePrefix, '\\') . ';', file_get_contents($file));
		file_put_contents($target . '/src/' . basename($file), $source);
	}
	spl_autoload_register(static function(string $class) use ($nativeBaselinePrefix, $target): void {
		if (str_starts_with($class, $nativeBaselinePrefix)) { require_once $target . '/src/' . substr($class, strlen($nativeBaselinePrefix)) . '.php'; }
	});
	$installBefore = static function($parser) use ($nativeBaselinePrefix): void {
		if (empty($GLOBALS['wdTestPersistBaseline'])) { return; }
		foreach (['debate', 'argument'] as $function) {
			$parser->setFunctionHook($function, [$nativeBaselinePrefix . 'Hooks', $function], $parser::SFH_OBJECT_ARGS);
		}
	};
	\MediaWiki\MediaWikiServices::getInstance()->getHookContainer()->register('ParserFirstCallInit', $installBefore);
}

/** Baseline hook replacement and opt-in profile capture on this parser only. */
function nativeOptimizationHooks($parser, string $mode, bool $profile, array &$events): void {
	global $nativeBaselinePrefix;
	$isBaseline = str_starts_with($mode, 'before-');
	if (!$isBaseline && !$profile) { return; }
	$prefix = $isBaseline ? $nativeBaselinePrefix : 'MediaWiki\\Extension\\DebatePHP\\';
	foreach (['debate' => 'Debate', 'argument' => 'Argument'] as $function => $module) {
		if (!$profile) {
			$parser->setFunctionHook($function, [$prefix . 'Hooks', $function], $parser::SFH_OBJECT_ARGS);
			continue;
		}
		$parser->setFunctionHook($function, static function($parser, $frame, $args) use ($prefix, $mode, $module, &$events): array {
			unset($args[0]);
			$telemetryClass = $prefix . 'Telemetry';
			$telemetry = new $telemetryClass(true, false, [], static function($event) use (&$events): void { $events[] = $event; });
			$contextClass = $prefix . 'MediaWikiContext';
			$context = new $contextClass($parser, $frame, $args, str_ends_with($mode, 'optimized'), false, $telemetry, $module);
			$rendererClass = $prefix . ($module === 'Argument' ? 'ArgumentReferenceRenderer' : 'ReferenceRenderer');
			$start = $telemetry->start();
			try { $text = (new $rendererClass($context))->render(); }
			finally { $telemetry->finish('render', $start); $telemetry->report(); }
			return [\UtfNormal\Validator::cleanUp($text), 'noparse' => true, 'isHTML' => false];
		}, $parser::SFH_OBJECT_ARGS);
	}
}
