<?php
/**
 * Content presenter: turns the plain semantic article HTML the generator
 * produces (h2/h3 + paragraphs + lists + tables) into a visually rich, scannable
 * layout — callouts, key-takeaway cards, numbered process steps, timelines, stat
 * cards and responsive tables — WITHOUT changing the words, the heading
 * hierarchy, links, lists or table data.
 *
 * Design goals:
 *  - Pure, deterministic PHP. No JavaScript, no external libraries.
 *  - Output only tags wp_kses_post allows (div, aside, figure, section, ol, ul,
 *    li, table, h2-h4, p, strong, a, span, details, summary) so it survives
 *    sanitisation everywhere.
 *  - Semantic + accessible: real headings stay headings, ordered content stays
 *    an <ol>, numbers are drawn with CSS counters (not baked into the text), and
 *    nothing important is hidden from crawlers.
 *  - Conservative: a component is only produced when the content clearly calls
 *    for it. Normal paragraphs stay normal paragraphs.
 *
 * The result is wrapped in <div class="scc-content"> so the front-end styles
 * (SCC_Plugin::front_styles) can scope to it and inherit the theme's own fonts
 * and colours.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presentation transformer.
 */
class SCC_Content_Presenter {

	/**
	 * Enhance article HTML into the visual component layout.
	 *
	 * @param string $html    Sanitised article HTML.
	 * @param array  $context Optional context (unused today; reserved).
	 * @return string
	 */
	public static function enhance( $html, array $context = array() ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return $html;
		}
		// Idempotent: never wrap twice.
		if ( false !== strpos( $html, 'class="scc-content"' ) ) {
			return $html;
		}

		// Order matters: structural (heading-anchored) transforms first, then the
		// generic list/paragraph passes, so a list already claimed as steps or
		// takeaways is not re-classified as a timeline.
		$html = self::takeaways( $html );
		$html = self::stat_cards( $html );
		$html = self::process_steps( $html );
		$html = self::timeline( $html );
		$html = self::callouts( $html );
		$html = self::wrap_tables( $html );

		return '<div class="scc-content">' . "\n" . trim( $html ) . "\n" . '</div>';
	}

	/**
	 * Wrap every table in a responsive, horizontally-scrollable container so wide
	 * comparison tables never cause page overflow on mobile.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected static function wrap_tables( $html ) {
		return preg_replace_callback(
			'#<table\b[^>]*>.*?</table>#is',
			function ( $m ) {
				return '<figure class="scc-table"><div class="scc-table__scroll" role="region" tabindex="0">' . $m[0] . '</div></figure>';
			},
			$html
		);
	}

	/**
	 * Turn "label paragraphs" (Note:, Tip:, Important:, Warning:, Key takeaway:,
	 * Definition:, Bottom line:) into visually distinct callout boxes.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected static function callouts( $html ) {
		$variants = array(
			'note'          => 'note',
			'tip'           => 'tip',
			'pro tip'       => 'tip',
			'important'     => 'important',
			'warning'       => 'warning',
			'caution'       => 'warning',
			'remember'      => 'note',
			'key takeaway'  => 'key',
			'takeaway'      => 'key',
			'bottom line'   => 'key',
			'definition'    => 'info',
			'example'       => 'info',
		);
		$labels = implode( '|', array_map( 'preg_quote', array_keys( $variants ) ) );

		return preg_replace_callback(
			'#<p\b[^>]*>(.*?)</p>#is',
			function ( $m ) use ( $variants, $labels ) {
				$inner = $m[1];
				$text  = trim( wp_strip_all_tags( $inner ) );
				if ( ! preg_match( '/^(' . $labels . ')\s*[\x{2013}:-]\s*(.+)$/isu', $text, $mm ) ) {
					return $m[0];
				}
				$label   = strtolower( trim( $mm[1] ) );
				$variant = isset( $variants[ $label ] ) ? $variants[ $label ] : 'note';

				// Strip the leading "Label:" (with any bold/italic wrapper) from the
				// original inner HTML so formatting inside the body is preserved.
				$body = preg_replace(
					'#^\s*(?:<(?:strong|b|em|i)>\s*)?' . preg_quote( $mm[1], '#' ) . '\s*[\x{2013}:-]\s*(?:</(?:strong|b|em|i)>\s*)?#isu',
					'',
					$inner,
					1
				);

				return '<aside class="scc-callout scc-callout--' . $variant . '">'
					. '<p class="scc-callout__label">' . esc_html( self::title_case( $mm[1] ) ) . '</p>'
					. '<p class="scc-callout__body">' . trim( (string) $body ) . '</p>'
					. '</aside>';
			},
			$html
		);
	}

	/**
	 * Convert a "Key takeaways / At a glance / What you'll learn" heading followed
	 * by a bullet list into a compact highlight card.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected static function takeaways( $html ) {
		$titles = 'Key takeaways|Key take-aways|At a glance|What you\'?ll learn|What you will learn|TL;?DR|In (?:this|short)|Key points|Quick summary|In summary|Summary';
		$re     = '#<(h[2-4])\b[^>]*>\s*(' . $titles . ')\s*</\1>\s*(<ul\b[^>]*>.*?</ul>)#isu';

		return preg_replace_callback(
			$re,
			function ( $m ) {
				return '<aside class="scc-takeaways" aria-label="' . esc_attr( wp_strip_all_tags( $m[2] ) ) . '">'
					. '<p class="scc-takeaways__title">' . esc_html( wp_strip_all_tags( $m[2] ) ) . '</p>'
					. $m[3]
					. '</aside>';
			},
			$html
		);
	}

	/**
	 * When a heading names a process/method/steps and is followed by an ordered
	 * list, render that list as numbered process steps (numbers via CSS counter).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected static function process_steps( $html ) {
		$kw = 'process|steps|step by step|step-by-step|how it works|how we work|how to|methodology|workflow|our approach|the approach|framework|stages|phases';
		$re = '#(<(h[2-4])\b[^>]*>\s*[^<]*\b(?:' . $kw . ')\b[^<]*</\2>\s*)<ol\b[^>]*>(.*?)</ol>#isu';

		return preg_replace_callback(
			$re,
			function ( $m ) {
				return $m[1] . '<ol class="scc-steps">' . $m[3] . '</ol>';
			},
			$html
		);
	}

	/**
	 * Detect a chronological list (items beginning Month/Week/Day/Phase/Quarter/
	 * Year/Stage N, or a "1-3" style range) and render it as a vertical timeline.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected static function timeline( $html ) {
		return preg_replace_callback(
			'#<(ul|ol)\b([^>]*)>(.*?)</\1>#isu',
			function ( $m ) {
				// Skip lists already claimed as steps/timeline/takeaway.
				if ( false !== strpos( $m[2], 'scc-' ) ) {
					return $m[0];
				}
				if ( ! preg_match_all( '#<li\b[^>]*>(.*?)</li>#isu', $m[3], $lis ) ) {
					return $m[0];
				}
				$total  = count( $lis[1] );
				if ( $total < 2 ) {
					return $m[0];
				}
				$marker = 0;
				foreach ( $lis[1] as $li ) {
					$t = ltrim( wp_strip_all_tags( $li ) );
					if ( preg_match( '/^(?:month|months|week|weeks|day|days|phase|quarter|year|stage)\s*\d/i', $t )
						|| preg_match( '/^Q[1-4]\b/', $t )
						|| preg_match( '/^\d{1,4}\s*[-\x{2013}]\s*\d{1,4}\b/u', $t )
						|| preg_match( '/^(?:month|week|day)s?\s*\d{1,3}\s*[-\x{2013}]/iu', $t )
					) {
						$marker++;
					}
				}
				if ( $marker >= 2 && $marker >= (int) ceil( $total * 0.6 ) ) {
					return '<ol class="scc-timeline">' . $m[3] . '</ol>';
				}
				return $m[0];
			},
			$html
		);
	}

	/**
	 * Convert a "By the numbers / Key stats / Fast facts" heading followed by a
	 * list of short "Label: value" items into stat cards.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected static function stat_cards( $html ) {
		$titles = 'By the numbers|Key stats|Key statistics|Fast facts|Key facts|Stats|Statistics|At a glance stats';
		$re     = '#<(h[2-4])\b[^>]*>\s*(' . $titles . ')\s*</\1>\s*<ul\b[^>]*>(.*?)</ul>#isu';

		return preg_replace_callback(
			$re,
			function ( $m ) {
				if ( ! preg_match_all( '#<li\b[^>]*>(.*?)</li>#isu', $m[3], $lis ) ) {
					return $m[0];
				}
				$cards = '';
				foreach ( $lis[1] as $li ) {
					$text = trim( wp_strip_all_tags( $li ) );
					if ( '' === $text ) {
						continue;
					}
					// Split "Label: value" or "value — label"; otherwise one line.
					if ( preg_match( '/^(.*?)\s*[\x{2013}:-]\s*(.+)$/u', $text, $mm ) ) {
						$a = trim( $mm[1] );
						$b = trim( $mm[2] );
						// Put the number-ish part on top.
						if ( preg_match( '/\d/', $a ) && ! preg_match( '/\d/', $b ) ) {
							$stat = $a;
							$lbl  = $b;
						} else {
							$stat = $b;
							$lbl  = $a;
						}
						$cards .= '<div class="scc-stat"><span class="scc-stat__value">' . esc_html( $stat ) . '</span><span class="scc-stat__label">' . esc_html( $lbl ) . '</span></div>';
					} else {
						$cards .= '<div class="scc-stat"><span class="scc-stat__value">' . esc_html( $text ) . '</span></div>';
					}
				}
				if ( '' === $cards ) {
					return $m[0];
				}
				return '<h' . substr( $m[1], 1 ) . ' class="scc-section-title">' . esc_html( wp_strip_all_tags( $m[2] ) ) . '</h' . substr( $m[1], 1 ) . '>'
					. '<div class="scc-stats">' . $cards . '</div>';
			},
			$html
		);
	}

	/**
	 * Title-case a short label ("pro tip" -> "Pro Tip").
	 *
	 * @param string $s Label.
	 * @return string
	 */
	protected static function title_case( $s ) {
		return ucwords( strtolower( trim( (string) $s ) ) );
	}
}
