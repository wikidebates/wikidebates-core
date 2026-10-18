<?php
// Read-only for the wiki. --rebuild updates local files only, after Lua/PHP comparisons.
$wikiRoot = null;
foreach ($argv as $a) { if (str_starts_with($a, '--wiki-root=')) { $wikiRoot = substr($a, 12); } }
if (!$wikiRoot) { fwrite(STDERR, "--wiki-root is required\n"); exit(2); }
require $wikiRoot . '/maintenance/Maintenance.php';
require __DIR__ . '/I18nCompiler.php';

use MediaWiki\Extension\DebatePHP\I18n;
use MediaWiki\Extension\DebatePHP\Table;
use MediaWiki\Extension\DebatePHP\Maintenance\I18nCompiler;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

class MaintainDebateI18n extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption('wiki-root', 'MediaWiki root', true, true);
		$this->addOption('rebuild', 'Replace local PHP table after successful comparisons');
		$this->addOption('source', 'Optional candidate raw data file instead of the wiki data page', false, true);
		$this->addOption('output', 'Candidate PHP output; when set, bundled files are not updated', false, true);
	}
	public function execute() {
		if ($this->hasOption('output') && !$this->hasOption('rebuild')) { $this->fatalError('--output requires --rebuild'); }
		$root = dirname(__DIR__);
		if ($this->hasOption('output')) {
			$path = $this->getOption('output');
			$resolved = realpath(dirname($path));
			$target = $resolved === false ? '' : $resolved . DIRECTORY_SEPARATOR . basename($path);
			foreach (['I18nData.php', 'WD-I18N-Data.snapshot.lua'] as $name) {
				if ($target === realpath($root . '/resources') . DIRECTORY_SEPARATOR . $name) {
					$this->fatalError('--output must be a separate candidate file; use --rebuild without --output for the installed table.');
				}
			}
		}
		$sources = [];
		$services = MediaWikiServices::getInstance();
		$sourceParser = $services->getParserFactory()->create();
		$sourceParser->preprocess('', Title::newFromText('DebatePHP validation'), ParserOptions::newFromAnon());
		foreach (['Module:Debate', 'Module:WD/I18N', 'Module:WD/I18N/Data'] as $name) {
			$title = Title::newFromText($name);
			if (!$title || !$title->hasContentModel(CONTENT_MODEL_SCRIBUNTO)) {
				throw new RuntimeException('Missing Scribunto source: ' . $name);
			}
			[$text] = $sourceParser->fetchTemplateAndTitle($title);
			if ($text === false) { throw new RuntimeException('Cannot fetch ' . $name); }
			$sources[$name] = $text;
		}
		foreach (['Module:Debate' => 'Module-Debate.reference.lua', 'Module:WD/I18N' => 'WD-I18N.reference.lua'] as $name => $file) {
			if (rtrim($sources[$name], "\r\n") !== rtrim(file_get_contents($root . '/resources/' . $file), "\r\n")) {
				throw new RuntimeException($name . ' logic changed: rebase the PHP implementation before rebuilding.');
			}
		}
		if ($this->hasOption('source')) {
			$text = file_get_contents($this->getOption('source'));
			if ($text === false) { throw new RuntimeException('Cannot read candidate source'); }
			if ($this->hasOption('rebuild') && !$this->hasOption('output') && $text !== $sources['Module:WD/I18N/Data']) {
				throw new RuntimeException('Candidate differs from the wiki; use --output to stage it without replacing the installed table.');
			}
			$sources['Module:WD/I18N/Data'] = $text;
		}
		$source = $sources['Module:WD/I18N/Data'];
		$candidate = I18nCompiler::prepare($source, $sources['Module:WD/I18N']);
		$queries = [];
		foreach ($candidate['data'] as $domain => $sections) {
			if (!is_array($sections)) { continue; }
			foreach ($sections as $section => $entries) {
				if (!is_array($entries)) { continue; }
				foreach ($entries as $key => $_) {
					foreach (['fr', 'en'] as $lang) { $queries[] = [$domain . '.' . $section, $lang, (string)$key, 'PAGE', 'SIDE', 'THIRD']; }
				}
			}
		}
		foreach (['Missing', 'Debate.missing', 'Common.text'] as $domain) {
			foreach (['fr-CA', 'EN', 'xx'] as $lang) { $queries[] = [$domain, $lang, 'missing-key', 'PAGE']; }
		}
		$args = [[], ['lang' => 'fr'], ['lang' => 'EN'], ['language' => 'fr-CA'], ['lang' => ''], ['lang' => '0'], ['lang' => 'en', 'language' => 'fr']];
		$json = json_encode(['queries' => $queries, 'args' => $args], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$equals = '='; while (str_contains($json, ']' . $equals . ']')) { $equals .= '='; }
		$lua = "local I = require('Module:WD/I18N')\nlocal p = {}\nfunction p.render()\nlocal input = mw.text.jsonDecode([" . $equals . '[' . $json . ']' . $equals . "])\n" .
			"local out = {messages = {}, languages = {}}\nfor i,q in ipairs(input.queries) do local ok,v = pcall(I.msg, unpack(q)); out.messages[i] = {ok = ok, value = tostring(v)} end\n" .
			"for i,a in ipairs(input.args) do out.languages[i] = I.getLangFromArgs(a) end\nreturn mw.text.jsonEncode(out)\nend\nreturn p";
		$options = ParserOptions::newFromAnon(); $normal = $options->getTemplateCallback();
		$options->setTemplateCallback(static function($title, $parser) use ($normal, $sources, $lua) {
			$value = $normal($title, $parser); $name = $title->getPrefixedText();
			if (isset($sources[$name])) { $value['text'] = $name === 'Module:Debate' ? $lua : $sources[$name]; }
			return $value;
		});
		$parser = $services->getParserFactory()->create();
		$result = $parser->preprocess('{{#invoke:Debate|render}}', Title::newFromText('DebatePHP validation'), $options);
		$oracle = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
		if (count($oracle['messages'] ?? []) !== count($queries) || count($oracle['languages'] ?? []) !== count($args)) {
			throw new RuntimeException('Incomplete Lua comparison');
		}
		foreach ([false, true] as $memoize) {
			$php = new I18n($candidate['data'], $memoize);
			foreach ($queries as $index => $query) {
				$expected = $oracle['messages'][$index];
				$label = implode(' / ', array_slice($query, 0, 3));
				try { $actual = $php->message(...$query); }
				catch (Throwable $e) { $this->fatalError('PHP translation failed at ' . $label . '; no files were modified.'); }
				if (!$expected['ok'] || $actual !== $expected['value']) {
					$this->fatalError('Translation differs at ' . $label . '; no files were modified.');
				}
			}
			foreach ($args as $index => $values) {
				if ($php->language(new Table($values), $services->getContentLanguage()->getCode()) !== $oracle['languages'][$index]) {
					throw new RuntimeException('Language selection differs at case ' . $index);
				}
			}
		}
		$this->output((count($queries) * 2) . " translation comparisons and " . (count($args) * 2) . " language comparisons passed with Scribunto.\n");
		if ($this->hasOption('rebuild')) {
			$target = $this->getOption('output', $root . '/resources/I18nData.php');
			if (!$this->hasOption('output')) {
				I18nCompiler::writeAtomically($root . '/resources/WD-I18N-Data.snapshot.lua', $source);
			}
			I18nCompiler::writeAtomically($target, $candidate['php']);
			$this->output($this->hasOption('output')
				? "Candidate PHP table written after validation; bundled files and wiki pages not modified.\n"
				: "PHP table and test snapshot rebuilt after validation. Follow the site's OPcache reload and page-cache invalidation procedure.\n");
		} else {
			$installed = require $root . '/resources/I18nData.php';
			if ($installed !== $candidate['compiled']) { throw new RuntimeException('PHP table is out of sync; run again with --rebuild.'); }
			$this->output("Installed PHP table is synchronized. No files or wiki pages modified.\n");
		}
	}
}
$maintClass = MaintainDebateI18n::class;
require RUN_MAINTENANCE_IF_MAIN;
