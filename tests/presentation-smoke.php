<?php

// Run with: wp eval-file wp-content/plugins/mlyn-digital-signage/tests/presentation-smoke.php

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
if ( ! $admins ) {
	throw new RuntimeException( 'No administrator is available for the presentation smoke test.' );
}

wp_set_current_user( (int) $admins[0] );
$plugin = MDS\Plugin::instance();

$sanitize_items = new ReflectionMethod( MDS\Plugin::class, 'sanitize_items' );
$sanitize_items->setAccessible( true );
$raw_items = array(
	array(
		'id'        => 'duplicate-item-id',
		'enabled'   => true,
		'type'      => 'image',
		'media_id'  => 4078,
		'duration'  => 5,
		'starts_at' => '2026-08-01T09:00',
		'ends_at'   => '',
	),
	array(
		'id'        => 'duplicate-item-id',
		'enabled'   => true,
		'type'      => 'video',
		'media_id'  => 4180,
		'duration'  => 13,
		'starts_at' => '',
		'ends_at'   => '2026-09-01T09:00',
	),
	array(
		'id'       => '',
		'enabled'  => false,
		'type'     => 'image',
		'media_id' => 4074,
		'duration' => 0,
	),
);
$items = $sanitize_items->invoke( $plugin, $raw_items );
if ( 3 !== count( $items ) || 3 !== count( array_unique( wp_list_pluck( $items, 'id' ) ) ) ) {
	throw new RuntimeException( 'Duplicate or empty item IDs were not repaired.' );
}
if ( 'duplicate-item-id' !== $items[0]['id'] || 'duplicate-item-id' === $items[1]['id'] || empty( $items[2]['id'] ) ) {
	throw new RuntimeException( 'Item ID repair did not preserve the first valid occurrence.' );
}
if ( array( 4078, 4180, 4074 ) !== array_map( 'intval', wp_list_pluck( $items, 'media_id' ) ) ) {
	throw new RuntimeException( 'Item ID repair changed media references or ordering.' );
}
if ( 13.0 !== (float) $items[1]['duration'] || '2026-09-01T09:00' !== $items[1]['ends_at'] || $items[2]['enabled'] ) {
	throw new RuntimeException( 'Item ID repair changed presentation settings.' );
}

$display = get_page_by_path( 'main-display', OBJECT, 'mlyn_display' );
if ( ! $display ) {
	throw new RuntimeException( 'Main Display is unavailable.' );
}
ob_start();
$editor_fixture     = clone $display;
$editor_fixture->ID = 0;
$plugin->render_items_meta_box( $editor_fixture );
$html = ob_get_clean();
foreach ( array( 'id="mds-item-template"', 'class="mds-item-id"', 'id="mds-add-media-bulk"', 'id="mds-add-item"' ) as $expected ) {
	if ( false === strpos( $html, $expected ) ) {
		throw new RuntimeException( 'Presentation editor output is missing: ' . $expected );
	}
}

$get_active_items = new ReflectionMethod( MDS\Plugin::class, 'get_active_items' );
$get_active_items->setAccessible( true );
$runtime_version = new ReflectionMethod( MDS\Plugin::class, 'get_runtime_version' );
$runtime_version->setAccessible( true );
$active_items = $get_active_items->invoke( $plugin, $display->ID );
if ( ! $active_items || $runtime_version->invoke( $plugin, $display->ID, $active_items ) !== $runtime_version->invoke( $plugin, $display->ID, $active_items ) ) {
	throw new RuntimeException( 'The presentation runtime version is unstable.' );
}

$admin_script  = file_get_contents( MDS_DIR . 'assets/admin.js' );
$player_script = file_get_contents( MDS_DIR . 'assets/player.js' );
foreach ( array( 'crypto.randomUUID', ".mds-item-id').value = createItemId()" ) as $expected ) {
	if ( false === strpos( $admin_script, $expected ) ) {
		throw new RuntimeException( 'Admin unique-ID behavior is missing: ' . $expected );
	}
}
foreach ( array( 'saveResumePosition', 'getResumePosition', 'window.sessionStorage', 'show(getResumePosition())' ) as $expected ) {
	if ( false === strpos( $player_script, $expected ) ) {
		throw new RuntimeException( 'Player resume behavior is missing: ' . $expected );
	}
}

echo "MDS presentation smoke test passed.\n";
