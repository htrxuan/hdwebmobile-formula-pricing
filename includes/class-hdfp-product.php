<?php

namespace htrxuan\hdfp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads/writes a product's formula-pricing configuration. All meta is stored via
 * this class only, so there is exactly one place that decides what counts as a
 * valid field key or formula -- no other code in this plugin ever writes this meta
 * directly.
 */
class HDFP_Product
{

    const META_ENABLED  = '_hdfp_enabled';
    const META_FIELDS   = '_hdfp_fields';
    const META_FORMULA  = '_hdfp_formula';
    const META_MIN_PRICE = '_hdfp_min_price';
    const META_MAX_PRICE = '_hdfp_max_price';

    const MAX_FIELDS = 6;

    public static function is_enabled($product_id)
    {
        return 'yes' === get_post_meta($product_id, self::META_ENABLED, true);
    }

    public static function get_fields($product_id)
    {
        $fields = get_post_meta($product_id, self::META_FIELDS, true);
        return is_array($fields) ? $fields : array();
    }

    public static function get_formula($product_id)
    {
        return (string) get_post_meta($product_id, self::META_FORMULA, true);
    }

    public static function get_min_price($product_id)
    {
        $value = get_post_meta($product_id, self::META_MIN_PRICE, true);
        return '' === $value ? null : (float) $value;
    }

    public static function get_max_price($product_id)
    {
        $value = get_post_meta($product_id, self::META_MAX_PRICE, true);
        return '' === $value ? null : (float) $value;
    }

    public static function get_field_keys($product_id)
    {
        return array_column(self::get_fields($product_id), 'key');
    }

    /**
     * Sanitizes and saves the whole configuration in one call. Returns true on
     * success or a WP_Error (e.g. the formula is invalid) -- the caller decides
     * whether to still save the fields/enabled flag with an empty formula, or
     * reject the whole save. We choose to always save the fields as submitted
     * (they're just labels/bounds, no execution risk) but only save the formula
     * if it validates against the *just-sanitized* field keys.
     */
    public static function save($product_id, $enabled, array $raw_fields, $raw_formula, $raw_min_price, $raw_max_price)
    {
        $fields = self::sanitize_fields($raw_fields);
        update_post_meta($product_id, self::META_FIELDS, $fields);
        update_post_meta($product_id, self::META_ENABLED, $enabled ? 'yes' : 'no');

        $min_price = '' !== trim((string) $raw_min_price) ? wc_format_decimal($raw_min_price) : '';
        $max_price = '' !== trim((string) $raw_max_price) ? wc_format_decimal($raw_max_price) : '';
        update_post_meta($product_id, self::META_MIN_PRICE, $min_price);
        update_post_meta($product_id, self::META_MAX_PRICE, $max_price);

        $formula = trim((string) $raw_formula);
        $field_keys = array_column($fields, 'key');

        if ('' === $formula || empty($field_keys)) {
            update_post_meta($product_id, self::META_FORMULA, '');
            return true;
        }

        $validation = HDFP_Formula_Evaluator::validate_formula($formula, $field_keys);
        if (is_wp_error($validation)) {
            update_post_meta($product_id, self::META_FORMULA, '');
            return $validation;
        }

        update_post_meta($product_id, self::META_FORMULA, $formula);
        return true;
    }

    private static function sanitize_fields(array $raw_fields)
    {
        $sanitized = array();
        $seen_keys = array();

        foreach (array_slice($raw_fields, 0, self::MAX_FIELDS) as $raw_field) {
            $label = isset($raw_field['label']) ? sanitize_text_field($raw_field['label']) : '';
            if ('' === $label) {
                continue;
            }

            $key = isset($raw_field['key']) ? self::sanitize_key_name($raw_field['key']) : '';
            if ('' === $key) {
                $key = self::sanitize_key_name($label);
            }
            if ('' === $key || isset($seen_keys[$key])) {
                continue; // Skip fields whose key can't be made valid/unique -- never silently rename into a collision.
            }
            $seen_keys[$key] = true;

            $min = isset($raw_field['min']) && '' !== $raw_field['min'] ? (float) $raw_field['min'] : 0.0;
            $max = isset($raw_field['max']) && '' !== $raw_field['max'] ? (float) $raw_field['max'] : 0.0;
            $default = isset($raw_field['default']) && '' !== $raw_field['default'] ? (float) $raw_field['default'] : $min;

            if ($max > 0 && $max < $min) {
                $max = $min;
            }

            $sanitized[] = array(
                'key'     => $key,
                'label'   => $label,
                'min'     => $min,
                'max'     => $max, // 0 means "no upper bound".
                'default' => $default,
            );
        }

        return $sanitized;
    }

    /**
     * A formula variable name must be a plain identifier the tokenizer will actually
     * accept: letters/digits/underscore, starting with a letter or underscore. This
     * is intentionally the exact same shape the tokenizer itself requires, so a key
     * that passes here can never later be rejected as "not an allowed variable" for
     * a spelling-normalization reason -- it just won't exist as a valid field at all.
     */
    private static function sanitize_key_name($raw)
    {
        $key = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $raw));
        $key = preg_replace('/^[0-9]+/', '', $key); // Can't start with a digit.
        $key = trim($key, '_');
        return $key;
    }
}
