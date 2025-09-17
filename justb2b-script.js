jQuery(function($) {
    const quantities = justb2b_data.quantities;
    const Selectors = justb2b_data.selectors;

    // Cache DOM elements
    const $qtyInput = $(Selectors.qtyInput);
    const $container = $(Selectors.relatedProductsContainer);
    const $radioContainer = $(Selectors.radioContainer);
    const $radioInputs = $(Selectors.radioInputs);

    // Early return if no quantity input
    if (!$qtyInput.length) return;

    // Create button container
    const $buttonContainer = $('<div class="quantity-buttons"></div>');
    quantities.forEach(qty => {
        const $btn = $('<button class="qty-button" type="button">' + qty + ' ml</button>');
        $btn.data('quantity', qty);
        $buttonContainer.append($btn);
    });

    // Insert buttons after quantity wrapper
    $qtyInput.closest('.quantity').after($buttonContainer);

    // Event delegation for quantity buttons
    $buttonContainer.on('click', '.qty-button', function() {
        const qty = $(this).data('quantity');
        $qtyInput.val(qty).trigger('change');
    });

    // Optimized updateActive function
    function updateActive(qty) {
        $buttonContainer.find('.qty-button').each(function() {
            $(this).toggleClass('active', $(this).data('quantity') === qty);
        });
    }

    // Initial active state
    updateActive(parseInt($qtyInput.val(), 10));

    // Quantity input change handler
    $qtyInput.on('input change', function() {
        const qty = parseInt($(this).val(), 10);
        updateActive(qty);
        debouncedUpdate();
    });

    let isRequestInProgress = false;

    function updateRelatedProducts() {
        if (isRequestInProgress) return;

        const qty = parseInt($qtyInput.val(), 10);
        const productId = parseInt($container.data("product-id"), 10);
        const selectedOption = $radioInputs.filter(':checked').val() || null;

        if (!qty || !productId) return;

        isRequestInProgress = true;
        $radioContainer.css({ opacity: 0.5, 'pointer-events': 'none' });

        $.ajax({
            url: justb2b_data.ajax_url,
            method: 'POST',
            data: {
                action: 'justb2b_update_related_products',
                nonce: justb2b_data.nonce,
                qty: qty,
                product_id: productId,
                selected_option: selectedOption
            },
            success: function(response) {
                if (response.success) {
                    $container.html(response.data.html);
                } else {
                    $container.html("<p>Error loading options.</p>");
                }
            },
            error: function() {
                $container.html("<p>Error loading options.</p>");
            },
            complete: function() {
                isRequestInProgress = false;
                $radioContainer.css({ opacity: '', 'pointer-events': '' });
            }
        });
    }

    // Improved debounce function
    function debounce(func, delay) {
        let timeoutId;
        return function(...args) {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => func.apply(this, args), delay);
        };
    }

    const debouncedUpdate = debounce(updateRelatedProducts, 300);
});
