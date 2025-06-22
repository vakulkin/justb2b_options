<?php
/**
 * Plugin Name: JustB2B Options
 * Description: Adds related product options as radio buttons on WooCommerce product pages.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
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
          ['id' => 63, 'min' => 2, 'max' => 15],
          ['id' => 64, 'min' => 16, 'max' => 30],
          ['id' => 64, 'min' => 2, 'max' => 25],
          // ['id' => 1946, 'min' => 2, 'max' => 40],
        ];

        $this->set_min_max_values();

        add_action('woocommerce_before_add_to_cart_quantity', [$this, 'display_related_products']);
        add_action('wp_ajax_justb2b_update_related_products', [$this, 'update_related_products']);
        add_action('wp_ajax_nopriv_justb2b_update_related_products', [$this, 'update_related_products']);
        add_action('wp_footer', [$this, 'enqueue_scripts']);
        add_filter('woocommerce_quantity_input_args', [$this, 'enforce_min_quantity'], 10, 2);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_related_product_to_cart'], 10, 2);
        add_filter('woocommerce_get_item_data', [$this, 'display_related_product_in_cart'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [$this, 'update_cart_item_price'], 20, 1);
    }

    private function is_test_category_product($product_id): bool
    {
        return has_term('test', 'product_cat', $product_id);
    }

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function set_min_max_values()
    {
        $minValues = [];
        $maxValues = [];

        foreach ($this->related_products as $related) {
            if (isset($related['min'])) {
                $minValues[] = $related['min'];
            }
            if (isset($related['max'])) {
                $maxValues[] = $related['max'];
            }
        }

        $this->min_value = (!empty($minValues)) ? min($minValues) : 1;
        $this->max_value = (!empty($maxValues)) ? max($maxValues) : null;
    }

    public function display_related_products()
    {
        global $product;
        if (!$product) {
            return;
        }

        echo '<div id="justb2b_related_products_container" data-product-id="' . esc_attr($product->get_id()) . '">';
        echo $this->generate_related_products_html($product->get_id(), isset($_POST['quantity']) ? absint($_POST['quantity']) : $this->min_value, null);
        echo '</div>';
    }

    public function generate_related_products_html($product_id, $quantity, $selected_option = null)
    {
        $hasProducts = false;
        if ($this->is_test_category_product($product_id)) {
            $html = '<fieldset id="justb2b_related_products_list" style="display: flex; flex-wrap: wrap; gap: 10px; border: none; padding: 0;">';

            $firstProductId = null;
            $optionsHtml = '';

            foreach ($this->related_products as $related) {
                if (($related['min'] === null || $quantity >= $related['min']) && ($related['max'] === null || $quantity <= $related['max'])) {
                    $related_product = wc_get_product($related['id']);
                    if ($related_product) {
                        $hasProducts = true;
                        if (!$firstProductId) {
                            $firstProductId = $related['id'];
                        }

                        $checked = ($selected_option == $related['id']) || (!$selected_option && $firstProductId == $related['id']) ? 'checked' : '';
                        $input_id = 'extra_option_' . $related['id'];

                        $optionsHtml .= sprintf(
                            '<label for="%1$s" style="display: flex; align-items: center; gap: 5px; border: 1px solid #ccc; padding: 5px; cursor: pointer; width: 200px;">
                  <input type="radio" id="%1$s" name="extra_option" value="%2$d" %3$s>
                  <img src="%4$s" alt="%5$s" width="40" height="40" style="border-radius: 5px;">
                  <span>%5$s - %6$s</span>
              </label>',
                            esc_attr($input_id),
                            $related['id'],
                            $checked,
                            esc_url(get_the_post_thumbnail_url($related['id'], 'thumbnail') ?: wc_placeholder_img_src()),
                            esc_attr($related_product->get_name()),
                            wc_price($related_product->get_price())
                        );
                    }
                }
            }

            $html .= $optionsHtml . '</fieldset>';
        }

        return $hasProducts ? $html : '';
    }

    public function enqueue_scripts()
    {
        global $product;
        if (!$product || !$this->is_test_category_product($product->get_id())) {
            return;
        }

        $quantities = range($this->min_value, $this->max_value ?? $this->min_value);
        ?>
<script>
	jQuery(document).ready(function($) {
		const quantities = <?php echo json_encode($quantities); ?> ;
		const Selectors = {
			qtyInput: ".cart .quantity input.qty",
			relatedProductsContainer: "#justb2b_related_products_container",
			radioContainer: "#justb2b_related_products_list",
			radioInputs: "input[name='extra_option']",
		};

		// Create buttons dynamically
		const buttonContainer = $('<div class="quantity-buttons"></div>');
		$.each(quantities, function(index, qty) {
			const button = $('<button class="qty-button" type="button">' + qty + ' ml</button>');
			button.data('quantity', qty);
			buttonContainer.append(button);
		});

		// Insert buttons after quantity input
		const quantityInput = $(Selectors.qtyInput);
		if (quantityInput.length) {
			quantityInput.parent().parent().append(buttonContainer);
		}

		// Handle button click to set quantity
		$('.qty-button').on('click', function() {
			const selectedQty = $(this).data('quantity');
			quantityInput.val(selectedQty).trigger('change');
		});

		// Highlight active button
		function handleQuantityChange(quantity) {
			let buttonActivated = false;
			$('.qty-button').each(function() {
				const buttonQty = $(this).data('quantity');
				if (buttonQty === quantity) {
					$(this).addClass('active');
					buttonActivated = true;
				} else {
					$(this).removeClass('active');
				}
			});
			if (!buttonActivated) {
				$('.qty-button').removeClass('active');
			}
		}

		quantityInput.on('change', function() {
			const qtyValue = parseInt($(this).val(), 10);
			handleQuantityChange(qtyValue);
		});

		handleQuantityChange(quantities[0]);

		// Existing logic for related products
		let isRequestInProgress = false;

		function updateRelatedProducts() {
			const quantity = quantityInput.length ? parseInt(quantityInput.val(), 10) : 1;
			const $relatedContainer = $(Selectors.relatedProductsContainer);
			const productId = parseInt($relatedContainer.data("product-id"), 10);
			const selectedOption = $(Selectors.radioInputs + ":checked").val() || null;

			if (isRequestInProgress || quantity < 1 || !productId) return;

			isRequestInProgress = true;
			$(Selectors.radioContainer).css("opacity", "0.5").css("pointer-events", "none");

			$.ajax({
					url: "<?php echo esc_url(admin_url('admin-ajax.php')); ?>",
					method: "POST",
					data: {
						action: "justb2b_update_related_products",
						nonce: "<?php echo wp_create_nonce('justb2b_nonce'); ?>",
						qty: quantity,
						product_id: productId,
						selected_option: selectedOption,
					},
				})
				.done(function(response) {
					if (response.success) {
						$relatedContainer.html(response.data.html);
						setTimeout(() => {
							const radioInputs = $(Selectors.radioInputs);
							const newSelectedOption = radioInputs.filter("[value='" + selectedOption +
								"']");
							if (newSelectedOption.length) {
								newSelectedOption.prop("checked", true);
							} else if (radioInputs.length) {
								radioInputs.first().prop("checked", true);
							}
						}, 100);
					} else {
						$relatedContainer.html("<p>Error loading options.</p>");
					}
				})
				.fail(function(xhr) {
					console.error("AJAX Error:", xhr.responseText);
				})
				.always(function() {
					isRequestInProgress = false;
					setTimeout(() => {
						$(Selectors.radioContainer).css("opacity", "1").css("pointer-events", "auto");
					}, 300);
				});
		}

		const debounce = (func, delay) => {
			let timer;
			return function(...args) {
				clearTimeout(timer);
				timer = setTimeout(() => func.apply(this, args), delay);
			};
		};

		const debouncedUpdate = debounce(updateRelatedProducts, 500);
		$(document.body).on("input change", Selectors.qtyInput, debouncedUpdate);
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
            if ($this->max_value) {
                $args['max_value'] = $this->max_value;
            }
        }
        return $args;
    }

    public function add_related_product_to_cart($cart_item_data, $product_id)
    {
        $main_product = wc_get_product($product_id);
        if (!$this->is_test_category_product($main_product->get_id())) {
            return $cart_item_data;
        }

        $cart_item_data['individual_id'] = uniqid();
        $locked_quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
        $cart_item_data['locked_quantity'] = $locked_quantity;

        if ($main_product->managing_stock()) {
            $stock_qty = $main_product->get_stock_quantity();
            if ($stock_qty < $locked_quantity) {
                wc_add_notice(sprintf(__('Not enough stock for "%s". Available: %d, Requested: %d.', 'justb2b'), $main_product->get_name(), $stock_qty, $locked_quantity), 'error');
                return false;
            }
        } elseif (!$main_product->is_in_stock()) {
            wc_add_notice(sprintf(__('"%s" is out of stock.', 'justb2b'), $main_product->get_name()), 'error');
            return false;
        }

        $related_product_id = isset($_POST['extra_option']) ? absint($_POST['extra_option']) : $this->related_products[0]['id'];
        $related_product = wc_get_product($related_product_id);

        if (!$related_product || !$related_product->is_in_stock()) {
            foreach ($this->related_products as $related) {
                $fallback = wc_get_product($related['id']);
                if ($fallback && $fallback->is_in_stock()) {
                    $related_product_id = $related['id'];
                    $related_product = $fallback;
                    break;
                }
            }

            if (!$related_product || !$related_product->is_in_stock()) {
                wc_add_notice(__('No valid related product available.', 'justb2b'), 'error');
                return false;
            }
        }

        $cart_item_data['extra_product_id'] = $related_product_id;
        $cart_item_data['extra_product_name'] = $related_product->get_name();
        $cart_item_data['extra_product_price'] = $related_product->get_price();

        return $cart_item_data;
    }

    public function display_related_product_in_cart($item_data, $cart_item)
    {
        if (isset($cart_item['extra_product_name'])) {
            $item_data[] = [
              'name' => __('Selected Option', 'justb2b'),
              'value' => esc_html($cart_item['extra_product_name']),
            ];
        }
        return $item_data;
    }

    public function update_related_products()
    {
        check_ajax_referer('justb2b_nonce', 'nonce');

        $quantity = isset($_POST['qty']) ? max(1, absint($_POST['qty'])) : $this->min_value;
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : null;
        $selected_option = isset($_POST['selected_option']) ? absint($_POST['selected_option']) : null;

        if (!$product_id || $quantity < 1) {
            wp_send_json_error([
              'html' => '<p>' . esc_html__('Invalid product or quantity.', 'justb2b') . '</p>'
            ]);
        }

        $html = $this->generate_related_products_html($product_id, $quantity, $selected_option);
        if (empty($html)) {
            wp_send_json_error([
              'html' => '<p>' . esc_html__('No valid related products found.', 'justb2b') . '</p>'
            ]);
        }

        wp_send_json_success(['html' => $html]);
    }

    public function update_cart_item_price($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['locked_quantity'])) {
                $locked_qty = intval($cart_item['locked_quantity']);
                if ($cart_item['quantity'] !== $locked_qty) {
                    $cart->set_quantity($cart_item_key, $locked_qty, false);
                }
            }

            if (isset($cart_item['extra_product_price'])) {
                $quantity = $cart_item['quantity'];
                if ($quantity > 0) {
                    $additional_price = $cart_item['extra_product_price'] / $quantity;
                    $original_price = $cart_item['data']->get_price();
                    $cart_item['data']->set_price($original_price + $additional_price);
                }
            }
        }
    }
}

JustB2B_Related_Products::get_instance();
