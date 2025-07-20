<?php
/**
 * Plugin Name: JustB2B Options
 * Description: Adds related product options as radio buttons on WooCommerce product pages and applies extra product prices as fees.
 * Version: 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JustB2B_Related_Products {
	private static $instance = null;
	private $related_products;
	private $min_max_cache = [];

	private function __construct() {
		$this->related_products = [ 
			[ 'id' => 63, 'min' => 2, 'max' => 15, 'free' => 3 ],
			[ 'id' => 64, 'min' => 16, 'max' => 30 ],
			[ 'id' => 1944, 'min' => 2, 'max' => 15, 'free' => 5 ],
			[ 'id' => 1945, 'min' => 16, 'max' => 30, 'free' => 16 ],
			[ 'id' => 1947, 'min' => 2, 'max' => 25 ],
			[ 'id' => 1946, 'min' => 2, 'max' => 40 ],
		];

		add_action( 'woocommerce_before_add_to_cart_quantity', [ $this, 'display_related_products' ] );
		add_action( 'wp_ajax_justb2b_update_related_products', [ $this, 'update_related_products' ] );
		add_action( 'wp_ajax_nopriv_justb2b_update_related_products', [ $this, 'update_related_products' ] );
		add_action( 'wp_footer', [ $this, 'enqueue_scripts' ] );
		add_filter( 'woocommerce_quantity_input_args', [ $this, 'enforce_min_quantity' ], 10, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', [ $this, 'tnl_custom_enforce_qty' ], 9999, 3 );

		// Store extra product selection
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'capture_extra_option' ], 10, 3 );

		// Add extra product price as a fee
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'add_extra_product_fee' ] );

		// Display extra product name in cart
		add_filter( 'woocommerce_get_item_data', [ $this, 'display_extra_in_cart' ], 10, 2 );
	}

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function is_test_category_product( $product_id ) {
		return has_term( 'test', 'product_cat', $product_id );
	}

	private function get_min_max_values( $product_id ) {
		if ( isset( $this->min_max_cache[ $product_id ] ) ) {
			return $this->min_max_cache[ $product_id ];
		}

		$mins = array_column( $this->related_products, 'min' );
		$maxs = array_column( $this->related_products, 'max' );

		$custom_min = ! empty( $mins ) ? min( $mins ) : 1;
		$custom_max = ! empty( $maxs ) ? max( $maxs ) : 100;

		$plugin_min = 0;
		if ( function_exists( 'wcmmq_get_product_limits' ) ) {
			$limits = wcmmq_get_product_limits( $product_id );
			if ( is_array( $limits ) && isset( $limits['min_qty'] ) ) {
				$plugin_min = (int) $limits['min_qty'];
			}
		}

		$min = max( $custom_min, $plugin_min );
		$max = $custom_max;

		return $this->min_max_cache[ $product_id ] = compact( 'min', 'max' );
	}

	public function display_related_products() {
		global $product;
		if ( ! $product ) {
			return;
		}

		$min_max = $this->get_min_max_values( $product->get_id() );

		echo '<div id="justb2b_related_products_container" data-product-id="' . esc_attr( $product->get_id() ) . '">';
		echo $this->generate_related_products_html( $product->get_id(), $min_max['min'] );
		echo '</div>';
	}

	public function generate_related_products_html( $product_id, $quantity, $selected_option = null ) {
		if ( ! $this->is_test_category_product( $product_id ) ) {
			return '';
		}

		$valid_ids = [];
		foreach ( $this->related_products as $related ) {
			if ( ( $related['min'] === null || $quantity >= $related['min'] ) &&
				( $related['max'] === null || $quantity <= $related['max'] ) ) {
				$valid_ids[] = $related['id'];
			}
		}

		if ( empty( $valid_ids ) ) {
			return '';
		}

		if ( ! in_array( $selected_option, $valid_ids, true ) ) {
			$selected_option = $valid_ids[0];
		}

		$html = '<fieldset id="justb2b_related_products_list" style="display: flex; flex-wrap: wrap; gap: 10px; border: none; padding: 0;">';
		foreach ( $this->related_products as $related ) {
			if ( ! in_array( $related['id'], $valid_ids, true ) ) {
				continue;
			}

			$product = wc_get_product( $related['id'] );
			if ( ! $product ) {
				continue;
			}

			$checked = ( $related['id'] === $selected_option ) ? 'checked' : '';
			$input_id = 'extra_option_' . $related['id'];

			$price = $product->get_price();
			if ( isset( $related['free'] ) && $quantity >= $related['free'] ) {
				$price = 0.00;
			}

			$html .= sprintf(
				'<label for="%1$s" style="display: flex; align-items: center; gap: 5px; border: 1px solid #ccc; padding: 5px; cursor: pointer; width: 200px;">
                    <input type="radio" id="%1$s" name="extra_option" value="%2$d" %3$s required>
                    <img src="%4$s" alt="%5$s" width="40" height="40" style="border-radius: 5px;">
                    <span>%5$s - %6$s</span>
                </label>',
				esc_attr( $input_id ),
				$related['id'],
				$checked,
				esc_url( get_the_post_thumbnail_url( $related['id'], 'thumbnail' ) ?: wc_placeholder_img_src() ),
				esc_attr( $product->get_name() ),
				wc_price( $price )
			);
		}
		$html .= '</fieldset>';
		return $html;
	}

	public function enqueue_scripts() {
		global $product;
		if ( ! $product || ! $this->is_test_category_product( $product->get_id() ) ) {
			return;
		}

		$min_max = $this->get_min_max_values( $product->get_id() );
		$quantities = range( $min_max['min'], $min_max['max'] );
		$ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
		$nonce = wp_create_nonce( 'justb2b_nonce' );
		?>
		<script>
			jQuery(function ($) {
				const quantities = <?php echo json_encode( $quantities ); ?>;
				const Selectors = {
					qtyInput: ".cart .quantity input.qty",
					relatedProductsContainer: "#justb2b_related_products_container",
					radioContainer: "#justb2b_related_products_list",
					radioInputs: "input[name='extra_option']"
				};

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

				$('.qty-button').on('click', function () {
					const qty = $(this).data('quantity');
					$qtyInput.val(qty).trigger('change');
				});

				function updateActive(qty) {
					$('.qty-button').each(function () {
						$(this).toggleClass('active', $(this).data('quantity') === qty);
					});
				}
				updateActive(parseInt($qtyInput.val(), 10));

				$qtyInput.on('input change', function () {
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
					}, function (response) {
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
					return function () {
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

	public function enforce_min_quantity( $args, $product ) {
		$product_id = $product->get_id();
		if ( $this->is_test_category_product( $product_id ) ) {
			$min_max = $this->get_min_max_values( $product_id );
			$args['min_value'] = $min_max['min'];

			if ( ! is_cart() && ! is_checkout() && ! wp_doing_ajax() ) {
				$args['input_value'] = $min_max['min'];
			}

			if ( $min_max['max'] ) {
				$args['max_value'] = $min_max['max'];
			}
		}
		return $args;
	}

	public function tnl_custom_enforce_qty( $button, $product, $args ) {
		$product_id = $product->get_id();

		// Example logic: enforce quantity for "test category" products
		if ( $this->is_test_category_product( $product_id ) ) {
			$min_max = $this->get_min_max_values( $product_id );
			$qty = $min_max['min'];

			// Replace data-quantity in the HTML
			// $button = preg_replace(
			// 	'/data-quantity="\d+"/',
			// 	'data-quantity="' . esc_attr( $qty ) . '"',
			// 	$button
			// );
		}

		return $button;
	}

	public function capture_extra_option( $cart_item_data, $product_id, $variation_id ) {
		if ( isset( $_POST['extra_option'] ) ) {
			$cart_item_data['justb2b_extra_option'] = absint( $_POST['extra_option'] );
		}
		return $cart_item_data;
	}

	public function add_extra_product_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) )
			return;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['justb2b_extra_option'] ) ) {
				$extra_id = $cart_item['justb2b_extra_option'];
				$extra_product = wc_get_product( $extra_id );

				if ( $extra_product ) {
					$price = (float) $extra_product->get_price();
					$qty = $cart_item['quantity'];

					// Apply "free" threshold only if qty condition is met
					foreach ( $this->related_products as $rel ) {
						if ( $rel['id'] == $extra_id && isset( $rel['free'] ) && $qty >= $rel['free'] ) {
							$price = 0;
							break;
						}
					}

					// Add fee **only once per cart item**, not multiplied by quantity
					if ( $price > 0 ) {
						$fee_name = sprintf( __( 'Флакон %s', 'justb2b' ), $cart_item['data']->get_name() );
						$cart->add_fee( $fee_name, $price );
					}
				}
			}
		}
	}

	public function display_extra_in_cart( $item_data, $cart_item ) {
		if ( isset( $cart_item['justb2b_extra_option'] ) ) {
			$extra_id = $cart_item['justb2b_extra_option'];
			$product = wc_get_product( $extra_id );
			if ( $product ) {
				$item_data[] = [ 
					'name' => __( 'Флакон', 'justb2b' ),
					'value' => $product->get_name() . ' (' . wc_price( $product->get_price() ) . ')',
				];
			}
		}
		return $item_data;
	}

	public function update_related_products() {
		check_ajax_referer( 'justb2b_nonce', 'nonce' );

		$qty = isset( $_POST['qty'] ) ? max( 1, absint( $_POST['qty'] ) ) : 1;
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : null;
		$selected = isset( $_POST['selected_option'] ) ? absint( $_POST['selected_option'] ) : null;

		if ( ! $product_id || $qty < 1 ) {
			wp_send_json_error( [ 'html' => '<p>' . esc_html__( 'Invalid input.', 'justb2b' ) . '</p>' ] );
		}

		$html = $this->generate_related_products_html( $product_id, $qty, $selected );

		if ( empty( $html ) ) {
			wp_send_json_error( [ 'html' => '<p>' . esc_html__( 'No valid related products.', 'justb2b' ) . '</p>' ] );
		}

		wp_send_json_success( [ 'html' => $html ] );
	}
}

JustB2B_Related_Products::get_instance();
