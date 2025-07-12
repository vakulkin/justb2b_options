<?php
/**
 * Plugin Name: JustB2B Options
 * Description: Adds related product options as radio buttons on WooCommerce product pages.
 * Version: 1.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

class JustB2B_Related_Products
{
    private static $instance = null;
    private $related_products;
    private $min_value;
    private $max_value;

    private function __construct()
    {
        $this->related_products = [
            // [ 'id' => 63, 'min' => 2, 'max' => 15, 'free' => 3 ],
            // [ 'id' => 64, 'min' => 16, 'max' => 30 ],
            [ 'id' => 1944, 'min' => 2, 'max' => 15, 'free' => 5 ],
            [ 'id' => 1945, 'min' => 16, 'max' => 30, 'free' => 16 ],
            [ 'id' => 1947, 'min' => 2, 'max' => 25 ],
            [ 'id' => 1946, 'min' => 2, 'max' => 40 ],
        ];

        $this->set_min_max_values();

        add_action('woocommerce_before_add_to_cart_quantity', [ $this, 'display_related_products' ]);
        add_action('wp_ajax_justb2b_update_related_products', [ $this, 'update_related_products' ]);
        add_action('wp_ajax_nopriv_justb2b_update_related_products', [ $this, 'update_related_products' ]);
        add_action('wp_footer', [ $this, 'enqueue_scripts' ]);
        add_filter('woocommerce_quantity_input_args', [ $this, 'enforce_min_quantity' ], 10, 2);
        add_filter('woocommerce_add_cart_item_data', [ $this, 'add_related_product_to_cart' ], 10, 2);
        add_filter('woocommerce_get_item_data', [ $this, 'display_related_product_in_cart' ], 10, 2);
        add_action('woocommerce_before_calculate_totals', [ $this, 'update_cart_item_price' ], 20);
    }

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function is_test_category_product($product_id)
    {
        return has_term('test', 'product_cat', $product_id);
    }

    private function set_min_max_values()
    {
        $mins = array_column($this->related_products, 'min');
        $maxs = array_column($this->related_products, 'max');

        // Fallbacks to avoid issues if arrays are empty
        $custom_min = ! empty($mins) ? min($mins) : 1;
        $custom_max = ! empty($maxs) ? max($maxs) : 100;

        // Get plugin min qty if available
        $plugin_min = 0;
        if (function_exists('wcmmq_get_product_limits') && is_product()) {
            global $product;
            if ($product instanceof \WC_Product) {
                $limits = wcmmq_get_product_limits($product->get_id());
                if (is_array($limits) && isset($limits['min_qty'])) {
                    $plugin_min = (int) $limits['min_qty'];
                }
            }
        }

        $this->min_value = max($custom_min, $plugin_min);
        $this->max_value = $custom_max;
    }

    public function display_related_products()
    {
        global $product;
        if (! $product) {
            return;
        }

        echo '<div id="justb2b_related_products_container" data-product-id="' . esc_attr($product->get_id()) . '">';
        echo $this->generate_related_products_html($product->get_id(), $this->min_value);
        echo '</div>';
    }

    public function generate_related_products_html($product_id, $quantity, $selected_option = null)
    {
        if (! $this->is_test_category_product($product_id)) {
            return '';
        }

        $valid_ids = [];
        foreach ($this->related_products as $related) {
            if (($related['min'] === null || $quantity >= $related['min']) &&
                ($related['max'] === null || $quantity <= $related['max'])) {
                $valid_ids[] = $related['id'];
            }
        }

        if (empty($valid_ids)) {
            return '';
        }

        if (! in_array($selected_option, $valid_ids, true)) {
            $selected_option = $valid_ids[0];
        }

        $html = '<fieldset id="justb2b_related_products_list" style="display: flex; flex-wrap: wrap; gap: 10px; border: none; padding: 0;">';
        foreach ($this->related_products as $related) {
            if (! in_array($related['id'], $valid_ids, true)) {
                continue;
            }

            $product = wc_get_product($related['id']);
            if (! $product) {
                continue;
            }

            $checked = ($related['id'] === $selected_option) ? 'checked' : '';
            $input_id = 'extra_option_' . $related['id'];

            $price = $product->get_price();
            if (isset($related['free']) && $quantity >= $related['free']) {
                $price = 0.00;
            }

            $html .= sprintf(
                '<label for="%1$s" style="display: flex; align-items: center; gap: 5px; border: 1px solid #ccc; padding: 5px; cursor: pointer; width: 200px;">
					<input type="radio" id="%1$s" name="extra_option" value="%2$d" %3$s required>
					<img src="%4$s" alt="%5$s" width="40" height="40" style="border-radius: 5px;">
					<span>%5$s - %6$s</span>
				</label>',
                esc_attr($input_id),
                $related['id'],
                $checked,
                esc_url(get_the_post_thumbnail_url($related['id'], 'thumbnail') ?: wc_placeholder_img_src()),
                esc_attr($product->get_name()),
                wc_price($price)
            );
        }
        $html .= '</fieldset>';
        return $html;
    }

    public function enqueue_scripts()
    {
        global $product;
        if (! $product || ! $this->is_test_category_product($product->get_id())) {
            return;
        }

        $quantities = range($this->min_value, $this->max_value ?? $this->min_value);
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        $nonce = wp_create_nonce('justb2b_nonce');
        ?>
<script>
	jQuery(function($) {
		const quantities = <?php echo json_encode($quantities); ?> ;
		const Selectors = {
			qtyInput: ".cart .quantity input.qty",
			relatedProductsContainer: "#justb2b_related_products_container",
			radioContainer: "#justb2b_related_products_list",
			radioInputs: "input[name='extra_option']"
		};

		// Create quantity buttons
		const buttonContainer = $('<div class="quantity-buttons"></div>');
		quantities.forEach(qty => {
			const btn = $('<button class="qty-button" type="button">' + qty + ' ml</button>');
			btn.data('quantity', qty);
			buttonContainer.append(btn);
		});
		const $qtyInput = $(Selectors.qtyInput);
		if ($qtyInput.length) {
			$qtyInput.closest('.quantity').after(buttonContainer);
		}

		// Button click sets quantity
		$('.qty-button').on('click', function() {
			const qty = $(this).data('quantity');
			$qtyInput.val(qty).trigger('change');
		});

		// Highlight active
		function updateActive(qty) {
			$('.qty-button').each(function() {
				$(this).toggleClass('active', $(this).data('quantity') === qty);
			});
		}
		updateActive(parseInt($qtyInput.val(), 10));

		$qtyInput.on('input change', function() {
			const qty = parseInt($(this).val(), 10);
			updateActive(qty);
			debouncedUpdate();
		});

		let isRequestInProgress = false;

		function updateRelatedProducts() {
			if (isRequestInProgress) return;
			const qty = parseInt($qtyInput.val(), 10);
			const $container = $(Selectors.relatedProductsContainer);
			const productId = parseInt($container.data("product-id"), 10);
			const selectedOption = $(Selectors.radioInputs + ":checked").val() || null;

			if (!qty || !productId) return;

			isRequestInProgress = true;
			$(Selectors.radioContainer).css("opacity", "0.5").css("pointer-events", "none");

			$.post('<?php echo $ajax_url; ?>', {
				action: 'justb2b_update_related_products',
				nonce: '<?php echo $nonce; ?>',
				qty: qty,
				product_id: productId,
				selected_option: selectedOption
			}, function(response) {
				if (response.success) {
					$container.html(response.data.html);
				} else {
					$container.html("<p>Error loading options.</p>");
				}
				isRequestInProgress = false;
			});
		}

		function debounce(fn, delay) {
			let timer;
			return function() {
				clearTimeout(timer);
				timer = setTimeout(fn, delay);
			};
		}
		const debouncedUpdate = debounce(updateRelatedProducts, 300);
	});
</script>
<style>
	.quantity-buttons {
		margin-top: 10px;
	}

	.qty-button {
		margin: 3px;
		padding: 5px 10px;
		border: 1px solid #ccc;
		cursor: pointer;
		background-color: #f8f8f8;
	}

	.qty-button.active {
		background-color: #333;
		color: #fff;
	}
</style>
<?php
    }

    public function enforce_min_quantity($args, $product)
    {
        if ($this->is_test_category_product($product->get_id())) {
            $args['min_value'] = $this->min_value;
            if (! is_cart() && ! is_checkout() && ! wp_doing_ajax()) {
                $args['input_value'] = $this->min_value;
            }
            if ($this->max_value) {
                $args['max_value'] = $this->max_value;
            }
        }
        return $args;
    }

    public function add_related_product_to_cart($cart_item_data, $product_id)
    {
        if (! $this->is_test_category_product($product_id)) {
            return $cart_item_data;
        }

        $cart_item_data['individual_id'] = uniqid();
        $locked_qty = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
        $cart_item_data['locked_quantity'] = $locked_qty;

        $related_id = isset($_POST['extra_option']) ? absint($_POST['extra_option']) : $this->related_products[0]['id'];
        $related = wc_get_product($related_id);

        if (! $related || ! $related->is_in_stock()) {
            foreach ($this->related_products as $rp) {
                $fallback = wc_get_product($rp['id']);
                if ($fallback && $fallback->is_in_stock()) {
                    $related = $fallback;
                    break;
                }
            }
        }

        if (! $related || ! $related->is_in_stock()) {
            wc_add_notice(__('No valid related product available.', 'justb2b'), 'error');
            return false;
        }

        $cart_item_data['extra_product_id'] = $related->get_id();
        return $cart_item_data;
    }



    public function display_related_product_in_cart($item_data, $cart_item)
    {
        if (isset($cart_item['extra_product_id'], $cart_item['quantity'])) {
            $product = wc_get_product($cart_item['extra_product_id']);
            if (! $product) {
                return $item_data;
            }

            $name = $product->get_name();
            $quantity = $cart_item['quantity'];
            $is_free = false;

            foreach ($this->related_products as $related) {
                if ($related['id'] === $cart_item['extra_product_id']) {
                    if (isset($related['free']) && $quantity >= $related['free']) {
                        $is_free = true;
                    }
                    break;
                }
            }

            $price = strip_tags(wc_price($is_free ? 0 : $product->get_price()));
            $name .= ' - ' . $price;

            $item_data[] = [
                'name' => __('Selected Option', 'justb2b'),
                'value' => esc_html($name),
            ];
        }
        return $item_data;
    }


    public function update_related_products()
    {
        check_ajax_referer('justb2b_nonce', 'nonce');

        $qty = isset($_POST['qty']) ? max(1, absint($_POST['qty'])) : 1;
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : null;
        $selected = isset($_POST['selected_option']) ? absint($_POST['selected_option']) : null;

        if (! $product_id || $qty < 1) {
            wp_send_json_error([ 'html' => '<p>' . esc_html__('Invalid input.', 'justb2b') . '</p>' ]);
        }

        $html = $this->generate_related_products_html($product_id, $qty, $selected);

        if (empty($html)) {
            wp_send_json_error([ 'html' => '<p>' . esc_html__('No valid related products.', 'justb2b') . '</p>' ]);
        }

        wp_send_json_success([ 'html' => $html ]);
    }

    public function update_cart_item_price($cart)
    {
        foreach ($cart->get_cart() as $key => $item) {
            // Enforce locked quantity
            if (isset($item['locked_quantity']) && $item['quantity'] !== $item['locked_quantity']) {
                $cart->set_quantity($key, $item['locked_quantity'], false);
            }

            if (! isset($item['extra_product_id'])) {
                continue;
            }

            $related_id = $item['extra_product_id'];
            $related_product = wc_get_product($related_id);
            if (! $related_product) {
                continue;
            }

            $quantity = max(1, $item['quantity']);
            $is_free = false;

            foreach ($this->related_products as $related) {
                if ($related['id'] === $related_id) {
                    if (isset($related['free']) && $quantity >= $related['free']) {
                        $is_free = true;
                    }
                    break;
                }
            }

            $unit_addon = $is_free ? 0 : ($related_product->get_price() / $quantity);
            $current_price = $item['data']->get_price();
            $item['data']->set_price($current_price + $unit_addon);
        }
    }


}

JustB2B_Related_Products::get_instance();
?>