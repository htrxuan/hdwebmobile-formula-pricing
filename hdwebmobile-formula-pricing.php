<?php

/**
 * Plugin Name: HDWebmobile Formula Pricing
 * Plugin URI: https://hdwebmobile.com/plugins/hdwebmobile-formula-pricing/
 * Description: Price a product from customer-entered numbers (width, height, quantity, etc.) using a merchant-defined formula -- evaluated by a safe, allow-list-only expression parser, never PHP's eval().
 * Version: 1.0.0
 * Author: htrxuan - Han Tran
 * Author URI: https://hdwebmobile.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hdwebmobile-formula-pricing
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.9
 */

namespace htrxuan\hdfp;

if (!defined('ABSPATH')) {
    exit;
}

define('HDFP_VERSION', '1.0.0');
define('HDFP_PLUGIN_FILE', __FILE__);
define('HDFP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HDFP_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once HDFP_PLUGIN_DIR . 'includes/class-hdfp-activator.php';

register_activation_hook(__FILE__, array(HDFP_Activator::class, 'activate'));

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', HDFP_PLUGIN_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    require_once HDFP_PLUGIN_DIR . 'includes/class-hdfp-core.php';
    HDFP_Core::get_instance();
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $donate_link = '<a href="https://paypal.me/htrxuan/20" target="_blank" rel="noopener noreferrer">' . esc_html__('Donate', 'hdwebmobile-formula-pricing') . '</a>';
    array_unshift($links, $donate_link);
    return $links;
});
