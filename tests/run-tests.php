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

echo "\n----------------------------------------\n";
echo "Tests: {$tests}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
