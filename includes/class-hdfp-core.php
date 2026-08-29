<?php

namespace htrxuan\hdfp;

if (!defined('ABSPATH')) {
    exit;
}

final class HDFP_Core
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    private function __clone()
    {
    }

    private function includes()
    {
        require_once HDFP_PLUGIN_DIR . 'includes/class-hdfp-formula-evaluator.php';
        require_once HDFP_PLUGIN_DIR . 'includes/class-hdfp-product.php';
        require_once HDFP_PLUGIN_DIR . 'includes/class-hdfp-frontend.php';
        require_once HDFP_PLUGIN_DIR . 'includes/class-hdfp-cart.php';
        require_once HDFP_PLUGIN_DIR . 'includes/class-hdfp-admin.php';
    }

    private function init_hooks()
    {
        add_action('admin_notices', array($this, 'render_missing_woocommerce_notice'));

        if (!class_exists('WooCommerce')) {
            return;
        }

        HDFP_Frontend::get_instance();
        HDFP_Cart::get_instance();

        if (is_admin()) {
            HDFP_Admin::get_instance();
        }
    }

    public function render_missing_woocommerce_notice()
    {
        $screen = get_current_screen();
        if (!$screen || 'plugins' !== $screen->id) {
            return;
        }

        if (!get_transient('hdfp_wc_missing_notice')) {
            return;
        }
        delete_transient('hdfp_wc_missing_notice');
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php esc_html_e('HDWebmobile Formula Pricing requires WooCommerce to be installed and active. The plugin has been deactivated.', 'hdwebmobile-formula-pricing'); ?>
            </p>
        </div>
        <?php
    }
}
