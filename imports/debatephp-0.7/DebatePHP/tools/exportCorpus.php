<?php
$wikiRoot = null;
foreach ($argv as $a) { if (str_starts_with($a, '--wiki-root=')) { $wikiRoot = substr($a, 12); } }
if (!$wikiRoot) { fwrite(STDERR, "--wiki-root is required\n"); exit(2); }
require $wikiRoot . '/maintenance/Maintenance.php';
class ExportDebateCorpus extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption('wiki-root', 'MediaWiki root', true, true);
		$this->addOption('titles', 'UTF-8 text file, one title per line', true, true);
		$this->addOption('output', 'Local JSON output', true, true);
	}
	public function execute() {
		$cases = []; $services = \MediaWiki\MediaWikiServices::getInstance();
		foreach (file($this->getOption('titles'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $name) {
			$title = \MediaWiki\Title\Title::newFromText($name);
			if (!$title) { throw new \RuntimeException('Invalid title: ' . $name); }
			$page = $services->getWikiPageFactory()->newFromTitle($title); $content = $page->getContent();
			if (!$content instanceof \MediaWiki\Content\TextContent) { throw new \RuntimeException('Missing text page: ' . $name); }
			$cases[] = ['title' => $title->getPrefixedText(), 'text' => $content->getText(), 'revision' => $page->getLatest()];
		}
		file_put_contents($this->getOption('output'), json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$this->output(count($cases) . " pages exported; no wiki pages modified.\n");
	}
}
$maintClass = ExportDebateCorpus::class;
require RUN_MAINTENANCE_IF_MAIN;
