<?php
$wikiRoot = null;
foreach ($argv as $a) { if (str_starts_with($a, '--wiki-root=')) { $wikiRoot = substr($a, 12); } }
if (!$wikiRoot) { fwrite(STDERR, "--wiki-root is required\n"); exit(2); }
require $wikiRoot . '/maintenance/Maintenance.php';

class DebateObservabilityTest extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption('wiki-root', 'Disposable test wiki', true, true);
		$this->addOption('log', 'New local log file', true, true);
	}
	public function execute() {
		if (!class_exists(\SMW\Scribunto\LibraryFactory::class)
			|| !str_ends_with((new ReflectionClass(\SMW\Scribunto\LibraryFactory::class))->getFileName(), 'SemanticDouble.php')) {
			throw new RuntimeException('Run only with the disposable semantic double');
		}
		$log = $this->getOption('log');
		if (file_exists($log)) { throw new RuntimeException('Use a new log path'); }
		$GLOBALS['wgDebugLogGroups']['DebatePHP'] = $log;
		\MediaWiki\MediaWikiServices::getInstance()->getHookContainer()->register('WikiSEOLuaPreAddPageProps', static function(&$props) {
			if ($GLOBALS['debateSeoFailure'] ?? false) { throw new RuntimeException('SENSITIVE seo value'); }
		});
		foreach (['normal', 'exception', 'result', 'seo-exception'] as $scenario) {
			\SMW\Scribunto\LibraryFactory::$failure = in_array($scenario, ['exception', 'result'], true) ? $scenario : '';
			$GLOBALS['debateSeoFailure'] = $scenario === 'seo-exception';
			$before = $this->one(false); $observed = $this->one(true);
			if ($before !== $observed) { throw new RuntimeException('Observation changed parser output: ' . $scenario); }
			$this->output($scenario . ": identical output with observations on/off\n");
		}
		$events = [];
		foreach (file($log, FILE_IGNORE_NEW_LINES) as $line) {
			$offset = strpos($line, '{'); if ($offset !== false) { $events[] = json_decode(substr($line, $offset), true, 512, JSON_THROW_ON_ERROR); }
		}
		if (str_contains(file_get_contents($log), 'SENSITIVE')) { throw new RuntimeException('Sensitive values leaked'); }
		$problems = array_values(array_filter($events, static fn($v) => $v['event'] === 'diagnostic'));
		$seoAvailable = class_exists(\MediaWiki\Extension\WikiSEO\Validator::class);
		$expectedProblems = $seoAvailable
			? [['semantic', 'exception'], ['semantic', 'returned-error'], ['seo', 'exception']]
			: [['seo', 'service-unavailable'], ['semantic', 'exception'], ['seo', 'service-unavailable'],
				['semantic', 'returned-error'], ['seo', 'service-unavailable'], ['seo', 'service-unavailable']];
		if (array_map(static fn($v) => [$v['stage'], $v['reason']], $problems)
			!== $expectedProblems) {
			throw new RuntimeException('Missing or unexpected diagnostics');
		}
		$profiles = array_values(array_filter($events, static fn($v) => $v['event'] === 'profile'));
		if (count($profiles) !== 4) { throw new RuntimeException('Missing profiles'); }
		foreach ($profiles as $profile) {
			foreach (['setup', 'render', 'arguments', 'native_tags', 'shortdesc', 'messages', 'semantic', 'seo', 'json'] as $stage) {
				if ($stage === 'seo' && !$seoAvailable) { continue; }
				if (($profile['stages'][$stage]['calls'] ?? 0) < 1) { throw new RuntimeException('Missing timing stage ' . $stage); }
			}
			if (!isset($profile['counters']['preprocess_executed'])
				|| $profile['counters']['preprocess_executed'] !== array_sum(array_column($profile['preprocessing'], 'calls'))) {
				throw new RuntimeException('Missing or inconsistent production preprocessing counts');
			}
		}
		$this->output("Error diagnostics, timing stages and preprocessing counters verified; values omitted. WikiSEO " . ($seoAvailable ? 'present' : 'absent') . ".\n");
	}
	private function one(bool $enabled): array {
		$GLOBALS['wgDebatePHPProfiling'] = $enabled; $GLOBALS['wgDebatePHPDiagnostics'] = $enabled;
		$services = \MediaWiki\MediaWikiServices::getInstance();
		$parser = $services->getParserFactory()->create();
		$options = \MediaWiki\Parser\ParserOptions::newFromAnon(); $options->setTimestamp('20261018000000');
		$normal = $options->getTemplateCallback();
		$options->setTemplateCallback(static function($title, $p) use ($normal) {
			$v = $normal($title, $p);
			if ($title->getNamespace() === NS_TEMPLATE && is_string($v['text'])) {
				$v['text'] = str_replace('{{#invoke:Debate|render', '{{#debate:render', $v['text']);
			} return $v;
		});
		$out = $parser->parse('{{Débat|sujet=Observability test}}', \MediaWiki\Title\Title::newFromText('DebatePHP observations'), $options);
		return [$out->getText(), $out->getCategoryMap(), $out->getPageProperties(), $out->getTemplates(),
			$out->getAllFlags(), $out->getCacheExpiry(), $out->getExternalLinks(), $out->getLanguageLinks(), $out->getModules(), $out->getLinks()];
	}
}
$maintClass = DebateObservabilityTest::class;
require RUN_MAINTENANCE_IF_MAIN;
