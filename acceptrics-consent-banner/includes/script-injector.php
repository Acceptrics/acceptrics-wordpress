<?php
if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

function acceptrics_enqueue_inline_script() {
	// The listener to set wp_consent_api
	wp_enqueue_script(
		'acceptrics-wp-consent-hook',
		plugin_dir_url(__FILE__) . 'js/listener.js',
		array(),
		'1.0',
		true
	);
	// The user defined configuration
	wp_enqueue_script(
		'acceptrics-wp-config-hook',
		plugin_dir_url(__FILE__) . 'conf/conf.js',
		array(),
		'1.0',
		true
	);
	// The core acceptrics banner library
	wp_enqueue_script( 'acceptrics-library', 'https://cdn.acceptrics.com', array(), '1.0', true );
}
add_action('wp_enqueue_scripts', 'acceptrics_enqueue_inline_script');

