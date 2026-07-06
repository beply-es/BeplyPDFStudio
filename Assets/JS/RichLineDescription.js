(function () {
    'use strict';

    const sourceSelector = [
        'textarea.beply-rich-source[name]',
        'textarea.doc-line-desc[name^="descripcion_"]',
        'textarea[name="observaciones"]',
        'textarea[name="footer_text"]',
        'textarea[name="texto"]'
    ].join(',');
    let activeSource = null;
    let activeMode = 'visual';
    let quillEditor = null;
    let modalInstance = null;
    let scheduled = false;

    function closeList(type) {
        return type ? '</' + type + '>' : '';
    }

    function emitChange(textarea) {
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
        textarea.dispatchEvent(new Event('change', {bubbles: true}));
        textarea.dispatchEvent(new KeyboardEvent('keyup', {bubbles: true}));
    }

    function editorLabel(textarea) {
        if (!textarea) {
            return 'Editor';
        }
        if (textarea.dataset.beplyRichLabel) {
            return textarea.dataset.beplyRichLabel;
        }
        if (textarea.name === 'observaciones') {
            return 'Observaciones';
        }
        if (textarea.name === 'footer_text' || textarea.name === 'texto') {
            return 'Texto final / legal';
        }
        return 'Descripcion';
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }

        return String(value).replace(/["\\]/g, '\\$&');
    }

    function flushParagraph(lines) {
        return lines.length ? '<p>' + lines.map(formatInline).join('<br>') + '</p>' : '';
    }

    function formatInline(text) {
        let out = escapeHtml(text.trim());
        out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        out = out.replace(/__([^_]+)__/g, '<strong>$1</strong>');
        out = out.replace(/(^|[^\*])\*([^*\s][^*]*)\*(?!\*)/g, '$1<em>$2</em>');
        return out.replace(/(^|[^_])_([^_\s][^_]*)_(?!_)/g, '$1<em>$2</em>');
    }

    function hasRichMarkdown(text) {
        const value = String(text || '').replace(/\r\n?/g, '\n').trim();
        if (value === '') {
            return false;
        }

        return /(^|\n)\s{0,3}(#{1,3}\s+|[-*]\s+|\d+[.)]\s+)/.test(value)
            || /(\*\*[^*]+\*\*|__[^_]+__|(^|[^\*])\*[^*\s][^*]*\*(?!\*)|(^|[^_])_[^_\s][^_]*_(?!_))/.test(value);
    }

    function htmlChildrenToMarkdown(node) {
        const lines = [];
        node.childNodes.forEach((child) => {
            const markdown = htmlNodeToMarkdown(child);
            if (markdown !== '') {
                lines.push(markdown);
            }
        });
        return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
    }

    function wrapInlineMarkdown(text, marker) {
        const value = String(text || '');
        const parts = value.match(/^(\s*)([\s\S]*?)(\s*)$/);
        if (!parts || parts[2] === '') {
            return value;
        }

        return parts[1] + marker + parts[2] + marker + parts[3];
    }

    function htmlInlineToMarkdown(node) {
        if (node.nodeType === Node.TEXT_NODE) {
            return (node.nodeValue || '').replace(/\u00a0/g, ' ');
        }
        if (node.nodeType !== Node.ELEMENT_NODE || node.classList.contains('ql-ui')) {
            return '';
        }

        const tag = node.tagName.toLowerCase();
        if (tag === 'br') {
            return '\n';
        }

        let text = '';
        node.childNodes.forEach((child) => {
            text += htmlInlineToMarkdown(child);
        });

        if ((tag === 'strong' || tag === 'b') && text.trim() !== '') {
            return wrapInlineMarkdown(text, '**');
        }
        if ((tag === 'em' || tag === 'i') && text.trim() !== '') {
            return wrapInlineMarkdown(text, '*');
        }
        return text;
    }

    function htmlNodeToMarkdown(node) {
        if (node.nodeType === Node.TEXT_NODE) {
            return (node.nodeValue || '').trim();
        }
        if (node.nodeType !== Node.ELEMENT_NODE || node.classList.contains('ql-ui')) {
            return '';
        }

        const tag = node.tagName.toLowerCase();
        if (tag === 'br') {
            return '';
        }
        if (/^h[1-6]$/.test(tag)) {
            return htmlInlineToMarkdown(node).trim();
        }
        if (tag === 'ul' || tag === 'ol') {
            let index = 1;
            const items = [];
            node.querySelectorAll(':scope > li').forEach((li) => {
                const bullet = tag === 'ul' || li.getAttribute('data-list') === 'bullet';
                const marker = bullet ? '- ' : (index++) + '. ';
                items.push(marker + htmlInlineToMarkdown(li).trim());
            });
            return items.join('\n');
        }
        if (tag === 'li') {
            const bullet = node.getAttribute('data-list') === 'bullet';
            return (bullet ? '- ' : '1. ') + htmlInlineToMarkdown(node).trim();
        }
        if (tag === 'p') {
            return htmlInlineToMarkdown(node).trim();
        }
        if (['div', 'section', 'article'].indexOf(tag) !== -1) {
            const hasBlockChildren = Array.from(node.children).some((child) => {
                const childTag = child.tagName.toLowerCase();
                return /^h[1-6]$/.test(childTag) || ['div', 'p', 'ul', 'ol', 'section', 'article'].indexOf(childTag) !== -1;
            });
            return hasBlockChildren ? htmlChildrenToMarkdown(node) : htmlInlineToMarkdown(node).trim();
        }

        return htmlInlineToMarkdown(node).trim();
    }

    function normalizeMarkdown(value) {
        const text = String(value || '').replace(/\r\n?/g, '\n').trim();
        if (text === '' || !/<[a-z][\s\S]*>/i.test(text)) {
            return text;
        }

        const wrapper = document.createElement('div');
        wrapper.innerHTML = text;
        return htmlChildrenToMarkdown(wrapper) || wrapper.textContent.trim();
    }

    function renderLite(text, editorTags) {
        const lines = String(text || '').replace(/\r\n?/g, '\n').trim().split('\n');
        let html = '';
        let paragraph = [];
        let listType = null;

        lines.forEach((line) => {
            const trimmed = line.trim();
            let match;
            if (trimmed === '') {
                html += flushParagraph(paragraph) + closeList(listType);
                paragraph = [];
                listType = null;
                return;
            }
            if ((match = line.match(/^\s{0,3}(#{1,3})\s+(.+)$/))) {
                html += flushParagraph(paragraph) + closeList(listType);
                paragraph = [];
                listType = null;
                html += flushParagraph([match[2]]);
                return;
            }
            if ((match = line.match(/^\s{0,3}[-*]\s+(.+)$/))) {
                html += flushParagraph(paragraph);
                paragraph = [];
                if (listType !== 'ul') {
                    html += closeList(listType) + '<ul>';
                    listType = 'ul';
                }
                html += '<li>' + formatInline(match[1]) + '</li>';
                return;
            }
            if ((match = line.match(/^\s{0,3}\d+[.)]\s+(.+)$/))) {
                html += flushParagraph(paragraph);
                paragraph = [];
                if (listType !== 'ol') {
                    html += closeList(listType) + '<ol>';
                    listType = 'ol';
                }
                html += '<li>' + formatInline(match[1]) + '</li>';
                return;
            }

            html += closeList(listType);
            listType = null;
            paragraph.push(line);
        });

        html += flushParagraph(paragraph) + closeList(listType);
        return html || '<p><br></p>';
    }

    function setQuillFromMarkdown(markdown) {
        if (!quillEditor) {
            return;
        }

        quillEditor.setText('', 'silent');
        quillEditor.clipboard.dangerouslyPasteHTML(renderLite(markdown, true), 'silent');
    }

    function getQuillMarkdown() {
        if (!quillEditor) {
            return '';
        }

        const wrapper = document.createElement('div');
        if (typeof quillEditor.getSemanticHTML === 'function') {
            wrapper.innerHTML = quillEditor.getSemanticHTML();
        } else {
            wrapper.innerHTML = quillEditor.root.innerHTML;
        }
        return htmlChildrenToMarkdown(wrapper);
    }

    function cleanMarkdown(text) {
        return String(text || '')
            .replace(/^\s{0,3}#{1,3}\s+/gm, '')
            .replace(/^\s{0,3}[-*]\s+/gm, '')
            .replace(/^\s{0,3}\d+[.)]\s+/gm, '')
            .replace(/\*\*([^*]+)\*\*/g, '$1')
            .replace(/__([^_]+)__/g, '$1')
            .replace(/(^|[^\*])\*([^*\s][^*]*)\*(?!\*)/g, '$1$2')
            .replace(/(^|[^_])_([^_\s][^_]*)_(?!_)/g, '$1$2');
    }

    function setMarkdownSelection(textarea, start, end, replacement, cursorStart, cursorEnd) {
        const value = textarea.value;
        textarea.value = value.substring(0, start) + replacement + value.substring(end);
        textarea.focus();
        textarea.setSelectionRange(cursorStart, cursorEnd);
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
    }

    function transformSelectedLines(textarea, callback) {
        const value = textarea.value;
        const selectionStart = textarea.selectionStart;
        const selectionEnd = textarea.selectionEnd;
        const start = value.lastIndexOf('\n', Math.max(0, selectionStart - 1)) + 1;
        let end = value.indexOf('\n', selectionEnd);
        if (end === -1) {
            end = value.length;
        }

        let index = 0;
        const replacement = value.substring(start, end).split('\n').map((line) => {
            if (line.trim() === '') {
                return line;
            }

            index++;
            return callback(line, index);
        }).join('\n');

        setMarkdownSelection(textarea, start, end, replacement, start, start + replacement.length);
    }

    function applyMarkdownAction(modal, action, value) {
        const textarea = modal.querySelector('.beply-rich-md');
        if (!textarea) {
            return;
        }

        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selected = textarea.value.substring(start, end);

        if (action === 'bold' || action === 'italic') {
            const marker = action === 'bold' ? '**' : '*';
            const replacement = selected === '' ? marker + marker : wrapInlineMarkdown(selected, marker);
            const cursorStart = selected === '' ? start + marker.length : start;
            const cursorEnd = selected === '' ? cursorStart : start + replacement.length;
            setMarkdownSelection(textarea, start, end, replacement, cursorStart, cursorEnd);
            return;
        }

        if (action === 'list') {
            transformSelectedLines(textarea, (line, index) => {
                const clean = line.replace(/^\s{0,3}([-*]|\d+[.)])\s+/, '');
                return value === 'ordered' ? index + '. ' + clean : '- ' + clean;
            });
            return;
        }

        if (action === 'clean') {
            const replacement = cleanMarkdown(selected || textarea.value);
            if (selected === '') {
                setMarkdownSelection(textarea, 0, textarea.value.length, replacement, 0, replacement.length);
            } else {
                setMarkdownSelection(textarea, start, end, replacement, start, start + replacement.length);
            }
        }
    }

    function createModal() {
        let modal = document.getElementById('beply-rich-desc-modal');
        if (modal) {
            return modal;
        }

        modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = 'beply-rich-desc-modal';
        modal.tabIndex = -1;
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = ''
            + '<div class="modal-dialog modal-xl modal-dialog-centered">'
            + '<div class="modal-content beply-rich-modal">'
            + '<div class="modal-header">'
            + '<h5 class="modal-title"><i class="fa-solid fa-pen-to-square me-2" aria-hidden="true"></i><span data-beply-rich-title>Editor</span></h5>'
            + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>'
            + '</div>'
            + '<div class="modal-body">'
            + '<div class="beply-rich-toolbar-row">'
            + '<select class="form-select form-select-sm beply-rich-mode-select" data-beply-rich-mode>'
            + '<option value="visual" selected>Visual</option>'
            + '<option value="markdown">Markdown</option>'
            + '</select>'
            + '<div class="beply-quill-toolbar ql-toolbar ql-snow">'
            + '<span class="ql-formats"><button type="button" class="ql-bold"></button><button type="button" class="ql-italic"></button></span>'
            + '<span class="ql-formats"><button type="button" class="ql-list" value="ordered"></button><button type="button" class="ql-list" value="bullet"></button></span>'
            + '<span class="ql-formats"><button type="button" class="ql-clean"></button></span>'
            + '</div>'
            + '</div>'
            + '<div class="beply-quill-wrap"><div class="beply-quill-editor"></div></div>'
            + '<textarea class="form-control beply-rich-md d-none" rows="16"></textarea>'
            + '</div>'
            + '<div class="modal-footer">'
            + '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>'
            + '<button type="button" class="btn btn-primary" data-beply-rich-save><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Aceptar</button>'
            + '</div>'
            + '</div>'
            + '</div>';
        document.body.appendChild(modal);

        modal.querySelector('[data-beply-rich-mode]').addEventListener('change', function (event) {
            setMode(modal, event.target.value);
        });
        modal.querySelector('.beply-quill-toolbar').addEventListener('click', function (event) {
            if (activeMode !== 'markdown') {
                return;
            }

            const button = event.target.closest('button');
            if (!button || !modal.contains(button)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            if (button.classList.contains('ql-bold')) {
                applyMarkdownAction(modal, 'bold');
            } else if (button.classList.contains('ql-italic')) {
                applyMarkdownAction(modal, 'italic');
            } else if (button.classList.contains('ql-list')) {
                applyMarkdownAction(modal, 'list', button.value);
            } else if (button.classList.contains('ql-clean')) {
                applyMarkdownAction(modal, 'clean');
            }
        }, true);

        modal.querySelector('[data-beply-rich-save]').addEventListener('click', function () {
            if (activeSource) {
                activeSource.value = getModalMarkdown(modal).trim();
                refreshLineState(activeSource);
                emitChange(activeSource);
            }
            getModalInstance(modal).hide();
        });

        modal.addEventListener('shown.bs.modal', function () {
            if (activeMode === 'visual' && quillEditor) {
                quillEditor.focus();
            } else {
                const markdown = modal.querySelector('.beply-rich-md');
                if (markdown) {
                    markdown.focus();
                }
            }
        });

        return modal;
    }

    function createQuillEditor(container, initialValue) {
        const modal = container.closest('.beply-rich-modal');
        const toolbar = modal ? modal.querySelector('.beply-quill-toolbar') : null;
        container.className = 'beply-quill-editor';
        container.innerHTML = '';
        if (!window.Quill) {
            return null;
        }

        const quill = new window.Quill(container, {
            theme: 'snow',
            formats: ['bold', 'italic', 'list'],
            modules: {
                toolbar: toolbar || [
                    ['bold', 'italic'],
                    [{list: 'ordered'}, {list: 'bullet'}],
                    ['clean']
                ]
            }
        });
        quillEditor = quill;
        setQuillFromMarkdown(initialValue || '');
        return quill;
    }

    function getModalInstance(modal) {
        if (!window.bootstrap || !window.bootstrap.Modal) {
            return {
                hide: function () {
                    modal.classList.remove('show');
                    modal.style.display = 'none';
                },
                show: function () {
                    modal.classList.add('show');
                    modal.style.display = 'block';
                }
            };
        }

        modalInstance = modalInstance || window.bootstrap.Modal.getOrCreateInstance(modal);
        return modalInstance;
    }

    function getModalMarkdown(modal) {
        const markdown = modal.querySelector('.beply-rich-md');
        if (activeMode === 'markdown' || !quillEditor) {
            return markdown ? markdown.value : '';
        }
        return getQuillMarkdown();
    }

    function hideInlineTextarea(textarea) {
        if (!textarea.beplyRichSurface) {
            return;
        }

        textarea.value = normalizeMarkdown(textarea.value);
        refreshLineState(textarea);
    }

    function isLocked(textarea) {
        return !textarea || textarea.disabled || textarea.readOnly || textarea.dataset.beplyRichLocked === '1';
    }

    function showInlineTextarea(textarea) {
        if (isLocked(textarea) || !textarea.beplyRichSurface) {
            return;
        }

        textarea.beplyRichSurface.classList.add('d-none');
        textarea.classList.remove('d-none');
        window.requestAnimationFrame(function () {
            textarea.focus();
            textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        });
    }

    function refreshLineState(textarea) {
        if (!textarea.beplyRichSurface) {
            return;
        }

        textarea.value = normalizeMarkdown(textarea.value);
        syncLineSurface(textarea);

        if (hasRichMarkdown(textarea.value)) {
            textarea.classList.add('d-none');
            textarea.beplyRichSurface.classList.remove('d-none');
            textarea.beplyRichSurface.classList.add('beply-rich-surface-readonly');
            textarea.beplyRichSurface.classList.toggle('beply-rich-surface-locked', isLocked(textarea));
            textarea.beplyRichSurface.setAttribute('aria-readonly', 'true');
            return;
        }

        textarea.beplyRichSurface.classList.add('d-none');
        textarea.beplyRichSurface.classList.remove('beply-rich-surface-readonly');
        textarea.beplyRichSurface.classList.remove('beply-rich-surface-locked');
        textarea.beplyRichSurface.removeAttribute('aria-readonly');
        textarea.classList.remove('d-none');
    }

    function setMode(modal, mode) {
        const visual = modal.querySelector('.beply-quill-wrap');
        const markdown = modal.querySelector('.beply-rich-md');
        const select = modal.querySelector('[data-beply-rich-mode]');
        activeMode = mode === 'markdown' ? 'markdown' : 'visual';

        if (activeMode === 'markdown') {
            if (markdown) {
                markdown.value = quillEditor ? getQuillMarkdown() : markdown.value;
                markdown.classList.remove('d-none');
                markdown.focus();
            }
            if (visual) {
                visual.classList.add('d-none');
            }
        } else {
            if (markdown && quillEditor) {
                setQuillFromMarkdown(markdown.value);
            }
            if (markdown) {
                markdown.classList.add('d-none');
            }
            if (visual) {
                visual.classList.remove('d-none');
            }
            if (quillEditor) {
                quillEditor.focus();
            }
        }

        if (select) {
            select.value = activeMode;
        }
    }

    function initSource(textarea) {
        if (textarea.dataset.beplyRichReady === '1') {
            return;
        }
        textarea.dataset.beplyRichReady = '1';
        textarea.value = normalizeMarkdown(textarea.value);

        const name = textarea.getAttribute('name');
        const isProductDescription = textarea.classList.contains('beply-rich-product-source');
        const isLineDescription = textarea.classList.contains('doc-line-desc') || /^descripcion_/.test(name || '');
        if (!isProductDescription && !isLineDescription) {
            enhanceInlineField(textarea, name);
        }

        const parent = textarea.parentElement;
        if (!parent || !name) {
            return;
        }

        let surface = parent.querySelector('[data-beply-rich-for="' + cssEscape(name) + '"]');
        if (!surface) {
            surface = document.createElement('div');
            parent.insertBefore(surface, textarea);
        }

        if (isProductDescription) {
            surface.className = 'form-control beply-rich-surface beply-rich-product-surface';
        } else if (isLineDescription) {
            surface.className = 'form-control form-control-sm border-0 beply-rich-surface';
        } else {
            surface.className = 'form-control beply-rich-surface beply-rich-generic-surface';
        }
        surface.dataset.beplyRichFor = name;
        surface.setAttribute('aria-label', editorLabel(textarea));
        surface.setAttribute('role', 'textbox');
        surface.setAttribute('tabindex', '0');
        surface.innerHTML = renderLite(textarea.value, false);
        textarea.beplyRichSurface = surface;

        surface.addEventListener('click', function () {
            if (isLocked(textarea)) {
                return;
            }
            if (hasRichMarkdown(textarea.value)) {
                openModal(name);
            } else {
                showInlineTextarea(textarea);
            }
        });
        surface.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (isLocked(textarea)) {
                    return;
                }
                if (hasRichMarkdown(textarea.value)) {
                    openModal(name);
                } else {
                    showInlineTextarea(textarea);
                }
            }
        });
        textarea.addEventListener('blur', function () {
            refreshLineState(textarea);
        });
        textarea.addEventListener('input', function () {
            syncLineSurface(textarea);
        });
        textarea.addEventListener('change', function () {
            refreshLineState(textarea);
        });
        refreshLineState(textarea);
    }

    function openModal(sourceName) {
        activeSource = document.querySelector('textarea[name="' + cssEscape(sourceName) + '"]');
        if (!activeSource || isLocked(activeSource)) {
            return;
        }
        activeSource.value = normalizeMarkdown(activeSource.value);
        syncLineSurface(activeSource);

        const modal = createModal();
        const title = modal.querySelector('[data-beply-rich-title]');
        if (title) {
            title.textContent = editorLabel(activeSource);
        }
        const container = modal.querySelector('.beply-quill-editor');
        const markdown = modal.querySelector('.beply-rich-md');
        if (markdown) {
            markdown.value = activeSource.value;
        }

        quillEditor = null;
        createQuillEditor(container, activeSource.value);
        setMode(modal, quillEditor ? 'visual' : 'markdown');
        getModalInstance(modal).show();
    }

    function syncLineSurface(textarea) {
        if (textarea.beplyRichSurface) {
            textarea.beplyRichSurface.innerHTML = renderLite(textarea.value, false);
        }
    }

    function enhanceInlineField(textarea, name) {
        if (!textarea || !name || textarea.dataset.beplyRichInlineReady === '1') {
            return;
        }
        textarea.dataset.beplyRichInlineReady = '1';
        textarea.dataset.beplyRichEditor = '1';
        textarea.classList.add('beply-rich-source', 'beply-rich-generic-source');

        const parent = textarea.parentElement;
        if (!parent) {
            return;
        }

        let field = textarea.closest('.beply-rich-inline-field');
        if (!field) {
            field = document.createElement('div');
            field.className = 'beply-rich-inline-field';
            parent.insertBefore(field, textarea);
            field.appendChild(textarea);
        }

        if (!isLocked(textarea) && !field.querySelector('[data-beply-rich-open]')) {
            const actions = document.createElement('div');
            actions.className = 'beply-rich-inline-actions';
            actions.innerHTML = ''
                + '<button type="button" class="btn btn-sm btn-light beply-rich-inline-button"'
                + ' data-beply-rich-open="' + escapeHtml(name) + '" title="Editor">'
                + '<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>'
                + '</button>';
            field.insertBefore(actions, field.firstChild);
        }
    }

    function initAll(root) {
        (root || document).querySelectorAll(sourceSelector).forEach(initSource);
    }

    function scheduleInit(root) {
        if (scheduled) {
            return;
        }
        scheduled = true;
        window.requestAnimationFrame(function () {
            scheduled = false;
            initAll(root || document);
        });
    }

    function observeChanges() {
        if (!document.body) {
            return;
        }

        new MutationObserver(function (mutations) {
            for (const mutation of mutations) {
                if (mutation.addedNodes.length > 0) {
                    scheduleInit(document);
                    return;
                }
            }
        }).observe(document.body, {childList: true, subtree: true});
    }

    document.addEventListener('click', function (event) {
        const openButton = event.target.closest('[data-beply-rich-open]');
        if (openButton) {
            event.preventDefault();
            openModal(openButton.dataset.beplyRichOpen);
        }
    });

    window.BeplyRichDescription = {
        initAll: initAll,
        open: openModal,
        refresh: function (textarea) {
            if (textarea) {
                refreshLineState(textarea);
            }
        }
    };

    document.addEventListener('beply-rich-description:init', function (event) {
        initAll(event.detail && event.detail.root ? event.detail.root : document);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAll(document);
            observeChanges();
        });
    } else {
        initAll(document);
        observeChanges();
    }
})();
