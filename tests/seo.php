<?php

use MediaWiki\Extension\Wikidebates\Seo\Metadata;

require_once __DIR__ . '/../src/Seo/Metadata.php';

$debateData = [
	'wd_kind' => 'debate',
	'wd_creation_date' => '2020-12-31',
	'title' => 'Faut-il débattre ? Arguments pour et contre – Wikidébats',
	'description' => 'Arguments pour et contre le débat : justifications et objections.',
	'image' => 'https://fr.wikidebates.org/w/resources/assets/logo-Wikidebats-Twitter.png',
	'image_alt' => 'Balance de la raison qui pèse les arguments pour et contre',
	'site_name' => 'Wikidébats',
	'locale' => 'fr_FR',
	'twitter_site' => '@Wikidebats',
	'author' => 'Contributeurs de Wikidébats',
	'section' => 'Débat',
	'keywords' => 'argument, débat',
	'robots' => 'index,follow',
	'googlebot' => 'index,follow',
];

$debate = Metadata::build( $debateData, 'https://fr.wikidebates.org/wiki/Test', '2026-11-23T12:30:00Z' );
same( $debateData['title'], $debate['title'], 'Legacy debate title retained' );
same( $debateData['description'], $debate['meta']['description'], 'Legacy debate description retained' );
same( 'website', $debate['open_graph']['og:type'], 'Debate uses approved website type' );
foreach ( [ 'author', 'section', 'tag', 'published_time', 'modified_time' ] as $field ) {
	same( false, isset( $debate['open_graph']['article:' . $field] ), 'No article namespace on debate: ' . $field );
}
same( $debateData['image'], $debate['open_graph']['og:image'], 'User-provided debate image retained exactly' );
same( $debateData['image'], $debate['meta']['twitter:image'], 'Only original image reused on X' );
same( 'image/png', $debate['open_graph']['og:image:type'], 'Image MIME type inferred without network access' );
same( $debateData['image_alt'], $debate['meta']['twitter:image:alt'], 'New X image alt matches original' );
same( $debateData['title'], $debate['meta']['twitter:title'], 'Dedicated X title exists' );
same( $debateData['description'], $debate['meta']['twitter:description'], 'Dedicated X description exists' );
same( 'summary_large_image', $debate['meta']['twitter:card'], 'Existing X card retained' );
same( 'index,follow', $debate['robots'], 'Existing robots policy retained' );

$argumentData = [
	'wd_kind' => 'argument',
	'wd_creation_date' => '2024-01-02',
	'wd_summary' => "'''La thèse''' [[Débat|défend]] l'idée <em>claire</em> &amp; fondée.",
	'title' => 'Une thèse : arguments et objections – Wikidébats',
	'description' => 'Arguments pour et contre : ancienne description.',
	'type' => 'article',
	'image' => $debateData['image'],
	'image_alt' => $debateData['image_alt'],
];
$argument = Metadata::build( $argumentData, 'https://fr.wikidebates.org/wiki/Argument', '2026-01-05T00:00:00Z' );
same( 'article', $argument['open_graph']['og:type'], 'Argument retains article type' );
same( false, isset( $argument['open_graph']['article:author'] ), 'Collective credit is not an Open Graph author profile' );
same( "La thèse défend l'idée claire & fondée.", $argument['meta']['description'], 'Argument summary converted to plain text' );
same( $argument['meta']['description'], $argument['open_graph']['og:description'], 'Open Graph uses argument summary' );
same( $argument['meta']['description'], $argument['meta']['twitter:description'], 'X uses argument summary' );
same( $argumentData['image'], $argument['meta']['twitter:image'], 'Original user image preserved for argument' );
same( '2024-01-02T00:00:00Z', $argument['open_graph']['article:published_time'], 'Argument creation-date preserved' );
$nativeDate = Metadata::build( $argumentData + [
	'wd_published_time' => '2024-01-02T14:37:25Z'
], 'https://fr.wikidebates.org/wiki/Argument', null );
same( '2024-01-02T14:37:25Z', $nativeDate['open_graph']['article:published_time'],
	'Native publication time wins over old editorial midnight' );
$undated = Metadata::build( $argumentData + [ 'wd_published_time' => null ],
	'https://fr.wikidebates.org/wiki/Argument', null );
check( !isset( $undated['open_graph']['article:published_time'] ), 'New undated payload never falls back to editorial date' );
$argumentData['keywords'] = 'philosophie, justice, philosophie,   débat';
$tagged = Metadata::build( $argumentData, 'https://fr.wikidebates.org/wiki/Argument', null );
same( [ 'philosophie', 'justice', 'débat' ], $tagged['open_graph']['article:tag'], 'Repeat Open Graph tags without duplicates' );

$graph = [
	'@graph' => [
		1 => [ '@type' => 'WebSite', '@id' => 'https://example.org/#website', 'url' => 'https://example.org/' ],
		2 => [ '@type' => 'Organization', '@id' => 'https://example.org/#organization' ],
		3 => [ '@type' => 'QAPage', '@id' => 'https://example.org/#qa', 'url' => 'https://example.org/' ],
	],
];
$originalGraph = $graph;
$updatedGraph = Metadata::withModificationDate( $graph, '2026-11-23T12:30:00Z' );
same( '2026-11-23T12:30:00Z', $updatedGraph['@graph'][3]['dateModified'], 'QAPage has revision date' );
same( false, isset( $updatedGraph['@graph'][1]['dateModified'] ), 'Website is not modified by page revision' );
same( false, isset( $updatedGraph['@graph'][2]['dateModified'] ), 'Organization is not modified by page revision' );
same( $originalGraph, Metadata::withModificationDate( $graph, null ), 'Missing revision does not invent date' );
$argGraph = [ '@graph' => [ [ '@type' => 'WebPage', '@id' => 'https://example.org/a', 'url' => 'https://example.org/a' ] ] ];
same( '2026-11-23T12:30:00Z',
	Metadata::withModificationDate( $argGraph, '2026-11-23T12:30:00Z' )['@graph'][0]['dateModified'],
	'WebPage has revision date' );

$publicationGraph = [ '@graph' => [
	[ '@type' => 'WebPage', '@id' => 'https://example.org/a', 'url' => 'https://example.org/a',
		'datePublished' => '2000-01-01T00:00:00Z', 'mainEntity' => [ '@id' => 'https://example.org/a#claim' ] ],
	[ '@type' => 'Claim', '@id' => 'https://example.org/a#claim', 'datePublished' => '2000-01-01T00:00:00Z',
		'citation' => [ '@type' => 'Book', 'datePublished' => '1900' ] ],
	[ '@type' => 'QAPage', '@id' => 'https://example.org/b#qa', 'url' => 'https://example.org/b',
		'mainEntity' => [ '@type' => 'Question', 'datePublished' => '2000-01-01T00:00:00Z' ] ],
] ];
$publishedGraph = Metadata::withPublicationDate( $publicationGraph, '2024-01-02T14:37:25Z' )['@graph'];
same( '2024-01-02T14:37:25Z', $publishedGraph[0]['datePublished'], 'Native page date overrides editorial date' );
same( '2024-01-02T14:37:25Z', $publishedGraph[2]['datePublished'], 'Native debate date overrides editorial date' );
check( !isset( $publishedGraph[1]['datePublished'], $publishedGraph[2]['mainEntity']['datePublished'] ) &&
	!isset( $publishedGraph[1]['datePublished'] ) && !isset( $publishedGraph[2]['mainEntity']['datePublished'] ),
	'Page creation must not date the claim or the question itself' );
same( '1900', $publishedGraph[1]['citation']['datePublished'], 'Bibliographic dates remain intact' );
$undatedGraph = Metadata::withPublicationDate( $publicationGraph, null )['@graph'];
check( !isset( $undatedGraph[0]['datePublished'] ), 'No fallback publication date on a new undated graph' );

$argumentData['wd_summary'] = '';
$fallback = Metadata::build( $argumentData, 'https://fr.wikidebates.org/wiki/Argument', null );
same( $argumentData['description'], $fallback['meta']['description'], 'Missing summary uses exact legacy wording' );
same( false, isset( $fallback['open_graph']['article:modified_time'] ), 'Unknown modification date not fabricated' );

$argumentData['wd_creation_date'] = '2025-02-30';
$argumentData['image'] = '';
$withoutImage = Metadata::build( $argumentData, 'https://fr.wikidebates.org/wiki/Argument', null );
same( false, isset( $withoutImage['open_graph']['og:image'] ), 'Never invent an image' );
same( false, isset( $withoutImage['meta']['twitter:image'] ), 'Never add an image fallback on X' );
same( false, isset( $withoutImage['open_graph']['article:published_time'] ), 'Invalid creation date not published' );
same( [], Metadata::build( [ 'wd_kind' => 'other' ], 'https://fr.wikidebates.org/wiki/Other', null ),
	'Ordinary pages are excluded from Wikidebates native SEO' );

echo "OK: native debate/argument SEO, existing images, publication/modification dates and social metadata\n";
