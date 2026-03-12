# SavedPixel Activity Tracker

Track high-privilege administrator activity in WordPress and review it from a dedicated SavedPixel audit screen.

## What It Does

SavedPixel Activity Tracker records administrator actions as they happen and stores them in a dedicated audit log table. The plugin is focused on privileged operational activity rather than full-site analytics, so it is built for site owners, technical admins, and staging workflows that need to know who changed what.

## Key Workflows

- Review recent administrator activity from one filterable, paginated log screen.
- Restrict log access to selected administrators instead of exposing it to every admin account.
- Protect settings and deactivation behind a guard password.
- Send a deactivation notification email and optionally mirror audit entries to CSV or TXT files.

## Features

- Tracks administrator-driven post saves, status transitions, and deletions across registered post types.
- Tracks media uploads and attachment deletions.
- Tracks user creation, profile updates, and account deletions.
- Tracks taxonomy term creation, edits, and deletions.
- Tracks comment status changes and comment deletions.
- Tracks plugin activation, deactivation, deletion, theme switches, and upgrader runs.
- Tracks WooCommerce order creation and order status changes when WooCommerce is active.
- Stores structured context for each event so the log can show object details and expanded JSON metadata.
- Provides actor, action, and object-type filters with pagination for large histories.
- Supports an administrator allowlist so only selected admins can review the log.
- Uses a guard password to lock settings and require verification before deactivation.
- Supports a customizable deactivation email template with `{action}`, `{site_url}`, `{user}`, and `{time}` tokens.
- Includes a test-email action for checking notification delivery.
- Supports optional CSV or TXT file logging under `wp-content/savedpixel-activity-tracker/`.
- Can hide the main **Plugins** menu and the built-in plugin editor from wp-admin.

## Admin Page

The Activity Tracker page combines a log table with summary information and a locked settings area. Once unlocked, the settings cards cover access control, notification and file-logging options, and admin-lockdown options. A separate guarded deactivation confirmation page is used when the plugin is turned off from the Plugins screen.

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later

## Installation

1. Upload the `savedpixel-activity-tracker` folder to `wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Open **SavedPixel > Activity Tracker**.
4. Set a guard password, choose which administrators can view the log, and configure any notification or file-logging options you want to use.

## Usage Notes

- This plugin records administrator activity only.
- Existing database rows and file logs are kept when the plugin is deactivated.
- If you leave the allowed-viewer list empty, every administrator can review the audit log.

## Author

**Byron Jacobs**  
[GitHub](https://github.com/savedpixel)

## License

GPL-2.0-or-later
