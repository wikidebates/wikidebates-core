<?php
namespace MediaWiki\Extension\DebatePHP;

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use UtfNormal\Validator;

final class Hooks {
	public static function onParserFirstCallInit(Parser $parser): void {
		$parser->setFunctionHook('argument', [self::class, 'argument'], Parser::SFH_OBJECT_ARGS);
		$parser->setFunctionHook('debate', [self::class, 'debate'], Parser::SFH_OBJECT_ARGS);
		$parser->setFunctionHook('argumentmap', [ArgumentMap::class, 'render'], Parser::SFH_OBJECT_ARGS);
	}
	public static function debate(Parser $parser, PPFrame $frame, array $args): array {
		return self::execute($parser, $frame, $args, 'Debate');
	}
	public static function argument(Parser $parser, PPFrame $frame, array $args): array {
		return self::execute($parser, $frame, $args, 'Argument');
	}
	private static function execute(Parser $parser, PPFrame $frame, array $args, string $moduleName): array {
		$entry = isset($args[0]) ? trim($frame->expand($args[0]), " \t\r\n\v\f") : '';
		if ($entry !== '' && $entry !== 'render') { throw new \InvalidArgumentException('#' . strtolower($moduleName) . ': expected render'); }
		// Explicit parameters are still unexpanded PPNode part nodes. Their names and values
		// are resolved by a genuine child frame, never split on an expanded '=' or '|'.
		unset($args[0]);
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$profile = $config->get('DebatePHPProfiling'); $diagnostics = $config->get('DebatePHPDiagnostics');
		$telemetry = $profile || $diagnostics ? new Telemetry($profile, $diagnostics,
			['page' => $parser->getTitle()->getPrefixedText(), 'module' => $moduleName]) : null;
		try {
			$started = $telemetry?->start();
			try {
				$context = new MediaWikiContext($parser, $frame, $args, true, false, $telemetry, $moduleName);
				$renderer = $moduleName === 'Argument'
					? new ArgumentRenderer($context)
					: new DebateRenderer($context);
			} finally { $telemetry?->finish('setup', $started); }
			$started = $telemetry?->start();
			try { $text = $renderer->render(); }
			finally { $telemetry?->finish('render', $started); }
			return [Validator::cleanUp($text), 'noparse' => true, 'isHTML' => false];
		} catch (\Throwable $e) {
			$telemetry?->problem('render', 'exception', $e); throw $e;
		} finally { $telemetry?->report(); }
	}
}
