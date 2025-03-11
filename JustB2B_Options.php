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
      ['id' => 236, 'min' => 7, 'max' => 20],
      ['id' => 235, 'min' => 5, 'max' => 30],
    ];

    $this->set_min_max_values();

    add_action('woocommerce_before_add_to_cart_quantity', [$this, 'display_related_products']);
    add_action('wp_ajax_justb2b_update_related_products', [$this, 'update_related_products']);
    add_action('wp_ajax_nopriv_justb2b_update_related_products', [$this, 'update_related_products']);
    add_action('wp_footer', [$this, 'enqueue_scripts']);
    add_filter('woocommerce_quantity_input_args', [$this, 'enforce_min_quantity'], 10, 2);
    add_action('woocommerce_before_calculate_totals', [$this, 'validate_cart_quantities']);

    add_filter('woocommerce_add_cart_item_data', [$this, 'add_related_product_to_cart'], 10, 2);
    add_filter('woocommerce_get_item_data', [$this, 'display_related_product_in_cart'], 10, 2);
    add_action('woocommerce_before_calculate_totals', [$this, 'update_cart_item_price'], 20, 1);
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

  public function generate_related_products_html($quantity, $selected_option = null)
  {
    $html = '<p><strong>Choose an option:</strong></p><div id="justb2b_related_products_list" style="display: flex; flex-wrap: wrap; gap: 10px;">';
    $hasProducts = false;
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
          $optionsHtml .= sprintf(
            '<label style="display: flex; align-items: center; gap: 5px; border: 1px solid #ccc; padding: 5px; cursor: pointer; width: 200px;">
                            <input type="radio" name="extra_option" value="%d" %s>
                            <img src="%s" alt="%s" width="40" height="40" style="border-radius: 5px;">
                            <span>%s - %s</span>
                        </label>',
            $related['id'],
            $checked,
            esc_url(get_the_post_thumbnail_url($related['id'], 'thumbnail') ?: wc_placeholder_img_src()),
            esc_attr($related_product->get_name()),
            esc_html($related_product->get_name()),
            wc_price($related_product->get_price())
          );
        }
      }
    }

    return $hasProducts ? $html . $optionsHtml . '</div>' : '';
  }

  public function display_related_products()
  {
    global $product;
    if (!$product)
      return;

    echo '<div id="justb2b_related_products_container" data-product-id="' . esc_attr($product->get_id()) . '">';
    echo $this->generate_related_products_html($this->min_value, null);
    echo '</div>';
  }

  public function update_related_products()
  {
    check_ajax_referer('justb2b_nonce', 'nonce');
    $quantity = isset($_POST['qty']) ? intval($_POST['qty']) : $this->min_value;
    $selected_option = isset($_POST['selected_option']) ? intval($_POST['selected_option']) : null;
    wp_send_json_success(['html' => $this->generate_related_products_html($quantity, $selected_option)]);
  }

  public function enqueue_scripts()
  {
    if (!is_product())
      return;
    ?>
    <script>
      jQuery(document).ready(function () {
        const Selectors = {
          qtyInput: ".cart .quantity input.qty",
          relatedProductsContainer: "#justb2b_related_products_container",
          radioContainer: "#justb2b_related_products_list",
          radioInputs: "input[name='extra_option']",
        };

        let isRequestInProgress = false;

        function updateRelatedProducts() {
          const $qtyInput = jQuery(Selectors.qtyInput);
          const quantity = $qtyInput.length ? parseInt($qtyInput.val(), 10) : 1;
          const $relatedContainer = jQuery(Selectors.relatedProductsContainer);

          if (isRequestInProgress || quantity < 1) return;

          isRequestInProgress = true;
          const selectedOption = jQuery(Selectors.radioInputs + ":checked").val() || null;

          jQuery(Selectors.radioContainer).css("opacity", "0.5").css("pointer-events", "none");

          jQuery.ajax({
            url: "<?php echo esc_url(admin_url('admin-ajax.php')); ?>",
            method: "POST",
            data: {
              action: "justb2b_update_related_products",
              nonce: "<?php echo wp_create_nonce('justb2b_nonce'); ?>",
              qty: quantity,
              selected_option: selectedOption,
            },
          })
            .done(function (response) {
              if (response.success) {
                $relatedContainer.html(response.data.html);

                setTimeout(() => {
                  const newSelectedOption = jQuery(Selectors.radioInputs + "[value='" + selectedOption + "']");
                  if (newSelectedOption.length) {
                    newSelectedOption.prop("checked", true);
                  } else {
                    jQuery(Selectors.radioInputs).first().prop("checked", true);
                  }
                }, 100);
              } else {
                $relatedContainer.html("<p>Error loading options.</p>");
              }
            })
            .fail(function (xhr) {
              console.error("AJAX Error:", xhr.responseText);
            })
            .always(function () {
              isRequestInProgress = false;
              setTimeout(() => {
                jQuery(Selectors.radioContainer).css("opacity", "1").css("pointer-events", "auto");
              }, 300);
            });
        }

        function debounce(func, delay) {
          let timer;
          return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => func.apply(this, args), delay);
          };
        }

        const debouncedUpdateRelatedProducts = debounce(updateRelatedProducts, 500);

        function initializeEventListeners() {
          jQuery(document.body).on("input change", Selectors.qtyInput, debouncedUpdateRelatedProducts);
        }

        initializeEventListeners();
      });
    </script>
    <?php
  }

  public function enforce_min_quantity($args, $product)
  {
    if (has_term('test', 'product_cat', $product->get_id())) {
      $args['min_value'] = $this->min_value;
      // $args['input_value'] = $this->min_value;
      if ($this->max_value) {
        $args['max_value'] = $this->max_value;
      }
    }
    return $args;
  }

  public function validate_cart_quantities($cart)
  {
    if (is_admin() && !defined('DOING_AJAX'))
      return;
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
      $product = $cart_item['data'];
      if (has_term('test', 'product_cat', $product->get_id()) && $cart_item['quantity'] < $this->min_value) {
        $cart->set_quantity($cart_item_key, $this->min_value);
      }
    }
  }


  public function add_related_product_to_cart($cart_item_data, $product_id)
  {
    $main_product = wc_get_product($product_id);
    if (!has_term('test', 'product_cat', $main_product->get_id())) {
      return $cart_item_data;
    }

    if (isset($_POST['extra_option']) && !empty($_POST['extra_option'])) {
      $related_product_id = intval($_POST['extra_option']);
    }
    else {
      $related_product_id = $this->related_products[0]['id'];
    }

    $related_product = wc_get_product($related_product_id);

    if ($related_product) {
      $cart_item_data['extra_product_id'] = $related_product_id;
      $cart_item_data['extra_product_name'] = $related_product->get_name();
      $cart_item_data['extra_product_price'] = $related_product->get_price();
    }

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

  public function update_cart_item_price($cart)
  {
    if (is_admin() && !defined('DOING_AJAX')) {
      return;
    }

    foreach ($cart->get_cart() as $cart_item) {
      if (isset($cart_item['extra_product_price'])) {
        $quantity = $cart_item['quantity'];
        $additional_price = $cart_item['extra_product_price'] / $quantity;
        $cart_item['data']->set_price($cart_item['data']->get_price() + $additional_price);
      }
    }
  }
}

JustB2B_Related_Products::get_instance();
