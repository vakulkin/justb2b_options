<?php
/**
 * Plugin Name: JustB2B Options
 * Description: Adds related product options as radio buttons on WooCommerce product pages and applies extra product prices as fees.
 * Version: 2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JustB2B_Related_Products {
	private static $instance = null;
	private $related_products;
	private $min_max_cache = [];
	private $target_category;
	private $selectors = [
		'qtyInput' => ".cart .quantity input.qty",
		'radioContainer' => "#justb2b_related_products_list",
	];

	/**
	 * Constructor: Initializes the plugin with hooks and filters.
	 */
	private function __construct() {
		$this->target_category = apply_filters( 'justb2b_target_category', 'rozpyv' );
		$this->related_products = apply_filters( 'justb2b_related_products', [
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
		] );
		$this->selectors = apply_filters( 'justb2b_selectors', $this->selectors );

		add_action( 'woocommerce_after_add_to_cart_quantity', [ $this, 'display_related_products' ] );
		add_action( 'wp_footer', [ $this, 'enqueue_scripts' ] );

		add_filter( 'woocommerce_quantity_input_args', [ $this, 'enforce_min_quantity' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'enforce_min_qty_on_add_to_cart' ], 10, 3 );
		add_filter( 'woocommerce_add_to_cart_quantity', [ $this, 'force_min_quantity_from_loop' ], 10, 2 );

		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'capture_extra_option' ], 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'ensure_extra_option_in_cart' ], 5 );

		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'add_extra_product_fee' ] );
		add_filter( 'woocommerce_get_item_data', [ $this, 'display_extra_in_cart' ], 10, 2 );

		add_action( 'woocommerce_before_calculate_totals', [ $this, 'adjust_cart_quantities' ], 20 );

		// Display extra option in order details, thank you page, admin, and emails
		add_action( 'woocommerce_order_item_meta_end', [ $this, 'display_extra_in_order' ], 10, 4 );
		add_action( 'woocommerce_after_order_itemmeta', [ $this, 'display_extra_in_admin_order' ], 10, 1 );
		add_action( 'woocommerce_email_order_meta', [ $this, 'display_extra_in_email' ], 10, 3 );

		// Save extra option to order item meta
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_extra_option_to_order' ], 10, 4 );
	}
	public static function get_instance() {
		if ( self::$instance === null ) {
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
	private function is_eligible_product( $product_id ) {
		return has_term( $this->target_category, 'product_cat', $product_id );
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

	private function clamp_quantity( $quantity, $min, $max ) {
		return max( $min, min( $quantity, $max ) );
	}

	private function get_related_data_by_id( $id ) {
		foreach ( $this->related_products as $rel ) {
			if ( $rel['id'] == $id ) {
				return $rel;
			}
		}
		return null;
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
		if ( ! $this->is_eligible_product( $product_id ) ) {
			return '';
		}

		$valid_ids = $this->get_valid_related_ids( $quantity );
		if ( empty( $valid_ids ) ) {
			return '';
		}

		if ( ! in_array( $selected_option, $valid_ids, true ) ) {
			$selected_option = $valid_ids[0];
		}

		$html = '<fieldset id="justb2b_related_products_list">';
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
			$price = $this->get_flacon_price( $related, $quantity );

			$price_display = ( $price == 0 ) ? 'Безкоштовно' : wc_price( $price );

			$html .= sprintf(
				'<label for="%1$s">
                    <input type="radio" id="%1$s" name="extra_option" value="%2$d" %3$s required>
                    <img src="%4$s" alt="%5$s">
                    <span>%5$s</span>
                    <strong>%6$s</strong>
                </label>',
				esc_attr( $input_id ),
				$related['id'],
				$checked,
				esc_url( get_the_post_thumbnail_url( $related['id'], 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src() ),
				esc_attr( $product->get_name() ),
				$price_display
			);
		}
		$html .= '</fieldset>';
		return $html;
	}

	private function get_flacon_price( $related, $quantity ) {
		$product = wc_get_product( $related['id'] );
		if ( ! $product ) {
			return 0.0;
		}

		$price = (float) $product->get_price();
		if ( isset( $related['free'] ) && $quantity >= $related['free'] ) {
			return 0.0;
		}
		return $price;
	}

	public function enforce_min_quantity( $args, $product ) {
		$product_id = $product->get_id();
		if ( $this->is_eligible_product( $product_id ) ) {
			$min_max = $this->get_min_max_values( $product_id );
			$args['min_value'] = $min_max['min'];
			$args['max_value'] = $min_max['max'];

			if ( ! is_cart() && ! is_checkout() && ! wp_doing_ajax() ) {
				$args['input_value'] = $min_max['min'];
			}
		}
		return $args;
	}

	public function enforce_min_qty_on_add_to_cart( $passed, $product_id, $quantity ) {
		if ( $this->is_eligible_product( $product_id ) ) {
			$min_max = $this->get_min_max_values( $product_id );
			$_REQUEST['quantity'] = $this->clamp_quantity( $quantity, $min_max['min'], $min_max['max'] );
		}
		return $passed;
	}

	public function force_min_quantity_from_loop( $quantity, $product_id ) {
		if ( $this->is_eligible_product( $product_id ) ) {
			$min_max = $this->get_min_max_values( $product_id );
			return $this->clamp_quantity( $quantity, $min_max['min'], $min_max['max'] );
		}
		return $quantity;
	}

	public function capture_extra_option( $cart_item_data, $product_id, $variation_id ) {
		if ( $this->is_eligible_product( $product_id ) ) {
			$selected_option = isset( $_POST['extra_option'] ) ? absint( $_POST['extra_option'] ) : null;
			if ( $selected_option ) {
				$cart_item_data['justb2b_last_selected'] = $selected_option;
				$cart_item_data['justb2b_extra_option'] = $selected_option;
			}
			// Unique hash to prevent merging of cart items
			$cart_item_data['justb2b_unique_key'] = uniqid( '', true );
		}
		return $cart_item_data;
	}

	public function save_extra_option_to_order( $item, $cart_item_key, $values, $order ) {
		if ( isset( $values['justb2b_extra_option'] ) ) {
			$item->add_meta_data( 'justb2b_extra_option', $values['justb2b_extra_option'] );
		}
	}

	public function ensure_extra_option_in_cart( $cart ) {
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product_id = $cart_item['product_id'];
			if ( ! $this->is_eligible_product( $product_id ) ) {
				continue;
			}

			$quantity = $cart_item['quantity'] ?? 1;
			$valid_ids = $this->get_valid_related_ids( $quantity );

			if ( empty( $valid_ids ) ) {
				unset( $cart->cart_contents[ $cart_item_key ]['justb2b_extra_option'] );
				continue;
			}

			$current = $cart_item['justb2b_extra_option'] ?? null;
			$last_selected = $cart_item['justb2b_last_selected'] ?? null;

			if ( ! $current || ! in_array( $current, $valid_ids, true ) ) {
				$cart_item['justb2b_extra_option'] = $last_selected && in_array( $last_selected, $valid_ids, true )
					? $last_selected
					: $valid_ids[0];
				$cart->cart_contents[ $cart_item_key ] = $cart_item;
			}
		}
	}

	public function add_extra_product_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$fees = [];

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['justb2b_extra_option'] ) ) {
				$extra_id = $cart_item['justb2b_extra_option'];
				$rel = $this->get_related_data_by_id( $extra_id );
				if ( $rel ) {
					$price = $this->get_flacon_price( $rel, $cart_item['quantity'] );
					if ( $price > 0 ) {
						$extra_product = wc_get_product( $extra_id );
						$fee_key = $extra_product ? $extra_product->get_name() : 'Unknown';

						if ( ! isset( $fees[ $fee_key ] ) ) {
							$fees[ $fee_key ] = [ 'price' => 0, 'count' => 0 ];
						}
						$fees[ $fee_key ]['price'] += $price;
						$fees[ $fee_key ]['count'] += 1;
					}
				}
			}
		}

		// Add aggregated fees with count
		foreach ( $fees as $flacon_name => $fee_data ) {
			$fee_name = sprintf( __( 'Флакон: %s x %d', 'justb2b' ), $flacon_name, $fee_data['count'] );
			$cart->add_fee( $fee_name, $fee_data['price'] );
		}
	}

	public function display_extra_in_cart( $item_data, $cart_item ) {
		if ( isset( $cart_item['justb2b_extra_option'] ) ) {
			$extra_id = $cart_item['justb2b_extra_option'];
			$product = wc_get_product( $extra_id );

			if ( $product ) {
				$related_data = $this->get_related_data_by_id( $extra_id );
				$price = $related_data ? $this->get_flacon_price( $related_data, $cart_item['quantity'] ) : 0.0;

				$item_data[] = [
					'name' => $product->get_name(),
					'value' => wc_price( $price )
				];
			}
		}
		return $item_data;
	}

	public function display_extra_in_order( $item_id, $item, $order, $plain_text = false ) {
		$extra_id = $item->get_meta( 'justb2b_extra_option' );
		if ( $extra_id ) {
			$product = wc_get_product( $extra_id );
			if ( $product ) {
				$related_data = $this->get_related_data_by_id( $extra_id );
				$price = $related_data ? $this->get_flacon_price( $related_data, $item->get_quantity() ) : 0.0;
				echo "\n" . $product->get_name() . ': ' . wc_price( $price );
			}
		}
	}

	public function display_extra_in_admin_order( $item_id ) {
		$item = new WC_Order_Item_Product( $item_id );
		$extra_id = $item->get_meta( 'justb2b_extra_option' );
		if ( $extra_id ) {
			$product = wc_get_product( $extra_id );
			if ( $product ) {
				$related_data = $this->get_related_data_by_id( $extra_id );
				$price = $related_data ? $this->get_flacon_price( $related_data, $item->get_quantity() ) : 0.0;
				echo '<p><strong>' . esc_html__( 'Extra Option:', 'justb2b' ) . '</strong> ' . esc_html( $product->get_name() ) . ' - ' . wc_price( $price ) . '</p>';
			}
		}
	}

	public function display_extra_in_email( $order, $sent_to_admin, $plain_text ) {
		$has_extra = false;
		foreach ( $order->get_items() as $item_id => $item ) {
			$extra_id = $item->get_meta( 'justb2b_extra_option' );
			if ( $extra_id ) {
				$has_extra = true;
				break;
			}
		}
		if ( ! $has_extra ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . __( 'Extra Options:', 'justb2b' ) . "\n";
			foreach ( $order->get_items() as $item_id => $item ) {
				$extra_id = $item->get_meta( 'justb2b_extra_option' );
				if ( $extra_id ) {
					$product = wc_get_product( $extra_id );
					if ( $product ) {
						$related_data = $this->get_related_data_by_id( $extra_id );
						$price = $related_data ? $this->get_flacon_price( $related_data, $item->get_quantity() ) : 0.0;
						echo $item->get_name() . ': ' . $product->get_name() . ' - ' . wc_price( $price ) . "\n";
					}
				}
			}
		} else {
			echo '<h3>' . __( 'Extra Options:', 'justb2b' ) . '</h3>';
			echo '<ul>';
			foreach ( $order->get_items() as $item_id => $item ) {
				$extra_id = $item->get_meta( 'justb2b_extra_option' );
				if ( $extra_id ) {
					$product = wc_get_product( $extra_id );
					if ( $product ) {
						$related_data = $this->get_related_data_by_id( $extra_id );
						$price = $related_data ? $this->get_flacon_price( $related_data, $item->get_quantity() ) : 0.0;
						echo '<li><strong>' . esc_html( $item->get_name() ) . ':</strong> ' . esc_html( $product->get_name() ) . ' - ' . wc_price( $price ) . '</li>';
					}
				}
			}
			echo '</ul>';
		}
	}

	public function adjust_cart_quantities( $cart ) {
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product_id = $cart_item['product_id'];
			if ( $this->is_eligible_product( $product_id ) ) {
				$min_max = $this->get_min_max_values( $product_id );
				$clamped = $this->clamp_quantity( $cart_item['quantity'], $min_max['min'], $min_max['max'] );
				if ( $clamped !== $cart_item['quantity'] ) {
					$cart->set_quantity( $cart_item_key, $clamped );
				}
			}
		}
	}

	private function get_valid_related_ids( $quantity ) {
		$valid_ids = [];
		foreach ( $this->related_products as $related ) {
			if ( ( empty( $related['min'] ) || $quantity >= $related['min'] ) &&
				( empty( $related['max'] ) || $quantity <= $related['max'] ) ) {
				$valid_ids[] = $related['id'];
			}
		}
		return $valid_ids;
	}

	public function enqueue_scripts() {
		global $product;
		if ( ! $product || ! $this->is_eligible_product( $product->get_id() ) ) {
			return;
		}

		$min_max = $this->get_min_max_values( $product->get_id() );
		$quantities = range( $min_max['min'], $min_max['max'] );

		$related_products_data = array_map( function ( $rel ) {
			$product = wc_get_product( $rel['id'] );
			return [
				'id' => $rel['id'],
				'min' => $rel['min'] ?? 0,
				'max' => $rel['max'] ?? PHP_INT_MAX,
				'free' => $rel['free'] ?? null,
				'name' => $product ? $product->get_name() : '',
				'image' => get_the_post_thumbnail_url( $rel['id'], 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src(),
				'formatted_price' => $product ? wc_price( $product->get_price() ) : ''
			];
		}, $this->related_products );

		wp_enqueue_style( 'justb2b-style', plugin_dir_url( __FILE__ ) . 'justb2b-style.css', [], '1.0.0' );
		wp_enqueue_script( 'justb2b-script', plugin_dir_url( __FILE__ ) . 'justb2b-script.js', [ 'jquery' ], '1.0.0', true );

		wp_localize_script( 'justb2b-script', 'justb2b_data', [
			'quantities' => $quantities,
			'selectors' => $this->selectors,
			'related_products' => $related_products_data
		] );
	}
}

JustB2B_Related_Products::get_instance();
?>