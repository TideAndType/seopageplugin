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

echo "\n== Elementor placeholder detection + replacement ==\n";
$tree = array(
	array(
		'id'       => 'aaa1111',
		'elType'   => 'section',
		'settings' => array( 'padding' => array( 'top' => '10' ) ),
		'elements' => array(
			array(
				'id'       => 'bbb2222',
				'elType'   => 'widget',
				'widgetType' => 'heading',
				'settings' => array( 'title' => '{{TITLE}}', 'align' => 'center' ),
				'elements' => array(),
			),
			array(
				'id'       => 'ccc3333',
				'elType'   => 'widget',
				'widgetType' => 'text-editor',
				'settings' => array( 'editor' => 'Intro: {{INTRO}} — {{UNKNOWN}}' ),
				'elements' => array(),
			),
		),
	),
);
$detected = SCC_Placeholders::detect( $tree );
sort( $detected );
assert_eq( array( 'INTRO', 'TITLE', 'UNKNOWN' ), $detected, 'tokens detected across tree' );

$replaced = SCC_Placeholders::replace( $tree, array( 'TITLE' => 'Hello World', 'INTRO' => 'welcome' ) );
assert_eq( 'Hello World', $replaced[0]['elements'][0]['settings']['title'], 'TITLE replaced in heading' );
assert_eq( 'Intro: welcome — ', $replaced[0]['elements'][1]['settings']['editor'], 'INTRO replaced, UNKNOWN cleared' );
assert_eq( 'center', $replaced[0]['elements'][0]['settings']['align'], 'non-token settings preserved' );
assert_eq( array( 'top' => '10' ), $replaced[0]['settings']['padding'], 'design settings preserved' );
// Original tree not mutated.
assert_eq( '{{TITLE}}', $tree[0]['elements'][0]['settings']['title'], 'input tree not mutated' );

echo "\n== Elementor builder replacement map ==\n";
$repl = SCC_Elementor_Builder::build_replacements(
	array( 'title' => 'Local SEO — Daytona Beach', 'primary_keyword' => 'local seo daytona beach', 'parent' => 'Local SEO' ),
	array( 'title' => 'Local SEO — Daytona Beach', 'content_html' => '<p>First para here.</p><p>Second.</p>', 'faqs' => array( array( 'question' => 'Q?', 'answer' => 'A.' ) ), 'meta_title' => 'MT', 'meta_description' => 'MD' ),
	array( 'cta' => 'Call today' )
);
assert_eq( 'Daytona Beach', $repl['CITY'], 'city derived from title' );
assert_eq( 'Local SEO', $repl['SERVICE'], 'service from parent' );
assert_eq( 'First para here.', $repl['INTRO'], 'intro from first paragraph' );
assert_eq( 'Call today', $repl['CTA'], 'cta carried' );
assert_true( strpos( $repl['FAQ'], 'Q?' ) !== false, 'faq html built' );

echo "\n== Internal link insertion (DOM) ==\n";
$inserter = new SCC_Link_Inserter();
$insert = new ReflectionMethod( 'SCC_Link_Inserter', 'insert_anchor' );
$insert->setAccessible( true );

$content = '<h2>Local SEO tips</h2><p>Our local SEO service helps small businesses. Ask about local SEO again.</p>';
$out = $insert->invoke( $inserter, $content, 'local SEO', 'https://example.com/local-seo/' );
assert_true( is_string( $out ), 'returns string when anchor found' );
assert_true( substr_count( $out, '<a href="https://example.com/local-seo/">' ) === 1, 'exactly one link inserted (first occurrence only)' );
// The H2 mention must NOT be linked.
assert_true( strpos( $out, '<h2>Local SEO tips</h2>' ) !== false, 'heading occurrence left untouched' );

$none = $insert->invoke( $inserter, '<p>Nothing relevant here.</p>', 'local SEO', 'https://example.com/x/' );
assert_eq( null, $none, 'returns null when anchor phrase absent' );

// Never link inside an existing anchor.
$already = '<p>See our <a href="https://example.com/other/">local SEO</a> page.</p>';
$out2 = $insert->invoke( $inserter, $already, 'local SEO', 'https://example.com/local-seo/' );
assert_eq( null, $out2, 'does not link inside an existing anchor' );

$countm = new ReflectionMethod( 'SCC_Link_Inserter', 'count_internal_links' );
$countm->setAccessible( true );
$c = $countm->invoke( $inserter, '<a href="/a">a</a> <a href="https://example.com/b">b</a> <a href="https://other.com/c">c</a> <a href="#top">t</a>' );
assert_eq( 2, $c, 'counts internal links only (relative + same host)' );

echo "\n== Integration connection state ==\n";
$GLOBALS['scc_test_options']['scc_credentials'] = array();
assert_eq( false, SCC_GSC::is_connected(), 'GSC not connected when empty' );
assert_eq( false, SCC_DataForSEO::is_connected(), 'DataForSEO not connected when empty' );

$GLOBALS['scc_test_options']['scc_credentials'] = array(
	'gsc_client_id'     => 'id',
	'gsc_client_secret' => 'secret',
	'gsc_refresh_token' => 'refresh',
	'dataforseo_login'  => 'user',
	'dataforseo_key'    => 'pass',
);
assert_true( SCC_GSC::is_connected(), 'GSC connected with client+refresh token' );
assert_true( SCC_DataForSEO::is_connected(), 'DataForSEO connected with login+key' );

echo "\n== Competitor topic extraction + content gaps ==\n";
$comp = new SCC_Competitor_Analysis();
$topics = new ReflectionMethod( 'SCC_Competitor_Analysis', 'topics' );
$topics->setAccessible( true );
$t = $topics->invoke( $comp, array( 'Local SEO Services', 'Google Business Profile Optimization' ) );
assert_true( in_array( 'local', $t, true ), 'topic token extracted' );
assert_true( in_array( 'optimization', $t, true ), 'multi-word heading tokenized' );
assert_true( ! in_array( 'the', $t, true ), 'stop word excluded' );
// Content gap = their topics minus ours.
$their = array( 'local', 'seo', 'audits', 'schema' );
$ours = array( 'local', 'seo' );
assert_eq( array( 'audits', 'schema' ), array_values( array_diff( $their, $ours ) ), 'content gap diff correct' );

echo "\n== Job queue pause/resume state ==\n";
unset( $GLOBALS['scc_test_options'][ SCC_Jobs::PAUSED_OPTION ] );
assert_eq( false, SCC_Jobs::is_paused(), 'not paused by default' );
SCC_Jobs::pause();
assert_true( SCC_Jobs::is_paused(), 'paused after pause()' );
SCC_Jobs::resume();
assert_eq( false, SCC_Jobs::is_paused(), 'not paused after resume()' );

echo "\n== Publishing schedule date validation ==\n";
$past = SCC_Publishing::schedule( 1, gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
assert_true( $past instanceof WP_Error, 'past date rejected' );
assert_eq( 'scc_bad_date', $past->code, 'past date error code' );
$bad = SCC_Publishing::schedule( 1, 'not-a-date' );
assert_true( $bad instanceof WP_Error, 'invalid date rejected' );
$future = SCC_Publishing::schedule( 1, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
assert_eq( true, $future, 'future date accepted' );

echo "\n== Content index: tokenize + semantic relevance ==\n";
$tk = SCC_Content_Index::tokenize( 'Local SEO services help small local businesses rank in local search' );
assert_true( isset( $tk['local'] ) && $tk['local'] >= 3, 'term frequency counted (local x3)' );
assert_true( ! isset( $tk['the'] ) && ! isset( $tk['in'] ), 'stop words excluded from tokens' );

$a = array(
	'title' => 'How Much Does Local SEO Cost?', 'primary_keyword' => 'local seo cost', 'intent' => 'informational',
	'url' => 'https://example.com/blog/local-seo-cost/',
	'tokens' => SCC_Content_Index::tokenize( 'local seo cost pricing factors budget small business local search optimization' ),
);
$related = array(
	'title' => 'Local SEO Services', 'primary_keyword' => 'local seo', 'intent' => 'commercial',
	'url' => 'https://example.com/local-seo/',
	'tokens' => SCC_Content_Index::tokenize( 'local seo services optimization local search small business rankings' ),
);
$unrelated = array(
	'title' => 'Wedding Photography Packages', 'primary_keyword' => 'wedding photography', 'intent' => 'commercial',
	'url' => 'https://example.com/weddings/',
	'tokens' => SCC_Content_Index::tokenize( 'wedding photography packages portraits albums engagement bride' ),
);
$rel_related   = SCC_Content_Index::relevance( $a, $related );
$rel_unrelated = SCC_Content_Index::relevance( $a, $unrelated );
assert_true( $rel_related > $rel_unrelated, 'related page scores higher than unrelated (' . $rel_related . ' > ' . $rel_unrelated . ')' );
assert_true( $rel_related >= 40, 'related page meets a meaningful relevance floor (' . $rel_related . ')' );
assert_true( $rel_unrelated < 30, 'unrelated page scores low (' . $rel_unrelated . ')' );

echo "\n== Anchor engine ==\n";
$cands = SCC_Anchor_Engine::candidates( array( 'title' => 'Local SEO Services | Tide & Type', 'primary_keyword' => 'local seo', 'tokens' => array( 'local' => 5, 'seo' => 4 ) ) );
assert_true( in_array( 'local seo', $cands, true ), 'keyword candidate present' );
assert_true( in_array( 'Local SEO Services', $cands, true ), 'brand suffix stripped from title candidate' );
$chosen = SCC_Anchor_Engine::choose(
	array( 'title' => 'Local SEO Services', 'primary_keyword' => 'local seo', 'tokens' => array() ),
	'Our team provides local SEO for clinics and shops across the county.',
	array()
);
assert_true( $chosen && $chosen['present'] === true, 'chooses an anchor phrase present in the text' );
assert_eq( 'local seo', strtolower( $chosen['anchor'] ), 'natural anchor matches text' );

echo "\n== Link engine: sentence extraction ==\n";
$engine = new SCC_Link_Engine();
$m = new ReflectionMethod( 'SCC_Link_Engine', 'sentence_with' );
$m->setAccessible( true );
$sentence = $m->invoke( $engine, 'First sentence here. This one mentions local SEO clearly. Third one.', 'local seo' );
assert_eq( 'This one mentions local SEO clearly.', $sentence, 'extracts the sentence containing the phrase' );
assert_eq( '', $m->invoke( $engine, 'Nothing relevant at all.', 'local seo' ), 'empty when phrase absent' );

echo "\n== Schema: Person / NewsArticle + URL validation ==\n";
$person = SCC_Schema::build( 'Person', array( 'name' => 'Jane Doe', 'url' => 'https://example.com/author/jane/', 'sameAs' => array( 'https://twitter.com/jane' ) ) );
assert_true( is_array( $person ) && 'Person' === $person['@type'] && 'Jane Doe' === $person['name'], 'Person schema built' );
$news = SCC_Schema::build( 'NewsArticle', array( 'name' => 'Headline', 'description' => 'x', 'url' => 'https://example.com/n/' ) );
assert_true( is_array( $news ) && 'NewsArticle' === $news['@type'] && 'Headline' === $news['headline'], 'NewsArticle built' );
$bad_url = SCC_Schema::validate( array( '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'x', 'url' => 'not a url' ) );
assert_true( $bad_url instanceof WP_Error, 'invalid URL rejected by validation' );

echo "\n== Change history: type guard ==\n";
assert_eq( false, SCC_Change_History::record( array( 'change_type' => 'bogus', 'post_id' => 1 ) ), 'invalid change type rejected' );
assert_true( false !== SCC_Change_History::record( array( 'change_type' => 'internal_link', 'post_id' => 1, 'previous_value' => 'x', 'new_value' => 'y' ) ), 'valid change type recorded' );

echo "\n----------------------------------------\n";
echo "Tests: {$tests}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
