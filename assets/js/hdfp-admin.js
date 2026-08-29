(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var container = document.getElementById('hdfp-fields');
        var addButton = document.getElementById('hdfp-add-field');
        var template = document.getElementById('hdfp-field-template');
        if (!container || !addButton || !template) {
            return;
        }

        var nextIndex = container.querySelectorAll('.hdfp-field-row').length;

        addButton.addEventListener('click', function () {
            var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            container.appendChild(wrapper.firstChild);
            nextIndex++;
        });

        container.addEventListener('click', function (event) {
            if (event.target.classList.contains('hdfp-remove-field')) {
                var row = event.target.closest('.hdfp-field-row');
                if (row) {
                    row.remove();
                }
            }
        });

        // Auto-fill a variable name from the label if the merchant hasn't typed one.
        container.addEventListener('input', function (event) {
            if (!event.target.classList.contains('hdfp-field-label')) {
                return;
            }
            var row = event.target.closest('.hdfp-field-row');
            var keyInput = row ? row.querySelector('.hdfp-field-key') : null;
            if (keyInput && !keyInput.dataset.userEdited) {
                keyInput.value = event.target.value
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_+|_+$/g, '');
            }
        });

        container.addEventListener('input', function (event) {
            if (event.target.classList.contains('hdfp-field-key')) {
                event.target.dataset.userEdited = '1';
            }
        });
    });
})();
