jQuery(function($) {
    const quantities = justb2b_data.quantities;
    const Selectors = justb2b_data.selectors;

    // Cache DOM elements
    const $qtyInput = $(Selectors.qtyInput);
    const $radioContainer = $(Selectors.radioContainer);

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
            renderRelatedProducts(qty);
        });

        // Initial render
        renderRelatedProducts(parseInt($qtyInput.val(), 10));

        function renderRelatedProducts(qty) {
            const validProducts = justb2b_data.related_products.filter(rel =>
                qty >= rel.min && qty <= rel.max
            );

            if (!validProducts.length) {
                $radioContainer.html("<p>No valid options.</p>");
                return;
            }

            // Get currently selected option
            let selectedOption = $radioContainer.find('input[name="extra_option"]:checked').val();

            // If no option selected or selected option is not valid, default to first valid option
            if (!selectedOption || !validProducts.some(rel => rel.id == selectedOption)) {
                selectedOption = validProducts[0].id;
            }

            $radioContainer.html(validProducts.map(rel => {
                let displayPrice = (rel.free && qty >= rel.free) ? 'Безкоштовно' : rel.formatted_price;
                return `
                <label>
                    <input type="radio" name="extra_option" value="${rel.id}" ${rel.id == selectedOption ? 'checked' : ''} required>
                    <img src="${rel.image}" alt="${rel.name}">
                    <span>${rel.name}</span><strong>${displayPrice}</strong>
                </label>
            `;
            }).join(''));
        }
});
