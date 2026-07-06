(function () {
    'use strict';

    const selector = 'textarea[name="descripcion"]:not([data-beply-rich-product-ready])';
    let scheduled = false;

    function enhance(textarea) {
        if (!textarea || textarea.dataset.beplyRichProductReady === '1') {
            return;
        }

        textarea.dataset.beplyRichProductReady = '1';
        textarea.dataset.beplyRichEditor = '1';
        textarea.classList.add('beply-rich-source', 'beply-rich-product-source');

        const outer = textarea.parentElement;
        if (!outer) {
            return;
        }

        outer.classList.add('beply-rich-product-wrap');

        let field = textarea.closest('.beply-rich-product-field');
        if (!field) {
            field = document.createElement('div');
            field.className = 'beply-rich-product-field';
            outer.insertBefore(field, textarea);
            field.appendChild(textarea);
        }

        if (!field.querySelector('[data-beply-rich-product-button="1"]')) {
            const actions = document.createElement('div');
            actions.className = 'beply-rich-product-actions';
            actions.innerHTML = ''
                + '<button type="button" class="btn btn-sm btn-light beply-rich-product-button"'
                + ' data-beply-rich-product-button="1" data-beply-rich-open="' + textarea.name + '" title="Editor">'
                + '<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>'
                + '</button>';
            field.insertBefore(actions, textarea);
        }

        if (window.BeplyRichDescription && typeof window.BeplyRichDescription.initAll === 'function') {
            window.BeplyRichDescription.initAll(field);
            return;
        }

        document.dispatchEvent(new CustomEvent('beply-rich-description:init', {detail: {root: field}}));
    }

    function init(root) {
        (root || document).querySelectorAll(selector).forEach(enhance);
    }

    function schedule(root) {
        if (scheduled) {
            return;
        }
        scheduled = true;
        window.requestAnimationFrame(function () {
            scheduled = false;
            init(root || document);
        });
    }

    function observeChanges() {
        if (!document.body) {
            return;
        }

        new MutationObserver(function (mutations) {
            for (const mutation of mutations) {
                if (mutation.addedNodes.length > 0) {
                    schedule(document);
                    return;
                }
            }
        }).observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
            observeChanges();
        });
    } else {
        init(document);
        observeChanges();
    }
})();
