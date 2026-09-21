<?php
/**
 * Elementor Widget Catalog.
 *
 * Small, owned catalog of Elementor widgets TideOrbit knows how to populate
 * safely. Runtime discovery decides what is actually usable on the current site.
 * This is intentionally independent from EMCP.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Elementor_Widget_Catalog {

	public static function all() {
		return array(
			'heading'        => array( 'tier' => 'free', 'role' => array( 'heading' ) ),
			'text-editor'    => array( 'tier' => 'free', 'role' => array( 'prose' ) ),
			'button'         => array( 'tier' => 'free', 'role' => array( 'cta' ) ),
			'image'          => array( 'tier' => 'free', 'role' => array( 'media' ) ),
			'icon-list'      => array( 'tier' => 'free', 'role' => array( 'benefits', 'feature_list' ) ),
			'accordion'      => array( 'tier' => 'free', 'role' => array( 'faq' ) ),
			'counter'        => array( 'tier' => 'free', 'role' => array( 'stat' ) ),
			'divider'        => array( 'tier' => 'free', 'role' => array( 'separator' ) ),
			'image-box'      => array( 'tier' => 'free', 'role' => array( 'card' ) ),
			'testimonial'    => array( 'tier' => 'free', 'role' => array( 'testimonial' ) ),
			'video'          => array( 'tier' => 'free', 'role' => array( 'video' ) ),
			'image-carousel' => array( 'tier' => 'free', 'role' => array( 'gallery' ) ),
			'google_maps'    => array( 'tier' => 'free', 'role' => array( 'map' ) ),
			'form'           => array( 'tier' => 'pro',  'role' => array( 'form', 'lead_capture' ) ),
			'call-to-action' => array( 'tier' => 'pro',  'role' => array( 'cta_card' ) ),
			'loop-grid'      => array( 'tier' => 'pro',  'role' => array( 'dynamic_grid' ) ),
		);
	}

	public static function supports( $widget ) {
		$widget = sanitize_key( (string) $widget );
		return isset( self::all()[ $widget ] )
			&& class_exists( 'SCC_Elementor_Capabilities' )
			&& SCC_Elementor_Capabilities::supports_widget( $widget );
	}

	public static function for_role( $role ) {
		$role = sanitize_key( (string) $role );
		foreach ( self::all() as $id => $meta ) {
			if ( in_array( $role, (array) $meta['role'], true ) && self::supports( $id ) ) {
				return $id;
			}
		}
		return '';
	}

	public static function available() {
		$out = array();
		foreach ( self::all() as $id => $meta ) {
			if ( self::supports( $id ) ) {
				$out[ $id ] = $meta;
			}
		}
		return $out;
	}

	public static function snapshot() {
		return array(
			'available' => array_keys( self::available() ),
			'roles'     => array(
				'benefits' => self::for_role( 'benefits' ),
				'faq'      => self::for_role( 'faq' ),
				'stat'     => self::for_role( 'stat' ),
				'card'     => self::for_role( 'card' ),
				'form'     => self::for_role( 'form' ),
			),
		);
	}
}
