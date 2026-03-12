<?php
/**
 * Plugin Name: SavedPixel Activity Tracker
 * Plugin URI: https://github.com/savedpixel
 * Description: Track high-privilege WordPress activity and review audit history from the SavedPixel admin hub.
 * Version: 1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Byron Jacobs
 * Author URI: https://github.com/savedpixel
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: savedpixel-activity-tracker
 * @package SavedPixelActivityTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SAVEDPIXEL_ACTIVITY_TRACKER_FILE' ) ) {
	define( 'SAVEDPIXEL_ACTIVITY_TRACKER_FILE', __FILE__ );
}

if ( ! defined( 'SAVEDPIXEL_ACTIVITY_TRACKER_DIR' ) ) {
	define( 'SAVEDPIXEL_ACTIVITY_TRACKER_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'SAVEDPIXEL_ACTIVITY_TRACKER_URL' ) ) {
	define( 'SAVEDPIXEL_ACTIVITY_TRACKER_URL', plugin_dir_url( __FILE__ ) );
}

require_once SAVEDPIXEL_ACTIVITY_TRACKER_DIR . 'includes/savedpixel-admin-shared.php';
require_once SAVEDPIXEL_ACTIVITY_TRACKER_DIR . 'includes/class-savedpixel-activity-tracker.php';

savedpixel_register_admin_preview_asset(
	SAVEDPIXEL_ACTIVITY_TRACKER_URL . 'assets/css/savedpixel-admin-preview.css',
	SavedPixel_Activity_Tracker::VERSION,
	array(
		'savedpixel',
		SavedPixel_Activity_Tracker::PAGE_SLUG,
		SavedPixel_Activity_Tracker::DEACTIVATE_PAGE_SLUG,
	)
);

SavedPixel_Activity_Tracker::bootstrap();

register_activation_hook( __FILE__, array( 'SavedPixel_Activity_Tracker', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SavedPixel_Activity_Tracker', 'deactivate' ) );
