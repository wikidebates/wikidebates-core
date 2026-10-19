<?php
/** Read-only corpus profiles. Same renderers and context as the production hooks. */
$wikiRoot = null;
foreach ($argv as $arg) { if (str_starts_with($arg, '--wiki-root=')) { $wikiRoot = substr($arg, 12); } }
if (!$wikiRoot) { fwrite(STDERR, "--wiki-root required\n"); exit(2); }
require $wikiRoot . '/maintenance/Maintenance.php';
use MediaWiki\Extension\DebatePHP\{MediaWikiContext,Telemetry,ReferenceRenderer,Renderer,ArgumentReferenceRenderer,ArgumentRenderer};
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\{Parser,ParserOptions};
use MediaWiki\Title\Title;
class ProfileNativeRender extends Maintenance {
	public function __construct() {
		parent::__construct();
		foreach (['wiki-root','corpus','output','module'] as $option) { $this->addOption($option, $option, true, true); }
	}
	public function execute() {
		$module = $this->getOption('module');
		if (!in_array($module, ['Argument','Debate'], true)) { $this->fatalError('Unknown module'); }
		$rows = [];
		foreach (json_decode(file_get_contents($this->getOption('corpus')), true, 512, JSON_THROW_ON_ERROR) as $case) {
			$title = Title::newFromText($case['title']); if (!$title) { continue; }
			foreach (['reference','optimized'] as $mode) {
				$profiles = []; $p = MediaWikiServices::getInstance()->getParserFactory()->create();
				$p->setFunctionHook(strtolower($module), static function($parser, $frame, $args) use ($module, $mode, &$profiles) {
					unset($args[0]);
					$t = new Telemetry(true, false, [], static function($event) use (&$profiles) { $profiles[] = $event; });
					$c = new MediaWikiContext($parser, $frame, $args, $mode === 'optimized', false, $t, $module);
					$class = $module === 'Argument' ? ($mode === 'optimized' ? ArgumentRenderer::class : ArgumentReferenceRenderer::class)
						: ($mode === 'optimized' ? Renderer::class : ReferenceRenderer::class);
					$start = $t->start();
					try { $text = (new $class($c))->render(); }
					finally { $t->finish('render', $start); $t->report(); }
					return [$text, 'noparse' => true, 'isHTML' => false];
				}, Parser::SFH_OBJECT_ARGS);
				if (isset($case['text'])) { $text = $case['text']; }
				elseif (isset($case['args'])) {
					$text = '{{#' . strtolower($module) . ':render';
					foreach ($case['args'] as $key => $value) { $text .= '|' . $key . '=' . str_replace('|', '{{!}}', $value); }
					$text .= '}}<references/>';
				} else { throw new InvalidArgumentException('Each case requires text or args'); }
				$replace = static fn($text) => str_replace('{{#invoke:' . $module . '|render', '{{#' . strtolower($module) . ':render', $text);
				$options = ParserOptions::newFromAnon(); $normal = $options->getTemplateCallback();
				$options->setTemplateCallback(static function($title, $parser) use ($normal, $replace) {
					$result = $normal($title, $parser);
					if ($title->getNamespace() === NS_TEMPLATE && is_string($result['text'])) { $result['text'] = $replace($result['text']); }
					return $result;
				});
				$p->parse($replace($text), $title, $options);
				foreach ($profiles as $index => $profile) {
					$rows[] = ['name' => $case['name'] ?? $case['title'], 'mode' => $mode, 'invocation' => $index + 1, 'profile' => $profile];
				}
			}
		}
		file_put_contents($this->getOption('output'), json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$this->output(count($rows) . " native profiles collected.\n");
	}
}
$maintClass = ProfileNativeRender::class; require RUN_MAINTENANCE_IF_MAIN;
