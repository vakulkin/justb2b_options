<?php
/**
 * Plugin Name: JustB2B Options
 * Description: Adds related product options as radio buttons on WooCommerce product pages and applies extra product prices as fees.
 * Version: 2.2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

class JustB2B_Related_Products
{
    private static $instance = null;
    private $related_products;
    private $min_max_cache = [];
    private $target_category;
    private $selectors = [
        'qtyInput' => ".cart .quantity input.qty",
        'relatedProductsContainer' => "#justb2b_related_products_container",
        'radioContainer' => "#justb2b_related_products_list",
        'radioInputs' => "input[name='extra_option']"
    ];

    /**
     * Constructor: Initializes the plugin with hooks and filters.
     */
    private function __construct()
    {
        $this->target_category = apply_filters('justb2b_target_category', 'parfumy');
        $this->related_products = apply_filters('justb2b_related_products', [
            [ 'id' => 140, 'min' => 2, 'max' => 50, 'free' => 5 ],
            [ 'id' => 142, 'min' => 2, 'max' => 10 ],
            [ 'id' => 144, 'min' => 2, 'max' => 10 ],
            [ 'id' => 146, 'min' => 2, 'max' => 10 ],
            [ 'id' => 148, 'min' => 2, 'max' => 10 ],
            [ 'id' => 150, 'min' => 2, 'max' => 10 ],
            [ 'id' => 154, 'min' => 2, 'max' => 10 ],
            [ 'id' => 156, 'min' => 2, 'max' => 10 ],
            [ 'id' => 158, 'min' => 2, 'max' => 10 ],
            [ 'id' => 160, 'min' => 2, 'max' => 20 ],
        ]);
        $this->selectors = apply_filters('justb2b_selectors', $this->selectors);

        add_action('woocommerce_after_add_to_cart_quantity', [ $this, 'display_related_products' ]);
        add_action('wp_ajax_justb2b_update_related_products', [ $this, 'update_related_products' ]);
        add_action('wp_ajax_nopriv_justb2b_update_related_products', [ $this, 'update_related_products' ]);
        add_action('wp_footer', [ $this, 'enqueue_scripts' ]);

        add_filter('woocommerce_quantity_input_args', [ $this, 'enforce_min_quantity' ], 10, 2);
        add_filter('woocommerce_add_to_cart_validation', [ $this, 'enforce_min_qty_on_add_to_cart' ], 10, 3);
        add_filter('woocommerce_add_to_cart_quantity', [ $this, 'force_min_quantity_from_loop' ], 10, 2);

        add_filter('woocommerce_add_cart_item_data', [ $this, 'capture_extra_option' ], 10, 3);
        add_action('woocommerce_before_calculate_totals', [ $this, 'assign_default_option_in_cart' ], 5);
        add_action('woocommerce_before_calculate_totals', [ $this, 'validate_flacon_selection' ], 6);

        add_action('woocommerce_cart_calculate_fees', [ $this, 'add_extra_product_fee' ]);
        add_filter('woocommerce_get_item_data', [ $this, 'display_extra_in_cart' ], 10, 2);

        add_action('woocommerce_before_calculate_totals', [ $this, 'adjust_cart_quantities' ], 20);
    }

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if a product is eligible for related options based on category.
     *
     * @param int $product_id The product ID to check.
     * @return bool True if the product is in the target category, false otherwise.
     */
    private function is_eligible_product($product_id)
    {
        return has_term($this->target_category, 'product_cat', $product_id);
    }

    private function get_min_max_values($product_id)
    {
        if (isset($this->min_max_cache[$product_id])) {
            return $this->min_max_cache[$product_id];
        }

        $mins = array_column($this->related_products, 'min');
        $maxs = array_column($this->related_products, 'max');

        $custom_min = !empty($mins) ? min($mins) : 1;
        $custom_max = !empty($maxs) ? max($maxs) : 100;

        $plugin_min = 0;
        if (function_exists('wcmmq_get_product_limits')) {
            $limits = wcmmq_get_product_limits($product_id);
            if (is_array($limits) && isset($limits['min_qty'])) {
                $plugin_min = (int) $limits['min_qty'];
            }
        }

        $min = max($custom_min, $plugin_min);
        $max = $custom_max;

        return $this->min_max_cache[$product_id] = compact('min', 'max');
    }

    public function display_related_products()
    {
        global $product;
        if (!$product) {
            return;
        }

        $min_max = $this->get_min_max_values($product->get_id());

        echo '<div id="justb2b_related_products_container" data-product-id="' . esc_attr($product->get_id()) . '">';
        echo $this->generate_related_products_html($product->get_id(), $min_max['min']);
        echo '</div>';
    }

    public function generate_related_products_html($product_id, $quantity, $selected_option = null)
    {
        if (!$this->is_eligible_product($product_id)) {
            return '';
        }

        $valid_ids = $this->get_valid_related_ids($quantity);
        if (empty($valid_ids)) {
            return '';
        }

        if (!in_array($selected_option, $valid_ids, true)) {
            $selected_option = $valid_ids[0];
        }

        $html = '<fieldset id="justb2b_related_products_list">';
        foreach ($this->related_products as $related) {
            if (!in_array($related['id'], $valid_ids, true)) {
                continue;
            }

            $product = wc_get_product($related['id']);
            if (!$product) {
                continue;
            }

            $checked = ($related['id'] === $selected_option) ? 'checked' : '';
            $input_id = 'extra_option_' . $related['id'];
            $price = $this->get_flacon_price($related, $quantity);

            $html .= sprintf(
                '<label for="%1$s" class="justb2b-option-card">
                    <input type="radio" id="%1$s" name="extra_option" value="%2$d" %3$s required>
                    <img src="%4$s" alt="%5$s">
                    <span>%5$s <strong>%6$s</strong></span>
                </label>',
                esc_attr($input_id),
                $related['id'],
                $checked,
                esc_url(get_the_post_thumbnail_url($related['id'], 'woocommerce_thumbnail') ?: wc_placeholder_img_src()),
                esc_attr($product->get_name()),
                wc_price($price)
            );
        }
        $html .= '</fieldset>';
        return $html;
    }

    private function get_flacon_price($related, $quantity)
    {
        $product = wc_get_product($related['id']);
        if (!$product) {
            return 0.0;
        }

        $price = (float) $product->get_price();
        if (isset($related['free']) && $quantity >= $related['free']) {
            return 0.0;
        }
        return $price;
    }

    public function enforce_min_quantity($args, $product)
    {
        $product_id = $product->get_id();
        if ($this->is_eligible_product($product_id)) {
            $min_max = $this->get_min_max_values($product_id);
            $args['min_value'] = $min_max['min'];
            $args['max_value'] = $min_max['max'];

            if (!is_cart() && !is_checkout() && !wp_doing_ajax()) {
                $args['input_value'] = $min_max['min'];
            }
        }
        return $args;
    }

    public function enforce_min_qty_on_add_to_cart($passed, $product_id, $quantity)
    {
        if ($this->is_eligible_product($product_id)) {
            $min_max = $this->get_min_max_values($product_id);
            if ($quantity < $min_max['min']) {
                $_REQUEST['quantity'] = $min_max['min'];
            } elseif ($quantity > $min_max['max']) {
                $_REQUEST['quantity'] = $min_max['max'];
            }
        }
        return $passed;
    }

    public function force_min_quantity_from_loop($quantity, $product_id)
    {
        if ($this->is_eligible_product($product_id)) {
            $min_max = $this->get_min_max_values($product_id);
            return max($min_max['min'], min($quantity, $min_max['max']));
        }
        return $quantity;
    }

    public function capture_extra_option($cart_item_data, $product_id, $variation_id)
    {
        if ($this->is_eligible_product($product_id)) {
            $selected_option = isset($_POST['extra_option']) ? absint($_POST['extra_option']) : null;
            if ($selected_option) {
                $cart_item_data['justb2b_last_selected'] = $selected_option;
                $cart_item_data['justb2b_extra_option'] = $selected_option;
            }
            // Unique hash to prevent merging of cart items
            $cart_item_data['justb2b_unique_key'] = uniqid('', true);
        }
        return $cart_item_data;
    }

    public function assign_default_option_in_cart($cart)
    {
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            if ($this->is_eligible_product($product_id)) {
                $quantity = $cart_item['quantity'] ?? 1;
                $valid_ids = $this->get_valid_related_ids($quantity);
                if (!isset($cart_item['justb2b_extra_option']) && !empty($valid_ids)) {
                    $cart_item['justb2b_extra_option'] = $valid_ids[0];
                    $cart->cart_contents[$cart_item_key] = $cart_item;
                }
            }
        }
    }

    public function validate_flacon_selection($cart)
    {
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            if (!$this->is_eligible_product($product_id)) {
                continue;
            }

            $quantity = $cart_item['quantity'] ?? 1;
            $valid_ids = $this->get_valid_related_ids($quantity);

            if (empty($valid_ids)) {
                unset($cart->cart_contents[$cart_item_key]['justb2b_extra_option']);
                continue;
            }

            $current = $cart_item['justb2b_extra_option'] ?? null;
            $last_selected = $cart_item['justb2b_last_selected'] ?? null;

            if ($current && in_array($current, $valid_ids, true)) {
                continue;
            }

            $cart_item['justb2b_extra_option'] = $last_selected && in_array($last_selected, $valid_ids, true)
                ? $last_selected
                : $valid_ids[0];

            $cart->cart_contents[$cart_item_key] = $cart_item;
        }
    }

    public function add_extra_product_fee($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $fees = [];

        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['justb2b_extra_option'])) {
                $extra_id = $cart_item['justb2b_extra_option'];
                $extra_product = wc_get_product($extra_id);
                if ($extra_product) {
                    $price = (float) $extra_product->get_price();
                    $qty = $cart_item['quantity'];

                    foreach ($this->related_products as $rel) {
                        if ($rel['id'] == $extra_id && isset($rel['free']) && $qty >= $rel['free']) {
                            $price = 0;
                            break;
                        }
                    }

                    if ($price > 0) {
                        $fee_key = $extra_product->get_name();

                        if (!isset($fees[$fee_key])) {
                            $fees[$fee_key] = ['price' => 0, 'count' => 0];
                        }
                        $fees[$fee_key]['price'] += $price;
                        $fees[$fee_key]['count'] += 1;
                    }
                }
            }
        }

        // Add aggregated fees with count
        foreach ($fees as $flacon_name => $fee_data) {
            $fee_name = sprintf(__('Флакон: %s x %d', 'justb2b'), $flacon_name, $fee_data['count']);
            $cart->add_fee($fee_name, $fee_data['price']);
        }
    }

    public function display_extra_in_cart($item_data, $cart_item)
    {
        if (isset($cart_item['justb2b_extra_option'])) {
            $extra_id = $cart_item['justb2b_extra_option'];
            $product = wc_get_product($extra_id);

            if ($product) {
                $related_data = null;
                foreach ($this->related_products as $rel) {
                    if ($rel['id'] == $extra_id) {
                        $related_data = $rel;
                        break;
                    }
                }

                $price = 0.0;
                if ($related_data) {
                    $price = $this->get_flacon_price($related_data, $cart_item['quantity']);
                }

                $item_data[] = [
                    'name'  => __('Флакон', 'justb2b'),
                    'value' => $product->get_name() . ' (' . wc_price($price) . ')',
                ];
            }
        }
        return $item_data;
    }

    public function update_related_products()
    {
        check_ajax_referer('justb2b_nonce', 'nonce');

        $qty = isset($_POST['qty']) ? max(1, absint($_POST['qty'])) : 1;
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : null;
        $selected = isset($_POST['selected_option']) ? absint($_POST['selected_option']) : null;

        if (!$product_id || $qty < 1) {
            wp_send_json_error(['html' => '<p>' . esc_html__('Invalid input.', 'justb2b') . '</p>']);
        }

        $html = $this->generate_related_products_html($product_id, $qty, $selected);

        if (empty($html)) {
            wp_send_json_error(['html' => '<p>' . esc_html__('No valid related products.', 'justb2b') . '</p>']);
        }

        wp_send_json_success(['html' => $html]);
    }

    public function adjust_cart_quantities($cart)
    {
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            if ($this->is_eligible_product($product_id)) {
                $min_max = $this->get_min_max_values($product_id);
                if ($cart_item['quantity'] < $min_max['min']) {
                    $cart->set_quantity($cart_item_key, $min_max['min']);
                } elseif ($cart_item['quantity'] > $min_max['max']) {
                    $cart->set_quantity($cart_item_key, $min_max['max']);
                }
            }
        }
    }

    private function get_valid_related_ids($quantity)
    {
        $valid_ids = [];
        foreach ($this->related_products as $related) {
            if ((empty($related['min']) || $quantity >= $related['min']) &&
                (empty($related['max']) || $quantity <= $related['max'])) {
                $valid_ids[] = $related['id'];
            }
        }
        return $valid_ids;
    }

    public function enqueue_scripts()
    {
        global $product;
        if (! $product || ! $this->is_eligible_product($product->get_id())) {
            return;
        }

        $min_max = $this->get_min_max_values($product->get_id());
        $quantities = range($min_max['min'], $min_max['max']);
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        $nonce = wp_create_nonce('justb2b_nonce');

        wp_enqueue_style('justb2b-style', plugin_dir_url(__FILE__) . 'justb2b-style.css', [], '1.0.0');
        wp_enqueue_script('justb2b-script', plugin_dir_url(__FILE__) . 'justb2b-script.js', ['jquery'], '1.0.0', true);

        wp_localize_script('justb2b-script', 'justb2b_data', [
            'quantities' => $quantities,
            'selectors' => $this->selectors,
            'ajax_url' => $ajax_url,
            'nonce' => $nonce
        ]);
    }
}

JustB2B_Related_Products::get_instance();
?>