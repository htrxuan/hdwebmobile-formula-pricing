<?php

namespace htrxuan\hdfp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds a "Formula Pricing" tab to the Product Data metabox, mirroring the pattern
 * used by Product Options & Add-ons (woocommerce_product_data_tabs / _panels /
 * process_product_meta). The panel is a small vanilla-JS repeater for defining
 * numeric input fields, plus a single formula text field referencing those fields
 * by name -- the formula itself is validated server-side by
 * HDFP_Formula_Evaluator::validate_formula() on save, never trusted as-typed.
 */
class HDFP_Admin
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
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'render_product_data_panel'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_meta'));
        add_action('admin_notices', array($this, 'render_formula_error_notice'));
        require_once HDFP_PLUGIN_DIR . 'includes/class-hdfp-hub.php';
        add_filter('hdwebmobile_hub_tabs', array($this, 'register_hub_tabs'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public function add_product_data_tab($tabs)
    {
        $tabs['hdfp'] = array(
            'label'    => __('Formula Pricing', 'hdwebmobile-formula-pricing'),
            'target'   => 'hdfp_product_data',
            'class'    => array('show_if_simple'),
            'priority' => 23,
        );
        return $tabs;
    }

    public function enqueue_admin_assets($hook)
    {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }

        global $post;
        if (!$post || 'product' !== $post->post_type) {
            return;
        }

        wp_enqueue_style('hdfp-admin', HDFP_PLUGIN_URL . 'assets/css/hdfp-admin.css', array(), self::asset_version('assets/css/hdfp-admin.css'));
        wp_enqueue_script('hdfp-admin', HDFP_PLUGIN_URL . 'assets/js/hdfp-admin.js', array(), self::asset_version('assets/js/hdfp-admin.js'), true);
    }

    private static function asset_version($relative_path)
    {
        $path = HDFP_PLUGIN_DIR . $relative_path;
        return file_exists($path) ? (string) filemtime($path) : HDFP_VERSION;
    }

    public function render_product_data_panel()
    {
        global $post;

        $product_id = $post->ID;
        $enabled    = HDFP_Product::is_enabled($product_id);
        $fields     = HDFP_Product::get_fields($product_id);
        $formula    = HDFP_Product::get_formula($product_id);
        $min_price  = HDFP_Product::get_min_price($product_id);
        $max_price  = HDFP_Product::get_max_price($product_id);

        wp_nonce_field('hdfp_save_meta', 'hdfp_meta_nonce');
        ?>
        <div id="hdfp_product_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <p style="padding: 0 12px;">
                    <?php esc_html_e('Let customers enter measurements (width, height, quantity, etc.) and price the product from a formula you write using those field names. The formula is checked for safety when you save -- it can only use +, -, *, /, parentheses, numbers, and your own field names below.', 'hdwebmobile-formula-pricing'); ?>
                </p>

                <p class="form-field" style="padding: 0 12px;">
                    <label for="hdfp_enabled">
                        <input type="checkbox" id="hdfp_enabled" name="hdfp_enabled" value="1" <?php checked($enabled); ?> />
                        <?php esc_html_e('Enable formula pricing for this product', 'hdwebmobile-formula-pricing'); ?>
                    </label>
                </p>

                <div id="hdfp-fields" class="hdfp-fields-repeater">
                    <?php foreach ($fields as $index => $field) : ?>
                        <?php echo self::render_field_row($index, $field); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field_row() escapes all interpolated values internally. ?>
                    <?php endforeach; ?>
                </div>

                <p style="padding: 0 12px;">
                    <button type="button" class="button" id="hdfp-add-field"><?php esc_html_e('+ Add Field', 'hdwebmobile-formula-pricing'); ?></button>
                </p>

                <p class="form-field" style="padding: 0 12px;">
                    <label for="hdfp_formula"><?php esc_html_e('Formula', 'hdwebmobile-formula-pricing'); ?></label>
                    <input type="text" id="hdfp_formula" name="hdfp_formula" class="hdfp-formula-input" value="<?php echo esc_attr($formula); ?>" placeholder="<?php esc_attr_e('e.g. 10 + width * height * 0.05', 'hdwebmobile-formula-pricing'); ?>" style="width:100%;" />
                    <span class="description"><?php esc_html_e('Use your field names above exactly as shown. Only numbers and + - * / ( ) are allowed.', 'hdwebmobile-formula-pricing'); ?></span>
                </p>

                <p class="form-field" style="padding: 0 12px;">
                    <label for="hdfp_min_price"><?php esc_html_e('Minimum price (optional)', 'hdwebmobile-formula-pricing'); ?></label>
                    <input type="number" step="0.01" min="0" id="hdfp_min_price" name="hdfp_min_price" value="<?php echo esc_attr(null !== $min_price ? $min_price : ''); ?>" />
                </p>
                <p class="form-field" style="padding: 0 12px;">
                    <label for="hdfp_max_price"><?php esc_html_e('Maximum price (optional)', 'hdwebmobile-formula-pricing'); ?></label>
                    <input type="number" step="0.01" min="0" id="hdfp_max_price" name="hdfp_max_price" value="<?php echo esc_attr(null !== $max_price ? $max_price : ''); ?>" />
                </p>
            </div>
        </div>

        <template id="hdfp-field-template">
            <?php echo self::render_field_row('__INDEX__', array('key' => '', 'label' => '', 'min' => '', 'max' => '', 'default' => '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field_row() escapes all interpolated values internally. ?>
        </template>
        <?php
    }

    private static function render_field_row($index, $field)
    {
        $key     = isset($field['key']) ? $field['key'] : '';
        $label   = isset($field['label']) ? $field['label'] : '';
        $min     = isset($field['min']) ? $field['min'] : '';
        $max     = isset($field['max']) ? $field['max'] : '';
        $default = isset($field['default']) ? $field['default'] : '';

        ob_start();
        ?>
        <div class="hdfp-field-row" data-index="<?php echo esc_attr($index); ?>">
            <input type="text" class="hdfp-field-label" placeholder="<?php esc_attr_e('Field label (e.g. Width in cm)', 'hdwebmobile-formula-pricing'); ?>" name="hdfp_fields[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr($label); ?>" />
            <input type="text" class="hdfp-field-key" placeholder="<?php esc_attr_e('Variable name (e.g. width)', 'hdwebmobile-formula-pricing'); ?>" name="hdfp_fields[<?php echo esc_attr($index); ?>][key]" value="<?php echo esc_attr($key); ?>" />
            <input type="number" step="any" class="hdfp-field-min" placeholder="<?php esc_attr_e('Min', 'hdwebmobile-formula-pricing'); ?>" name="hdfp_fields[<?php echo esc_attr($index); ?>][min]" value="<?php echo esc_attr($min); ?>" />
            <input type="number" step="any" class="hdfp-field-max" placeholder="<?php esc_attr_e('Max', 'hdwebmobile-formula-pricing'); ?>" name="hdfp_fields[<?php echo esc_attr($index); ?>][max]" value="<?php echo esc_attr($max); ?>" />
            <input type="number" step="any" class="hdfp-field-default" placeholder="<?php esc_attr_e('Default', 'hdwebmobile-formula-pricing'); ?>" name="hdfp_fields[<?php echo esc_attr($index); ?>][default]" value="<?php echo esc_attr($default); ?>" />
            <button type="button" class="button-link hdfp-remove-field" aria-label="<?php esc_attr_e('Remove field', 'hdwebmobile-formula-pricing'); ?>">&times;</button>
        </div>
        <?php
        return ob_get_clean();
    }

    public function save_product_meta($post_id)
    {
        if (!isset($_POST['hdfp_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdfp_meta_nonce'])), 'hdfp_save_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $enabled = !empty($_POST['hdfp_enabled']);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; every field is sanitized inside HDFP_Product::save(), and the formula is independently re-validated there via the safe evaluator before ever being stored.
        $raw_fields = isset($_POST['hdfp_fields']) && is_array($_POST['hdfp_fields']) ? wp_unslash($_POST['hdfp_fields']) : array();
        $raw_formula = isset($_POST['hdfp_formula']) ? sanitize_text_field(wp_unslash($_POST['hdfp_formula'])) : '';
        $raw_min = isset($_POST['hdfp_min_price']) ? sanitize_text_field(wp_unslash($_POST['hdfp_min_price'])) : '';
        $raw_max = isset($_POST['hdfp_max_price']) ? sanitize_text_field(wp_unslash($_POST['hdfp_max_price'])) : '';

        $result = HDFP_Product::save($post_id, $enabled, $raw_fields, $raw_formula, $raw_min, $raw_max);

        if (is_wp_error($result)) {
            set_transient('hdfp_formula_error_' . get_current_user_id(), $result->get_error_message(), 60);
        }
    }

    public function render_formula_error_notice()
    {
        $message = get_transient('hdfp_formula_error_' . get_current_user_id());
        if (!$message) {
            return;
        }
        delete_transient('hdfp_formula_error_' . get_current_user_id());
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php printf(/* translators: %s: the formula validation error */ esc_html__('Formula Pricing: %s The formula was not saved.', 'hdwebmobile-formula-pricing'), esc_html($message)); ?></p>
        </div>
        <?php
    }

    public function register_hub_tabs($tabs)
    {
        $tabs['formula-pricing'] = array(
            'label'  => __('Formula Pricing', 'hdwebmobile-formula-pricing'),
            'order'  => 105,
            'render' => array($this, 'render_plugins_page'),
        );
        return $tabs;
    }

    public function render_plugins_page()
    {
        ?>
        <p><?php esc_html_e('Price a product from customer-entered numbers using a formula -- evaluated by a safe expression parser, never PHP\'s eval(). There\'s nothing to configure here -- go to any simple product\'s own "Formula Pricing" tab under Product Data to set it up.', 'hdwebmobile-formula-pricing'); ?></p>
        <?php
    }
}
