<?php
/**
 * Plugin Name:       Mlýn Digital Signage
 * Description:       Full-screen image and video presentations managed from WordPress.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Velký mlýn
 * License:           GPL-2.0-or-later
 * Text Domain:       mlyn-digital-signage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MDS_VERSION', '1.1.1' );
define( 'MDS_FILE', __FILE__ );
define( 'MDS_DIR', plugin_dir_path( __FILE__ ) );

require_once MDS_DIR . 'src/class-plugin.php';

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'edit.php?post_type=mlyn_display' ) ),
				esc_html__( 'Presentations', 'mlyn-digital-signage' )
			)
		);
		return $links;
	}
);

register_activation_hook( __FILE__, array( 'MDS\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MDS\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		MDS\Plugin::instance();
	}
);

if ( ! function_exists( 'mlyn_display_url' ) ) {
	function mlyn_display_url( $id ): string {
		return MDS\Plugin::instance()->get_display_url( $id );
	}
}
