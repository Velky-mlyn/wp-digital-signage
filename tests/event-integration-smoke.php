<?php

/** Run in the WordPress web container (Imagick with image codecs), with Mlýn Event,
 * Event Intake, Flexible Slider and the Velký Mlýn theme active. Disposable fixtures.
 * Define MDS_KEEP_FIXTURES for a subsequent browser check; clean up those IDs afterwards.
 */
if (! defined('ABSPATH')) {
	exit(1);
}

use Mlyn_Event\Promo_Banner as Promo;

$assert = static function ($ok, $message) {
	if (! $ok) {
		throw new RuntimeException($message);
	}
};
$invoke = static function ($object, $method, ...$args) {
	$reflection = new ReflectionMethod($object, $method);
	$reflection->setAccessible(true);
	return $reflection->invoke($object, ...$args);
};
$posts = $media = array();
$profile = $event = $display = 0;
global $wpdb;
try {
	$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ids'));
	wp_set_current_user((int) $admins[0]);
	require_once ABSPATH . 'wp-admin/includes/image.php';
	foreach (array('#397caf', '#af7039') as $color) {
		$image = new Imagick();
		$image->newImage(1200, 675, new ImagickPixel($color), 'png');
		$upload = wp_upload_bits('mds-test-' . wp_generate_uuid4() . '.png', null, $image->getImageBlob());
		$image->clear();
		$assert(! $upload['error'], 'Cannot create fixture image.');
		$id = wp_insert_attachment(array('post_title' => 'MDS disposable image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'), $upload['file']);
		$media[] = $id;
		wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
	}
	$profile = wp_insert_post(array('post_type' => 'mlyn_event_profile', 'post_status' => 'publish', 'post_title' => 'MDS disposable profile'));
	$posts[] = $profile;
	$organizer = wp_insert_post(array('post_type' => 'tribe_organizer', 'post_status' => 'publish', 'post_title' => 'MDS disposable organizer'));
	$posts[] = $organizer;
	$database = new MEI\Database();
	$sync = new MEI\TEC_Sync($database);
	$start = new DateTimeImmutable('first day of next month 18:00:00', wp_timezone());
	$month = $start->format('Y-m');
	$row = array(
		'uuid' => wp_generate_uuid4(),
		'title' => 'MDS disposable event',
		'content' => '<p>Initial description.</p>',
		'excerpt' => '',
		'start_at' => $start->format('Y-m-d H:i:s'),
		'end_at' => $start->modify('+2 hours')->format('Y-m-d H:i:s'),
		'all_day' => false,
		'venue_id' => 0,
		'organizer_id' => $organizer,
		'website' => '',
		'cost' => '',
		'capacity' => '',
		'available_places' => '',
		'occupancy_note' => '',
		'tag_ids' => array(),
		'category_ids' => array(),
		'image_id' => $media[0],
		'focal_x' => '',
		'focal_y' => '',
	);
	$settings = array('currency_symbol' => 'Kč', 'currency_position' => 'postfix', 'currency_code' => 'CZK', 'event_status' => 'scheduled', 'hide_from_upcoming' => false, 'sticky' => false, 'featured' => false, 'default_capacity' => '', 'default_available_places' => '');
	$database->save_month($profile, $month, array($row), (int) $admins[0]);
	$result = $sync->import_profile($profile, $settings);
	$saved = $database->get_month_rows($profile, $month);
	$event = (int) ($saved[0]['tec_event_id'] ?? 0);
	$posts[] = $event;
	$assert($event && 1 === $result['created'] && ! $result['errors'], 'Intake event creation failed.');
	wp_update_post(array('ID' => $event, 'post_status' => 'publish'));
	$config = array_merge(Promo::config($event), array('enabled' => true, 'position' => 'none', 'subtitle_mode' => 'custom', 'subtitle' => 'Admin subtitle', 'datetime_mode' => 'custom', 'datetime' => 'Každý pátek od 18 hodin', 'logo_options' => array($organizer => array('show' => false, 'height' => 75))));
	update_post_meta($event, Promo::CONFIG, $config);
	$banner = Promo::generate($event);
	$assert(! is_wp_error($banner) && $banner > 0, 'Initial promo generation failed.');
	$display = wp_insert_post(array('post_type' => 'mlyn_display', 'post_status' => 'publish', 'post_title' => 'MDS disposable banner presentation'));
	$posts[] = $display;
	$plugin = MDS\Plugin::instance();
	$items = $invoke($plugin, 'sanitize_items', array(
		array('id' => 'linked-event', 'type' => 'event', 'event_id' => $event, 'enabled' => true, 'duration' => 2),
		array('id' => 'static-image', 'type' => 'image', 'media_id' => $media[0], 'enabled' => true, 'duration' => 2),
	));
	update_post_meta($display, '_mds_items', $items);
	update_post_meta($display, '_mds_settings', array('poll_interval' => 5, 'image_duration' => 2));
	$active = $invoke($plugin, 'get_active_items', $display);
	$assert(count($active) === 2 && $active[0]['type'] === 'image' && $active[0]['url'] === wp_get_attachment_url($banner), 'Event slide did not resolve the promo banner.');
	$assert($active[1]['url'] === wp_get_attachment_url($media[0]), 'Static image changed.');
	$version = $invoke($plugin, 'get_runtime_version', $display, $active);
	$assert(strpos(velkymlyn_get_event_card_image($event), esc_url(wp_get_attachment_image_url($banner, 'medium_large'))) !== false, 'Theme card did not use promo.');
	$slide = $invoke(MFS\Plugin::instance(), 'resolve_slide', array('type' => 'post', 'post_id' => $event, 'enabled' => true, 'hide_after_event' => false));
	$assert($slide['image_url'] === wp_get_attachment_image_url($media[0], 'full'), 'Slider did not retain clean image.');
	$assert(get_the_post_thumbnail_url($event, 'full') === wp_get_attachment_image_url($media[0], 'full'), 'Featured image API was changed.');

	// A real organizer re-import changes generated inputs, but never admin-owned settings.
	$row['title'] = 'MDS disposable event updated';
	$row['content'] = '<p>Updated organizer description.</p>';
	$row['start_at'] = $start->modify('+1 hour')->format('Y-m-d H:i:s');
	$row['end_at'] = $start->modify('+3 hours')->format('Y-m-d H:i:s');
	$row['image_id'] = $media[1];
	$database->save_month($profile, $month, array($row), (int) $admins[0]);
	$result = $sync->import_profile($profile, $settings);
	$assert($result['updated'] === 1 && ! $result['errors'], 'Intake update failed.');
	$assert(get_post_meta($event, Promo::CONFIG, true) === $config, 'Intake overwrote admin promo settings.');
	$assert(mlyn_event_get_promo_image_id($event) === $media[1], 'Stale banner did not fall back to new featured image.');
	Promo::flush();
	$assert(wp_next_scheduled(Promo::JOB, array($event)), 'Intake did not schedule banner regeneration.');
	do_action(Promo::JOB, $event);
	$new_banner = mlyn_event_get_promo_banner_id($event);
	$assert($new_banner > 0 && $new_banner !== $banner, 'Queued generation did not create the updated banner.');
	$active = $invoke($plugin, 'get_active_items', $display);
	$assert($active[0]['url'] === wp_get_attachment_url($new_banner), 'Signage did not follow regeneration.');
	$assert($version !== $invoke($plugin, 'get_runtime_version', $display, $active), 'Banner regeneration did not change the playback manifest.');
	$assert($active[0]['id'] === 'linked-event', 'Event slide lost its stable ID.');

	foreach (array('ready', 'disabled') as $mode) {
		$config['mode'] = 'ready';
		$config['ready_image'] = $media[0];
		$config['enabled'] = 'ready' === $mode;
		update_post_meta($event, Promo::CONFIG, $config);
		$row['title'] .= ' ' . $mode;
		$database->save_month($profile, $month, array($row), (int) $admins[0]);
		$result = $sync->import_profile($profile, $settings);
		$assert(! $result['errors'] && get_post_meta($event, Promo::CONFIG, true) === $config, 'Intake changed ready/disabled config.');
		$expected = 'ready' === $mode ? $media[0] : $media[1];
		$active = $invoke($plugin, 'get_active_items', $display);
		$assert($active[0]['url'] === wp_get_attachment_url($expected), 'Signage ready/disabled routing failed.');
		$assert(strpos(velkymlyn_get_event_card_image($event), esc_url(wp_get_attachment_image_url($expected, 'medium_large'))) !== false, 'Theme ready/disabled routing failed.');
	}
	foreach (array('draft', 'private', 'trash') as $status) {
		wp_update_post(array('ID' => $event, 'post_status' => $status));
		$assert(count($invoke($plugin, 'get_active_items', $display)) === 1, 'Nonpublic event appeared in playback.');
	}
	wp_update_post(array('ID' => $event, 'post_status' => 'publish', 'post_password' => 'test-password'));
	$assert(count($invoke($plugin, 'get_active_items', $display)) === 1, 'Protected event appeared in playback.');
	wp_update_post(array('ID' => $event, 'post_password' => ''));
	$items[0]['ends_at'] = '2000-01-01T00:00';
	update_post_meta($display, '_mds_items', $items);
	$assert(count($invoke($plugin, 'get_active_items', $display)) === 1, 'Event scheduling ignored.');
	$items[0]['ends_at'] = '';
	update_post_meta($display, '_mds_items', $items);
	delete_post_thumbnail($event);
	$assert(count($invoke($plugin, 'get_active_items', $display)) === 1, 'Imageless event played.');
	$assert(strpos(velkymlyn_get_event_card_image($event), 'placeholder.jpg') !== false, 'Missing image did not use theme placeholder.');
	set_post_thumbnail($event, $media[1]);
	$config['enabled'] = true;
	$config['mode'] = 'generated';
	update_post_meta($event, Promo::CONFIG, $config);
	$assert(! is_wp_error(Promo::generate($event)), 'Restoring generated banner failed.');
	echo "MDS event / theme / slider / intake integration smoke test passed.\n";
	if (defined('MDS_KEEP_FIXTURES') && MDS_KEEP_FIXTURES) {
		echo wp_json_encode(compact('event', 'display', 'profile', 'posts', 'media')) . "\n";
	}
} finally {
	if (! defined('MDS_KEEP_FIXTURES') || ! MDS_KEEP_FIXTURES) {
		foreach ($posts as $id) {
			if ($id) {
				wp_clear_scheduled_hook(Promo::JOB, array($id));
				wp_delete_post($id, true);
			}
		}
		if ($profile) {
			$wpdb->delete($wpdb->prefix . 'mei_event_rows', array('profile_id' => $profile), array('%d'));
		}
		$generated = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_mlyn_promo_owner', 'meta_value' => $event));
		foreach (array_merge($media, $generated) as $id) {
			wp_delete_attachment($id, true);
		}
	}
}
