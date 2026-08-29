<?php

namespace htrxuan\hdfp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * All server-side price truth lives here. The frontend only ever submits raw
 * numbers for the merchant's own configured fields -- never a price, and never
 * the formula itself. Every price is recomputed fresh from the product's own
 * stored formula via HDFP_Formula_Evaluator, so a tampered request (extra POST
 * keys, an out-of-range number) either gets clamped to the field's configured
 * bounds or is simply ignored.
 */
final class HDFP_Cart
{

    private static $instance = null;

    const CART_ITEM_KEY = 'hdfp_values';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_add_to_cart'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 2);
        add_filter('woocommerce_get_item_data', array($this, 'get_item_data'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array($this, 'adjust_price'));
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_order_line_item_meta'), 10, 4);
    }

    public function validate_add_to_cart($passed, $product_id, $quantity)
    {
        if (!HDFP_Product::is_enabled($product_id)) {
            return $passed;
        }

        $fields = HDFP_Product::get_fields($product_id);
        $formula = HDFP_Product::get_formula($product_id);
        if (empty($fields) || '' === $formula) {
            return $passed;
        }

        $submitted = self::get_submitted_values();

        foreach ($fields as $field) {
            if (!isset($submitted[$field['key']]) || '' === trim((string) $submitted[$field['key']]) || !is_numeric($submitted[$field['key']])) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: field label */
                        __('Please enter a valid number for "%s".', 'hdwebmobile-formula-pricing'),
                        $field['label']
                    ),
                    'error'
                );
                $passed = false;
                continue;
            }

            $value = (float) $submitted[$field['key']];
            if ($value < $field['min'] || ($field['max'] > 0 && $value > $field['max'])) {
                wc_add_notice(
                    sprintf(
                        /* translators: 1: field label, 2: minimum value, 3: maximum value */
                        __('"%1$s" must be between %2$s and %3$s.', 'hdwebmobile-formula-pricing'),
                        $field['label'],
                        $field['min'],
                        $field['max'] > 0 ? $field['max'] : __('unlimited', 'hdwebmobile-formula-pricing')
                    ),
                    'error'
                );
                $passed = false;
            }
        }

        return $passed;
    }

    public function add_cart_item_data($cart_item_data, $product_id)
    {
        if (!HDFP_Product::is_enabled($product_id)) {
            return $cart_item_data;
        }

        $fields = HDFP_Product::get_fields($product_id);
        $formula = HDFP_Product::get_formula($product_id);
        if (empty($fields) || '' === $formula) {
            return $cart_item_data;
        }

        $submitted = self::get_submitted_values();
        $variables = self::collect_variables($fields, $submitted);

        $result = HDFP_Formula_Evaluator::evaluate($formula, $variables, array_column($fields, 'key'));
        if (is_wp_error($result)) {
            return $cart_item_data; // Validation already blocked this; defensively skip rather than fabricate a price.
        }

        $cart_item_data['hdfp_values'] = $variables;
        $cart_item_data['hdfp_price']  = self::clamp_price($result, $product_id);

        return $cart_item_data;
    }

    public function get_item_data($item_data, $cart_item)
    {
        if (empty($cart_item['hdfp_values'])) {
            return $item_data;
        }

        $product_id = $cart_item['product_id'];
        $fields = HDFP_Product::get_fields($product_id);

        foreach ($fields as $field) {
            if (!isset($cart_item['hdfp_values'][$field['key']])) {
                continue;
            }
            $item_data[] = array(
                'key'   => $field['label'],
                'value' => wc_clean($cart_item['hdfp_values'][$field['key']]),
            );
        }

        return $item_data;
    }

    public function adjust_price($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (!isset($cart_item['hdfp_price'])) {
                continue;
            }
            $cart_item['data']->set_price((float) $cart_item['hdfp_price']);
        }
    }

    public function add_order_line_item_meta($item, $cart_item_key, $values, $order)
    {
        if (empty($values['hdfp_values'])) {
            return;
        }

        $product_id = $values['product_id'];
        $fields = HDFP_Product::get_fields($product_id);

        foreach ($fields as $field) {
            if (!isset($values['hdfp_values'][$field['key']])) {
                continue;
            }
            $item->add_meta_data($field['label'], $values['hdfp_values'][$field['key']], true);
        }
    }

    /**
     * Builds the variables map used by the formula evaluator: for every
     * configured field, the submitted numeric value clamped to that field's own
     * min/max, or its default if nothing valid was submitted. Only ever reads
     * the product's own allow-listed field keys -- any other key in $submitted
     * is ignored outright.
     */
    public static function collect_variables(array $fields, array $submitted)
    {
        $variables = array();
        foreach ($fields as $field) {
            $value = isset($submitted[$field['key']]) && is_numeric($submitted[$field['key']])
                ? (float) $submitted[$field['key']]
                : (float) $field['default'];

            $value = max($value, (float) $field['min']);
            if ($field['max'] > 0) {
                $value = min($value, (float) $field['max']);
            }

            $variables[$field['key']] = $value;
        }
        return $variables;
    }

    public static function clamp_price($price, $product_id)
    {
        $price = (float) $price;

        $min = HDFP_Product::get_min_price($product_id);
        $max = HDFP_Product::get_max_price($product_id);

        if (null !== $min) {
            $price = max($price, $min);
        }
        if (null !== $max) {
            $price = min($price, $max);
        }

        return max($price, 0.0); // Never a negative price, regardless of settings.
    }

    private static function get_submitted_values()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's own add-to-cart nonce/session flow is verified upstream (WC_Form_Handler / Store API CartController) before this hook fires; every value is validated as numeric and bounds-checked against the product's own field config immediately below.
        if (!isset($_POST['hdfp_field']) || !is_array($_POST['hdfp_field'])) {
            return array();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see justification above; values are validated as numeric and clamped in collect_variables() before ever reaching the formula evaluator.
        return wp_unslash($_POST['hdfp_field']);
    }
}
