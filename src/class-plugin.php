<?php

namespace MDS;

use DateTimeImmutable;
use Exception;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private const POST_TYPE     = 'mlyn_display';
	private const QUERY_VAR     = 'mlyn_display';
	private const META_SETTINGS = '_mds_settings';
	private const META_ITEMS    = '_mds_items';
	private const META_VERSION  = '_mds_version';

	private static $instance;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate(): void {
		self::instance()->register_post_type();
		self::instance()->register_rewrite_rule();
		flush_rewrite_rules( false );
	}

	public static function deactivate(): void {
		flush_rewrite_rules( false );
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_rewrite_rule' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_display' ), 0 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_presentation' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'filter_title_placeholder' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'Digital Signage', 'mlyn-digital-signage' ),
					'singular_name' => __( 'Presentation', 'mlyn-digital-signage' ),
					'add_new'       => __( 'Add presentation', 'mlyn-digital-signage' ),
					'add_new_item'  => __( 'Add new presentation', 'mlyn-digital-signage' ),
					'edit_item'     => __( 'Edit presentation', 'mlyn-digital-signage' ),
					'new_item'      => __( 'New presentation', 'mlyn-digital-signage' ),
					'search_items'  => __( 'Search presentations', 'mlyn-digital-signage' ),
					'not_found'     => __( 'No presentations found.', 'mlyn-digital-signage' ),
					'menu_name'     => __( 'Digital Signage', 'mlyn-digital-signage' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'menu_icon'           => 'dashicons-desktop',
				'supports'            => array( 'title' ),
				'map_meta_cap'        => true,
			)
		);
	}

	public function register_rewrite_rule(): void {
		add_rewrite_rule( '^display/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function register_shortcode(): void {
		add_shortcode( 'mlyn_display', array( $this, 'render_shortcode' ) );
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'mds-settings',
			__( 'Presentation settings', 'mlyn-digital-signage' ),
			array( $this, 'render_settings_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'mds-items',
			__( 'Images and videos', 'mlyn-digital-signage' ),
			array( $this, 'render_items_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);
		add_meta_box(
			'mds-open',
			__( 'Screen URL', 'mlyn-digital-signage' ),
			array( $this, 'render_open_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
		add_meta_box(
			'mds-help',
			__( 'Screen setup help', 'mlyn-digital-signage' ),
			array( $this, 'render_help_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'mds-admin', plugins_url( 'assets/admin.css', MDS_FILE ), array(), MDS_VERSION );
		wp_enqueue_script( 'mds-admin', plugins_url( 'assets/admin.js', MDS_FILE ), array(), MDS_VERSION, true );
		wp_localize_script(
			'mds-admin',
			'mdsAdmin',
			array(
				'confirmRemove' => __( 'Remove this item?', 'mlyn-digital-signage' ),
				'imageTitle'    => __( 'Choose an image', 'mlyn-digital-signage' ),
				'videoTitle'    => __( 'Choose a video', 'mlyn-digital-signage' ),
				'bulkTitle'     => __( 'Choose images and videos', 'mlyn-digital-signage' ),
				'logoTitle'     => __( 'Choose a fallback logo', 'mlyn-digital-signage' ),
				'useMedia'      => __( 'Use this media', 'mlyn-digital-signage' ),
				'useSelected'   => __( 'Add selected media', 'mlyn-digital-signage' ),
				'untitled'      => __( 'No media selected', 'mlyn-digital-signage' ),
			)
		);
	}

	public function render_settings_meta_box( WP_Post $post ): void {
		$settings = $this->get_settings( $post->ID );
		wp_nonce_field( 'mds_save_presentation', 'mds_nonce' );
		?>
		<div class="mds-settings-grid">
			<label><span><?php esc_html_e( 'Background colour', 'mlyn-digital-signage' ); ?></span><input type="color" name="mds_settings[background_color]" value="<?php echo esc_attr( $settings['background_color'] ); ?>"></label>
			<label><span><?php esc_html_e( 'Default image duration (seconds)', 'mlyn-digital-signage' ); ?></span><input type="number" name="mds_settings[image_duration]" min="0.5" max="86400" step="0.5" value="<?php echo esc_attr( (string) $settings['image_duration'] ); ?>"></label>
			<label><span><?php esc_html_e( 'Check for updates every (seconds)', 'mlyn-digital-signage' ); ?></span><input type="number" name="mds_settings[poll_interval]" min="5" max="3600" step="5" value="<?php echo esc_attr( (string) $settings['poll_interval'] ); ?>"></label>
			<?php $this->render_logo_field( (int) $settings['logo_id'] ); ?>
		</div>
		<p class="description"><?php esc_html_e( 'All media uses “contain”: the complete image or video remains visible, and unused screen space uses the selected background colour.', 'mlyn-digital-signage' ); ?></p>
		<?php
	}

	private function render_logo_field( int $attachment_id ): void {
		$url = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
		?>
		<div class="mds-logo-field">
			<span><?php esc_html_e( 'Fallback logo', 'mlyn-digital-signage' ); ?></span>
			<input class="mds-logo-id" type="hidden" name="mds_settings[logo_id]" value="<?php echo esc_attr( (string) $attachment_id ); ?>">
			<div class="mds-logo-preview"><?php if ( $url ) : ?><img src="<?php echo esc_url( $url ); ?>" alt=""><?php endif; ?></div>
			<div><button type="button" class="button mds-choose-logo"><?php esc_html_e( 'Choose logo', 'mlyn-digital-signage' ); ?></button> <button type="button" class="button-link-delete mds-clear-logo" <?php disabled( ! $attachment_id ); ?>><?php esc_html_e( 'Clear', 'mlyn-digital-signage' ); ?></button></div>
		</div>
		<?php
	}

	public function render_items_meta_box( WP_Post $post ): void {
		$items = $this->get_items( $post->ID );
		?>
		<p><?php esc_html_e( 'Drag items or use the arrow buttons to define playback order. An empty image duration uses the presentation default; an empty video duration uses the video’s natural length.', 'mlyn-digital-signage' ); ?></p>
		<div id="mds-items" class="mds-items">
			<?php foreach ( $items as $index => $item ) : ?>
				<?php $this->render_item_editor( (string) $index, $item ); ?>
			<?php endforeach; ?>
		</div>
		<div class="mds-add-actions">
			<button type="button" class="button button-primary" id="mds-add-media-bulk"><?php esc_html_e( 'Add multiple media', 'mlyn-digital-signage' ); ?></button>
			<button type="button" class="button" id="mds-add-item"><?php esc_html_e( 'Add empty item', 'mlyn-digital-signage' ); ?></button>
		</div>
		<script type="text/html" id="mds-item-template"><?php $this->render_item_editor( '__INDEX__', $this->item_defaults() ); ?></script>
		<?php
	}

	private function render_item_editor( string $index, array $item ): void {
		$item       = wp_parse_args( $item, $this->item_defaults() );
		$attachment = $item['media_id'] ? get_post( $item['media_id'] ) : null;
		$title      = $attachment ? $attachment->post_title : __( 'No media selected', 'mlyn-digital-signage' );
		$url        = $attachment ? wp_get_attachment_url( $attachment->ID ) : '';
		$metadata   = $attachment ? wp_get_attachment_metadata( $attachment->ID ) : array();
		$natural    = is_array( $metadata ) && ! empty( $metadata['length_formatted'] ) ? $metadata['length_formatted'] : '';
		?>
		<article class="mds-item-editor" draggable="true" data-item-index="<?php echo esc_attr( $index ); ?>">
			<header class="mds-item-header">
				<button type="button" class="mds-drag-handle" aria-label="<?php esc_attr_e( 'Drag to reorder', 'mlyn-digital-signage' ); ?>"><span class="dashicons dashicons-move"></span></button>
				<strong class="mds-item-summary"><?php echo esc_html( $title ); ?></strong>
				<button type="button" class="button-link mds-move-up" aria-label="<?php esc_attr_e( 'Move up', 'mlyn-digital-signage' ); ?>"><span class="dashicons dashicons-arrow-up-alt2"></span></button>
				<button type="button" class="button-link mds-move-down" aria-label="<?php esc_attr_e( 'Move down', 'mlyn-digital-signage' ); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
				<label><input type="checkbox" name="mds_items[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $item['enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'mlyn-digital-signage' ); ?></label>
				<button type="button" class="button-link-delete mds-remove-item"><?php esc_html_e( 'Remove', 'mlyn-digital-signage' ); ?></button>
			</header>
			<input type="hidden" name="mds_items[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $item['id'] ); ?>">
			<div class="mds-item-fields">
				<label><span><?php esc_html_e( 'Media type', 'mlyn-digital-signage' ); ?></span><select class="mds-item-type" name="mds_items[<?php echo esc_attr( $index ); ?>][type]"><option value="image" <?php selected( $item['type'], 'image' ); ?>><?php esc_html_e( 'Image', 'mlyn-digital-signage' ); ?></option><option value="video" <?php selected( $item['type'], 'video' ); ?>><?php esc_html_e( 'Video', 'mlyn-digital-signage' ); ?></option></select></label>
				<div class="mds-media-field">
					<span><?php esc_html_e( 'Media', 'mlyn-digital-signage' ); ?></span>
					<input class="mds-media-id" type="hidden" name="mds_items[<?php echo esc_attr( $index ); ?>][media_id]" value="<?php echo esc_attr( (string) $item['media_id'] ); ?>">
					<div class="mds-media-preview" data-url="<?php echo esc_url( $url ); ?>"><?php if ( $url && 'image' === $item['type'] ) : ?><img src="<?php echo esc_url( $url ); ?>" alt=""><?php elseif ( $url ) : ?><code><?php echo esc_html( wp_basename( $url ) ); ?></code><?php endif; ?></div>
					<div><button type="button" class="button mds-choose-media"><?php esc_html_e( 'Choose', 'mlyn-digital-signage' ); ?></button> <button type="button" class="button-link-delete mds-clear-media" <?php disabled( ! $item['media_id'] ); ?>><?php esc_html_e( 'Clear', 'mlyn-digital-signage' ); ?></button></div>
				</div>
				<label><span><?php esc_html_e( 'Duration override (seconds)', 'mlyn-digital-signage' ); ?></span><input type="number" name="mds_items[<?php echo esc_attr( $index ); ?>][duration]" min="0.5" max="86400" step="0.5" value="<?php echo esc_attr( $item['duration'] ? (string) $item['duration'] : '' ); ?>"><small class="mds-duration-help"><?php echo 'video' === $item['type'] && $natural ? esc_html( sprintf( __( 'Natural video length: %s', 'mlyn-digital-signage' ), $natural ) ) : ''; ?></small></label>
				<label><span><?php esc_html_e( 'Show from', 'mlyn-digital-signage' ); ?></span><input type="datetime-local" name="mds_items[<?php echo esc_attr( $index ); ?>][starts_at]" value="<?php echo esc_attr( $item['starts_at'] ); ?>"></label>
				<label><span><?php esc_html_e( 'Show until', 'mlyn-digital-signage' ); ?></span><input type="datetime-local" name="mds_items[<?php echo esc_attr( $index ); ?>][ends_at]" value="<?php echo esc_attr( $item['ends_at'] ); ?>"></label>
			</div>
		</article>
		<?php
	}

	public function render_open_meta_box( WP_Post $post ): void {
		$url = $this->get_display_url( $post );
		?>
		<p><?php esc_html_e( 'Publish or update the presentation, then open this URL on the screen computer:', 'mlyn-digital-signage' ); ?></p>
		<input type="url" class="widefat" readonly value="<?php echo esc_url( $url ); ?>" onclick="this.select();">
		<p><a class="button button-primary" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open screen view', 'mlyn-digital-signage' ); ?></a></p>
		<p><?php esc_html_e( 'Optional embed:', 'mlyn-digital-signage' ); ?><br><code>[mlyn_display id="<?php echo esc_html( $post->post_name ); ?>"]</code></p>
		<?php
	}

	public function render_help_meta_box(): void {
		?>
		<p><strong><?php esc_html_e( 'Playback', 'mlyn-digital-signage' ); ?></strong></p>
		<ul class="mds-help-list">
			<li><?php esc_html_e( 'Images use their duration override or the default image duration.', 'mlyn-digital-signage' ); ?></li>
			<li><?php esc_html_e( 'Videos are muted. With no override they advance when the video ends; an override sets a maximum playback duration.', 'mlyn-digital-signage' ); ?></li>
			<li><?php esc_html_e( 'Changes are detected automatically and loaded after the current item finishes.', 'mlyn-digital-signage' ); ?></li>
			<li><?php esc_html_e( 'If no items are active, the configured logo is centred on the solid background.', 'mlyn-digital-signage' ); ?></li>
		</ul>
		<p><strong><?php esc_html_e( 'Windows screen computer', 'mlyn-digital-signage' ); ?></strong></p>
		<p><?php esc_html_e( 'Open the Screen URL in Microsoft Edge or Chrome kiosk mode. The page fills the viewport, but only browser kiosk mode removes browser tabs and controls.', 'mlyn-digital-signage' ); ?></p>
		<p><?php esc_html_e( 'Keyboard: Left/Right changes item, F toggles browser fullscreen, and R reloads.', 'mlyn-digital-signage' ); ?></p>
		<?php
	}

	public function save_presentation( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['mds_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mds_nonce'] ) ), 'mds_save_presentation' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw_settings = isset( $_POST['mds_settings'] ) && is_array( $_POST['mds_settings'] ) ? wp_unslash( $_POST['mds_settings'] ) : array();
		update_post_meta( $post_id, self::META_SETTINGS, $this->sanitize_settings( $raw_settings ) );

		$raw_items = isset( $_POST['mds_items'] ) && is_array( $_POST['mds_items'] ) ? wp_unslash( $_POST['mds_items'] ) : array();
		$items     = array();
		foreach ( $raw_items as $raw_item ) {
			if ( is_array( $raw_item ) ) {
				$items[] = $this->sanitize_item( $raw_item );
			}
		}
		update_post_meta( $post_id, self::META_ITEMS, $items );
		update_post_meta( $post_id, self::META_VERSION, wp_generate_uuid4() );
	}

	public function maybe_render_display(): void {
		$slug = get_query_var( self::QUERY_VAR );
		if ( ! $slug ) {
			return;
		}

		$display = get_page_by_path( sanitize_title( $slug ), OBJECT, self::POST_TYPE );
		if ( ! $display || 'publish' !== $display->post_status ) {
			status_header( 404 );
			nocache_headers();
			echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>' . esc_html__( 'Presentation not found', 'mlyn-digital-signage' ) . '</title></head><body></body></html>';
			exit;
		}

		$items   = $this->get_active_items( $display->ID );
		$version = $this->get_runtime_version( $display->ID, $items );
		if ( isset( $_GET['manifest'] ) ) {
			nocache_headers();
			wp_send_json( array( 'version' => $version ) );
		}

		nocache_headers();
		$this->render_player_document( $display, $items, $version );
		exit;
	}

	private function render_player_document( WP_Post $display, array $items, string $version ): void {
		$settings = $this->get_settings( $display->ID );
		$logo_url = $settings['logo_id'] ? wp_get_attachment_image_url( $settings['logo_id'], 'full' ) : '';
		$config    = array(
			'version'       => $version,
			'manifestUrl'   => add_query_arg( 'manifest', '1', $this->get_display_url( $display ) ),
			'pollInterval'  => (int) round( $settings['poll_interval'] * 1000 ),
			'imageDuration' => (int) round( $settings['image_duration'] * 1000 ),
			'errorDuration' => 10000,
		);
		?><!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
			<meta name="robots" content="noindex,nofollow,noarchive">
			<title><?php echo esc_html( $display->post_title ); ?></title>
			<link rel="stylesheet" href="<?php echo esc_url( plugins_url( 'assets/player.css', MDS_FILE ) . '?ver=' . MDS_VERSION ); ?>">
		</head>
		<body style="--mds-background:<?php echo esc_attr( $settings['background_color'] ); ?>">
			<main class="mds-player" aria-label="<?php echo esc_attr( $display->post_title ); ?>">
				<?php foreach ( $items as $index => $item ) : ?>
					<figure class="mds-player-item<?php echo 0 === $index ? ' is-active' : ''; ?>" data-item-id="<?php echo esc_attr( $item['id'] ); ?>" data-type="<?php echo esc_attr( $item['type'] ); ?>" data-duration="<?php echo esc_attr( $item['duration'] ? (string) (int) round( $item['duration'] * 1000 ) : '0' ); ?>" aria-hidden="<?php echo 0 === $index ? 'false' : 'true'; ?>"<?php echo 0 === $index ? '' : ' inert'; ?>>
						<?php if ( 'image' === $item['type'] ) : ?>
							<img src="<?php echo esc_url( $item['url'] ); ?>" alt="" draggable="false">
						<?php else : ?>
							<video src="<?php echo esc_url( $item['url'] ); ?>" muted playsinline preload="auto"></video>
						<?php endif; ?>
					</figure>
				<?php endforeach; ?>
				<div class="mds-empty<?php echo $items ? '' : ' is-active'; ?>" aria-hidden="<?php echo $items ? 'true' : 'false'; ?>">
					<?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $display->post_title ); ?>"><?php endif; ?>
				</div>
			</main>
			<script id="mds-player-config" type="application/json"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
			<script src="<?php echo esc_url( plugins_url( 'assets/player.js', MDS_FILE ) . '?ver=' . MDS_VERSION ); ?>"></script>
		</body>
		</html><?php
	}

	public function render_shortcode( $attributes ): string {
		$attributes = shortcode_atts( array( 'id' => '', 'height' => '100vh', 'class' => '' ), (array) $attributes, 'mlyn_display' );
		$display    = $this->find_display( $attributes['id'] );
		if ( ! $display || 'publish' !== $display->post_status ) {
			return '';
		}
		$height  = preg_match( '/^\d+(?:\.\d+)?(?:px|vh|vw|rem|em|%)$/', $attributes['height'] ) ? $attributes['height'] : '100vh';
		$classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', 'mlyn-display-embed ' . $attributes['class'] ) ) );
		return sprintf(
			'<iframe class="%s" src="%s" title="%s" style="display:block;width:100%%;height:%s;border:0" allow="autoplay; fullscreen" loading="lazy"></iframe>',
			esc_attr( implode( ' ', $classes ) ),
			esc_url( $this->get_display_url( $display ) ),
			esc_attr( $display->post_title ),
			esc_attr( $height )
		);
	}

	public function get_display_url( $id ): string {
		$display = $id instanceof WP_Post ? $id : $this->find_display( $id );
		$slug    = $display ? $display->post_name : sanitize_title( (string) $id );
		return home_url( user_trailingslashit( 'display/' . $slug ) );
	}

	private function find_display( $id ) {
		if ( is_numeric( $id ) ) {
			$post = get_post( (int) $id );
			return $post && self::POST_TYPE === $post->post_type ? $post : null;
		}
		return get_page_by_path( sanitize_title( (string) $id ), OBJECT, self::POST_TYPE );
	}

	private function get_settings( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_SETTINGS, true );
		return $this->sanitize_settings( wp_parse_args( is_array( $stored ) ? $stored : array(), $this->settings_defaults() ) );
	}

	private function settings_defaults(): array {
		return array(
			'background_color' => '#000000',
			'image_duration'   => 10,
			'poll_interval'    => 30,
			'logo_id'          => 0,
		);
	}

	private function sanitize_settings( array $settings ): array {
		$color = sanitize_hex_color( $settings['background_color'] ?? '' );
		return array(
			'background_color' => $color ?: '#000000',
			'image_duration'   => max( 0.5, min( 86400, (float) ( $settings['image_duration'] ?? 10 ) ) ),
			'poll_interval'    => max( 5, min( 3600, absint( $settings['poll_interval'] ?? 30 ) ) ),
			'logo_id'          => absint( $settings['logo_id'] ?? 0 ),
		);
	}

	private function get_items( int $post_id ): array {
		$items = get_post_meta( $post_id, self::META_ITEMS, true );
		return is_array( $items ) ? $items : array();
	}

	private function get_active_items( int $post_id ): array {
		$active = array();
		foreach ( $this->get_items( $post_id ) as $item ) {
			$item = wp_parse_args( $item, $this->item_defaults() );
			if ( ! $item['enabled'] || ! $this->is_in_schedule( $item ) ) {
				continue;
			}
			$url  = $item['media_id'] ? wp_get_attachment_url( $item['media_id'] ) : '';
			$mime = $item['media_id'] ? get_post_mime_type( $item['media_id'] ) : '';
			if ( ! $url || 0 !== strpos( (string) $mime, $item['type'] . '/' ) ) {
				continue;
			}
			$active[] = array(
				'id'       => $item['id'],
				'type'     => $item['type'],
				'url'      => $url,
				'duration' => $item['duration'],
			);
		}
		return $active;
	}

	private function sanitize_item( array $item ): array {
		$type = isset( $item['type'] ) && in_array( $item['type'], array( 'image', 'video' ), true ) ? $item['type'] : 'image';
		$id   = sanitize_key( $item['id'] ?? '' );
		return array(
			'id'        => $id ?: wp_generate_uuid4(),
			'enabled'   => ! empty( $item['enabled'] ),
			'type'      => $type,
			'media_id'  => absint( $item['media_id'] ?? 0 ),
			'duration'  => empty( $item['duration'] ) ? 0 : max( 0.5, min( 86400, (float) $item['duration'] ) ),
			'starts_at' => sanitize_text_field( $item['starts_at'] ?? '' ),
			'ends_at'   => sanitize_text_field( $item['ends_at'] ?? '' ),
		);
	}

	private function item_defaults(): array {
		return array(
			'id'        => wp_generate_uuid4(),
			'enabled'   => true,
			'type'      => 'image',
			'media_id'  => 0,
			'duration'  => 0,
			'starts_at' => '',
			'ends_at'   => '',
		);
	}

	private function is_in_schedule( array $item ): bool {
		$now = current_datetime()->getTimestamp();
		if ( $item['starts_at'] && $this->local_datetime_timestamp( $item['starts_at'] ) > $now ) {
			return false;
		}
		if ( $item['ends_at'] && $this->local_datetime_timestamp( $item['ends_at'] ) < $now ) {
			return false;
		}
		return true;
	}

	private function local_datetime_timestamp( string $value ): int {
		try {
			return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $exception ) {
			return 0;
		}
	}

	private function get_runtime_version( int $post_id, array $items ): string {
		$stored = (string) get_post_meta( $post_id, self::META_VERSION, true );
		if ( ! $stored ) {
			$stored = (string) get_post_modified_time( 'U', true, $post_id );
		}
		return hash( 'sha256', $stored . '|' . wp_json_encode( wp_list_pluck( $items, 'id' ) ) );
	}

	public function add_admin_columns( array $columns ): array {
		$columns['mds_url']   = __( 'Screen URL', 'mlyn-digital-signage' );
		$columns['mds_items'] = __( 'Items', 'mlyn-digital-signage' );
		return $columns;
	}

	public function render_admin_column( string $column, int $post_id ): void {
		if ( 'mds_url' === $column ) {
			echo '<a href="' . esc_url( $this->get_display_url( $post_id ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open screen', 'mlyn-digital-signage' ) . '</a>';
		}
		if ( 'mds_items' === $column ) {
			echo esc_html( (string) count( $this->get_items( $post_id ) ) );
		}
	}

	public function add_row_action( array $actions, WP_Post $post ): array {
		if ( self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
			$actions['mds_open'] = '<a href="' . esc_url( $this->get_display_url( $post ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open screen', 'mlyn-digital-signage' ) . '</a>';
		}
		return $actions;
	}

	public function filter_title_placeholder( string $placeholder, WP_Post $post ): string {
		return self::POST_TYPE === $post->post_type ? __( 'Presentation name', 'mlyn-digital-signage' ) : $placeholder;
	}
}
