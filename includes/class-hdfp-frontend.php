<?php

namespace htrxuan\hdfp;

if (!defined('ABSPATH')) {
    exit;
}

final class HDFP_Frontend
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    const NONCE_ACTION = 'hdfp_preview';

    private function __construct()
    {
        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_fields'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
        add_action('wp_ajax_hdfp_preview', array($this, 'ajax_preview'));
        add_action('wp_ajax_nopriv_hdfp_preview', array($this, 'ajax_preview'));
    }

    public function maybe_enqueue_assets()
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        global $product;
        if (!$product || !HDFP_Product::is_enabled($product->get_id())) {
            return;
        }

        wp_enqueue_style('hdfp-frontend', HDFP_PLUGIN_URL . 'assets/css/hdfp-frontend.css', array(), HDFP_VERSION);
        wp_enqueue_script('hdfp-frontend', HDFP_PLUGIN_URL . 'assets/js/hdfp-frontend.js', array(), HDFP_VERSION, true);
        wp_localize_script('hdfp-frontend', 'hdfpSettings', array(
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            'productId' => $product->get_id(),
        ));
    }

    /**
     * The live preview is computed here, server-side, through the exact same
     * evaluator used at add-to-cart time -- there is no separate formula
     * interpreter in JavaScript. This also means the merchant's formula text
     * itself is never sent to the browser, only the resulting price.
     */
    public function ajax_preview()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $product = $product_id ? wc_get_product($product_id) : null;

        if (!$product || !HDFP_Product::is_enabled($product_id)) {
            wp_send_json_error();
        }

        $fields = HDFP_Product::get_fields($product_id);
        $formula = HDFP_Product::get_formula($product_id);
        if (empty($fields) || '' === $formula) {
            wp_send_json_error();
        }

        $submitted = isset($_POST['hdfp_field']) && is_array($_POST['hdfp_field']) ? wp_unslash($_POST['hdfp_field']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is cast through (float) below via HDFP_Cart::collect_variables(), and only allow-listed field keys are ever read.

        $variables = HDFP_Cart::collect_variables($fields, $submitted);
        $result = HDFP_Formula_Evaluator::evaluate($formula, $variables, array_column($fields, 'key'));

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $price = HDFP_Cart::clamp_price($result, $product_id);

        wp_send_json_success(array('price_html' => wc_price($price)));
    }

    public function render_fields()
    {
        global $product;
        if (!$product || !HDFP_Product::is_enabled($product->get_id())) {
            return;
        }

        $fields = HDFP_Product::get_fields($product->get_id());
        $formula = HDFP_Product::get_formula($product->get_id());
        if (empty($fields) || '' === $formula) {
            return;
        }

        echo '<div class="hdfp-fields" data-base-price="' . esc_attr($product->get_price()) . '">';
        echo '<p class="hdfp-note">' . esc_html__('Enter your measurements to see the price update.', 'hdwebmobile-formula-pricing') . '</p>';

        foreach ($fields as $field) {
            printf(
                '<p class="hdfp-field"><label for="hdfp_%1$s">%2$s</label><input type="number" step="any" id="hdfp_%1$s" name="hdfp_field[%1$s]" class="hdfp-input" min="%3$s" %4$s value="%5$s" required /></p>',
                esc_attr($field['key']),
                esc_html($field['label']),
                esc_attr($field['min']),
                $field['max'] > 0 ? 'max="' . esc_attr($field['max']) . '"' : '',
                esc_attr($field['default'])
            );
        }

        echo '<p class="hdfp-preview">' . esc_html__('Estimated price:', 'hdwebmobile-formula-pricing') . ' <span class="hdfp-preview-amount">' . wp_kses_post(wc_price($product->get_price())) . '</span></p>';
        echo '<p class="hdfp-preview-disclaimer">' . esc_html__('Final price is confirmed when added to cart.', 'hdwebmobile-formula-pricing') . '</p>';
        echo '</div>';
    }
}
