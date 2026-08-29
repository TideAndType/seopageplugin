<?php
/**
 * Honest placeholder for features scheduled in a later phase.
 *
 * @package SEO_Command_Center
 * @var array $data View data (title, phase).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title = isset( $data['title'] ) ? $data['title'] : '';
$phase = isset( $data['phase'] ) ? (int) $data['phase'] : 2;
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php echo esc_html( $title ); ?></h1>
	</div>
	<div class="scc-card scc-empty">
		<span class="scc-phase-badge"><?php echo esc_html( sprintf( /* translators: %d: phase number */ __( 'Planned for Phase %d', 'seo-command-center' ), $phase ) ); ?></span>
		<h2><?php esc_html_e( 'This feature is on the roadmap', 'seo-command-center' ); ?></h2>
		<p><?php esc_html_e( 'The architecture and interfaces for this section are in place. The working feature ships in the phase shown above — see docs/ROADMAP.md for details.', 'seo-command-center' ); ?></p>
	</div>
</div>
