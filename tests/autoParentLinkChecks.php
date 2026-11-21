<?php

use MediaWiki\Extension\Wikidebates\AutoParentLink;

$invoke = static function ( string $method, mixed ...$args ): mixed {
	return ( new ReflectionMethod( AutoParentLink::class, $method ) )->invoke( null, ...$args );
};
$config = [ 'childPageParam' => 'page', 'childDisplayTitleParam' => 'displayed-title' ];
$contains = static fn( string $text, string $page = 'Argument B' ): bool =>
	$invoke( 'parameterContainsChild', $text, 'Pro argument', $page, $config );
$cases = [
	[ false, '{{Pro argument|page=Argument A|displayed-title=Argument B}}', 'A label is not the target' ],
	[ true, '{{Pro argument|page=Argument B|displayed-title=Other}}', 'Explicit target' ],
	[ true, '{{Pro argument|page=argument_B}}', 'Title normalization' ],
	[ true, '{{Template:Pro_argument|page=Argument B}}', 'Template namespace normalization' ],
	[ true, '{{Pro argument|displayed-title=Argument B}}', 'Legacy label without a page parameter' ],
	[ false, '{{Pro argument|page=|displayed-title=Argument B}}', 'Explicit empty page is not a label' ],
	[ false, "{{Pro argument|page=Argument B\nextra}}", 'A matching prefix is not the whole title' ],
	[ false, '{{Pro argument|page=A}}{{Other|page=Argument B}}', 'No crossing between templates' ],
	[ false, '<!-- {{Pro argument|page=Argument B}} -->', 'No commented child records' ],
	[ false, '<nowiki>{{Pro argument|page=Argument B}}</nowiki>', 'No literal child records' ],
	[ false, '{{Wrapper|nested={{Pro argument|page=Argument B}}}}', 'No nested child records' ],
	[ false, '{{Pro argument|page=Argument B|page=Other}}', 'Last target wins' ],
	[ true, '{{Pro argument|page=Other|page=Argument B}}', 'Last target is recognized' ]
];
foreach ( $cases as [ $expected, $text, $label ] ) {
	same( $expected, $contains( $text ), $label );
}
foreach ( [
	'plain' => 'normal text',
	'comment' => '<!-- }} |fake=1 {{ -->',
	'nowiki' => '<nowiki>}} |fake=1 {{</nowiki>',
	'case and attributes' => '<NOWIKI data-x="a>b">}}|x=1</NOWIKI>',
	'self closing' => '<nowiki />text',
	'parameter' => '{{{name|default}}}',
	'nested parameters' => '{{{one|{{{two|fallback}}}}}}',
	'nested template' => '{{Example|value={{Nested|a=b}}}}',
	'link' => '[[Target|a=b|c]]',
	'UTF-8' => "\u{00e9}\u{00e8} <!-- }} --> \u{03b1} <nowiki>|</nowiki>"
] as $label => $value ) {
	$text = "{{Debate\n|topic=$value\n|pro-arguments=\n|other=keep exactly\n}}";
	$parsed = $invoke( 'parseTopLevelTemplate', $text );
	check( $parsed !== null && $parsed['end'] === strlen( $text ), "Complete template: $label" );
	same( $value, $invoke( 'getParameterValue', $parsed['parts'], 'topic' ), "Preserve value: $label" );
	$block = '{{Pro argument|page=New child}}';
	$parts = $invoke( 'upsertParameterInParts', $parsed['parts'], 'pro-arguments', $block, [] );
	same( str_replace( '|pro-arguments=', '|pro-arguments=' . $block, $text ),
		$invoke( 'joinTemplateParts', $parts ), "Only the requested field changes: $label" );
}
foreach ( [ '<!-- }}', '<nowiki>}}', '{{Nested|missing close', '{{{{dynamic}}}}' ] as $value ) {
	same( null, $invoke( 'parseTopLevelTemplate', '{{Debate|topic=' . $value . '}}' ),
		'Unclosed or ambiguous syntax is refused: ' . $value );
}
$text = "{{Debate\n|topic = Text with trailing spaces  \n|pro-arguments = {{Pro argument|page=Old}}\n|other= value  \n}}\n<!-- outside -->";
$parsed = $invoke( 'parseTopLevelTemplate', $text );
$parts = $invoke( 'upsertParameterInParts', $parsed['parts'], 'pro-arguments', '{{Pro argument|page=New}}', [] );
$updated = substr( $text, 0, $parsed['start'] ) . $invoke( 'joinTemplateParts', $parts ) . substr( $text, $parsed['end'] );
same( str_replace( '{{Pro argument|page=Old}}', "{{Pro argument|page=Old}}\n{{Pro argument|page=New}}", $text ),
	$updated, 'Unrelated whitespace and the suffix remain byte-identical' );
foreach ( [ '{{Debate}}', '{{Debate|topic=Topic}}', "{{Debate\n|topic=Topic\n|other=keep\n}}" ] as $text ) {
	$parsed = $invoke( 'parseTopLevelTemplate', $text );
	$parts = $invoke( 'upsertParameterInParts', $parsed['parts'], 'pro-arguments', '{{Pro argument|page=New}}', [ 'topic', 'pro-arguments', 'other' ] );
	$updated = $invoke( 'joinTemplateParts', $parts );
	$reparsed = $invoke( 'parseTopLevelTemplate', $updated );
	same( '{{Pro argument|page=New}}', $invoke( 'getParameterValue', $reparsed['parts'], 'pro-arguments' ), 'A missing parameter is inserted' );
}
$text = '{{Debate|pro-arguments=Earlier|pro-arguments=Final}}';
$parts = $invoke( 'parseTopLevelTemplate', $text )['parts'];
$parts = $invoke( 'upsertParameterInParts', $parts, 'pro-arguments', '{{Pro argument|page=New}}', [] );
same( "{{Debate|pro-arguments=Earlier|pro-arguments=Final\n{{Pro argument|page=New}}}}", $invoke( 'joinTemplateParts', $parts ),
	'Only the effective duplicate parameter is changed' );
$fr = [ 'childPageParam' => 'page', 'childDisplayTitleParam' => "titre-affich\u{00e9}" ];
check( !$invoke( 'parameterContainsChild', "{{Argument pour|page=Autre|titre-affich\u{00e9}=Cible}}", 'Argument pour', 'Cible', $fr ),
	'French labels are not targets either' );
echo "OK: automatic parent syntax, target identity and byte preservation\n";
