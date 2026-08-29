(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var wrapper = document.querySelector('.hdfp-fields');
        if (!wrapper || typeof window.hdfpSettings === 'undefined') {
            return;
        }

        var inputs = wrapper.querySelectorAll('.hdfp-input');
        var previewAmount = wrapper.querySelector('.hdfp-preview-amount');
        var debounceTimer = null;

        function requestPreview() {
            var formData = new FormData();
            formData.append('action', 'hdfp_preview');
            formData.append('nonce', window.hdfpSettings.nonce);
            formData.append('product_id', window.hdfpSettings.productId);

            inputs.forEach(function (input) {
                var key = input.id.replace(/^hdfp_/, '');
                formData.append('hdfp_field[' + key + ']', input.value);
            });

            fetch(window.hdfpSettings.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (json) {
                    if (json.success && previewAmount) {
                        previewAmount.innerHTML = json.data.price_html;
                    }
                })
                .catch(function () {
                    // Preview is cosmetic only -- the real price is always confirmed at add-to-cart.
                });
        }

        inputs.forEach(function (input) {
            input.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(requestPreview, 300);
            });
        });
    });
})();
