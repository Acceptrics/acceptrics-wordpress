<?php
/**
 * Plugin Name: Acceptrics Consent Banner
 * Description: GDPR-compliant consent banner with built-in analytics recovery — recover data lost to ad blockers without any DNS changes.
 * Version: 2.10
 * Author: <a href="https://acceptrics.com">Acceptrics</a>
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: acceptrics-consent-banner
 * Requires at least: 5.9
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

/*
Acceptrics Consent Banner is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
(at your option) any later version.

Acceptrics Consent Banner is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Acceptrics Consent Banner. If not, see http://www.gnu.org/licenses/gpl-2.0.txt.
*/

if (!defined('ABSPATH')) {
    exit;
}

define('ACCEPTRICS_VERSION', '2.10');
define('ACCEPTRICS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ACCEPTRICS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ACCEPTRICS_PLUGIN_DIR . 'includes/telemetry.php';
require_once ACCEPTRICS_PLUGIN_DIR . 'includes/relay-api.php';
require_once ACCEPTRICS_PLUGIN_DIR . 'includes/proxy-handler.php';
require_once ACCEPTRICS_PLUGIN_DIR . 'includes/detect-handler.php';
require_once ACCEPTRICS_PLUGIN_DIR . 'includes/admin-settings.php';
require_once ACCEPTRICS_PLUGIN_DIR . 'includes/script-injector.php';

function acceptrics_activate() {
    update_option('acceptrics_show_activation_notice', true);
    // Recorded so opt-in telemetry can backfill the install date later.
    add_option('acceptrics_installed_at', gmdate('c'));
}
register_activation_hook(__FILE__, 'acceptrics_activate');

function acceptrics_deactivate() {
    delete_option('acceptrics_show_activation_notice');
    if (function_exists('acceptrics_track')) {
        acceptrics_track('wp_plugin_deactivated');
    }
}
register_deactivation_hook(__FILE__, 'acceptrics_deactivate');

function acceptrics_admin_notice() {
    if (get_option('acceptrics_show_activation_notice')) {
        $account_id   = get_option('acceptrics_account_id', '');
        $settings_url = esc_url(admin_url('options-general.php?page=acceptrics-consent-banner'));
        ?>
        <div class="updated notice is-dismissible">
            <p>
                <strong>Acceptrics Consent Banner activated!</strong>
                <?php if (empty($account_id)) : ?>
                    <a href="<?php echo $settings_url; ?>">Create your free account or enter your account code to get started.</a>
                <?php else : ?>
                    <a href="<?php echo $settings_url; ?>">View settings.</a>
                <?php endif; ?>
            </p>
        </div>
        <?php
        delete_option('acceptrics_show_activation_notice');
    }
}
add_action('admin_notices', 'acceptrics_admin_notice');
