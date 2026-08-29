<?php
/**
 * Tiny dependency-free test runner for SEO Command Center pure logic.
 *
 * Run with: php tests/run-tests.php
 *
 * @package SEO_Command_Center
 */

require_once __DIR__ . '/bootstrap.php';

$tests  = 0;
$failed = 0;

/**
 * Assert equality.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $label    Label.
 */
function assert_eq( $expected, $actual, $label ) {
	global $tests, $failed;
	$tests++;
	if ( $expected === $actual ) {
		echo "  ok  - {$label}\n";
	} else {
		$failed++;
		echo "  FAIL - {$label}\n";
		echo '        expected: ' . var_export( $expected, true ) . "\n";
		echo '        actual:   ' . var_export( $actual, true ) . "\n";
	}
}

/**
 * Assert truthiness.
 *
 * @param mixed  $actual Actual.
 * @param string $label  Label.
 */
function assert_true( $actual, $label ) {
	assert_eq( true, (bool) $actual, $label );
}

echo "== Security sanitizers ==\n";
assert_eq( 5, SCC_Security::sanitize_int( '5', 0, 10 ), 'int within range' );
assert_eq( 10, SCC_Security::sanitize_int( '99', 0, 10 ), 'int clamped to max' );
assert_eq( 0, SCC_Security::sanitize_int( '-4', 0, 10 ), 'int clamped to min' );
assert_eq( true, SCC_Security::sanitize_bool( 'on' ), 'bool on => true' );
assert_eq( false, SCC_Security::sanitize_bool( '0' ), 'bool 0 => false' );
assert_eq( 'sk-abc123DEF', SCC_Security::sanitize_key_value( "  sk-abc123DEF \n" ), 'key trimmed + whitespace stripped' );
assert_eq( '••••6789', SCC_Security::mask( 'secret-123456789' ), 'mask shows last 4' );
assert_eq( '', SCC_Security::mask( '' ), 'mask of empty is empty' );

echo "\n== AI response JSON parsing ==\n";
$r = new SCC_AI_Response();
$r->content = "```json\n{\"a\":1,\"b\":\"x\"}\n```";
assert_eq( array( 'a' => 1, 'b' => 'x' ), $r->json(), 'strips code fences and decodes' );
$r2 = new SCC_AI_Response();
$r2->content = 'not json';
assert_eq( null, $r2->json(), 'invalid json returns null' );
$r3 = new SCC_AI_Response();
assert_eq( false, $r3->is_error(), 'no error by default' );
$r3->error = new WP_Error();
assert_true( $r3->is_error(), 'error detected' );

echo "\n== Crawler HTML parsing ==\n";
$crawler = new SCC_Crawler();
$html = '<html><head><title>Hello</title><meta name="description" content="Desc here">'
	. '<link rel="canonical" href="https://example.com/x">'
	. '<script type="application/ld+json">{"@type":"Article","headline":"h"}</script></head>'
	. '<body><h1>Main</h1><h2>Sub</h2><img src="a.jpg" alt="ok"><img src="b.jpg">'
	. '<a href="/internal">i</a><a href="https://other.com/x">e</a></body></html>';
$parsed = $crawler->parse( $html, 'https://example.com/page' );
assert_eq( 'Hello', $parsed['title'], 'title parsed' );
assert_eq( 'Desc here', $parsed['meta_description'], 'meta description parsed' );
assert_eq( 'https://example.com/x', $parsed['canonical'], 'canonical parsed' );
assert_eq( array( 'Main' ), $parsed['h1'], 'h1 parsed' );
assert_eq( 2, $parsed['images'], 'image count' );
assert_eq( 1, $parsed['images_missing_alt'], 'missing alt count' );
assert_eq( 1, $parsed['internal_links'], 'internal link count' );
assert_eq( 1, $parsed['external_links'], 'external link count' );
assert_true( in_array( 'Article', $parsed['schema_types'], true ), 'schema type extracted' );

echo "\n== Crawler @graph schema extraction ==\n";
$html2 = '<html><head><script type="application/ld+json">'
	. '{"@graph":[{"@type":"Organization"},{"@type":["WebPage","FAQPage"]}]}'
	. '</script></head><body></body></html>';
$parsed2 = $crawler->parse( $html2, 'https://example.com' );
sort( $parsed2['schema_types'] );
assert_eq( array( 'FAQPage', 'Organization', 'WebPage' ), $parsed2['schema_types'], '@graph types flattened' );

echo "\n== SEO plugin label ==\n";
assert_eq( 'Yoast SEO', SCC_SEO_Meta::label( SCC_SEO_Meta::PLUGIN_YOAST ), 'yoast label' );
assert_eq( 'Rank Math', SCC_SEO_Meta::label( SCC_SEO_Meta::PLUGIN_RANKMATH ), 'rankmath label' );
assert_eq( 'All in One SEO', SCC_SEO_Meta::label( SCC_SEO_Meta::PLUGIN_AIOSEO ), 'aioseo label' );

echo "\n== Logger secret redaction ==\n";
$ref = new ReflectionMethod( 'SCC_Logger', 'redact' );
$ref->setAccessible( true );
$out = $ref->invoke( null, array(
	'api_key'  => 'sk-supersecret',
	'nested'   => array( 'openai_key' => 'sk-abc', 'safe' => 'value' ),
	'freeform' => 'here is a Bearer sk-12345678 token',
	'count'    => 5,
) );
assert_eq( '[redacted]', $out['api_key'], 'top-level secret redacted' );
assert_eq( '[redacted]', $out['nested']['openai_key'], 'nested secret redacted' );
assert_eq( 'value', $out['nested']['safe'], 'safe nested value kept' );
assert_true( strpos( $out['freeform'], '[redacted]' ) !== false, 'bearer token redacted in free text' );
assert_eq( 5, $out['count'], 'non-secret scalar kept' );

echo "\n== Cannibalization heuristic ==\n";
$cannibal = new SCC_Cannibalization();
$tok = new ReflectionMethod( 'SCC_Cannibalization', 'tokens' );
$tok->setAccessible( true );
$t1 = $tok->invoke( $cannibal, 'Local SEO Services Company' );
assert_true( in_array( 'local', $t1, true ) && in_array( 'seo', $t1, true ), 'significant tokens kept' );
assert_true( ! in_array( 'services', $t1, true ) && ! in_array( 'company', $t1, true ), 'stop words removed' );

$sim = new ReflectionMethod( 'SCC_Cannibalization', 'similar' );
$sim->setAccessible( true );
assert_true( $sim->invoke( $cannibal, array( 'local', 'seo', 'daytona' ), array( 'local', 'seo', 'daytona', 'beach' ) ), 'high overlap flagged' );
assert_eq( false, $sim->invoke( $cannibal, array( 'web', 'design' ), array( 'plumbing', 'repair' ) ), 'no overlap not flagged' );

echo "\n== Architecture tree building ==\n";
SCC_Analyzer::$latest = null; // No existing pages.
$arch = new SCC_Architecture();
$map = array(
	'clusters' => array(
		array( 'service' => 'Local SEO', 'location' => '', 'primary_keyword' => 'local seo', 'supporting_terms' => array(), 'intent' => 'commercial', 'recommended_url' => '/local-seo/', 'related' => array(), 'page_type' => 'service', 'rationale' => '' ),
		array( 'service' => 'Local SEO', 'location' => 'Daytona Beach', 'primary_keyword' => 'local seo daytona beach', 'supporting_terms' => array(), 'intent' => 'local', 'recommended_url' => '/local-seo/daytona-beach/', 'related' => array(), 'page_type' => 'location', 'rationale' => '' ),
		array( 'service' => 'Local SEO', 'location' => '', 'primary_keyword' => 'how much does local seo cost', 'supporting_terms' => array(), 'intent' => 'informational', 'recommended_url' => '/blog/local-seo-cost/', 'related' => array(), 'page_type' => 'article', 'rationale' => '' ),
	),
	'entities' => array(),
	'notes'    => '',
);
$tree = $arch->build( $map );
assert_eq( 1, count( $tree['pillars'] ), 'one service pillar' );
assert_eq( 'Local SEO', $tree['pillars'][0]['service'], 'pillar service name' );
assert_eq( 1, count( $tree['pillars'][0]['children'] ), 'one location child' );
assert_eq( 1, count( $tree['pillars'][0]['articles'] ), 'one supporting article' );
assert_eq( false, $tree['pillars'][0]['children'][0]['exists'], 'new page not marked existing' );

echo "\n== Architecture marks existing pages ==\n";
SCC_Analyzer::$latest = array( 'items' => array( array( 'url' => 'https://example.com/local-seo/' ) ) );
$tree2 = $arch->build( $map );
assert_true( $tree2['pillars'][0]['exists'], 'existing pillar detected' );
SCC_Analyzer::$latest = null;

echo "\n== Content plan sanitization ==\n";
$clean = SCC_Content_Plan::sanitize( array(
	'title'      => '  Test Page ',
	'status'     => 'bogus',
	'priority'   => 'high',
	'word_count' => '999999999',
	'secondary'  => "a, b\nc",
) );
assert_eq( 'Test Page', $clean['title'], 'title trimmed' );
assert_eq( 'recommended', $clean['status'], 'invalid status falls back' );
assert_eq( 'high', $clean['priority'], 'valid priority kept' );
assert_eq( 20000, $clean['word_count'], 'word count clamped to max' );
assert_eq( array( 'a', 'b', 'c' ), json_decode( $clean['secondary'], true ), 'secondary list parsed' );

echo "\n== Schema build + validation ==\n";
$article = SCC_Schema::build( 'BlogPosting', array( 'name' => 'My Title', 'description' => 'Desc', 'url' => 'https://example.com/p' ) );
assert_true( is_array( $article ) && 'BlogPosting' === $article['@type'], 'BlogPosting built' );
assert_eq( 'My Title', $article['headline'], 'headline set' );
$bad = SCC_Schema::build( 'BlogPosting', array( 'name' => '', 'description' => 'x' ) );
assert_true( $bad instanceof WP_Error, 'article without headline rejected' );
$faq = SCC_Schema::build( 'FAQPage', array( 'faqs' => array( array( 'question' => 'Q?', 'answer' => 'A.' ) ) ) );
assert_true( is_array( $faq ) && ! empty( $faq['mainEntity'] ), 'FAQPage built with entities' );
$faq_empty = SCC_Schema::build( 'FAQPage', array( 'faqs' => array() ) );
assert_true( $faq_empty instanceof WP_Error, 'empty FAQPage rejected' );
$unsupported = SCC_Schema::build( 'HowTo', array( 'name' => 'x' ) );
assert_true( $unsupported instanceof WP_Error, 'unsupported type rejected' );
assert_eq( 'LocalBusiness', SCC_Schema::type_for( 'location' ), 'location -> LocalBusiness' );
assert_eq( 'BlogPosting', SCC_Schema::type_for( 'article' ), 'article -> BlogPosting' );

echo "\n== Quality score ==\n";
$good = SCC_Quality_Score::score( array(
	'html'             => '<h2>One</h2><p>local seo helps</p><h2>Two</h2><p>more</p><h2>Three</h2><p>' . str_repeat( 'word ', 500 ) . '</p>',
	'brief'            => array( 'recommended_words' => 500, 'context' => array( 'primary_keyword' => 'local seo' ), 'entities' => array( 'local seo' ) ),
	'meta_title'       => 'A concise SEO title',
	'meta_description' => str_repeat( 'x', 130 ),
	'faqs'             => array( array( 'question' => 'Q', 'answer' => 'A' ) ),
	'has_schema'       => true,
	'cta'              => 'Contact us',
) );
assert_true( $good['score'] >= 80, 'well-formed content scores high (' . $good['score'] . ')' );
$poor = SCC_Quality_Score::score( array(
	'html'             => '<p>short</p>',
	'brief'            => array( 'recommended_words' => 1500, 'context' => array( 'primary_keyword' => 'missing kw' ), 'entities' => array( 'x' ) ),
	'meta_title'       => '',
	'meta_description' => '',
	'faqs'             => array(),
	'has_schema'       => false,
	'cta'              => '',
) );
assert_true( $poor['score'] < 30, 'thin content scores low (' . $poor['score'] . ')' );

echo "\n----------------------------------------\n";
echo "Tests: {$tests}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
