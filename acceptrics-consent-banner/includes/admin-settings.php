<?php
if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

// Define path for config file
define('ACCEPTRICS_CONFIG_FILE', plugin_dir_path(__FILE__) . 'conf/conf.js');

// Register settings page
function acceptrics_add_admin_menu() {
    add_options_page(
        'Acceptrics Consent Banner',
        'Acceptrics Consent Banner',
        'manage_options',
        'acceptrics-consent-banner',
        'acceptrics_settings_page'
    );
}
add_action('admin_menu', 'acceptrics_add_admin_menu');

// Check if "WP Consent API" plugin is active
function acceptrics_is_wp_consent_api_active() {
    include_once ABSPATH . 'wp-admin/includes/plugin.php';
    return is_plugin_active('wp-consent-api/wp-consent-api.php');
}

// Handle settings save
function acceptrics_save_settings() {
    // Check security nonce
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce( sanitize_key($_POST['_wpnonce']))) {
        wp_die('Security check failed.');
    }

    // Ensure the user has permission
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }

    // Fetch and sanitize input
    $custom_script = isset($_POST['acceptrics_custom_script']) ? sanitize_textarea_field(wp_unslash($_POST['acceptrics_custom_script'])) : '';

    // Save settings to file
    $result = acceptrics_write_config_to_js(stripslashes($custom_script));

    // Redirect with success or error message
    $redirect_url = admin_url('options-general.php?page=acceptrics-consent-banner');
    $redirect_url .= $result === true ? '&updated=true' : '&error=file_write_error';

    wp_redirect($redirect_url);
    exit;
}
add_action('admin_post_acceptrics_save_settings', 'acceptrics_save_settings');

// Write configuration to a file
function acceptrics_write_config_to_js($config_data) {
    $absolute_file_path = ACCEPTRICS_CONFIG_FILE;

    // Attempt to write to the file
    if (file_put_contents($absolute_file_path, $config_data) !== false) {
        return true; // Success
    } else {
        return new WP_Error('file_write_error', 'Failed to write to config file.');
    }
}

// Load saved script content (if exists)
function acceptrics_get_saved_script() {
    if (file_exists(ACCEPTRICS_CONFIG_FILE)) {
        return file_get_contents(ACCEPTRICS_CONFIG_FILE);
    }
    return '';
}

function acceptrics_admin_inline_js(){ 
    wp_enqueue_script( 'acceptrics_filtertextareacontent', plugin_dir_url( __FILE__ ) . 'js/filterTextAreaContent.js', array(), '1.0', true );
 } 
 add_action( 'admin_enqueue_scripts', 'acceptrics_admin_inline_js' );


// Admin settings page content
function acceptrics_settings_page() {
    $is_consent_api_active = acceptrics_is_wp_consent_api_active();
    $plugin_install_url = admin_url('plugin-install.php?s=WP+Consent+API&tab=search&type=term');
    $saved_script = acceptrics_get_saved_script();

    ?>
    <div class="wrap">
        <h1>Acceptrics Consent Banner</h1>
        <div class="notice notice-info" style="margin-bottom: 20px;">
            <p>
                Need help configuring your consent banner? Check out our 
                <a href="https://acceptrics.com/faq" target="_blank" style="font-weight: bold;">FAQ documentation</a>.
            </p>
        </div>

        <?php if (isset($_GET['updated'])) : ?>
            <div class="updated notice is-dismissible"><p><strong>Settings saved successfully.</strong></p></div>
        <?php elseif (isset($_GET['error'])) : ?>
            <div class="error notice is-dismissible"><p><strong>Error: Invalid script or failed to save.</strong></p></div>
        <?php endif; ?>

        <h2>Plugin Status</h2>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">WP Consent API</th>
                <td>
                    <?php if ($is_consent_api_active) : ?>
                        <span style="color: green; font-weight: bold;">&#x2B24; Installed</span>
                    <?php else : ?>
                        <span style="color: red; font-weight: bold;">&#x274C; Not Installed</span>
                        <p style="color: red;">
                            <strong>WP Consent API is required for this plugin to work properly.</strong><br>
                            <a href="<?php echo esc_url($plugin_install_url); ?>" class="button button-primary">
                                Install WP Consent API
                            </a>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="acceptrics_save_settings">
            <?php wp_nonce_field(-1, '_wpnonce', true, true); ?>
            <h2>Consent Banner Settings</h2>
            <p class="description">Visit <a href="https://acceptrics.com/wizard" target="_blank">the Acceptrics Banner Wizard</a> and paste the configuration below.</p>
            <h2>Generated Configuration</h2>
            <textarea onkeyup="acceptricsFilterTextAreaContent()" id="acceptrics_custom_script" name="acceptrics_custom_script" rows="6" cols="80">
                <?php echo esc_js(stripslashes(html_entity_decode(trim($saved_script)))); ?>
            </textarea><br/>
            <input type="submit" value="Save Settings" class="button button-primary">
        </form>
    </div>
    <?php
}
?>
