<?php

require __DIR__ . '/bootstrap.php';

use MediaWiki\Extension\DebatePHP\HtmlBuilder;
use MediaWiki\Extension\DebatePHP\I18n;
use MediaWiki\Extension\DebatePHP\LiteralData;
use MediaWiki\Extension\DebatePHP\Lua;
use MediaWiki\Extension\DebatePHP\Table;
use MediaWiki\Extension\DebatePHP\Telemetry;
use MediaWiki\Extension\DebatePHP\TextEscaper;

$resources = __DIR__ . '/../debatephp/resources/';
$data = file_get_contents($resources . 'WD-I18N-Data.snapshot.lua');
$logic = file_get_contents($resources . 'WD-I18N.reference.lua');
$compiled = require $resources . 'I18nData.php';
same($compiled['data'], (new LiteralData)->read($data), 'Compiled translations match the complete Lua snapshot');
$reference = I18n::bundled($data, false, $logic);
$optimized = I18n::bundled($data, true, $logic);
$messages = 0;
$walk = static function(array $table, string $path = '') use (&$walk, &$messages, $reference, $optimized): void {
	foreach ($table as $key => $value) {
		if (is_array($value) && !isset($value['fr']) && !isset($value['en'])) {
			$walk($value, $path === '' ? $key : $path . '.' . $key);
			continue;
		}
		foreach (['en', 'fr', 'fr-CA', 'de'] as $language) {
			same($reference->message($path, $language, $key), $optimized->message($path, $language, $key), 'Translation ' . $path . '.' . $key);
			$messages++;
		}
	}
};
$walk($compiled['data']);
same('fr', $reference->language(new Table(['lang' => ' FR-ca ']), 'en'), 'Explicit language');
same('en', $reference->language(new Table, 'de'), 'English fallback');
rejects(static fn() => I18n::bundled($data . ' ', true, $logic), 'Reject changed translation data');
rejects(static fn() => I18n::bundled($data, true, $logic . 'changed'), 'Reject changed translation logic');
rejects(static fn() => (new LiteralData)->read('return os.execute("false")'), 'Never execute Lua');

same(true, Lua::truth('0'), 'Lua zero string is truthy');
same(true, Lua::truth(new Table), 'Lua empty table is truthy');
same('string', Lua::type('trim'), 'Callable names remain Lua strings');
$wrapped = Table::wrap(['items' => [1 => 'é', 2 => ['nested' => '0']]]);
same(['items' => [1 => 'é', 2 => ['nested' => '0']]], Table::unwrap($wrapped), 'Nested table round trip');
same(['é', '0'], TextEscaper::jsonValue(new Table([1 => 'é', 2 => '0'])), 'One-based arrays encode as JSON lists');
same('A_é', rawurldecode(TextEscaper::uriEncode('A é', 'WIKI')), 'Wiki URL encoding');
check(!str_contains(TextEscaper::nowiki('{{x}} [[y]] https://example.test', ['https://']), '{{'), 'Escape wikitext delimiters');
same('mailto&#58;test@example.test', TextEscaper::nowiki('mailto:test@example.test', ['mailto:']), 'Configured protocols');
$json = json_encode(['outer' => ['inner' => new stdClass], 'text' => '}}} "\\'], JSON_THROW_ON_ERROR);
same(json_decode($json, true, 512, JSON_THROW_ON_ERROR), json_decode(TextEscaper::spaceStructuralClosures($json), true, 512, JSON_THROW_ON_ERROR), 'JSON whitespace never changes strings');
same('<a title="&quot;&lt;&amp;">text</a>', (string)HtmlBuilder::create('a')->attr('title', '"<&')->wikitext('text'), 'Attribute escaping');

$events = [];
$sink = static function(array $event) use (&$events): void { $events[] = $event; };
$telemetry = new Telemetry(false, false, [], $sink);
$telemetry->problem('render', 'exception', new RuntimeException('private text'));
$telemetry->report();
same([], $events, 'Telemetry is opt-in');
$telemetry = new Telemetry(true, true, ['module' => 'Debate'], $sink);
$started = $telemetry->start();
$telemetry->finish('render', $started);
$telemetry->problem('render', 'exception', new RuntimeException('private text'));
$telemetry->report();
same('diagnostic', $events[0]['event'], 'Diagnostic event');
same('profile', $events[1]['event'], 'Profile event');
check(!str_contains(json_encode($events), 'private text'), 'Diagnostics never log expanded text or exception messages');
$telemetry = new Telemetry(false, true, [], static function(): void { throw new RuntimeException('sink failure'); });
$telemetry->problem('render', 'test');

foreach (['en', 'fr'] as $language) {
	$messagesFile = json_decode(file_get_contents(__DIR__ . '/../debatephp/i18n/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
	check(isset($messagesFile['htmltags-notagname'], $messagesFile['htmltags-unsupportedtag']), 'HTML Tags translations loaded');
}
echo "OK: $messages translation comparisons, source guards, Lua semantics, escaping and telemetry\n";
