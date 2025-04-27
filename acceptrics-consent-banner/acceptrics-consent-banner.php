<?php
/**
 * Plugin Name: Acceptrics Consent Banner
 * Description: Acceptrics Cookie Banner is the easiest way for privacy compliance.
 * Version: 1.0
 * Author: <href="https://acceptrics.com">Acceptrics</a>
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: acceptrics-consent-banner
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
    exit; // Prevent direct access
}

// Define plugin constants
define('ACCEPTRICS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ACCEPTRICS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ACCEPTRICS_TRANSIENT_KEY', 'acceptrics_cached_script');
define('ACCEPTRICS_TRANSIENT_EXPIRATION', 6 * HOUR_IN_SECONDS);

// Include required files
require_once ACCEPTRICS_PLUGIN_DIR . 'includes/admin-settings.php';
require_once ACCEPTRICS_PLUGIN_DIR . 'includes/script-injector.php';

// Activation Hook
function acceptrics_activate() {
    // Set an option to show the "Settings" link after activation
    update_option('acceptrics_show_activation_notice', true);
}
register_activation_hook(__FILE__, 'acceptrics_activate');

// Deactivation Hook
function acceptrics_deactivate() {
    delete_transient(ACCEPTRICS_TRANSIENT_KEY); // Clear cached script
    delete_option('acceptrics_show_activation_notice'); // Remove activation flag
}
register_deactivation_hook(__FILE__, 'acceptrics_deactivate');

// Display an admin notice after activation
function acceptrics_admin_notice() {
    if (get_option('acceptrics_show_activation_notice')) {
        ?>
        <div class="updated notice is-dismissible">
            <p>
                <strong>Acceptrics Consent Banner Activated!</strong>  
                <a href="<?php echo esc_url(admin_url('options-general.php?page=acceptrics-consent-banner')); ?>">
                    Configure the plugin settings here.
                </a>
            </p>
        </div>
        <?php
        // Remove the flag so the message is shown only once
        delete_option('acceptrics_show_activation_notice');
    }
}
add_action('admin_notices', 'acceptrics_admin_notice');
