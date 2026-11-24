<?php

/**
 * Informational microbenchmark using the actual modern WikiSEO hooks.
 * This isolated lab compares the current fork's full and global-only paths,
 * not the historical migration or end-to-end HTTP response latency.
 */
require __DIR__ . '/mediawikiEnvironment.php';

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikiSEO\Hooks\PageHooks;
use MediaWiki\Extension\WikiSEO\PagePolicy;
use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;

check( ExtensionRegistry::getInstance()->isLoaded( 'WikiSEO' ) && class_exists( PagePolicy::class ),
	'Benchmark requires the modern WikiSEO fork' );

$services = MediaWikiServices::getInstance();
// Only this disposable process overrides the native opt-out for the baseline.
// Both paths still run the same native final head replacement.
$services->getHookContainer()->register( 'WikiSEOPageMetadataMode',
	static function ( OutputPage $out, string &$mode ): void {
		if ( $mode === 'global-only' && $out->getProperty( 'wd-seo-benchmark-full' ) === true ) {
			$mode = 'full';
		}
	}
);
$wikiSeo = new PageHooks( $services->getMainConfig() );
$skin = new class( [ 'name' => 'vector' ] ) extends \Skin {
	public function outputPage() {}
};

function seoBenchmarkOutput( array $data, bool $full ): OutputPage {
	$context = new RequestContext();
	$context->setTitle( Title::newFromText( $data['wd_kind'] === 'debate'
		? 'SEO benchmark debate?' : 'SEO benchmark argument' ) );
	$out = $context->getOutput();
	$out->setArticleFlag( true );
	$out->setRevisionTimestamp( '20261123120000' );

	$parserOutput = new ParserOutput();
	$parserOutput->setExtensionData( Metadata::DATA_KEY, $data );
	Metadata::onOutputPageParserOutput( $out, $parserOutput );
	$out->setProperty( 'wd-seo-benchmark-full', $full );

	// Simulate pre-migration page properties in both branches. No explicit
	// property or native marker is removed to manipulate benchmark timing.
	foreach ( [ 'title', 'description', 'keywords', 'image', 'image_alt', 'robots' ] as $key ) {
		$out->setProperty( $key, $data[$key] );
	}
	$out->setProperty( 'type', $data['wd_kind'] === 'debate' ? 'website' : 'article' );
	return $out;
}

/** Time the hook path only; input construction and assertions stay outside. */
function seoBenchmarkRender( OutputPage $out, PageHooks $wikiSeo, \Skin $skin, bool $verify = false ): array {
	$start = hrtime( true );
	Metadata::beforePageDisplay( $out );
	$wikiSeo->onBeforePageDisplay( $out, $skin );
	$beforeEnd = hrtime( true );
	$generated = $verify ? !empty( $out->getHeadItemsArray()['jsonld-metadata'] ) : null;
	$headLinks = $out->getHeadLinksArray();
	$headItems = $out->getHeadItemsArray();
	$end = hrtime( true );

	return [
		'before_us' => ( $beforeEnd - $start ) / 1000,
		'head_us' => ( $end - $beforeEnd ) / 1000,
		'total_us' => ( $end - $start ) / 1000,
		'generated' => $generated,
		'html' => $verify ? implode( "\n", $headLinks ) . "\n" . implode( "\n", $headItems ) : '',
	];
}

function seoBenchmarkStats( array $samples ): array {
	sort( $samples, SORT_NUMERIC );
	$n = count( $samples );
	$median = ( $samples[(int)floor( ( $n - 1 ) / 2 )] + $samples[(int)floor( $n / 2 )] ) / 2;
	return [
		'median_us' => round( $median, 3 ),
		'p95_us' => round( $samples[(int)ceil( $n * 0.95 ) - 1], 3 ),
	];
}

$common = [
	'wd_creation_date' => '2020-12-31',
	'image' => 'https://example.test/static/logo.png',
	'image_alt' => 'Balance of arguments',
	'site_name' => 'Wikidebates',
	'locale' => 'en_US',
	'twitter_site' => '@Wikidebates',
	'keywords' => 'debate, argument, objections',
	'robots' => 'index,follow',
	'section' => 'Argument',
];
$cases = [
	'debate' => $common + [
		'wd_kind' => 'debate',
		'title' => 'Should we debate? Pros and cons – Wikidebates',
		'description' => 'Arguments for and against debate.',
	],
	'argument' => $common + [
		'wd_kind' => 'argument',
		'title' => 'An argument: arguments and objections – Wikidebates',
		'description' => 'A generic argument description.',
		'wd_summary' => 'An argument supported by evidence and open to objections.',
	],
];

$warmups = 12;
$iterations = 100;
foreach ( $cases as $kind => $data ) {
	$expected = Metadata::build( $data, 'https://example.test/wiki/Test', '2026-11-23T12:00:00Z' );
	foreach ( [ true, false ] as $full ) {
		$out = seoBenchmarkOutput( $data, $full );
		same( $full ? 'full' : 'global-only', PagePolicy::forOutput( $out ), 'Correct benchmark policy' );
		$render = seoBenchmarkRender( $out, $wikiSeo, $skin, true );
		same( $full, $render['generated'], 'Only full mode runs the generic WikiSEO JSON-LD generator' );
		check( !str_contains( $render['html'], 'application/ld+json' ),
			'No competing JSON-LD remains in the final head' );
		preg_match_all( '~<meta\b[^>]*\b(?:name|property)="([^"]+)"[^>]*\bcontent="([^"]*)"[^>]*>~i',
			$render['html'], $matches, PREG_SET_ORDER );
		$tags = [];
		foreach ( $matches as $match ) {
			$tags[$match[1]][] = html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		foreach ( [ 'description', 'twitter:title', 'twitter:description' ] as $key ) {
			same( [ $expected['meta'][$key] ], $tags[$key] ?? null, 'Same single native ' . $key );
		}
		foreach ( [ 'og:type', 'og:image', 'og:description' ] as $key ) {
			same( [ $expected['open_graph'][$key] ], $tags[$key] ?? null, 'Same single native ' . $key );
		}
		if ( $kind === 'argument' ) {
			same( [ $expected['open_graph']['article:modified_time'] ],
				$tags['article:modified_time'] ?? null, 'Same article revision date' );
		}
	}

	$samples = [ 'full' => [], 'global-only' => [] ];
	for ( $i = -$warmups; $i < $iterations; $i++ ) {
		foreach ( $i % 2 === 0 ? [ true, false ] : [ false, true ] as $full ) {
			$out = seoBenchmarkOutput( $data, $full );
			$render = seoBenchmarkRender( $out, $wikiSeo, $skin );
			if ( $i >= 0 ) {
				$mode = $full ? 'full' : 'global-only';
				foreach ( [ 'before_us', 'head_us', 'total_us' ] as $phase ) {
					$samples[$mode][$phase][] = $render[$phase];
				}
			}
			unset( $out, $render );
		}
	}
	$statistics = [];
	foreach ( $samples as $mode => $phases ) {
		foreach ( $phases as $phase => $values ) {
			$statistics[$mode][$phase] = seoBenchmarkStats( $values );
		}
	}
	$fullMedian = $statistics['full']['total_us']['median_us'];
	$nativeMedian = $statistics['global-only']['total_us']['median_us'];
	echo 'WD_SEO_BENCHMARK ' . json_encode( [
		'kind' => $kind,
		'php' => PHP_VERSION,
		'mediawiki' => MW_VERSION,
		'iterations_per_mode' => $iterations,
		'statistics' => $statistics,
		'median_saved_us' => round( $fullMedian - $nativeMedian, 3 ),
		'median_reduction_percent' => $fullMedian > 0
			? round( 100 * ( 1 - $nativeMedian / $fullMedian ), 2 ) : null,
	], JSON_THROW_ON_ERROR ) . PHP_EOL;
}

echo "OK: comparable native metadata, actual full versus global-only hook timings\n";
