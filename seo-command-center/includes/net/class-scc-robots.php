<?php
/**
 * robots.txt parser + matcher.
 *
 * Implements the parts of the Robots Exclusion Protocol that matter for a
 * polite SEO crawler: multiple user-agent groups, Allow and Disallow rules,
 * '*' wildcards, '$' end-anchors, comments/blank lines, and Allow/Disallow
 * precedence by longest (most specific) match — with Allow winning ties, per
 * Google's implementation. Pure and unit-testable.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * robots.txt logic.
 */
class SCC_Robots {

	/**
	 * Whether a path is allowed for the given user agent under a robots.txt body.
	 *
	 * A missing/empty robots.txt allows everything. The most specific matching
	 * group is selected (exact agent name, else '*'); within it the rule with the
	 * longest matching pattern wins, and Allow beats Disallow on an equal-length
	 * tie.
	 *
	 * @param string $robots_txt   The full robots.txt body.
	 * @param string $path         The request path (with query, no host).
	 * @param string $user_agent   Our crawler's product token (e.g. 'SEO-Command-Center').
	 * @return bool
	 */
	public static function is_allowed( $robots_txt, $path, $user_agent = '*' ) {
		$robots_txt = (string) $robots_txt;
		if ( '' === trim( $robots_txt ) ) {
			return true;
		}
		$path = self::normalize_path( $path );

		$groups = self::parse_groups( $robots_txt );
		$rules  = self::select_group( $groups, $user_agent );
		if ( empty( $rules ) ) {
			return true; // No applicable group: allowed.
		}

		$best_len   = -1;
		$best_allow = true;
		foreach ( $rules as $rule ) {
			$len = self::match_length( $rule['pattern'], $path );
			if ( $len < 0 ) {
				continue;
			}
			// Longer (more specific) rule wins; Allow wins an equal-length tie.
			if ( $len > $best_len || ( $len === $best_len && 'allow' === $rule['type'] ) ) {
				$best_len   = $len;
				$best_allow = ( 'allow' === $rule['type'] );
			}
		}

		return ( $best_len < 0 ) ? true : $best_allow;
	}

	/**
	 * Parse robots.txt into groups: [ ['agents'=>[], 'rules'=>[ ['type','pattern'] ]] ].
	 *
	 * Consecutive User-agent lines share the following rule block.
	 *
	 * @param string $robots_txt Body.
	 * @return array
	 */
	public static function parse_groups( $robots_txt ) {
		$groups          = array();
		$idx             = -1; // Index of the current group in $groups.
		$expecting_agent = false; // True right after a User-agent line, before any rule.

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $robots_txt ) as $raw ) {
			$line = trim( $raw );
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = trim( substr( $line, 0, $hash ) );
			}
			if ( '' === $line ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$field = strtolower( trim( substr( $line, 0, $colon ) ) );
			$value = trim( substr( $line, $colon + 1 ) );

			if ( 'user-agent' === $field ) {
				// A User-agent line that follows a rule starts a fresh group;
				// consecutive User-agent lines share one group.
				if ( ! $expecting_agent || $idx < 0 ) {
					$groups[] = array( 'agents' => array(), 'rules' => array() );
					$idx      = count( $groups ) - 1;
				}
				$groups[ $idx ]['agents'][] = strtolower( $value );
				$expecting_agent            = true;
			} elseif ( in_array( $field, array( 'allow', 'disallow' ), true ) ) {
				if ( $idx < 0 ) {
					$groups[] = array( 'agents' => array( '*' ), 'rules' => array() );
					$idx      = count( $groups ) - 1;
				}
				$groups[ $idx ]['rules'][] = array(
					'type'    => ( 'allow' === $field ) ? 'allow' : 'disallow',
					'pattern' => $value,
				);
				$expecting_agent = false;
			} else {
				// Sitemap, Crawl-delay, Host, etc. — ignored for access decisions,
				// but they end an agent-declaration run.
				$expecting_agent = false;
			}
		}

		return $groups;
	}

	/**
	 * Pick the rules of the most specific group for a user agent.
	 *
	 * Exact (case-insensitive substring) agent match is preferred; otherwise the
	 * '*' group is used. Rules from all groups naming the same agent are merged.
	 *
	 * @param array  $groups     Parsed groups.
	 * @param string $user_agent Our token.
	 * @return array Rules list.
	 */
	protected static function select_group( array $groups, $user_agent ) {
		$ua       = strtolower( (string) $user_agent );
		$specific = array();
		$wildcard = array();

		foreach ( $groups as $group ) {
			foreach ( (array) $group['agents'] as $agent ) {
				if ( '*' === $agent ) {
					$wildcard = array_merge( $wildcard, $group['rules'] );
				} elseif ( '' !== $agent && false !== strpos( $ua, $agent ) ) {
					$specific = array_merge( $specific, $group['rules'] );
				}
			}
		}

		return ! empty( $specific ) ? $specific : $wildcard;
	}

	/**
	 * Length of the matched prefix if the pattern matches the path, else -1.
	 *
	 * Supports '*' (any run of characters) and a trailing '$' (end anchor). An
	 * empty Disallow/Allow value matches nothing (length -1) — an empty Disallow
	 * means "allow all", which naturally falls out of not matching.
	 *
	 * @param string $pattern robots pattern.
	 * @param string $path    Normalized path.
	 * @return int Match "specificity" (pattern length without wildcards), or -1.
	 */
	protected static function match_length( $pattern, $path ) {
		$pattern = (string) $pattern;
		if ( '' === $pattern ) {
			return -1;
		}

		$anchored = false;
		if ( '$' === substr( $pattern, -1 ) ) {
			$anchored = true;
			$pattern  = substr( $pattern, 0, -1 );
		}

		// Build a regex from the pattern: escape everything, then restore '*'.
		$regex = '';
		foreach ( explode( '*', $pattern ) as $i => $chunk ) {
			if ( $i > 0 ) {
				$regex .= '.*';
			}
			$regex .= preg_quote( $chunk, '#' );
		}
		$regex = '#^' . $regex . ( $anchored ? '$' : '' ) . '#';

		if ( preg_match( $regex, $path ) ) {
			// Specificity = number of literal characters in the pattern.
			return strlen( str_replace( '*', '', $pattern ) );
		}
		return -1;
	}

	/**
	 * Normalize a path for matching (ensure a leading slash; keep query).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected static function normalize_path( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return '/';
		}
		if ( 0 !== strpos( $path, '/' ) ) {
			$path = '/' . $path;
		}
		return $path;
	}
}
