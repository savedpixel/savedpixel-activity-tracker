<?php
/**
 * SavedPixel Activity Tracker plugin runtime.
 *
 * @package SavedPixelActivityTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SavedPixel_Activity_Tracker {

	const VERSION              = '1.0';
	const OPTION               = 'savedpixel_activity_tracker_settings';
	const PAGE_SLUG            = 'savedpixel-activity-tracker';
	const DEACTIVATE_PAGE_SLUG = 'savedpixel-activity-tracker-deactivate';
	const TABLE_SUFFIX         = 'savedpixel_activity_logs';
	const LEGACY_TABLE_SUFFIX  = 'at_activity_logs';
	const SCHEMA_OPTION        = 'savedpixel_activity_tracker_schema_version';
	const LOG_MIGRATION_OPTION = 'savedpixel_activity_tracker_legacy_log_file_migrated';
	const UNLOCK_META          = 'savedpixel_activity_tracker_guard_unlock';
	const PER_PAGE             = 25;

	private static $instance = null;

	private $log_dedupe = array();
	private $deleted_users = array();

	public static function bootstrap() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate() {
		$plugin = self::bootstrap();

		$plugin->ensure_settings();
		$plugin->install_schema();
		$plugin->migrate_legacy_logs_table();
		update_option( self::SCHEMA_OPTION, self::VERSION, false );
	}

	public static function deactivate() {
		self::bootstrap()->send_notification_email( 'plugin_deactivated' );
	}

	private function __construct() {
		add_action( 'init', array( $this, 'ensure_runtime_state' ), 1 );
		add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
		add_action( 'admin_menu', array( $this, 'maybe_hide_plugin_menus' ), 999 );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_savedpixel_activity_tracker_test_email', array( $this, 'ajax_test_email' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SAVEDPIXEL_ACTIVITY_TRACKER_FILE ), array( $this, 'filter_plugin_action_links' ) );

		$this->register_tracking_hooks();
	}

	public function ensure_runtime_state() {
		$this->ensure_settings();

		if ( self::VERSION !== get_option( self::SCHEMA_OPTION ) ) {
			$this->install_schema();
			$this->migrate_legacy_logs_table();
			update_option( self::SCHEMA_OPTION, self::VERSION, false );
		}
	}

	private function register_tracking_hooks() {
		add_action( 'save_post', array( $this, 'handle_post_save' ), 20, 3 );
		add_action( 'transition_post_status', array( $this, 'handle_post_status_transition' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'handle_post_delete' ), 20 );
		add_action( 'add_attachment', array( $this, 'handle_attachment_add' ), 20 );
		add_action( 'delete_attachment', array( $this, 'handle_attachment_delete' ), 20 );

		add_action( 'user_register', array( $this, 'handle_user_register' ), 20 );
		add_action( 'profile_update', array( $this, 'handle_profile_update' ), 20, 3 );
		add_action( 'delete_user', array( $this, 'capture_deleted_user' ), 20, 3 );
		add_action( 'deleted_user', array( $this, 'handle_deleted_user' ), 20, 3 );

		add_action( 'created_term', array( $this, 'handle_term_created' ), 20, 4 );
		add_action( 'edited_term', array( $this, 'handle_term_updated' ), 20, 4 );
		add_action( 'delete_term', array( $this, 'handle_term_deleted' ), 20, 5 );

		add_action( 'transition_comment_status', array( $this, 'handle_comment_status_transition' ), 20, 3 );
		add_action( 'delete_comment', array( $this, 'handle_comment_deleted' ), 20, 2 );

		add_action( 'activated_plugin', array( $this, 'handle_plugin_activated' ), 20, 2 );
		add_action( 'deactivated_plugin', array( $this, 'handle_plugin_deactivated' ), 20, 2 );
		add_action( 'deleted_plugin', array( $this, 'handle_plugin_deleted' ), 20, 2 );
		add_action( 'switch_theme', array( $this, 'handle_theme_switched' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'handle_upgrader_process_complete' ), 20, 2 );

		if ( $this->is_woocommerce_active() ) {
			add_action( 'woocommerce_new_order', array( $this, 'handle_new_order' ), 20, 2 );
			add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_changed' ), 20, 4 );
		}
	}

	private function ensure_settings() {
		$current  = get_option( self::OPTION, null );
		$settings = is_array( $current ) ? $current : array();
		$defaults = $this->default_settings();
		$changed  = ! is_array( $current );

		if ( empty( $settings ) ) {
			$settings = $this->migrate_legacy_settings();
			$changed  = true;
		}

		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
				$changed          = true;
			}
		}

		$settings['viewer_ids']                = $this->sanitize_viewer_ids( $settings['viewer_ids'] ?? array() );
		$settings['guard_password_hash']       = $this->sanitize_password_hash( $settings['guard_password_hash'] ?? '' );
		$settings['notification_email']        = sanitize_email( (string) ( $settings['notification_email'] ?? '' ) );
		$settings['deactivation_email_content'] = sanitize_textarea_field( (string) ( $settings['deactivation_email_content'] ?? '' ) );
		$settings['file_logging_enabled']      = empty( $settings['file_logging_enabled'] ) ? 0 : 1;
		$settings['log_file_format']           = $this->sanitize_log_file_format( $settings['log_file_format'] ?? 'csv' );
		$settings['hide_plugins_menu']         = empty( $settings['hide_plugins_menu'] ) ? 0 : 1;
		$settings['hide_plugin_editor']        = empty( $settings['hide_plugin_editor'] ) ? 0 : 1;

		if ( '' === $settings['deactivation_email_content'] ) {
			$settings['deactivation_email_content'] = $this->default_email_template();
			$changed                                = true;
		}

		if ( $changed ) {
			update_option( self::OPTION, $settings, false );
		}
	}

	private function settings() {
		$this->ensure_settings();

		return wp_parse_args( get_option( self::OPTION, array() ), $this->default_settings() );
	}

	private function default_settings() {
		return array(
			'viewer_ids'                 => array(),
			'guard_password_hash'        => '',
			'notification_email'         => get_option( 'admin_email', '' ),
			'deactivation_email_content' => $this->default_email_template(),
			'file_logging_enabled'       => 0,
			'log_file_format'            => 'csv',
			'hide_plugins_menu'          => 0,
			'hide_plugin_editor'         => 0,
		);
	}

	private function default_email_template() {
		return "SavedPixel Activity Tracker notification\n\nAction: {action}\nSite: {site_url}\nUser: {user}\nTime: {time}\n";
	}

	private function migrate_legacy_settings() {
		$viewer_ids = array_merge(
			(array) get_option( 'at_allowed_users', array() ),
			array_filter( array( (int) get_option( 'at_default_allowed_user', 0 ) ) )
		);

		$legacy_password = (string) get_option( 'at_password', '' );
		$file_format     = get_option( 'at_log_file_format', get_option( 'at_file_format', 'csv' ) );

		return array(
			'viewer_ids'                 => $viewer_ids,
			'guard_password_hash'        => '' !== $legacy_password ? wp_hash_password( $legacy_password ) : '',
			'notification_email'         => sanitize_email( (string) get_option( 'at_notification_email', get_option( 'admin_email', '' ) ) ),
			'deactivation_email_content' => sanitize_textarea_field( (string) get_option( 'at_deactivation_email_content', $this->default_email_template() ) ),
			'file_logging_enabled'       => empty( get_option( 'at_file_logging_enabled', 0 ) ) ? 0 : 1,
			'log_file_format'            => $this->sanitize_log_file_format( $file_format ),
			'hide_plugins_menu'          => empty( get_option( 'at_hide_plugins_menu', 0 ) ) ? 0 : 1,
			'hide_plugin_editor'         => empty( get_option( 'at_hide_edit_plugins_menu', 0 ) ) ? 0 : 1,
		);
	}

	private function sanitize_viewer_ids( $viewer_ids ) {
		$viewer_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $viewer_ids ) ) ) );
		if ( empty( $viewer_ids ) ) {
			return array();
		}

		$admin_ids = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'include' => $viewer_ids,
			)
		);

		return array_values( array_map( 'absint', (array) $admin_ids ) );
	}

	private function sanitize_password_hash( $hash ) {
		$hash = trim( (string) $hash );

		return '' !== $hash ? $hash : '';
	}

	private function sanitize_log_file_format( $format ) {
		$format = sanitize_key( (string) $format );

		return in_array( $format, array( 'csv', 'txt' ), true ) ? $format : 'csv';
	}

	private function is_guard_configured( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : $this->settings();

		return '' !== (string) $settings['guard_password_hash'];
	}

	private function is_settings_unlocked( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : $this->settings();

		if ( ! $this->is_guard_configured( $settings ) ) {
			return true;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$unlock = get_user_meta( $user_id, self::UNLOCK_META, true );
		if ( ! is_array( $unlock ) ) {
			return false;
		}

		$expires = isset( $unlock['expires'] ) ? (int) $unlock['expires'] : 0;
		if ( $expires < time() ) {
			delete_user_meta( $user_id, self::UNLOCK_META );
			return false;
		}

		return hash_equals( (string) $settings['guard_password_hash'], (string) ( $unlock['guard_password_hash'] ?? '' ) );
	}

	private function unlock_settings( $guard_hash ) {
		update_user_meta(
			get_current_user_id(),
			self::UNLOCK_META,
			array(
				'expires'             => time() + HOUR_IN_SECONDS,
				'guard_password_hash' => (string) $guard_hash,
			)
		);
	}

	private function clear_unlock_state() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			delete_user_meta( $user_id, self::UNLOCK_META );
		}
	}

	public function register_admin_pages() {
		$parent_slug = function_exists( 'savedpixel_admin_parent_slug' ) ? savedpixel_admin_parent_slug() : 'options-general.php';

		if ( current_user_can( 'manage_options' ) && $this->user_can_view_logs() ) {
			add_submenu_page(
				$parent_slug,
				'SavedPixel Activity Tracker',
				'Activity Tracker',
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_admin_page' ),
				14
			);
		}

		if ( current_user_can( 'manage_options' ) ) {
			add_submenu_page(
				'',
				'SavedPixel Activity Tracker Deactivate',
				'SavedPixel Activity Tracker Deactivate',
				'manage_options',
				self::DEACTIVATE_PAGE_SLUG,
				array( $this, 'render_deactivate_page' )
			);
		}
	}

	public function maybe_hide_plugin_menus() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings();

		if ( ! empty( $settings['hide_plugins_menu'] ) ) {
			remove_menu_page( 'plugins.php' );
		}

		if ( ! empty( $settings['hide_plugin_editor'] ) ) {
			remove_submenu_page( 'plugins.php', 'plugin-editor.php' );
		}
	}

	public function enqueue_admin_assets() {
		$page = $this->current_page();
		if ( ! in_array( $page, array( self::PAGE_SLUG, self::DEACTIVATE_PAGE_SLUG ), true ) ) {
			return;
		}

		if ( function_exists( 'savedpixel_admin_enqueue_preview_style' ) ) {
			savedpixel_admin_enqueue_preview_style( $page );
		}

		wp_enqueue_style(
			'savedpixel-activity-tracker-admin',
			SAVEDPIXEL_ACTIVITY_TRACKER_URL . 'assets/css/admin.css',
			array( 'savedpixel-admin-preview' ),
			self::VERSION
		);

		wp_enqueue_script(
			'savedpixel-activity-tracker-admin',
			SAVEDPIXEL_ACTIVITY_TRACKER_URL . 'assets/js/admin.js',
			array(),
			self::VERSION,
			true
		);

		wp_localize_script(
			'savedpixel-activity-tracker-admin',
			'savedpixelActivityTracker',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'testEmailNonce' => wp_create_nonce( 'savedpixel_activity_tracker_test_email' ),
				'detailsLabel'   => __( 'Details', 'savedpixel-activity-tracker' ),
				'hideLabel'      => __( 'Hide', 'savedpixel-activity-tracker' ),
			)
		);
	}

	public function handle_admin_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page           = $this->current_page();
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtolower( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'get';

		if ( 'post' !== $request_method ) {
			return;
		}

		if ( self::PAGE_SLUG === $page && isset( $_POST['spat_unlock_settings'] ) ) {
			$this->handle_unlock_request();
		}

		if ( self::PAGE_SLUG === $page && isset( $_POST['spat_save_settings'] ) ) {
			$this->handle_save_settings_request();
		}

		if ( self::DEACTIVATE_PAGE_SLUG === $page && isset( $_POST['spat_deactivate_plugin'] ) ) {
			$this->handle_deactivate_request();
		}
	}

	private function handle_unlock_request() {
		if ( ! $this->user_can_view_logs() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'savedpixel-activity-tracker' ) );
		}

		check_admin_referer( 'spat_unlock_settings' );

		$settings = $this->settings();
		$password = isset( $_POST['spat_guard_password_unlock'] ) ? (string) wp_unslash( $_POST['spat_guard_password_unlock'] ) : '';

		if ( ! $this->verify_guard_password( $password, $settings ) ) {
			wp_safe_redirect( $this->main_page_url( array( 'spat_notice' => 'unlock-failed' ) ) );
			exit;
		}

		$this->unlock_settings( $settings['guard_password_hash'] );
		wp_safe_redirect( $this->main_page_url( array( 'spat_notice' => 'unlocked' ) ) );
		exit;
	}

	private function handle_save_settings_request() {
		if ( ! $this->user_can_view_logs() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'savedpixel-activity-tracker' ) );
		}

		$settings = $this->settings();
		if ( $this->is_guard_configured( $settings ) && ! $this->is_settings_unlocked( $settings ) ) {
			wp_safe_redirect( $this->main_page_url( array( 'spat_notice' => 'locked' ) ) );
			exit;
		}

		check_admin_referer( 'spat_save_settings' );

		$current_user_id = get_current_user_id();
		$viewer_ids      = isset( $_POST['spat_viewer_ids'] ) ? (array) wp_unslash( $_POST['spat_viewer_ids'] ) : array();
		$viewer_ids      = $this->sanitize_viewer_ids( $viewer_ids );
		if ( ! empty( $viewer_ids ) && ! in_array( $current_user_id, $viewer_ids, true ) ) {
			$viewer_ids[] = $current_user_id;
			$viewer_ids   = $this->sanitize_viewer_ids( $viewer_ids );
		}

		$new_password         = isset( $_POST['spat_guard_password'] ) ? (string) wp_unslash( $_POST['spat_guard_password'] ) : '';
		$confirm_new_password = isset( $_POST['spat_guard_password_confirm'] ) ? (string) wp_unslash( $_POST['spat_guard_password_confirm'] ) : '';
		$password_hash        = (string) $settings['guard_password_hash'];

		if ( ! $this->is_guard_configured( $settings ) && '' === trim( $new_password ) ) {
			wp_safe_redirect( $this->main_page_url( array( 'spat_notice' => 'guard-required' ) ) );
			exit;
		}

		if ( '' !== trim( $new_password ) || '' !== trim( $confirm_new_password ) ) {
			if ( $new_password !== $confirm_new_password ) {
				wp_safe_redirect( $this->main_page_url( array( 'spat_notice' => 'guard-mismatch' ) ) );
				exit;
			}

			if ( strlen( $new_password ) < 8 ) {
				wp_safe_redirect( $this->main_page_url( array( 'spat_notice' => 'guard-too-short' ) ) );
				exit;
			}

			$password_hash = wp_hash_password( $new_password );
		}

		$updated = array(
			'viewer_ids'                 => $viewer_ids,
			'guard_password_hash'        => $password_hash,
			'notification_email'         => sanitize_email( (string) wp_unslash( $_POST['spat_notification_email'] ?? '' ) ),
			'deactivation_email_content' => sanitize_textarea_field( (string) wp_unslash( $_POST['spat_deactivation_email_content'] ?? '' ) ),
			'file_logging_enabled'       => empty( $_POST['spat_file_logging_enabled'] ) ? 0 : 1,
			'log_file_format'            => $this->sanitize_log_file_format( wp_unslash( $_POST['spat_log_file_format'] ?? 'csv' ) ),
			'hide_plugins_menu'          => empty( $_POST['spat_hide_plugins_menu'] ) ? 0 : 1,
			'hide_plugin_editor'         => empty( $_POST['spat_hide_plugin_editor'] ) ? 0 : 1,
		);

		update_option( self::OPTION, $updated, false );
		$this->unlock_settings( $updated['guard_password_hash'] );

		wp_safe_redirect( $this->main_page_url( array( 'spat_notice' => 'saved' ) ) );
		exit;
	}

	private function handle_deactivate_request() {
		check_admin_referer( 'spat_deactivate_plugin' );

		$settings = $this->settings();
		$password = isset( $_POST['spat_guard_password'] ) ? (string) wp_unslash( $_POST['spat_guard_password'] ) : '';

		if ( $this->is_guard_configured( $settings ) && ! $this->verify_guard_password( $password, $settings ) ) {
			wp_safe_redirect( $this->deactivate_page_url( array( 'spat_notice' => 'deactivate-failed' ) ) );
			exit;
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( plugin_basename( SAVEDPIXEL_ACTIVITY_TRACKER_FILE ) );
		wp_safe_redirect( admin_url( 'plugins.php?deactivate=true' ) );
		exit;
	}

	public function filter_plugin_action_links( $actions ) {
		$settings = $this->settings();
		if ( empty( $actions['deactivate'] ) || ! $this->is_guard_configured( $settings ) ) {
			return $actions;
		}

		$actions['deactivate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->deactivate_page_url() ),
			esc_html__( 'Deactivate', 'savedpixel-activity-tracker' )
		);

		return $actions;
	}

	public function ajax_test_email() {
		check_ajax_referer( 'savedpixel_activity_tracker_test_email', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) || ! $this->user_can_view_logs() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to send a test email.', 'savedpixel-activity-tracker' ),
				),
				403
			);
		}

		$settings = $this->settings();
		if ( $this->is_guard_configured( $settings ) && ! $this->is_settings_unlocked( $settings ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unlock settings before sending a test email.', 'savedpixel-activity-tracker' ),
				),
				403
			);
		}

		$email   = sanitize_email( (string) wp_unslash( $_POST['notification_email'] ?? '' ) );
		$content = sanitize_textarea_field( (string) wp_unslash( $_POST['email_content'] ?? '' ) );

		if ( '' === $email ) {
			wp_send_json_error(
				array(
					'message' => __( 'Enter a notification email first.', 'savedpixel-activity-tracker' ),
				),
				400
			);
		}

		$subject = 'SavedPixel Activity Tracker Test Email';
		$message = $this->replace_email_tokens(
			'' !== $content ? $content : $this->default_email_template(),
			'test_email'
		);

		if ( ! wp_mail( $email, $subject, $message ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'WordPress could not send the test email.', 'savedpixel-activity-tracker' ),
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: notification email address. */
					__( 'Test email sent to %s.', 'savedpixel-activity-tracker' ),
					$email
				),
			)
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->user_can_view_logs() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'savedpixel-activity-tracker' ) );
		}

		$settings       = $this->settings();
		$unlocked       = $this->is_settings_unlocked( $settings );
		$filters        = $this->current_filters();
		$logs           = $this->query_logs( $filters );
		$summary        = $this->query_summary();
		$actor_options  = $this->distinct_values( 'user_login' );
		$action_options = $this->distinct_values( 'action_type' );
		$object_options = $this->distinct_values( 'object_type' );
		$admins         = $this->admin_users();
		$notice         = isset( $_GET['spat_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['spat_notice'] ) ) : '';
		?>
		<?php savedpixel_admin_page_start( 'spat-page' ); ?>
			<header id="spat-header" class="sp-page-header">
				<div id="spat-header-main">
					<h1 id="spat-header-title" class="sp-page-title">SavedPixel Activity Tracker</h1>
					<p id="spat-header-desc" class="sp-page-desc">Review high-privilege WordPress activity, filter recent audit entries, and manage the SavedPixel guard controls from one page.</p>
				</div>
				<div id="spat-header-actions" class="sp-header-actions">
					<a id="spat-back-link" class="button" href="<?php echo esc_url( savedpixel_admin_page_url( savedpixel_admin_parent_slug() ) ); ?>">Back to Overview</a>
				</div>
			</header>

			<div id="spat-intro-note" class="sp-note">
				<p>SavedPixel Activity Tracker records administrator actions only. Leave the allowed-viewer list empty if every administrator should be able to review the log.</p>
			</div>

			<?php $this->render_notice( $notice ); ?>

			<div id="spat-summary" class="sp-summary">
				<div id="spat-summary-total" class="sp-summary-card sp-summary--total">
					<span class="sp-summary-num"><?php echo (int) $summary['total']; ?></span>
					<span class="sp-summary-label">Total Events</span>
				</div>
				<div id="spat-summary-today" class="sp-summary-card sp-summary--total">
					<span class="sp-summary-num"><?php echo (int) $summary['today']; ?></span>
					<span class="sp-summary-label">Today</span>
				</div>
				<div id="spat-summary-admins" class="sp-summary-card sp-summary--total">
					<span class="sp-summary-num"><?php echo (int) $summary['actors']; ?></span>
					<span class="sp-summary-label">Tracked Admins</span>
				</div>
				<div id="spat-summary-file" class="sp-summary-card <?php echo ! empty( $settings['file_logging_enabled'] ) ? 'sp-summary--success' : 'sp-summary--warning'; ?>">
					<span class="sp-summary-num"><?php echo esc_html( ! empty( $settings['file_logging_enabled'] ) ? strtoupper( $settings['log_file_format'] ) : 'Off' ); ?></span>
					<span class="sp-summary-label">File Logging</span>
				</div>
			</div>

			<div id="spat-content" class="sp-stack">
				<section id="spat-log-section">
					<div id="spat-log-header" class="sp-card__header">
						<div id="spat-log-header-main">
							<h2 id="spat-log-title" class="sp-card__title">Activity History</h2>
							<p id="spat-log-desc" class="sp-card__desc">Newest first, with server-side filtering for the current page only.</p>
						</div>
						<span id="spat-log-count"><?php echo esc_html( (int) $logs['total'] . ' items' ); ?></span>
					</div>
					<div id="spat-log-filters" class="sp-note sp-note--section-gap">
						<form id="spat-filter-form" class="spat-filter-form" method="get">
							<input type="hidden" id="spat-filter-page" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
							<div id="spat-filter-field-actor" class="spat-filter-field">
								<label id="spat-label-actor" class="sp-field__help" for="spat-actor-filter">Actor</label>
								<select id="spat-actor-filter" name="spat_actor">
									<option id="spat-actor-opt-all" value="">All actors</option>
									<?php foreach ( $actor_options as $actor_login ) : ?>
										<option id="spat-actor-opt-<?php echo esc_attr( $actor_login ); ?>" value="<?php echo esc_attr( $actor_login ); ?>" <?php selected( $filters['actor'], $actor_login ); ?>>
											<?php echo esc_html( $actor_login ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div id="spat-filter-field-action" class="spat-filter-field">
								<label id="spat-label-action" class="sp-field__help" for="spat-action-filter">Action</label>
								<select id="spat-action-filter" name="spat_action">
									<option id="spat-action-opt-all" value="">All actions</option>
									<?php foreach ( $action_options as $action_type ) : ?>
										<option id="spat-action-opt-<?php echo esc_attr( $action_type ); ?>" value="<?php echo esc_attr( $action_type ); ?>" <?php selected( $filters['action'], $action_type ); ?>>
											<?php echo esc_html( $this->human_action_label( $action_type ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div id="spat-filter-field-object" class="spat-filter-field">
								<label id="spat-label-object" class="sp-field__help" for="spat-object-filter">Object</label>
								<select id="spat-object-filter" name="spat_object">
									<option id="spat-object-opt-all" value="">All objects</option>
									<?php foreach ( $object_options as $object_type ) : ?>
										<option id="spat-object-opt-<?php echo esc_attr( $object_type ); ?>" value="<?php echo esc_attr( $object_type ); ?>" <?php selected( $filters['object'], $object_type ); ?>>
											<?php echo esc_html( $this->human_object_label( $object_type ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div id="spat-filter-actions" class="spat-filter-actions">
								<button id="spat-filter-btn" type="submit" class="button button-primary">Filter</button>
								<a id="spat-filter-reset" class="button" href="<?php echo esc_url( $this->main_page_url() ); ?>">Reset</a>
							</div>
						</form>
					</div>
					<div id="spat-log-card" class="sp-card">
						<div id="spat-log-body" class="sp-card__body sp-card__body--flush">
							<?php if ( empty( $logs['rows'] ) ) : ?>
								<div id="spat-empty" class="sp-empty">
									<h2>No activity recorded yet</h2>
									<p>Run an administrative action such as editing a post, activating a plugin, or switching a theme to start the audit trail.</p>
								</div>
							<?php else : ?>
								<div id="spat-log-table-wrap" class="sp-table-wrap">
									<table id="spat-log-table" class="sp-table">
										<thead>
											<tr>
												<th scope="col">Time</th>
												<th scope="col">Actor</th>
												<th scope="col">Role</th>
												<th scope="col">Action</th>
												<th scope="col">Object</th>
												<th scope="col">Plugin</th>
												<th scope="col" class="sp-th-actions">Actions</th>
											</tr>
										</thead>
										<tbody id="spat-log-tbody">
											<?php foreach ( $logs['rows'] as $row ) : ?>
												<?php
												$log_id   = (int) $row['id'];
												$context  = $this->decode_context( $row['context'] );
												$object   = $this->row_object_summary( $row );
												$user_row = trim( (string) $row['user_login'] );
												$plugin_slug  = '';
												if ( ! empty( $context['plugin'] ) ) {
													$plugin_slug = dirname( (string) $context['plugin'] );
													if ( '.' === $plugin_slug ) {
														$plugin_slug = basename( (string) $context['plugin'], '.php' );
													}
												}
												?>
												<tr id="spat-log-row-<?php echo esc_attr( $log_id ); ?>">
													<td id="spat-cell-time-<?php echo esc_attr( $log_id ); ?>"><?php echo esc_html( $row['action_time'] ); ?></td>
													<td id="spat-cell-actor-<?php echo esc_attr( $log_id ); ?>">
														<div class="spat-cell-stack">
															<strong><?php echo esc_html( '' !== $user_row ? $user_row : 'Unknown user' ); ?></strong>
														</div>
													</td>
													<td id="spat-cell-role-<?php echo esc_attr( $log_id ); ?>"><?php echo esc_html( '' !== (string) $row['user_role'] ? (string) $row['user_role'] : '—' ); ?></td>
													<td id="spat-cell-action-<?php echo esc_attr( $log_id ); ?>">
														<span class="sp-badge sp-badge--neutral"><?php echo esc_html( $this->human_action_label( $row['action_type'] ) ); ?></span>
													</td>
													<td id="spat-cell-object-<?php echo esc_attr( $log_id ); ?>">
														<div class="spat-cell-stack">
															<strong><?php echo esc_html( $object['label'] ); ?></strong>
															<?php if ( '' !== $object['meta'] ) : ?>
																<span class="description"><?php echo esc_html( $object['meta'] ); ?></span>
															<?php endif; ?>
														</div>
													</td>
													<td id="spat-cell-plugin-<?php echo esc_attr( $log_id ); ?>"><?php echo esc_html( $plugin_slug ); ?></td>
													<td id="spat-cell-actions-<?php echo esc_attr( $log_id ); ?>" class="sp-td-actions">
														<div class="sp-actions">
															<button
																type="button"
																class="sp-btn sp-btn--ghost spat-toggle-details"
																data-target="spat-log-detail-<?php echo esc_attr( $log_id ); ?>"
																aria-expanded="false"
															>Details</button>
														</div>
													</td>
												</tr>
												<tr id="spat-log-detail-<?php echo esc_attr( $log_id ); ?>" class="spat-log-detail-row" hidden>
													<td colspan="7">
														<div class="spat-log-detail-body">
															<div class="spat-detail-grid">
																<div class="spat-detail-meta">
																	<strong>Object type</strong>
																	<span><?php echo esc_html( $this->human_object_label( $row['object_type'] ) ); ?></span>
																</div>
																<div class="spat-detail-meta">
																	<strong>Object ID</strong>
																	<span><?php echo ! empty( $row['object_id'] ) ? (int) $row['object_id'] : '—'; ?></span>
																</div>
																<div class="spat-detail-meta">
																	<strong>Context</strong>
																	<span><?php echo esc_html( $this->context_summary( $context ) ); ?></span>
																</div>
															</div>
																<?php $context_json = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); ?>
																<pre class="sp-code-block"><code><?php echo esc_html( false !== $context_json ? $context_json : '{}' ); ?></code></pre>
														</div>
													</td>
												</tr>
											<?php endforeach; ?>
											</tbody>
										</table>
									</div>
							<?php endif; ?>
						</div>
					</div>
				</section>

				<section id="spat-settings-section">
					<div id="spat-settings-header" class="sp-card__header">
						<div id="spat-settings-header-main">
							<h2 id="spat-settings-title" class="sp-card__title">Guard & Settings</h2>
							<p id="spat-settings-desc" class="sp-card__desc">Only allowlisted administrators can review logs. The settings cards stay locked behind the guard password.</p>
						</div>
					</div>

					<?php if ( $this->is_guard_configured( $settings ) && ! $unlocked ) : ?>
						<div id="spat-settings-lock-card" class="sp-card">
							<div class="sp-card__body">
								<h2>Unlock Settings</h2>
								<p class="sp-card__desc">Enter the guard password to update viewers, notifications, file logging, and deactivation protection.</p>
								<form method="post" class="spat-unlock-form">
									<?php wp_nonce_field( 'spat_unlock_settings' ); ?>
									<table class="form-table sp-form-table">
										<tr>
											<th><label for="spat-guard-password-unlock">Guard password</label></th>
											<td>
												<input type="password" class="regular-text" id="spat-guard-password-unlock" name="spat_guard_password_unlock" autocomplete="current-password">
											</td>
										</tr>
									</table>
									<p class="submit">
										<button type="submit" name="spat_unlock_settings" class="button button-primary">Unlock Settings</button>
									</p>
								</form>
							</div>
						</div>
					<?php else : ?>
						<?php if ( ! $this->is_guard_configured( $settings ) ) : ?>
							<div id="spat-settings-guard-note" class="sp-note sp-note--section-gap">
								<p>Set a guard password now. The plugin only protects deactivation and settings access after the first guard password is saved.</p>
							</div>
						<?php endif; ?>

						<form id="spat-settings-form" class="sp-stack" method="post">
							<?php wp_nonce_field( 'spat_save_settings' ); ?>

							<div id="spat-access-card" class="sp-card">
								<div id="spat-access-body" class="sp-card__body">
									<h2 id="spat-access-title">Access Control</h2>
									<table id="spat-access-table" class="form-table sp-form-table">
										<tr id="spat-row-viewers">
											<th id="spat-label-viewers">Allowed viewers</th>
											<td id="spat-field-viewers">
												<div class="spat-allowlist">
													<?php foreach ( $admins as $admin ) : ?>
														<?php $is_checked = in_array( $admin->ID, $settings['viewer_ids'], true ); ?>
														<label class="spat-allowlist__item">
															<input type="checkbox" name="spat_viewer_ids[]" value="<?php echo esc_attr( $admin->ID ); ?>" <?php checked( $is_checked ); ?>>
															<span>
																<strong><?php echo esc_html( $admin->display_name ); ?></strong>
															</span>
														</label>
													<?php endforeach; ?>
												</div>
												<p class="description">Leave every checkbox clear to allow all administrators. The current administrator is kept in the allowlist when you save.</p>
											</td>
										</tr>
										<tr id="spat-row-guard-password">
											<th id="spat-label-guard-password"><label for="spat-guard-password">Guard password</label></th>
											<td id="spat-field-guard-password">
												<input type="password" class="regular-text" id="spat-guard-password" name="spat_guard_password" autocomplete="new-password">
												<p class="description"><?php echo $this->is_guard_configured( $settings ) ? esc_html__( 'Leave blank to keep the current guard password.', 'savedpixel-activity-tracker' ) : esc_html__( 'Required before the plugin can lock settings or protect deactivation.', 'savedpixel-activity-tracker' ); ?></p>
											</td>
										</tr>
										<tr id="spat-row-guard-confirm">
											<th id="spat-label-guard-confirm"><label for="spat-guard-password-confirm">Confirm guard password</label></th>
											<td id="spat-field-guard-confirm">
												<input type="password" class="regular-text" id="spat-guard-password-confirm" name="spat_guard_password_confirm" autocomplete="new-password">
											</td>
										</tr>
									</table>
								</div>
							</div>

							<div id="spat-email-card" class="sp-card">
								<div id="spat-email-body" class="sp-card__body">
									<h2 id="spat-email-title">Notifications & Logging</h2>
									<table id="spat-email-table" class="form-table sp-form-table">
										<tr id="spat-row-notification-email">
											<th id="spat-label-notification-email"><label for="spat-notification-email">Notification email</label></th>
											<td id="spat-field-notification-email">
												<input type="email" class="regular-text" id="spat-notification-email" name="spat_notification_email" value="<?php echo esc_attr( $settings['notification_email'] ); ?>">
												<p class="description">SavedPixel sends the deactivation notification to this address.</p>
											</td>
										</tr>
										<tr id="spat-row-email-content">
											<th id="spat-label-email-content"><label for="spat-deactivation-email-content">Deactivation email content</label></th>
											<td id="spat-field-email-content">
												<textarea id="spat-deactivation-email-content" class="large-text code" rows="8" name="spat_deactivation_email_content"><?php echo esc_textarea( $settings['deactivation_email_content'] ); ?></textarea>
												<p class="description">Available tokens: <code>{action}</code>, <code>{site_url}</code>, <code>{user}</code>, <code>{time}</code>.</p>
												<p class="sp-inline-actions">
													<button type="button" id="spat-test-email" class="button">Send Test Email</button>
													<span id="spat-test-email-status" class="sp-status-text" aria-live="polite"></span>
												</p>
											</td>
										</tr>
										<tr id="spat-row-file-logging">
											<th id="spat-label-file-logging"><label for="spat-file-logging-enabled">File logging</label></th>
											<td id="spat-field-file-logging">
												<label>
													<input type="checkbox" id="spat-file-logging-enabled" name="spat_file_logging_enabled" value="1" <?php checked( ! empty( $settings['file_logging_enabled'] ) ); ?>>
													Write each audit entry to <code><?php echo esc_html( wp_normalize_path( $this->log_directory() ) ); ?></code>.
												</label>
											</td>
										</tr>
										<tr id="spat-row-log-format">
											<th id="spat-label-log-format"><label for="spat-log-file-format">Log file format</label></th>
											<td id="spat-field-log-format">
												<select id="spat-log-file-format" name="spat_log_file_format">
													<option value="csv" <?php selected( 'csv', $settings['log_file_format'] ); ?>>CSV</option>
													<option value="txt" <?php selected( 'txt', $settings['log_file_format'] ); ?>>TXT</option>
												</select>
											</td>
										</tr>
									</table>
								</div>
							</div>

							<div id="spat-admin-card" class="sp-card">
								<div id="spat-admin-body" class="sp-card__body">
									<h2 id="spat-admin-title">Admin Lockdown</h2>
									<table id="spat-admin-table" class="form-table sp-form-table">
										<tr id="spat-row-hide-plugins">
											<th id="spat-label-hide-plugins"><label for="spat-hide-plugins-menu">Hide Plugins menu</label></th>
											<td id="spat-field-hide-plugins">
												<label>
													<input type="checkbox" id="spat-hide-plugins-menu" name="spat_hide_plugins_menu" value="1" <?php checked( ! empty( $settings['hide_plugins_menu'] ) ); ?>>
													Remove the main <code>Plugins</code> menu from wp-admin for administrators.
												</label>
											</td>
										</tr>
										<tr id="spat-row-hide-editor">
											<th id="spat-label-hide-editor"><label for="spat-hide-plugin-editor">Hide Plugin Editor</label></th>
											<td id="spat-field-hide-editor">
												<label>
													<input type="checkbox" id="spat-hide-plugin-editor" name="spat_hide_plugin_editor" value="1" <?php checked( ! empty( $settings['hide_plugin_editor'] ) ); ?>>
													Remove the built-in plugin file editor submenu.
												</label>
											</td>
										</tr>
									</table>
									<p id="spat-submit-row" class="submit">
										<button type="submit" name="spat_save_settings" class="button button-primary">Save Settings</button>
									</p>
								</div>
							</div>
						</form>
					<?php endif; ?>
				</section>
			</div>
		<?php
		savedpixel_admin_page_end();
	}

	public function render_deactivate_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'savedpixel-activity-tracker' ) );
		}

		$settings = $this->settings();
		$notice   = isset( $_GET['spat_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['spat_notice'] ) ) : '';
		?>
		<?php savedpixel_admin_page_start( 'spat-deactivate-page' ); ?>
			<header id="spat-deactivate-header" class="sp-page-header">
				<div id="spat-deactivate-header-main">
					<h1 id="spat-deactivate-title" class="sp-page-title">SavedPixel Activity Tracker</h1>
					<p id="spat-deactivate-desc" class="sp-page-desc">Confirm plugin deactivation with the SavedPixel guard password before WordPress disables the tracker.</p>
				</div>
				<div id="spat-deactivate-header-actions" class="sp-header-actions">
					<a class="button" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Back to Plugins</a>
				</div>
			</header>

			<div id="spat-deactivate-note" class="sp-note">
				<p>Deactivating this plugin stops new audit logging immediately. Existing database records and file logs are kept intact.</p>
			</div>

			<?php $this->render_notice( $notice ); ?>

			<div id="spat-deactivate-card" class="sp-card">
				<div class="sp-card__body">
					<h2>Deactivate Plugin</h2>
					<form method="post">
						<?php wp_nonce_field( 'spat_deactivate_plugin' ); ?>
						<table class="form-table sp-form-table">
							<?php if ( $this->is_guard_configured( $settings ) ) : ?>
								<tr>
									<th><label for="spat-deactivate-guard-password">Guard password</label></th>
									<td>
										<input type="password" class="regular-text" id="spat-deactivate-guard-password" name="spat_guard_password" autocomplete="current-password">
										<p class="description">The plugin only accepts deactivation after the current guard password is verified on the server.</p>
									</td>
								</tr>
							<?php else : ?>
								<tr>
									<th>Guard status</th>
									<td>
										<p class="description">No guard password is configured yet. You can deactivate immediately, or return to the main Activity Tracker page and set a guard password first.</p>
									</td>
								</tr>
							<?php endif; ?>
						</table>
						<p class="submit">
							<button type="submit" name="spat_deactivate_plugin" class="button button-primary">Deactivate Plugin</button>
						</p>
					</form>
				</div>
			</div>
		<?php
		savedpixel_admin_page_end();
	}

	private function render_notice( $notice ) {
		$notice = sanitize_key( (string) $notice );
		if ( '' === $notice ) {
			return;
		}

		$type    = 'success';
		$message = '';

		switch ( $notice ) {
			case 'saved':
				$message = __( 'Settings saved.', 'savedpixel-activity-tracker' );
				break;
			case 'unlocked':
				$message = __( 'Settings unlocked.', 'savedpixel-activity-tracker' );
				break;
			case 'unlock-failed':
				$type    = 'error';
				$message = __( 'The guard password was incorrect.', 'savedpixel-activity-tracker' );
				break;
			case 'locked':
				$type    = 'error';
				$message = __( 'Unlock settings before saving changes.', 'savedpixel-activity-tracker' );
				break;
			case 'guard-required':
				$type    = 'error';
				$message = __( 'Set a guard password before saving settings for the first time.', 'savedpixel-activity-tracker' );
				break;
			case 'guard-mismatch':
				$type    = 'error';
				$message = __( 'The new guard password confirmation did not match.', 'savedpixel-activity-tracker' );
				break;
			case 'guard-too-short':
				$type    = 'error';
				$message = __( 'The guard password must be at least 8 characters long.', 'savedpixel-activity-tracker' );
				break;
			case 'deactivate-failed':
				$type    = 'error';
				$message = __( 'Deactivation blocked because the guard password was incorrect.', 'savedpixel-activity-tracker' );
				break;
		}

		if ( '' === $message ) {
			return;
		}
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	private function current_page() {
		if ( function_exists( 'savedpixel_current_admin_page' ) ) {
			return savedpixel_current_admin_page();
		}

		return isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
	}

	private function main_page_url( $args = array() ) {
		$url = function_exists( 'savedpixel_admin_page_url' ) ? savedpixel_admin_page_url( self::PAGE_SLUG ) : admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	private function deactivate_page_url( $args = array() ) {
		$url = function_exists( 'savedpixel_admin_page_url' ) ? savedpixel_admin_page_url( self::DEACTIVATE_PAGE_SLUG ) : admin_url( 'admin.php?page=' . self::DEACTIVATE_PAGE_SLUG );

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	private function current_filters() {
		$paged = isset( $_GET['spat_paged'] ) ? absint( wp_unslash( $_GET['spat_paged'] ) ) : 1;

		return array(
			'actor'  => isset( $_GET['spat_actor'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['spat_actor'] ) ) : '',
			'action' => isset( $_GET['spat_action'] ) ? sanitize_key( (string) wp_unslash( $_GET['spat_action'] ) ) : '',
			'object' => isset( $_GET['spat_object'] ) ? sanitize_key( (string) wp_unslash( $_GET['spat_object'] ) ) : '',
			'paged'  => max( 1, $paged ),
		);
	}

	private function pagination_args( $filters, $page_number ) {
		$args = array(
			'spat_paged' => max( 1, (int) $page_number ),
		);

		if ( '' !== $filters['actor'] ) {
			$args['spat_actor'] = $filters['actor'];
		}

		if ( '' !== $filters['action'] ) {
			$args['spat_action'] = $filters['action'];
		}

		if ( '' !== $filters['object'] ) {
			$args['spat_object'] = $filters['object'];
		}

		return $args;
	}

	private function admin_users() {
		return get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
	}

	private function user_can_view_logs( $user = null ) {
		$user = $user instanceof WP_User ? $user : wp_get_current_user();
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		if ( ! user_can( $user, 'manage_options' ) ) {
			return false;
		}

		$settings   = $this->settings();
		$viewer_ids = $settings['viewer_ids'];

		if ( empty( $viewer_ids ) ) {
			return true;
		}

		return in_array( (int) $user->ID, array_map( 'absint', $viewer_ids ), true );
	}

	private function verify_guard_password( $password, $settings = null ) {
		$settings = is_array( $settings ) ? $settings : $this->settings();
		$hash     = (string) $settings['guard_password_hash'];

		if ( '' === $hash ) {
			return true;
		}

		return '' !== $password && wp_check_password( $password, $hash );
	}

	private function table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	private function legacy_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::LEGACY_TABLE_SUFFIX;
	}

	private function install_schema() {
		global $wpdb;

		$table_name      = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(255) NOT NULL DEFAULT '',
			user_role varchar(255) NOT NULL DEFAULT '',
			action_type varchar(100) NOT NULL DEFAULT '',
			object_type varchar(100) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NULL DEFAULT NULL,
			object_label varchar(255) NULL DEFAULT NULL,
			context longtext NULL,
			action_time datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY action_time (action_time),
			KEY user_id (user_id),
			KEY action_type (action_type),
			KEY object_type (object_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function migrate_legacy_logs_table() {
		$legacy_table = $this->legacy_table_name();
		$new_table    = $this->table_name();

		global $wpdb;

		$legacy_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) );
		if ( $legacy_table !== $legacy_exists ) {
			return;
		}

		$existing_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static table name.
		if ( $existing_rows > 0 ) {
			return;
		}

		$context = wp_json_encode(
			array(
				'source' => 'legacy_activity_tracker',
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static table names and controlled JSON value.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$new_table} ( user_id, user_login, user_role, action_type, object_type, object_id, object_label, context, action_time )
				SELECT user_id, user_login, user_role, action_type, '' AS object_type, item_id, item_title, %s AS context, action_time
				FROM {$legacy_table}
				ORDER BY id ASC",
				$context
			)
		);
	}

	private function query_summary() {
		global $wpdb;

		$now        = current_datetime();
		$today      = clone $now;
		$week       = clone $now;
		$today_date = $today->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
		$week_date  = $week->modify( '-7 days' )->format( 'Y-m-d H:i:s' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN action_time >= %s THEN 1 ELSE 0 END) AS today_total,
					SUM(CASE WHEN action_time >= %s THEN 1 ELSE 0 END) AS week_total,
					COUNT(DISTINCT user_id) AS actor_total
				FROM {$this->table_name()}",
				$today_date,
				$week_date
			),
			ARRAY_A
		);

		return array(
			'total'  => (int) ( $row['total'] ?? 0 ),
			'today'  => (int) ( $row['today_total'] ?? 0 ),
			'week'   => (int) ( $row['week_total'] ?? 0 ),
			'actors' => (int) ( $row['actor_total'] ?? 0 ),
		);
	}

	private function query_logs( $filters ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $filters['actor'] ) {
			$where[]  = 'user_login = %s';
			$params[] = $filters['actor'];
		}

		if ( '' !== $filters['action'] ) {
			$where[]  = 'action_type = %s';
			$params[] = $filters['action'];
		}

		if ( '' !== $filters['object'] ) {
			$where[]  = 'object_type = %s';
			$params[] = $filters['object'];
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( max( 1, (int) $filters['paged'] ) - 1 ) * self::PER_PAGE;

		$table = $this->table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY action_time DESC, id DESC LIMIT %d OFFSET %d";

		$count_query = empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_sql built from allowlisted columns.
		$list_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
		$list_query  = $wpdb->prepare( $list_sql, $list_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $list_sql built from allowlisted columns.

		$total = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( $list_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'total' => $total,
			'pages' => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
			'rows'  => is_array( $rows ) ? $rows : array(),
		);
	}

	private function distinct_values( $column ) {
		global $wpdb;

		$column = in_array( $column, array( 'user_login', 'action_type', 'object_type' ), true ) ? $column : 'action_type';
		$rows   = $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$this->table_name()} WHERE {$column} <> '' ORDER BY {$column} ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Column is allowlisted above.

		return is_array( $rows ) ? array_values( array_filter( array_map( 'sanitize_key', $rows ) ) ) : array();
	}

	private function decode_context( $context_json ) {
		if ( '' === trim( (string) $context_json ) ) {
			return array();
		}

		$decoded = json_decode( (string) $context_json, true );

		return is_array( $decoded ) ? $decoded : array( 'raw' => (string) $context_json );
	}

	private function context_summary( $context ) {
		if ( empty( $context ) ) {
			return 'No extra context recorded.';
		}

		$parts = array();
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			$parts[] = sprintf(
				'%1$s: %2$s',
				$this->human_key_label( $key ),
				'' !== trim( (string) $value ) ? (string) $value : '—'
			);
		}

		return implode( ' | ', $parts );
	}

	private function row_object_summary( $row ) {
		$type_label = $this->human_object_label( $row['object_type'] ?? '' );
		$label      = '' !== trim( (string) ( $row['object_label'] ?? '' ) ) ? (string) $row['object_label'] : $type_label;
		$meta_parts = array();

		if ( '' !== $type_label && sanitize_title( $type_label ) !== sanitize_title( $label ) ) {
			$meta_parts[] = $type_label;
		}

		if ( ! empty( $row['object_id'] ) ) {
			$meta_parts[] = 'ID #' . (int) $row['object_id'];
		}

		return array(
			'label' => $label,
			'meta'  => implode( ' | ', $meta_parts ),
		);
	}

	private function human_action_label( $action ) {
		$labels = array(
			'created'           => 'Created',
			'updated'           => 'Updated',
			'published'         => 'Published',
			'trashed'           => 'Trashed',
			'restored'          => 'Restored',
			'deleted'           => 'Deleted',
			'status_changed'    => 'Status Changed',
			'activated'         => 'Activated',
			'deactivated'       => 'Deactivated',
			'removed'           => 'Removed',
			'switched'          => 'Switched',
			'updates_completed' => 'Updates Completed',
		);

		return $labels[ sanitize_key( (string) $action ) ] ?? ucwords( str_replace( '_', ' ', sanitize_key( (string) $action ) ) );
	}

	private function human_object_label( $object_type ) {
		$labels = array(
			'post'        => 'Post',
			'page'        => 'Page',
			'attachment'  => 'Media',
			'user'        => 'User',
			'comment'     => 'Comment',
			'plugin'      => 'Plugin',
			'theme'       => 'Theme',
			'update'      => 'Update',
			'product'     => 'Product',
			'shop_coupon' => 'Coupon',
			'shop_order'  => 'Order',
			'category'    => 'Category',
			'post_tag'    => 'Tag',
		);

		$key = sanitize_key( (string) $object_type );

		return $labels[ $key ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $key ) );
	}

	private function human_key_label( $key ) {
		return ucwords( str_replace( '_', ' ', sanitize_key( (string) $key ) ) );
	}

	private function should_track_user( $user = null ) {
		$user = $user instanceof WP_User ? $user : wp_get_current_user();
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		return user_can( $user, 'manage_options' );
	}

	private function record_event( $action_type, $object_type = '', $object_id = 0, $object_label = '', $context = array(), $user = null ) {
		$user = $user instanceof WP_User ? $user : wp_get_current_user();
		if ( ! $this->should_track_user( $user ) ) {
			return;
		}

		$action_type = sanitize_key( (string) $action_type );
		$object_type = sanitize_key( (string) $object_type );
		$object_id   = absint( $object_id );
		$object_label = sanitize_text_field( (string) $object_label );
		$context      = $this->sanitize_context( (array) $context );
		$context_json = empty( $context ) ? '' : wp_json_encode( $context );

		$fingerprint = md5(
			implode(
				'|',
				array(
					$user->ID,
					$action_type,
					$object_type,
					$object_id,
					$object_label,
					(string) $context_json,
				)
			)
		);

		if ( isset( $this->log_dedupe[ $fingerprint ] ) ) {
			return;
		}

		$this->log_dedupe[ $fingerprint ] = true;

		global $wpdb;

		if ( $object_id > 0 ) {
			$query = $wpdb->prepare(
				"INSERT INTO {$this->table_name()} ( user_id, user_login, user_role, action_type, object_type, object_id, object_label, context, action_time )
				VALUES ( %d, %s, %s, %s, %s, %d, %s, %s, %s )",
				$user->ID,
				(string) $user->user_login,
				implode( ', ', array_map( 'sanitize_key', (array) $user->roles ) ),
				$action_type,
				$object_type,
				$object_id,
				$object_label,
				(string) $context_json,
				current_time( 'mysql' )
			);
		} else {
			$query = $wpdb->prepare(
				"INSERT INTO {$this->table_name()} ( user_id, user_login, user_role, action_type, object_type, object_id, object_label, context, action_time )
				VALUES ( %d, %s, %s, %s, %s, NULL, %s, %s, %s )",
				$user->ID,
				(string) $user->user_login,
				implode( ', ', array_map( 'sanitize_key', (array) $user->roles ) ),
				$action_type,
				$object_type,
				$object_label,
				(string) $context_json,
				current_time( 'mysql' )
			);
		}

		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.

		$settings = $this->settings();
		if ( ! empty( $settings['file_logging_enabled'] ) ) {
			$this->write_log_to_file(
				array(
					'user_id'      => $user->ID,
					'user_login'   => (string) $user->user_login,
					'user_role'    => implode( ', ', array_map( 'sanitize_key', (array) $user->roles ) ),
					'action_type'  => $action_type,
					'object_type'  => $object_type,
					'object_id'    => $object_id,
					'object_label' => $object_label,
					'context'      => $context,
					'action_time'  => current_time( 'mysql' ),
				),
				$settings['log_file_format']
			);
		}
	}

	private function sanitize_context( $context ) {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = array_values(
					array_map(
						static function ( $item ) {
							return is_scalar( $item ) ? sanitize_text_field( (string) $item ) : wp_json_encode( $item );
						},
						$value
					)
				);
				continue;
			}

			$clean[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : wp_json_encode( $value );
		}

		return $clean;
	}

	private function send_notification_email( $action ) {
		$settings = $this->settings();
		$email    = sanitize_email( (string) $settings['notification_email'] );

		if ( '' === $email ) {
			return;
		}

		wp_mail(
			$email,
			'SavedPixel Activity Tracker Notification',
			$this->replace_email_tokens( $settings['deactivation_email_content'], $action )
		);
	}

	private function replace_email_tokens( $message, $action ) {
		$current_user = wp_get_current_user();
		$user_label   = $current_user instanceof WP_User && $current_user->exists()
			? $current_user->display_name . ' (' . $current_user->user_login . ')'
			: 'Unknown user';

		return strtr(
			(string) $message,
			array(
				'{action}'   => $this->human_action_label( $action ),
				'{site_url}' => home_url( '/' ),
				'{user}'     => $user_label,
				'{time}'     => current_time( 'mysql' ),
			)
		);
	}

	private function log_directory() {
		return trailingslashit( WP_CONTENT_DIR ) . 'savedpixel-activity-tracker/';
	}

	private function log_file_path( $format ) {
		return $this->log_directory() . 'activity-log.' . $this->sanitize_log_file_format( $format );
	}

	private function write_log_to_file( $entry, $format ) {
		$format = $this->sanitize_log_file_format( $format );
		$dir    = $this->log_directory();

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$this->maybe_migrate_legacy_log_file( $format );

		$file = $this->log_file_path( $format );
		if ( 'csv' === $format ) {
			$this->append_csv_log( $file, $entry );
			return;
		}

		$this->append_text_log( $file, $entry );
	}

	private function maybe_migrate_legacy_log_file( $format ) {
		if ( get_option( self::LOG_MIGRATION_OPTION ) ) {
			return;
		}

		$legacy_dir  = trailingslashit( WP_CONTENT_DIR ) . 'secure-activity-tracker/';
		$legacy_file = $legacy_dir . 'secure-activity-tracker.' . $format;
		$new_file    = $this->log_file_path( $format );

		if ( ! file_exists( $legacy_file ) || file_exists( $new_file ) ) {
			update_option( self::LOG_MIGRATION_OPTION, 1, false );
			return;
		}

		if ( @copy( $legacy_file, $new_file ) ) {
			update_option( self::LOG_MIGRATION_OPTION, 1, false );
		}
	}

	private function append_csv_log( $file, $entry ) {
		$is_new = ! file_exists( $file );
		$handle = fopen( $file, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fputcsv requires a file handle.

		if ( false === $handle ) {
			return;
		}

		if ( $is_new ) {
			fputcsv(
				$handle,
				array(
					'Time',
					'User ID',
					'Username',
					'Role',
					'Action',
					'Object Type',
					'Object ID',
					'Object Label',
					'Context',
				)
			);
		}

		fputcsv(
			$handle,
			array(
				$entry['action_time'],
				$entry['user_id'],
				$entry['user_login'],
				$entry['user_role'],
				$entry['action_type'],
				$entry['object_type'],
				$entry['object_id'],
				$entry['object_label'],
				wp_json_encode( $entry['context'] ),
			)
		);

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	private function append_text_log( $file, $entry ) {
		$line = sprintf(
			"[%s] %s (%s) %s %s %s | context: %s\n",
			$entry['action_time'],
			$entry['user_login'],
			$entry['user_role'],
			$entry['action_type'],
			$entry['object_type'],
			'' !== $entry['object_label'] ? $entry['object_label'] : '#' . (int) $entry['object_id'],
			wp_json_encode( $entry['context'] )
		);

		file_put_contents( $file, $line, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Plain local log append.
	}

	public function handle_post_save( $post_id, $post, $update ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( 'attachment' === $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->record_event(
			$update ? 'updated' : 'created',
			$this->normalize_post_type_label( $post->post_type ),
			$post_id,
			$this->post_label( $post ),
			array(
				'status' => $post->post_status,
			)
		);
	}

	public function handle_post_status_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( 'attachment' === $post->post_type || wp_is_post_revision( $post->ID ) || $new_status === $old_status ) {
			return;
		}

		$action = '';
		if ( 'trash' === $new_status ) {
			$action = 'trashed';
		} elseif ( 'trash' === $old_status && 'trash' !== $new_status ) {
			$action = 'restored';
		} elseif ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$action = 'published';
		}

		if ( '' === $action ) {
			return;
		}

		$this->record_event(
			$action,
			$this->normalize_post_type_label( $post->post_type ),
			$post->ID,
			$this->post_label( $post ),
			array(
				'old_status' => $old_status,
				'new_status' => $new_status,
			)
		);
	}

	public function handle_post_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'attachment' === $post->post_type || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$this->record_event(
			'deleted',
			$this->normalize_post_type_label( $post->post_type ),
			$post_id,
			$this->post_label( $post ),
			array(
				'status' => $post->post_status,
			)
		);
	}

	public function handle_attachment_add( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$this->record_event(
			'created',
			'attachment',
			$post_id,
			$this->post_label( $post ),
			array(
				'mime_type' => $post->post_mime_type,
			)
		);
	}

	public function handle_attachment_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$this->record_event(
			'deleted',
			'attachment',
			$post_id,
			$this->post_label( $post ),
			array(
				'mime_type' => $post->post_mime_type,
			)
		);
	}

	public function handle_user_register( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->record_event(
			'created',
			'user',
			$user_id,
			$this->user_label( $user ),
			array(
				'roles' => $user->roles,
			)
		);
	}

	public function handle_profile_update( $user_id, $old_user_data, $userdata ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->record_event(
			'updated',
			'user',
			$user_id,
			$this->user_label( $user ),
			array(
				'roles' => $user->roles,
				'email' => $userdata['user_email'] ?? $user->user_email,
			)
		);
	}

	public function capture_deleted_user( $user_id, $reassign = null, $user = null ) {
		$user = $user instanceof WP_User ? $user : get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->deleted_users[ $user_id ] = array(
			'label' => $this->user_label( $user ),
			'roles' => $user->roles,
		);
	}

	public function handle_deleted_user( $user_id, $reassign = null, $user = null ) {
		$data = $this->deleted_users[ $user_id ] ?? null;
		unset( $this->deleted_users[ $user_id ] );

		$this->record_event(
			'deleted',
			'user',
			$user_id,
			is_array( $data ) ? (string) $data['label'] : 'Deleted user',
			array(
				'reassign' => $reassign ? (string) $reassign : '',
				'roles'    => is_array( $data ) ? (array) $data['roles'] : array(),
			)
		);
	}

	public function handle_term_created( $term_id, $tt_id, $taxonomy, $args = array() ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$this->record_event(
			'created',
			(string) $taxonomy,
			$term_id,
			(string) $term->name,
			array(
				'taxonomy' => $taxonomy,
			)
		);
	}

	public function handle_term_updated( $term_id, $tt_id, $taxonomy, $args = array() ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$this->record_event(
			'updated',
			(string) $taxonomy,
			$term_id,
			(string) $term->name,
			array(
				'taxonomy' => $taxonomy,
			)
		);
	}

	public function handle_term_deleted( $term, $tt_id, $taxonomy, $deleted_term, $object_ids = array() ) {
		if ( is_object( $deleted_term ) && ! empty( $deleted_term->term_id ) ) {
			$this->record_event(
				'deleted',
				(string) $taxonomy,
				(int) $deleted_term->term_id,
				(string) $deleted_term->name,
				array(
					'taxonomy' => $taxonomy,
				)
			);
		}
	}

	public function handle_comment_status_transition( $new_status, $old_status, $comment ) {
		if ( ! $comment instanceof WP_Comment || $new_status === $old_status ) {
			return;
		}

		$this->record_event(
			'status_changed',
			'comment',
			(int) $comment->comment_ID,
			$this->comment_label( $comment ),
			array(
				'old_status' => $old_status,
				'new_status' => $new_status,
				'post_id'    => (int) $comment->comment_post_ID,
			)
		);
	}

	public function handle_comment_deleted( $comment_id, $comment = null ) {
		$comment = $comment instanceof WP_Comment ? $comment : get_comment( $comment_id );
		if ( ! $comment instanceof WP_Comment ) {
			return;
		}

		$this->record_event(
			'deleted',
			'comment',
			(int) $comment->comment_ID,
			$this->comment_label( $comment ),
			array(
				'post_id' => (int) $comment->comment_post_ID,
			)
		);
	}

	public function handle_plugin_activated( $plugin, $network_wide = false ) {
		$this->record_event(
			'activated',
			'plugin',
			0,
			$this->plugin_label( $plugin ),
			array(
				'plugin'       => $plugin,
				'network_wide' => $network_wide ? 'yes' : 'no',
			)
		);
	}

	public function handle_plugin_deactivated( $plugin, $network_deactivating = false ) {
		$this->record_event(
			'deactivated',
			'plugin',
			0,
			$this->plugin_label( $plugin ),
			array(
				'plugin'               => $plugin,
				'network_deactivating' => $network_deactivating ? 'yes' : 'no',
			)
		);
	}

	public function handle_plugin_deleted( $plugin_file, $deleted = false ) {
		$this->record_event(
			'removed',
			'plugin',
			0,
			$this->plugin_label( $plugin_file ),
			array(
				'plugin'  => $plugin_file,
				'deleted' => $deleted ? 'yes' : 'no',
			)
		);
	}

	public function handle_theme_switched( $new_name, $new_theme, $old_theme ) {
		$old_name = is_object( $old_theme ) && method_exists( $old_theme, 'get' ) ? $old_theme->get( 'Name' ) : '';
		$new_slug = is_object( $new_theme ) && method_exists( $new_theme, 'get_stylesheet' ) ? $new_theme->get_stylesheet() : '';

		$this->record_event(
			'switched',
			'theme',
			0,
			(string) $new_name,
			array(
				'old_theme' => (string) $old_name,
				'new_slug'  => (string) $new_slug,
			)
		);
	}

	public function handle_upgrader_process_complete( $upgrader, $hook_extra ) {
		$hook_extra = is_array( $hook_extra ) ? $hook_extra : array();
		if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
			return;
		}

		$type  = sanitize_key( (string) ( $hook_extra['type'] ?? 'update' ) );
		$items = array();

		foreach ( array( 'plugins', 'themes' ) as $key ) {
			if ( ! empty( $hook_extra[ $key ] ) && is_array( $hook_extra[ $key ] ) ) {
				$items = array_merge( $items, array_map( 'sanitize_text_field', $hook_extra[ $key ] ) );
			}
		}

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$items[] = sanitize_text_field( (string) $hook_extra['plugin'] );
		}

		if ( ! empty( $hook_extra['theme'] ) ) {
			$items[] = sanitize_text_field( (string) $hook_extra['theme'] );
		}

		$this->record_event(
			'updates_completed',
			'update',
			0,
			ucfirst( $type ) . ' update',
			array(
				'type'  => $type,
				'items' => array_values( array_unique( array_filter( $items ) ) ),
			)
		);
	}

	public function handle_new_order( $order_id, $order = null ) {
		if ( ! $this->is_woocommerce_active() ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->record_event(
			'created',
			'shop_order',
			$order_id,
			$this->order_label( $order ),
			array(
				'status' => $order->get_status(),
			)
		);
	}

	public function handle_order_status_changed( $order_id, $from, $to, $order ) {
		if ( ! $this->is_woocommerce_active() ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->record_event(
			'status_changed',
			'shop_order',
			$order_id,
			$this->order_label( $order ),
			array(
				'old_status' => $from,
				'new_status' => $to,
			)
		);
	}

	private function normalize_post_type_label( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );

		if ( 'shop_coupon' === $post_type || 'shop_order' === $post_type || 'product' === $post_type ) {
			return $post_type;
		}

		return '' !== $post_type ? $post_type : 'post';
	}

	private function post_label( $post ) {
		$title = trim( (string) get_the_title( $post ) );
		if ( '' !== $title ) {
			return $title;
		}

		return sprintf( 'Untitled %s #%d', $this->human_object_label( $post->post_type ), (int) $post->ID );
	}

	private function user_label( $user ) {
		return sprintf( '%1$s (%2$s)', $user->display_name, $user->user_login );
	}

	private function comment_label( $comment ) {
		$excerpt = wp_trim_words( wp_strip_all_tags( (string) $comment->comment_content ), 8, '…' );
		$post_id = (int) $comment->comment_post_ID;

		return '' !== $excerpt ? $excerpt : 'Comment on post #' . $post_id;
	}

	private function plugin_label( $plugin_file ) {
		$plugin_file = (string) $plugin_file;
		$plugin_name = '';

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$absolute_path = trailingslashit( WP_PLUGIN_DIR ) . ltrim( $plugin_file, '/' );
		if ( file_exists( $absolute_path ) ) {
			$data = get_plugin_data( $absolute_path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				$plugin_name = (string) $data['Name'];
			}
		}

		return '' !== $plugin_name ? $plugin_name : $plugin_file;
	}

	private function order_label( $order ) {
		return sprintf( 'Order #%d', $order->get_id() );
	}

	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) || function_exists( 'wc_get_order' );
	}
}
