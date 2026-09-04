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

// Enriched competitor-analysis signals: h3 headings + a body-text excerpt.
$html_rich = '<html><head><title>T</title>'
	. '<script type="application/ld+json">{"@type":"Service"}</script></head>'
	. '<body><nav>Menu Home About</nav><h1>Web Design</h1><h2>Process</h2><h3>Discovery</h3>'
	. '<p>We build fast websites for local businesses.</p>'
	. '<script>var x=1;</script><footer>Copyright</footer></body></html>';
$rich = $crawler->parse( $html_rich, 'https://example.com/c' );
assert_eq( array( 'Discovery' ), $rich['h3'], 'h3 headings captured for competitor comparison' );
assert_true( strpos( $rich['text_excerpt'], 'We build fast websites' ) !== false, 'body content excerpt captured' );
assert_true( strpos( $rich['text_excerpt'], 'var x=1' ) === false, 'scripts stripped from the excerpt' );
assert_true( in_array( 'Service', $rich['schema_types'], true ), 'schema still extracted after excerpt stripping' );

// Internal link URLs are collected (for multi-page competitor crawling).
$html_links = '<html><head><title>T</title></head><body>'
	. '<a href="/services/">Services</a><a href="/about">About</a>'
	. '<a href="https://other.com/x">ext</a><a href="#top">anchor</a>'
	. '<a href="mailto:a@b.com">mail</a><a href="/logo.png">img</a></body></html>';
$lp = $crawler->parse( $html_links, 'https://example.com/' );
assert_true( in_array( 'https://example.com/services/', $lp['internal_link_urls'], true ), 'relative internal link absolutized' );
assert_true( ! in_array( 'https://other.com/x', $lp['internal_link_urls'], true ), 'external link excluded from internal list' );
$has_asset = false; foreach ( $lp['internal_link_urls'] as $u ) { if ( strpos( $u, 'logo.png' ) !== false ) { $has_asset = true; } }
assert_eq( false, $has_asset, 'asset/anchor/mailto links excluded from internal list' );

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

echo "\n== Template engine: default structures + fallback ==\n";
$struct = SCC_Template::default_structure( 'location_service' );
assert_true( ! empty( $struct['sections'] ), 'location_service has sections' );
$labels = array_map( function ( $s ) { return $s['label']; }, $struct['sections'] );
assert_true( in_array( 'FAQ', $labels, true ) && in_array( 'Hero', $labels, true ), 'expected sections present' );
$fallback = SCC_Template::fallback( 'service' );
assert_true( $fallback instanceof SCC_Template && count( $fallback->sections() ) > 0, 'fallback template built' );

echo "\n== Content object: from_generation + variables ==\n";
$entry = array( 'page_type' => 'location_service', 'title' => 'Local SEO — Daytona Beach', 'primary_keyword' => 'daytona beach local seo', 'parent' => 'Local SEO', 'url' => '/local-seo/daytona-beach/' );
$body  = array( 'title' => 'Local SEO — Daytona Beach', 'content_html' => '<p>We help Daytona Beach businesses.</p>', 'faqs' => array( array( 'question' => 'How much?', 'answer' => 'It depends.' ) ), 'meta_title' => 'MT', 'meta_description' => 'MD', 'image' => array( 'alt' => 'alt text' ) );
$co = SCC_Content_Object::from_generation( $entry, $body, array( 'cta' => 'Call us' ) );
assert_eq( 'location_service', $co->content_type, 'content type carried' );
assert_eq( 'Daytona Beach', $co->city, 'city derived from title' );
assert_eq( 'Local SEO', $co->service, 'service from parent' );
$vars = $co->variables();
assert_eq( 'Daytona Beach', $vars['CITY'], 'CITY variable' );
assert_eq( 'Call us', $vars['CTA'], 'CTA variable' );
assert_true( strpos( $vars['FAQ'], 'How much?' ) !== false, 'FAQ variable built' );

echo "\n== WordPress renderer ==\n";
$wp = new SCC_WordPress_Renderer();
$out = $wp->render( $co, SCC_Template::fallback( 'location_service' ) );
assert_true( is_array( $out ) && ! empty( $out['post_content'] ), 'renders post_content' );
assert_true( strpos( $out['post_content'], '{{' ) === false, 'no unreplaced placeholders' );
assert_true( strpos( $out['post_content'], 'Daytona Beach' ) !== false, 'content includes populated data' );
assert_true( strpos( $out['post_content'], '<h1' ) === false, 'no in-body H1 (theme renders title)' );
assert_eq( 'daytona-beach', $out['post_name'], 'slug from url last segment' );

echo "\n== Gutenberg renderer ==\n";
$gb = new SCC_Gutenberg_Renderer();
$gout = $gb->render( $co, SCC_Template::fallback( 'location_service' ) );
assert_true( strpos( $gout['post_content'], '<!-- wp:' ) !== false, 'produces block markup' );
$blocks = SCC_Gutenberg_Renderer::html_to_blocks( '<h2>Title</h2><p>Body text</p><ul><li>a</li></ul>' );
assert_true( strpos( $blocks, '<!-- wp:heading' ) !== false, 'heading block' );
assert_true( strpos( $blocks, '<!-- wp:paragraph' ) !== false, 'paragraph block' );
assert_true( strpos( $blocks, '<!-- wp:list' ) !== false, 'list block' );

echo "\n== Renderer manager: fallback when Elementor unavailable ==\n";
SCC_Elementor::$active = false;
$manager = new SCC_Renderer_Manager();
$picked = $manager->pick( 'elementor', 'service' );
assert_true( in_array( $picked->get_id(), array( 'gutenberg', 'wordpress' ), true ), 'falls back from unavailable elementor (' . $picked->get_id() . ')' );
assert_eq( 'gutenberg', $manager->pick( 'gutenberg', 'service' )->get_id(), 'honors available preference' );
$el = $manager->get( 'elementor' );
assert_eq( false, $el->is_available( 'service' ), 'elementor renderer not available without Elementor' );

echo "\n== Template selector: pinned renderer + fallback ==\n";
$pinned = new SCC_Template( array( 'renderer' => 'wordpress', 'structure' => array( 'sections' => array() ) ) );
assert_eq( 'wordpress', SCC_Template_Selector::renderer_for( 'service', $pinned ), 'template pins renderer' );
assert_eq( 'gutenberg', SCC_Template_Selector::renderer_for( 'service', SCC_Template::fallback( 'service' ) ), 'default renderer when unpinned' );

echo "\n== Original template never modified during render ==\n";
$tpl = SCC_Template::fallback( 'service' );
$before = wp_json_encode( $tpl->sections() );
$wp->render( $co, $tpl );
$gb->render( $co, $tpl );
assert_eq( $before, wp_json_encode( $tpl->sections() ), 'template structure unchanged after rendering' );

echo "\n== Gemini provider ==\n";
$gem = new SCC_Gemini_Provider();
assert_eq( 'gemini', $gem->get_id(), 'gemini id' );
$GLOBALS['scc_test_options']['scc_credentials'] = array();
assert_eq( false, $gem->is_configured(), 'not configured without key' );
// With no key, list_models returns the curated list without any network call.
assert_true( array_key_exists( 'gemini-flash-latest', $gem->list_models() ), 'lists the latest flash alias' );
assert_true( $gem->estimate_cost( 1000000, 1000000, 'gemini-flash-latest' ) > 0, 'cost estimate positive' );
assert_true( abs( $gem->estimate_cost( 1000000, 0, 'gemini-pro-latest' ) - 1.25 ) < 0.001, 'pro cost via name heuristic' );
// Retired model ids self-heal to a working alias.
assert_eq( 'gemini-flash-latest', SCC_Gemini_Provider::resolve_model( 'gemini-2.5-flash' ), 'retired 2.5-flash -> latest' );
assert_eq( 'gemini-pro-latest', SCC_Gemini_Provider::resolve_model( 'gemini-1.5-pro' ), 'retired 1.5-pro -> pro latest' );
assert_eq( 'gemini-flash-latest', SCC_Gemini_Provider::resolve_model( '' ), 'empty -> latest' );
assert_eq( 'gemini-3.6-flash', SCC_Gemini_Provider::resolve_model( 'gemini-3.6-flash' ), 'current model left as-is' );
$GLOBALS['scc_test_options']['scc_credentials'] = array( 'gemini_key' => 'AIzaTESTKEY' );
assert_true( $gem->is_configured(), 'configured with key' );
$gnorm = new ReflectionMethod( 'SCC_Gemini_Provider', 'normalize_messages' );
$gnorm->setAccessible( true );
$gmsgs = $gnorm->invoke( $gem, array( 'messages' => array( array( 'role' => 'user', 'content' => 'hi' ), array( 'role' => 'assistant', 'content' => 'yo' ) ) ) );
assert_eq( 'user', $gmsgs[0]['role'], 'user role kept' );
assert_eq( 'model', $gmsgs[1]['role'], 'assistant mapped to model role' );
assert_eq( 'hi', $gmsgs[0]['parts'][0]['text'], 'gemini parts text shape' );

echo "\n== LM Studio provider ==\n";
$lm = new SCC_LMStudio_Provider();
assert_eq( 'lmstudio', $lm->get_id(), 'lmstudio id' );
assert_true( $lm->is_configured(), 'always configured (local, key optional)' );
assert_eq( 0.0, $lm->estimate_cost( 999999, 999999, 'local-model' ), 'local inference is free' );
$lbase = new ReflectionMethod( 'SCC_LMStudio_Provider', 'base_url' );
$lbase->setAccessible( true );
assert_eq( 'http://localhost:1234/v1', $lbase->invoke( $lm ), 'default base url, trailing slash trimmed' );

echo "\n== GSC field-status diagnostics ==\n";
$GLOBALS['scc_test_options']['scc_credentials'] = array( 'gsc_client_id' => 'x' );
$fs = SCC_GSC::field_status();
assert_true( $fs['client_id'] === true, 'client_id detected' );
assert_true( $fs['client_secret'] === false && $fs['refresh_token'] === false, 'missing fields flagged' );
assert_eq( false, SCC_GSC::is_connected(), 'not connected with only client_id' );
$GLOBALS['scc_test_options']['scc_credentials'] = array( 'gsc_client_id' => 'a', 'gsc_client_secret' => 'b', 'gsc_refresh_token' => 'c' );
$fs2 = SCC_GSC::field_status();
assert_true( $fs2['client_id'] && $fs2['client_secret'] && $fs2['refresh_token'], 'all fields present' );
assert_true( SCC_GSC::is_connected(), 'connected with all three fields' );

echo "\n== LM Studio error extraction ==\n";
$lm2  = new SCC_LMStudio_Provider();
$eerr = new ReflectionMethod( 'SCC_LMStudio_Provider', 'extract_error' );
$eerr->setAccessible( true );
assert_eq( 'HTTP 400 — bad model', $eerr->invoke( $lm2, array( 'error' => array( 'message' => 'bad model' ) ), '', 400 ), 'object error.message' );
assert_eq( 'HTTP 400 — plain string err', $eerr->invoke( $lm2, array( 'error' => 'plain string err' ), '', 400 ), 'string error' );
assert_eq( 'HTTP 400 — raw body here', $eerr->invoke( $lm2, null, 'raw body here', 400 ), 'raw-body fallback' );
assert_eq( 'HTTP 400', $eerr->invoke( $lm2, null, '', 400 ), 'no detail fallback' );

echo "\n== AI Manager: safety-net fallback to a configured provider ==\n";
if ( ! class_exists( 'SCC_AI_Usage' ) ) {
	class SCC_AI_Usage {
		public static function record( $response, $operation = '' ) {}
		public static function month_to_date_cost() { return 0.0; }
	}
}
require_once __DIR__ . '/../seo-command-center/includes/ai/class-scc-ai-manager.php';

/**
 * Fake provider for manager routing tests. `$ok` decides success vs error.
 */
class SCC_Fake_Provider implements SCC_AI_Provider_Interface {
	public $id;
	public $ok;
	public $configured;
	public function __construct( $id, $ok = true, $configured = true ) {
		$this->id = $id; $this->ok = $ok; $this->configured = $configured;
	}
	public function get_id() { return $this->id; }
	public function get_label() { return $this->id; }
	public function is_configured() { return $this->configured; }
	public function list_models() { return array( 'm' => 'm' ); }
	public function complete( array $request ) {
		$r = new SCC_AI_Response();
		if ( $this->ok ) {
			$r->content = 'from:' . $this->id;
		} else {
			$r->error = new WP_Error( 'auth', 'invalid x-api-key' );
		}
		return $r;
	}
	public function estimate_cost( $input_tokens, $output_tokens, $model ) { return 0.0; }
}

/** Manager subclass that registers only fakes (no real HTTP providers). */
class SCC_Test_Manager extends SCC_AI_Manager {
	public function __construct( array $providers ) {
		foreach ( $providers as $p ) { $this->register( $p ); }
	}
}

// Primary (claude) has a bad key; LM Studio is configured → manager should
// fall through to LM Studio automatically instead of returning the auth error.
$GLOBALS['scc_test_options']['scc_settings'] = array( 'default_provider' => 'claude', 'fallback_provider' => '' );
$mgr = new SCC_Test_Manager( array(
	new SCC_Fake_Provider( 'claude', false, true ),
	new SCC_Fake_Provider( 'lmstudio', true, true ),
) );
$resp = $mgr->complete( array( 'messages' => array() ), 'keyword-strategy' );
assert_true( ! $resp->is_error(), 'falls back to a configured provider when primary key is bad' );
assert_eq( 'from:lmstudio', $resp->content, 'used LM Studio as the safety-net provider' );

// When nothing succeeds, the reported error is the primary provider's, with guidance.
$mgr2 = new SCC_Test_Manager( array(
	new SCC_Fake_Provider( 'claude', false, true ),
	new SCC_Fake_Provider( 'lmstudio', false, true ),
) );
$resp2 = $mgr2->complete( array( 'messages' => array() ), 'keyword-strategy' );
assert_true( $resp2->is_error(), 'error when every provider fails' );
assert_true( false !== strpos( $resp2->error->get_error_message(), 'Primary provider' ) || false !== strpos( $resp2->error->get_error_message(), 'Primary' ), 'error message points user to Settings → AI primary provider' );

// A per-task route override is honored as the primary.
$GLOBALS['scc_test_options']['scc_settings'] = array(
	'default_provider' => 'claude', 'fallback_provider' => '',
	'route_keyword_strategy_provider' => 'lmstudio',
);
$mgr3 = new SCC_Test_Manager( array(
	new SCC_Fake_Provider( 'claude', true, true ),
	new SCC_Fake_Provider( 'lmstudio', true, true ),
) );
$resp3 = $mgr3->complete( array( 'messages' => array() ), 'keyword-strategy' );
assert_eq( 'from:lmstudio', $resp3->content, 'route override sends keyword-strategy to LM Studio' );

echo "\n== Keyword strategy: mirror the real site ==\n";
require_once __DIR__ . '/../seo-command-center/includes/strategy/class-scc-keyword-strategy.php';

class SCC_KS_Test extends SCC_Keyword_Strategy {
	public static $pages = array();
	public static function existing_site_pages( $limit = 200 ) { return self::$pages; }
	public function reconcile_public( array $map ) { return $this->reconcile_with_site( $map ); }
	public function guess_public( $path ) { return $this->guess_page_type( $path ); }
}

$ks = new SCC_KS_Test( new SCC_Test_Manager( array() ) );

// Real site has two pages; the AI map only mentioned one (with a wrong slug)
// plus one genuine new idea. After reconcile: both real pages present as
// existing (anchored to real URLs), and the new idea kept as a gap.
SCC_KS_Test::$pages = array(
	array( 'title' => 'SEO Audit', 'path' => '/seo-audit/' ),
	array( 'title' => 'About Us', 'path' => '/about/' ),
);
$raw = array(
	'clusters' => array(
		array( 'service' => 'SEO Audit', 'location' => '', 'primary_keyword' => 'seo audit',
			'supporting_terms' => array(), 'intent' => 'commercial', 'recommended_url' => '/seo-audit/',
			'related' => array(), 'page_type' => 'service', 'status' => 'new', 'rationale' => '' ),
		array( 'service' => 'Pricing Guide', 'location' => '', 'primary_keyword' => 'pricing',
			'supporting_terms' => array(), 'intent' => 'commercial', 'recommended_url' => '/pricing/',
			'related' => array(), 'page_type' => 'service', 'status' => 'new', 'rationale' => '' ),
	),
	'entities' => array(),
	'notes'    => '',
);
$rec = $ks->reconcile_public( $raw );
$by_url = array();
foreach ( $rec['clusters'] as $c ) { $by_url[ $c['recommended_url'] ] = $c['status']; }
assert_eq( 'existing', $by_url['/seo-audit/'], 'matched page flagged existing' );
assert_eq( 'existing', $by_url['/about/'], 'missing real page injected as existing' );
assert_eq( 'new', $by_url['/pricing/'], 'genuine gap kept as new' );
assert_eq( 2, $rec['existing_count'], 'existing_count reflects real pages' );
assert_eq( 1, $rec['new_count'], 'new_count reflects gaps' );
assert_eq( 'existing', $rec['clusters'][0]['status'], 'existing pages sorted first' );

// No real pages (brand-new site) → map returned unchanged.
SCC_KS_Test::$pages = array();
$rec2 = $ks->reconcile_public( $raw );
assert_eq( 2, count( $rec2['clusters'] ), 'no injection when site has no pages' );

assert_eq( 'pillar', $ks->guess_public( '/' ), 'home is a pillar' );
assert_eq( 'service', $ks->guess_public( '/services/' ), 'top-level is a service' );
assert_eq( 'article', $ks->guess_public( '/blog/my-post/' ), 'deep path is an article' );

// Subtopics: existing/new flagging and topic counts span pillars + subtopics.
SCC_KS_Test::$pages = array(
	array( 'title' => 'Web Design', 'path' => '/web-design/' ),
	array( 'title' => 'Pricing', 'path' => '/web-design/pricing/' ),
);
$withSubs = array(
	'clusters' => array(
		array(
			'service' => 'Web Design', 'location' => '', 'primary_keyword' => 'web design',
			'supporting_terms' => array(), 'intent' => 'commercial', 'recommended_url' => '/web-design/',
			'related' => array(), 'page_type' => 'service', 'status' => 'new', 'rationale' => '',
			'subtopics' => array(
				array( 'title' => 'Pricing', 'primary_keyword' => 'web design pricing', 'intent' => 'commercial', 'recommended_url' => '/web-design/pricing/', 'status' => 'new' ),
				array( 'title' => 'Portfolio', 'primary_keyword' => 'web design portfolio', 'intent' => 'informational', 'recommended_url' => '/web-design/portfolio/', 'status' => 'new' ),
			),
		),
	),
	'entities' => array(), 'notes' => '',
);
$recSubs = $ks->reconcile_public( $withSubs );
$pillar  = $recSubs['clusters'][0];
assert_eq( 'existing', $pillar['status'], 'pillar matched to real page is existing' );
$subByUrl = array();
foreach ( $pillar['subtopics'] as $s ) { $subByUrl[ $s['recommended_url'] ] = $s['status']; }
assert_eq( 'existing', $subByUrl['/web-design/pricing/'], 'subtopic matching a real page is existing' );
assert_eq( 'new', $subByUrl['/web-design/portfolio/'], 'subtopic with no real page is new' );
assert_eq( 2, $recSubs['existing_count'], 'existing count includes pillar + subtopic' );
assert_eq( 1, $recSubs['new_count'], 'new count includes the gap subtopic' );

// Generation settings sanitize with safe defaults + whitelisting.
$def = SCC_Keyword_Strategy::sanitize_inputs( array( 'business_name' => 'X' ) );
assert_eq( 'seo', $def['map_type'], 'map_type defaults to seo' );
assert_eq( 'standard', $def['depth'], 'depth defaults to standard' );
$chosen = SCC_Keyword_Strategy::sanitize_inputs( array( 'business_name' => 'X', 'map_type' => 'question', 'depth' => 'deep', 'language' => 'Spanish' ) );
assert_eq( 'question', $chosen['map_type'], 'valid map_type kept' );
assert_eq( 'deep', $chosen['depth'], 'valid depth kept' );
assert_eq( 'Spanish', $chosen['language'], 'language kept' );
$bad = SCC_Keyword_Strategy::sanitize_inputs( array( 'business_name' => 'X', 'map_type' => 'hacker', 'depth' => 'ludicrous' ) );
assert_eq( 'seo', $bad['map_type'], 'invalid map_type falls back to seo' );
assert_eq( 'standard', $bad['depth'], 'invalid depth falls back to standard' );

echo "\n== Background worker auth ==\n";
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $len = 12, $special = true, $extra = true ) {
		return substr( str_repeat( 'abcdef0123456789', 8 ), 0, (int) $len );
	}
}
$GLOBALS['scc_test_options']['scc_worker_secret'] = '';
$secret1 = SCC_Jobs::worker_secret();
$secret2 = SCC_Jobs::worker_secret();
assert_true( '' !== $secret1, 'a worker secret is generated' );
assert_eq( $secret1, $secret2, 'worker secret is stable across calls' );
$jobs = new SCC_Jobs( new SCC_Test_Manager( array() ) );
assert_eq( false, $jobs->run_authenticated( 'wrong-secret' ), 'worker rejects a bad secret' );

echo "\n== Tolerant JSON parsing (small local models) ==\n";
$mk = function ( $content ) {
	$r = new SCC_AI_Response();
	$r->content = $content;
	return $r;
};
$clean = $mk( '{"clusters":[{"service":"x"}],"notes":"hi"}' )->json();
assert_true( is_array( $clean ) && 'hi' === $clean['notes'], 'clean JSON decodes' );
$fenced = $mk( "```json\n{\"a\":1}\n```" )->json();
assert_true( is_array( $fenced ) && 1 === $fenced['a'], 'fenced JSON decodes' );
$prose = $mk( "Sure! Here is your map:\n{\"a\":1}\nHope this helps." )->json();
assert_true( is_array( $prose ) && 1 === $prose['a'], 'prose-wrapped JSON is extracted' );
$trailing = $mk( '{"a":1,"b":[1,2,],}' )->json();
assert_true( is_array( $trailing ) && 1 === $trailing['a'], 'trailing commas are repaired' );
$truncated = $mk( '{"clusters":[{"service":"Web Design","primary_keyword":"web design"' )->json();
assert_true( is_array( $truncated ) && isset( $truncated['clusters'][0]['service'] ), 'truncated JSON is closed and salvaged' );
$smart = $mk( '{“a”:“b”}' )->json();
assert_true( is_array( $smart ) && 'b' === $smart['a'], 'smart quotes are normalized' );
$garbage = $mk( 'I could not build a map.' )->json();
assert_eq( null, $garbage, 'non-JSON returns null' );

echo "\n== Topical Authority scorecard ==\n";
require_once __DIR__ . '/../seo-command-center/includes/strategy/class-scc-topical-authority.php';

$ta_map = array( 'clusters' => array(
	array(
		'service' => 'Local SEO', 'primary_keyword' => 'local seo', 'supporting_terms' => array( 'a', 'b' ),
		'intent' => 'commercial', 'recommended_url' => '/local-seo/', 'priority' => 'high', 'status' => 'existing',
		'subtopics' => array(
			array( 'title' => 'GBP', 'primary_keyword' => 'google business profile', 'intent' => 'commercial', 'recommended_url' => '/gbp/', 'status' => 'existing' ),
			array( 'title' => 'Citations', 'primary_keyword' => 'local citations', 'intent' => 'commercial', 'recommended_url' => '/citations/', 'status' => 'new' ),
		),
	),
	array(
		'service' => 'Content Marketing', 'primary_keyword' => 'content marketing', 'supporting_terms' => array(),
		'intent' => 'informational', 'recommended_url' => '/content/', 'priority' => 'medium', 'status' => 'new',
		'subtopics' => array(
			array( 'title' => 'Blogging', 'primary_keyword' => 'blogging', 'intent' => 'informational', 'recommended_url' => '/blog/', 'status' => 'new' ),
		),
	),
) );
$ta_signals = array( 'depth_analyzed' => 10, 'depth_thin' => 2, 'link_pages' => 10, 'link_orphans' => 2, 'cannibalization' => 3, 'link_opportunities' => 7 );
$ta = SCC_Topical_Authority::compute( $ta_map, $ta_signals, array() );

assert_eq( 5, $ta['totals']['topics'], 'topics = pillars + subtopics' );
assert_eq( 2, $ta['totals']['existing_topics'], 'existing topics counted' );
assert_eq( 3, $ta['totals']['missing_topics'], 'missing topics counted' );
assert_eq( 3, $ta['totals']['cannibalization'], 'cannibalization passed through' );
assert_eq( 7, $ta['totals']['link_opportunities'], 'link opportunities passed through' );
// Cluster statuses.
$byname = array();
foreach ( $ta['clusters'] as $c ) { $byname[ $c['name'] ] = $c; }
assert_eq( 'attention', $byname['Local SEO']['status'], 'existing pillar with a gap subtopic needs attention' );
assert_eq( 'missing', $byname['Content Marketing']['status'], 'new pillar is missing' );
// Opportunities: Citations(new), Content Marketing(new pillar), Blogging(new) = 3.
assert_eq( 3, $ta['opportunities']['high'] + $ta['opportunities']['medium'] + $ta['opportunities']['low'], 'three new opportunities' );
assert_true( $ta['score'] >= 0 && $ta['score'] <= 100, 'score in range' );
// Component percentages are deterministic.
$pcts = array();
foreach ( $ta['components'] as $c ) { $pcts[ $c['key'] ] = $c['pct']; }
assert_eq( 40, $pcts['topic'], 'topic coverage 2/5 = 40%' );
assert_eq( 33, $pcts['supporting'], 'supporting 1/3 = 33%' );

// Unknown components (no analysis / no link graph) are excluded, not zeroed.
$ta2 = SCC_Topical_Authority::compute( $ta_map, array(), array() );
$known = array();
foreach ( $ta2['components'] as $c ) { $known[ $c['key'] ] = $c['known']; }
assert_eq( false, $known['depth'], 'depth unknown without analysis' );
assert_eq( false, $known['links'], 'links unknown without a graph' );
assert_true( $ta2['score'] > 0, 'score still computed from known components' );

echo "\n== Competitor gap map guards ==\n";
$comp = new SCC_Competitor_Analysis();
$err_no_urls = $comp->gap_map( array( '', '   ' ) );
assert_true( is_wp_error( $err_no_urls ), 'gap_map with no usable URLs errors' );
assert_eq( 'scc_no_urls', $err_no_urls->get_error_code(), 'no-URLs error code' );
$err_no_ai = $comp->gap_map( array( 'https://example.com/' ) );
assert_true( is_wp_error( $err_no_ai ), 'gap_map without an AI manager errors' );
assert_eq( 'scc_no_ai', $err_no_ai->get_error_code(), 'no-AI error code' );

echo "\n== Internal link insertion into Elementor widgets ==\n";
$inserter = new SCC_Link_Inserter();
$ref = new ReflectionMethod( 'SCC_Link_Inserter', 'walk_elementor' );
$ref->setAccessible( true );
$tree = array(
	array(
		'elType'   => 'section',
		'elements' => array(
			array(
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => '<p>We offer local SEO services to small businesses.</p>' ),
			),
		),
	),
);
$done = false;
$out  = $ref->invokeArgs( $inserter, array( $tree, 'local SEO services', 'https://example.com/local-seo/', &$done ) );
assert_true( $done, 'anchor found and linked in Elementor editor field' );
$edited = $out[0]['elements'][0]['settings']['editor'];
assert_true( false !== strpos( $edited, '<a href="https://example.com/local-seo/">local SEO services</a>' ), 'editor field now contains the real hyperlink' );

// A phrase that is not present leaves the tree untouched.
$done2 = false;
$ref->invokeArgs( $inserter, array( $tree, 'nonexistent phrase', 'https://example.com/x/', &$done2 ) );
assert_eq( false, $done2, 'missing anchor does not force a link' );

echo "\n== Template Variables 2.0 registry ==\n";
$reg = SCC_Template_Variables::registry();
assert_true( isset( $reg['H1'] ) && isset( $reg['PRIMARY_KEYWORD'] ) && isset( $reg['FAQ_SCHEMA'] ), 'registry has core + schema tokens' );
assert_eq( true, $reg['H1']['required'], 'H1 is required' );
assert_eq( true, $reg['CTA_URL']['url'], 'CTA_URL is a URL type' );
assert_eq( true, $reg['SCHEMA']['schema'], 'SCHEMA is schema type' );
assert_eq( false, $reg['SCHEMA']['safe_for_text'], 'schema is not safe for visible text' );

// Type-aware escaping.
assert_eq( 'a &amp; b', SCC_Template_Variables::escape_value( 'H1', 'a & b' ), 'text token is html-escaped' );
assert_eq( '', SCC_Template_Variables::escape_value( 'SCHEMA', '{"@type":"x"}' ), 'schema token never renders as text' );
assert_eq( 'one, two', SCC_Template_Variables::escape_value( 'SECONDARY_KEYWORDS', array( 'one', 'two' ) ), 'list token joins + escapes' );
$html_out = SCC_Template_Variables::escape_value( 'CONTENT', '<p>hi</p><script>alert(1)</script>' );
assert_true( false !== strpos( $html_out, '<p>hi</p>' ) && false === strpos( $html_out, '<script' ), 'html token keeps markup but drops script' );

// Custom value sanitizer.
assert_eq( 'safe', SCC_Template_Variables::sanitize_custom_value( 'safe[shortcode]' ), 'custom value strips shortcodes' );
assert_eq( 'x', SCC_Template_Variables::sanitize_custom_value( 'x<script>bad()</script>' ), 'custom value strips scripts' );

// Resolve + render_map from a content object.
$co = new SCC_Content_Object();
$co->title = 'Local SEO Services';
$co->h1 = 'Local SEO Services in Daytona Beach';
$co->content = '<p>Intro paragraph.</p><h2>How it works</h2>';
$co->primary_keyword = 'local seo services';
$co->secondary_keywords = array( 'seo audit', 'gmb' );
$co->faq = array( array( 'question' => 'How much?', 'answer' => 'It depends.' ) );
$map = SCC_Template_Variables::render_map( $co, array( 'business' => array( 'phone' => '555-1234', 'organization_name' => 'Tide & Type' ) ), array( 'H1', 'CONTENT', 'CUSTOM_THING' ) );
assert_eq( 'Local SEO Services in Daytona Beach', $map['H1'], 'H1 resolved from content object' );
assert_true( false !== strpos( $map['FAQ'], 'How much?' ), 'FAQ resolved as accordion HTML' );
assert_eq( '555-1234', $map['PHONE'], 'PHONE resolved from verified business data' );
assert_eq( '', $map['CUSTOM_THING'], 'unknown custom token resolves to empty (never leaks the raw token)' );
assert_eq( '', $map['SCHEMA'], 'schema token empty in the visible map' );

// Validation.
$tree_ok = array( array( 'elType' => 'widget', 'settings' => array( 'title' => '{{H1}}', 'editor' => '{{CONTENT}}' ) ) );
$v_ok = SCC_Template_Variables::validate_template( $tree_ok );
assert_eq( 'ready', $v_ok['status'], 'template with H1 + content validates ready' );
$tree_bad = array( array( 'elType' => 'widget', 'settings' => array( 'editor' => '{{INTRO}} {{SCHEMA}} {{FOO}}' ) ) );
$v_bad = SCC_Template_Variables::validate_template( $tree_bad );
assert_eq( 'attention', $v_bad['status'], 'template missing H1 needs attention' );
assert_true( ! empty( $v_bad['warnings'] ), 'schema-in-widget / custom token produce warnings' );

// Backward compatibility: variables() still returns the legacy keys.
$legacy = $co->variables();
assert_true( isset( $legacy['TITLE'] ) && isset( $legacy['H1'] ) && isset( $legacy['CONTENT'] ) && isset( $legacy['DATE_PUBLISHED'] ), 'variables() keeps legacy keys' );

echo "\n== AI-assisted internal linking (merge safety) ==\n";
$det_recs = array(
	array( 'target_post_id' => 10, 'target_title' => 'SEO Audit', 'target_url' => '/seo-audit/', 'anchor' => 'seo audit', 'natural' => true, 'sentence' => '', 'confidence' => 60, 'reason' => 'det' ),
	array( 'target_post_id' => 20, 'target_title' => 'Local SEO', 'target_url' => '/local-seo/', 'anchor' => 'local seo', 'natural' => true, 'sentence' => '', 'confidence' => 58, 'reason' => 'det' ),
);
$page_text = 'We provide a full local SEO service and a detailed technical review for small businesses.';
$ai_links = array(
	array( 'id' => 20, 'anchor' => 'local SEO service', 'confidence' => 92, 'reason' => 'Great match' ), // valid, anchor present.
	array( 'id' => 10, 'anchor' => 'keyword research', 'confidence' => 80, 'reason' => 'Nope' ),          // anchor NOT in page.
	array( 'id' => 99, 'anchor' => 'invented', 'confidence' => 99, 'reason' => 'Invented' ),              // id not a candidate.
);
$merged = SCC_Link_Engine::merge_ai_links( $det_recs, $ai_links, $page_text );
$m_by = array();
foreach ( $merged as $r ) { $m_by[ $r['target_post_id'] ] = $r; }
assert_eq( 2, count( $merged ), 'invented target id is never added' );
assert_eq( 'local SEO service', $m_by[20]['anchor'], 'AI anchor accepted when it appears verbatim in the page' );
assert_eq( 92, $m_by[20]['confidence'], 'AI confidence applied' );
assert_eq( '/local-seo/', $m_by[20]['target_url'], 'verified target URL is preserved, never AI-invented' );
assert_eq( 'seo audit', $m_by[10]['anchor'], 'AI anchor rejected when absent — deterministic anchor kept' );
assert_true( $m_by[10]['confidence'] <= 92, 'endorsed-but-unverified anchor still merges confidence' );

echo "\n== Opportunity Engine (scoring + explainability + confidence) ==\n";
$signals = array(
	'gsc' => array(
		'connected'  => true,
		'quick_wins' => array( array( 'query' => 'web design company', 'impressions' => 4821, 'clicks' => 20, 'position' => 8.7 ) ),
		'untapped'   => array( array( 'query' => 'ecommerce web design', 'impressions' => 900, 'position' => 24 ) ),
	),
	'topical'  => array( 'has_map' => true, 'items' => array( array( 'title' => 'Local SEO Audits', 'pillar' => 'Local SEO', 'intent' => 'commercial', 'priority' => 'high', 'url' => '/local-seo-audits/' ) ) ),
	'cannibal' => array( array( 'keyword' => 'local seo', 'pages' => array( array( 'post_id' => 1, 'title' => 'A', 'url' => '/a/' ), array( 'post_id' => 2, 'title' => 'B', 'url' => '/b/' ) ) ) ),
	'orphans'  => array( array( 'post_id' => 9, 'title' => 'Orphan Page', 'url' => '/orphan/' ) ),
	'thin'     => array( array( 'post_id' => 5, 'title' => 'Thin', 'url' => '/thin/', 'word_count' => 120 ) ),
	'missing_meta' => array( array( 'post_id' => 6, 'title' => 'No Meta', 'url' => '/no-meta/' ) ),
);
$opps = SCC_Opportunity_Engine::compute( $signals );
assert_true( count( $opps ) >= 6, 'opportunities produced from every signal source' );

// Find the striking-distance opportunity.
$sd = null; foreach ( $opps as $o ) { if ( 'striking_distance' === $o['type'] ) { $sd = $o; break; } }
assert_true( is_array( $sd ), 'striking-distance opportunity exists' );
assert_eq( 'verified', $sd['data_confidence'], 'GSC-derived opportunity is verified data' );
$sum = 0; foreach ( $sd['factors'] as $f ) { $sum += (int) $f['points']; }
assert_eq( min( 100, $sum ), $sd['score'], 'score equals the clamped sum of factor points (transparent)' );
assert_true( $sd['confidence'] >= 80, 'verified data yields high confidence' );
assert_true( strpos( $sd['reason'], '4,821' ) !== false || strpos( $sd['reason'], '4821' ) !== false, 'reason cites the real impression figure, not a fabricated one' );

// Sorted by score desc.
$sorted = true; for ( $i = 1; $i < count( $opps ); $i++ ) { if ( $opps[ $i ]['score'] > $opps[ $i - 1 ]['score'] ) { $sorted = false; break; } }
assert_true( $sorted, 'opportunities are ranked by score descending' );

// Without GSC, topical gaps are "estimated" (never presented as measured).
$no_gsc = SCC_Opportunity_Engine::compute( array(
	'gsc'     => array( 'connected' => false, 'quick_wins' => array(), 'untapped' => array() ),
	'topical' => array( 'has_map' => true, 'items' => array( array( 'title' => 'X', 'pillar' => 'P', 'intent' => 'informational', 'priority' => 'medium' ) ) ),
) );
$mt = null; foreach ( $no_gsc as $o ) { if ( 'missing_topic' === $o['type'] ) { $mt = $o; break; } }
assert_true( is_array( $mt ), 'missing-topic opportunity exists without GSC' );
assert_eq( 'estimated', $mt['data_confidence'], 'AI topical gap is estimated when no GSC data backs it' );

// Empty site → no opportunities, no errors, no fabricated data.
assert_eq( 0, count( SCC_Opportunity_Engine::compute( array() ) ), 'empty signals produce zero opportunities (never fabricated)' );

// Every opportunity has a stable id.
$ids = array(); foreach ( $opps as $o ) { $ids[ $o['id'] ] = true; }
assert_eq( count( $opps ), count( $ids ), 'each opportunity has a unique, stable id' );

echo "\n== Content Decay Engine (confidence-thresholded) ==\n";
$decay_pages = array(
	// Real decay: strong baseline, clicks down 50%.
	array( 'url' => '/local-seo/', 'post_id' => 3, 'title' => 'Local SEO', 'prev_clicks' => 200, 'curr_clicks' => 100, 'prev_impr' => 8000, 'curr_impr' => 5000, 'prev_pos' => 6.0, 'curr_pos' => 11.0, 'age_months' => 14 ),
	// Noise: tiny baseline, big % swing — must NOT be flagged.
	array( 'url' => '/tiny/', 'post_id' => 4, 'title' => 'Tiny', 'prev_clicks' => 3, 'curr_clicks' => 1, 'prev_impr' => 40, 'curr_impr' => 10, 'prev_pos' => 5, 'curr_pos' => 30 ),
	// Stable page: no meaningful change — not decay.
	array( 'url' => '/stable/', 'post_id' => 5, 'title' => 'Stable', 'prev_clicks' => 150, 'curr_clicks' => 148, 'prev_impr' => 6000, 'curr_impr' => 6100, 'prev_pos' => 4.0, 'curr_pos' => 4.1 ),
	// Growing page: improving — never decay.
	array( 'url' => '/growing/', 'post_id' => 6, 'title' => 'Growing', 'prev_clicks' => 100, 'curr_clicks' => 180, 'prev_impr' => 5000, 'curr_impr' => 9000, 'prev_pos' => 8.0, 'curr_pos' => 5.0 ),
);
$decayed = SCC_Content_Decay::analyze( $decay_pages );
assert_eq( 1, count( $decayed ), 'only the genuinely decaying page is flagged (noise/stable/growing excluded)' );
assert_eq( '/local-seo/', $decayed[0]['url'], 'the decaying page is identified' );
$codes = array(); foreach ( $decayed[0]['causes'] as $c ) { $codes[] = $c['code']; }
assert_true( in_array( 'clicks_down', $codes, true ), 'clicks-down cause detected' );
assert_true( in_array( 'rankings_declining', $codes, true ), 'ranking-decline cause detected' );
assert_true( in_array( 'stale', $codes, true ), 'staleness noted as a contributing cause' );
assert_true( ! empty( $decayed[0]['refresh_plan'] ), 'a concrete refresh plan is produced' );
assert_true( $decayed[0]['confidence'] >= 70, 'strong baseline yields solid confidence' );
assert_eq( 0, count( SCC_Content_Decay::analyze( array() ) ), 'no pages → no decay (never fabricated)' );

// Decay flows into the opportunity engine as a verified refresh_content opp.
$opps_decay = SCC_Opportunity_Engine::compute( array( 'decay' => $decayed ) );
$cd = null; foreach ( $opps_decay as $o ) { if ( 'content_decay' === $o['type'] ) { $cd = $o; break; } }
assert_true( is_array( $cd ), 'decay produces a content_decay opportunity' );
assert_eq( 'verified', $cd['data_confidence'], 'decay opportunity is verified (GSC-backed)' );
assert_eq( 'refresh_content', $cd['action_type'], 'decay maps to a refresh action' );

echo "\n== Search Intent Drift (GSC-only) ==\n";
// Query wording classifier.
assert_eq( 'informational', SCC_Intent_Drift::classify_intent( 'how to do local seo' ), 'how-to → informational' );
assert_eq( 'commercial', SCC_Intent_Drift::classify_intent( 'best seo company' ), 'best/company → commercial' );
assert_eq( 'commercial', SCC_Intent_Drift::classify_intent( 'seo services pricing' ), 'services/pricing → commercial' );
assert_eq( 'local', SCC_Intent_Drift::classify_intent( 'seo agency near me' ), 'near me → local' );
assert_eq( 'unspecified', SCC_Intent_Drift::classify_intent( 'tideandtype' ), 'brand term → unspecified' );

// A page whose mix flips informational → commercial with a real baseline.
$drift_pages = array(
	array(
		'url' => '/seo/', 'post_id' => 7, 'title' => 'SEO',
		'prev_queries' => array(
			array( 'query' => 'what is seo', 'impressions' => 600 ),
			array( 'query' => 'how to learn seo', 'impressions' => 400 ),
			array( 'query' => 'best seo company', 'impressions' => 100 ),
		),
		'curr_queries' => array(
			array( 'query' => 'best seo company', 'impressions' => 700 ),
			array( 'query' => 'seo services pricing', 'impressions' => 300 ),
			array( 'query' => 'what is seo', 'impressions' => 100 ),
		),
	),
	// Below baseline → ignored.
	array(
		'url' => '/tiny/', 'post_id' => 8, 'title' => 'Tiny',
		'prev_queries' => array( array( 'query' => 'what is x', 'impressions' => 30 ) ),
		'curr_queries' => array( array( 'query' => 'best x', 'impressions' => 40 ) ),
	),
	// Stable mix → not drift.
	array(
		'url' => '/stable/', 'post_id' => 9, 'title' => 'Stable',
		'prev_queries' => array( array( 'query' => 'how to garden', 'impressions' => 800 ) ),
		'curr_queries' => array( array( 'query' => 'how to garden tips', 'impressions' => 820 ) ),
	),
);
$drifts = SCC_Intent_Drift::analyze( $drift_pages );
assert_eq( 1, count( $drifts ), 'only the page with a real intent flip + baseline is flagged' );
assert_eq( '/seo/', $drifts[0]['url'], 'the drifting page is identified' );
assert_eq( 'informational', $drifts[0]['prev_dominant'], 'previous dominant intent was informational' );
assert_eq( 'commercial', $drifts[0]['curr_dominant'], 'current dominant intent is commercial' );
assert_true( $drifts[0]['confidence'] <= 85, 'intent classification is heuristic → capped confidence (never verified)' );
assert_eq( 0, count( SCC_Intent_Drift::analyze( array() ) ), 'no pages → no drift (never fabricated)' );

// Drift flows into the engine as a "partial"-confidence realign opportunity.
$opps_drift = SCC_Opportunity_Engine::compute( array( 'intent_drift' => $drifts ) );
$idd = null; foreach ( $opps_drift as $o ) { if ( 'intent_drift' === $o['type'] ) { $idd = $o; break; } }
assert_true( is_array( $idd ), 'intent drift produces an opportunity' );
assert_eq( 'partial', $idd['data_confidence'], 'intent-drift opportunity is partial (real data, heuristic labels)' );
assert_eq( 'realign_intent', $idd['action_type'], 'intent drift maps to a realign action' );

echo "\n== Page Optimizer (composed scorecard + prioritized fixes) ==\n";
// compose(): unknown components excluded + renormalized.
$po = SCC_Page_Optimizer::compose( array(
	'content'          => array( 'known' => true, 'pct' => 100 ),
	'technical'        => array( 'known' => true, 'pct' => 100 ),
	'metadata'         => array( 'known' => true, 'pct' => 0 ),
	'internal_linking' => array( 'known' => true, 'pct' => 100 ),
	'schema'           => array( 'known' => true, 'pct' => 0 ),
	'intent'           => array( 'known' => false, 'pct' => 0 ),
	'gsc'              => array( 'known' => false, 'pct' => 0 ),
) );
// Known weights: content20 tech15 meta15 links15 schema15 = 80; earned =
// 20*100+15*100+15*0+15*100+15*0 = 5000 → 5000/80 = 62.5 → 63.
assert_eq( 63, $po['score'], 'score renormalizes over known components only' );
$intent_known = null; foreach ( $po['components'] as $c ) { if ( 'intent' === $c['key'] ) { $intent_known = $c['known']; } }
assert_eq( false, $intent_known, 'unmeasured component reported as not-known (n/a), not zero-scored' );
$all_unknown = SCC_Page_Optimizer::compose( array() );
assert_eq( 0, $all_unknown['score'], 'no known components → 0, never fabricated' );

// build_recommendations(): prioritized, high severity first.
$recs = SCC_Page_Optimizer::build_recommendations( array(
	'has_title' => false, 'has_desc' => true, 'schema_valid' => false,
	'thin' => true, 'word_count' => 120, 'is_orphan' => true, 'outbound_opps' => 0,
	'decay' => true, 'drift' => false, 'missing_h1' => false, 'images_missing_alt' => 0,
) );
assert_true( count( $recs ) >= 4, 'multiple prioritized fixes produced' );
assert_eq( 'high', $recs[0]['severity'], 'highest-severity fix is first' );
$rkeys = array(); foreach ( $recs as $r ) { $rkeys[] = $r['action']; }
assert_true( in_array( 'refresh_content', $rkeys, true ), 'decay → refresh_content fix' );
assert_true( in_array( 'add_internal_links', $rkeys, true ), 'orphan → add_internal_links fix' );
assert_true( in_array( 'schema', $rkeys, true ), 'no schema → schema fix' );
$clean = SCC_Page_Optimizer::build_recommendations( array( 'has_title' => true, 'has_desc' => true, 'schema_valid' => true, 'thin' => false, 'is_orphan' => false, 'outbound_opps' => 0 ) );
assert_eq( 0, count( $clean ), 'a well-optimized page yields no fixes' );

echo "\n== Health Timeline (transparent site health) ==\n";
$h = SCC_Health_Timeline::compute_health( array( 'analyzed' => 100, 'missing_meta' => 20, 'has_schema' => 50, 'thin_content' => 10, 'no_h1' => 0 ) );
// metadata 80, schema 50, content 90, headings 100; weights 25/25/30/20 = 100.
// (25*80 + 25*50 + 30*90 + 20*100)/100 = (2000+1250+2700+2000)/100 = 79.5 → 80.
assert_eq( 80, $h['score'], 'health score is a transparent weighted blend of measured coverage' );
$empty_h = SCC_Health_Timeline::compute_health( array( 'analyzed' => 0 ) );
assert_eq( 0, $empty_h['score'], 'no analyzed pages → 0 health (never fabricated)' );
$unknown = true; foreach ( $empty_h['components'] as $c ) { if ( $c['known'] ) { $unknown = false; } }
assert_true( $unknown, 'with no data every component is not-known' );

echo "\n== Experiments (correlation, not causation) ==\n";
$pos = SCC_Experiments::evaluate_result(
	array( 'available' => true, 'clicks' => 100, 'impressions' => 5000, 'position' => 9.0 ),
	array( 'available' => true, 'clicks' => 140, 'impressions' => 6000, 'position' => 6.0 ),
	28, gmdate( 'Y-m-d', time() - 40 * 86400 )
);
assert_eq( 'positive', $pos['verdict'], 'clicks up + position improved → positive correlation' );
assert_eq( 'complete', $pos['status'], 'past the measurement window → complete' );
assert_true( strpos( strtolower( $pos['detail'] ), 'causation' ) !== false, 'result explicitly disclaims causation' );
$neg = SCC_Experiments::evaluate_result(
	array( 'available' => true, 'clicks' => 100, 'impressions' => 5000, 'position' => 6.0 ),
	array( 'available' => true, 'clicks' => 70, 'impressions' => 4000, 'position' => 9.0 ),
	28, gmdate( 'Y-m-d', time() - 40 * 86400 )
);
assert_eq( 'negative', $neg['verdict'], 'clicks down + position worse → negative movement' );
$nodata = SCC_Experiments::evaluate_result( array( 'available' => false ), array( 'available' => false ), 28, gmdate( 'Y-m-d' ) );
assert_eq( 'no_data', $nodata['verdict'], 'no GSC data → cannot measure (not fabricated)' );

echo "\n== Entity Graph ==\n";
$eg = SCC_Entity_Graph::analyze( array(
	'organization' => 'Tide & Type',
	'services'     => array( 'Web Design', 'Local SEO' ),
	'locations'    => array( 'Savannah', 'Daytona Beach' ),
	'pages'        => array(
		array( 'title' => 'Web Design Services', 'path' => '/web-design/' ),
		array( 'title' => 'Local SEO', 'path' => '/local-seo/' ),
		array( 'title' => 'Savannah', 'path' => '/savannah/' ),
	),
) );
assert_true( ! empty( $eg['available'] ), 'entity graph built from configured data' );
$gap_labels = array(); foreach ( $eg['gaps'] as $g ) { $gap_labels[] = $g['entity']; }
assert_true( in_array( 'Daytona Beach', $gap_labels, true ), 'unsupported location surfaced as a gap' );
assert_true( ! in_array( 'Web Design', $gap_labels, true ), 'supported service is not a gap' );
assert_eq( false, SCC_Entity_Graph::analyze( array() )['available'], 'no business data → graph unavailable (never fabricated)' );

echo "\n== Revenue-aware prioritization ==\n";
$sig_rev = array(
	'gsc' => array( 'connected' => true, 'quick_wins' => array( array( 'query' => 'best seo company', 'impressions' => 300, 'position' => 7 ) ), 'untapped' => array() ),
	'value_weights' => array( 'enabled' => true, 'commercial' => 20, 'local' => 12, 'informational' => 0 ),
);
$rev_opps = SCC_Opportunity_Engine::compute( $sig_rev );
$has_value = false; foreach ( $rev_opps[0]['factors'] as $f ) { if ( strpos( $f['label'], 'commercial value' ) !== false ) { $has_value = true; } }
assert_true( $has_value, 'commercial query gains a business-value factor when revenue weighting is on' );
$sig_norev = array( 'gsc' => array( 'connected' => true, 'quick_wins' => array( array( 'query' => 'best seo company', 'impressions' => 300, 'position' => 7 ) ), 'untapped' => array() ), 'value_weights' => array( 'enabled' => false ) );
$norev = SCC_Opportunity_Engine::compute( $sig_norev );
$has_value2 = false; foreach ( $norev[0]['factors'] as $f ) { if ( strpos( $f['label'], 'commercial value' ) !== false ) { $has_value2 = true; } }
assert_eq( false, $has_value2, 'no value factor when revenue weighting is off' );

echo "\n== AI visibility (honest) ==\n";
$aiv = SCC_AI_Visibility::status();
assert_eq( false, $aiv['connected'], 'no AI-visibility provider connected by default' );
$all_unavail = true; foreach ( $aiv['providers'] as $p ) { if ( $p['connected'] ) { $all_unavail = false; } }
assert_true( $all_unavail, 'every provider reports not-connected (no fabricated AI metrics)' );

echo "\n== Automation modes ==\n";
assert_eq( 'assisted', SCC_Action_Queue::automation_mode(), 'default automation mode is assisted' );
assert_true( in_array( 'autopilot', SCC_Action_Queue::MODES, true ), 'autopilot is a valid mode' );

echo "\n== Content Ideas (sanitize) ==\n";
$ideas = SCC_Content_Ideas::sanitize_ideas( array( 'ideas' => array(
	array( 'title' => 'Manufacturing SEO', 'meta_title' => 'Manufacturing SEO Services', 'meta_description' => 'Grow leads.', 'primary_keyword' => 'manufacturing seo', 'secondary_keywords' => array( 'industrial seo', '', 'factory marketing' ), 'intent' => 'commercial', 'page_type' => 'industry', 'recommended_url' => '/industries/manufacturing/', 'priority' => 'high', 'why' => 'Targets untapped industry demand.' ),
	array( 'title' => '', 'page_type' => 'article' ), // dropped: no title.
	array( 'title' => 'Weird', 'page_type' => 'nonsense', 'priority' => 'urgent' ), // type/priority normalized.
) ) );
assert_eq( 2, count( $ideas ), 'ideas without a title are dropped' );
assert_eq( 'industry', $ideas[0]['page_type'], 'valid page_type kept' );
assert_eq( 'high', $ideas[0]['priority'], 'valid priority kept' );
assert_eq( array( 'industrial seo', 'factory marketing' ), $ideas[0]['secondary_keywords'], 'blank secondary keywords filtered' );
assert_eq( 'article', $ideas[1]['page_type'], 'unknown page_type falls back to article' );
assert_eq( 'medium', $ideas[1]['priority'], 'unknown priority falls back to medium' );

echo "\n== Meta Editor (char status) ==\n";
assert_eq( 'empty', SCC_Metadata::char_status( '', 30, 60 ), 'blank title flagged empty' );
assert_eq( 'short', SCC_Metadata::char_status( 'Too short', 30, 60 ), 'under min flagged short' );
assert_eq( 'good', SCC_Metadata::char_status( str_repeat( 'a', 45 ), 30, 60 ), 'within range is good' );
assert_eq( 'long', SCC_Metadata::char_status( str_repeat( 'a', 80 ), 30, 60 ), 'over max flagged long' );

echo "\n== Action Queue (safety) ==\n";
assert_true( SCC_Action_Queue::is_safe( 'add_internal_links' ), 'add_internal_links is a safe auto action' );
assert_true( SCC_Action_Queue::is_safe( 'fix_orphan' ), 'fix_orphan is a safe auto action' );
assert_eq( false, SCC_Action_Queue::is_safe( 'create_page' ), 'create_page is NOT auto-executable (needs human)' );
assert_eq( false, SCC_Action_Queue::is_safe( 'fix_cannibalization' ), 'cannibalization fix is NOT auto-executable' );
assert_eq( false, SCC_Action_Queue::is_safe( 'improve_meta' ), 'meta change is NOT auto-executable (AI + review)' );

echo "\n== Outbound URL security (SSRF guard) ==\n";
// Loopback is allowed (LM Studio runs locally); private/reserved/metadata is not.
assert_true( true === SCC_URL::is_safe_outbound_url( 'http://localhost:1234/v1' ), 'localhost allowed' );
assert_true( true === SCC_URL::is_safe_outbound_url( 'http://127.0.0.1:1234/v1' ), '127.0.0.1 allowed' );
assert_true( true === SCC_URL::is_safe_outbound_url( 'http://[::1]:1234/v1' ), '::1 allowed' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'http://10.0.0.5/x' ) ), 'RFC1918 10.x blocked' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'http://172.16.9.9/x' ) ), 'RFC1918 172.16 blocked' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'http://192.168.1.1/x' ) ), 'RFC1918 192.168 blocked' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'http://169.254.169.254/latest/meta-data/' ) ), 'cloud metadata endpoint blocked' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'http://[fd00::1]/x' ) ), 'IPv6 ULA blocked' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'http://[fe80::1]/x' ) ), 'IPv6 link-local blocked' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'http://0.0.0.0/x' ) ), 'unspecified address blocked' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'http://user:pass@8.8.8.8/x' ) ), 'embedded credentials rejected' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'file:///etc/passwd' ) ), 'file:// scheme rejected' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'ftp://8.8.8.8/x' ) ), 'ftp:// scheme rejected' );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'gopher://8.8.8.8/x' ) ), 'gopher:// scheme rejected' );
// A hostname that resolves to a private address is blocked (DNS-rebinding style).
add_filter( 'scc_resolve_host_ips', function ( $ips, $host ) {
	return ( 'rebind.evil.test' === $host ) ? array( '169.254.169.254' ) : ( ( 'good.public.test' === $host ) ? array( '93.184.216.34' ) : $ips );
}, 10, 2 );
assert_true( is_wp_error( SCC_URL::is_safe_outbound_url( 'http://rebind.evil.test/x' ) ), 'hostname resolving to metadata IP blocked' );
assert_true( true === SCC_URL::is_safe_outbound_url( 'https://good.public.test/api' ), 'hostname resolving to public IP allowed' );
remove_all_filters( 'scc_resolve_host_ips' );

echo "\n== IP classification ==\n";
assert_eq( 'loopback', SCC_URL::ip_category( '127.10.20.30' ), '127/8 is loopback' );
assert_eq( 'private', SCC_URL::ip_category( '10.255.255.255' ), '10/8 is private' );
assert_eq( 'public', SCC_URL::ip_category( '172.32.0.1' ), '172.32 is public (outside 172.16/12)' );
assert_eq( 'reserved', SCC_URL::ip_category( '100.64.0.1' ), 'CGNAT 100.64/10 is reserved' );
assert_eq( 'multicast', SCC_URL::ip_category( '224.0.0.1' ), '224/4 is multicast' );
assert_eq( 'public', SCC_URL::ip_category( '8.8.4.4' ), 'public v4' );
assert_eq( 'private', SCC_URL::ip_category( '::ffff:192.168.0.1' ), 'IPv4-mapped private v6' );

echo "\n== URL resolution (RFC 3986) ==\n";
$b = 'https://example.com/services/seo/local/';
assert_eq( 'https://example.com/services/seo/web-design/', SCC_URL::resolve( $b, '../web-design/' ), '../ resolves up one dir' );
assert_eq( 'https://example.com/services/about', SCC_URL::resolve( $b, '../../about' ), '../../ resolves up two dirs' );
assert_eq( 'https://example.com/services/seo/local/page', SCC_URL::resolve( $b, './page' ), './ stays in current dir' );
assert_eq( 'https://example.com/services/seo/local/page', SCC_URL::resolve( $b, 'page' ), 'bare relative path' );
assert_eq( 'https://example.com/contact', SCC_URL::resolve( $b, '/contact' ), 'root-relative path' );
assert_eq( 'https://example.com/services/seo/local/?foo=bar', SCC_URL::resolve( $b, '?foo=bar' ), 'query-only reference' );
assert_eq( 'https://example.com/services/seo/local/#section', SCC_URL::resolve( $b, '#section' ), 'fragment-only reference' );
assert_eq( 'https://other.com/x', SCC_URL::resolve( $b, 'https://other.com/x' ), 'absolute reference kept' );
assert_eq( 'https://cdn.com/a.js', SCC_URL::resolve( $b, '//cdn.com/a.js' ), 'protocol-relative reference' );
assert_eq( 'https://example.com/a/b/d/e', SCC_URL::resolve( 'https://example.com/a/b/c', 'd/e' ), 'nested relative path' );

echo "\n== Crawl URL normalization ==\n";
assert_eq( 'https://e.com/Path/?id=5', SCC_URL::normalize_for_crawl( 'https://E.com:443/Path/?utm_source=x&id=5#frag' ), 'lowercase host, drop default port + utm + fragment' );
assert_eq( 'http://e.com/p', SCC_URL::normalize_for_crawl( 'http://e.com/p?fbclid=abc&gclid=z' ), 'all tracking params stripped' );
assert_eq( 'https://e.com/p?a=1&b=2', SCC_URL::normalize_for_crawl( 'https://e.com/p?a=1&utm_medium=cpc&b=2' ), 'meaningful params preserved in order' );
assert_eq( 'id=5', SCC_URL::strip_tracking_params( 'utm_source=x&id=5&gclid=1' ), 'strip_tracking_params keeps content params' );

echo "\n== robots.txt parsing ==\n";
$rb = "User-agent: *\nDisallow: /private/\nAllow: /private/ok\n";
assert_eq( false, SCC_Robots::is_allowed( $rb, '/private/secret', 'SEO-Command-Center' ), 'Disallow blocks matching path' );
assert_eq( true, SCC_Robots::is_allowed( $rb, '/private/ok', 'SEO-Command-Center' ), 'more specific Allow overrides Disallow' );
assert_eq( true, SCC_Robots::is_allowed( $rb, '/public', 'SEO-Command-Center' ), 'unlisted path allowed' );
$rb2 = "User-agent: SEO-Command-Center\nDisallow: /\n\nUser-agent: *\nAllow: /\n";
assert_eq( false, SCC_Robots::is_allowed( $rb2, '/anything', 'SEO-Command-Center' ), 'our specific agent group wins over *' );
assert_eq( true, SCC_Robots::is_allowed( $rb2, '/anything', 'Googlebot' ), 'other agent uses the * group' );
$rb3 = "User-agent: *\nDisallow: /*.pdf$\n";
assert_eq( false, SCC_Robots::is_allowed( $rb3, '/docs/a.pdf', 'Bot' ), 'wildcard + $ anchor blocks .pdf' );
assert_eq( true, SCC_Robots::is_allowed( $rb3, '/docs/a.pdf.html', 'Bot' ), '$ anchor does not block .pdf.html' );
$rb4 = "# comment\nUser-agent: *\nDisallow:\n"; // Empty Disallow == allow all.
assert_eq( true, SCC_Robots::is_allowed( $rb4, '/anything', 'Bot' ), 'empty Disallow allows everything' );
assert_eq( true, SCC_Robots::is_allowed( '', '/x', 'Bot' ), 'missing robots.txt allows everything' );
$rb5 = "User-agent: A\nUser-agent: B\nDisallow: /x\n"; // Shared group across two agents.
assert_eq( false, SCC_Robots::is_allowed( $rb5, '/x', 'B' ), 'grouped user-agents share rules' );

echo "\n== JSON-LD extraction robustness ==\n";
$crawler2 = new SCC_Crawler();
// Malformed JSON-LD must not abort the crawl; a valid sibling block still parses.
$mixed = '<html><head>'
	. '<script type="application/ld+json">{ this is : not json }</script>'
	. '<script type="application/ld+json">{"@type":"Organization"}</script>'
	. '</head><body></body></html>';
$mp = $crawler2->parse( $mixed, 'https://example.com/m' );
assert_true( in_array( 'Organization', $mp['schema_types'], true ), 'valid JSON-LD parsed despite a malformed sibling block' );
// Identical duplicate blocks are de-duplicated (type appears once).
$dup = '<html><head>'
	. '<script type="application/ld+json">{"@type":"Article"}</script>'
	. '<script type="application/ld+json">{"@type":"Article"}</script>'
	. '</head><body></body></html>';
$dp = $crawler2->parse( $dup, 'https://example.com/d' );
assert_eq( 1, count( array_keys( $dp['schema_types'], 'Article', true ) ), 'duplicate JSON-LD blocks counted once' );
// Canonical is resolved to an absolute URL distinct from the crawl URL.
$canon = '<html><head><link rel="canonical" href="/canonical-home"></head><body></body></html>';
$cp = $crawler2->parse( $canon, 'https://example.com/some/deep/page' );
assert_eq( 'https://example.com/canonical-home', $cp['canonical_resolved'], 'relative canonical resolved to absolute' );

echo "\n----------------------------------------\n";
echo "Tests: {$tests}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
