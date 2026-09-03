/**
 * WHMCS WordPress Theme Provisioner — order form picker.
 *
 * Mounts a two-stage theme -> demo chooser into the product configuration form
 * and writes the chosen ids into two hidden inputs. It is intentionally plain
 * ES5-compatible DOM code with no dependencies: WHMCS order form templates vary
 * wildly between installs, and the one thing they all have is a form.
 *
 * Everything here is a convenience. The authoritative validation happens in PHP
 * on ShoppingCartValidateProductUpdate and again at checkout — a customer with
 * the developer console open can change these values and will simply be told
 * to choose again.
 */
(function () {
    'use strict';

    var CATALOGUE_ID = 'wtp-catalogue';
    var MOUNTED_FLAG = 'wtpPickerMounted';

    /** Candidate anchors, most specific first, across the stock order forms. */
    var FORM_SELECTORS = [
        'form#frmProduct',
        'form#frmConfigureProduct',
        'form[name="frmProduct"]',
        '#order-standard_cart form',
        '#order-boxes form',
        '.cart-body form',
        'form[action*="cart.php"]'
    ];

    var SUBMIT_SELECTORS = [
        '#btnCompleteProductConfig',
        'button[type="submit"]',
        'input[type="submit"]'
    ];

    function parseCatalogue() {
        var node = document.getElementById(CATALOGUE_ID);
        if (!node) {
            return null;
        }
        try {
            return JSON.parse(node.textContent || node.innerText || '');
        } catch (e) {
            return null;
        }
    }

    function findForm() {
        for (var i = 0; i < FORM_SELECTORS.length; i++) {
            var form = document.querySelector(FORM_SELECTORS[i]);
            if (form) {
                return form;
            }
        }
        return null;
    }

    function findSubmit(form) {
        for (var i = 0; i < SUBMIT_SELECTORS.length; i++) {
            var button = form.querySelector(SUBMIT_SELECTORS[i]);
            if (button) {
                return button;
            }
        }
        return null;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    /** Only http(s) URLs are ever put into a style or href attribute. */
    function safeUrl(value) {
        if (!value) {
            return '';
        }
        var trimmed = String(value).trim();
        return /^https?:\/\//i.test(trimmed) ? trimmed : '';
    }

    function media(imageUrl, altLabel) {
        var url = safeUrl(imageUrl);
        if (!url) {
            var empty = el('div', 'wtp-card__media wtp-card__media--empty', 'No preview');
            return empty;
        }
        var node = el('div', 'wtp-card__media');
        // Assigning through style.backgroundImage keeps the URL out of HTML
        // parsing entirely; combined with the http(s) check above there is no
        // path from catalogue text to executable markup.
        node.style.backgroundImage = 'url("' + url.replace(/["\\]/g, '') + '")';
        node.setAttribute('role', 'img');
        node.setAttribute('aria-label', altLabel || '');
        return node;
    }

    function Picker(catalogue, form) {
        this.catalogue = catalogue;
        this.form = form;
        this.themeId = catalogue.selected.theme || 0;
        this.demoId = catalogue.selected.demo || 0;
        this.stage = this.themeId ? 'demos' : 'themes';
    }

    Picker.prototype.mount = function () {
        this.root = el('div', 'wtp-picker');

        var head = el('div', 'wtp-picker__head');
        head.appendChild(el('h3', 'wtp-picker__title', this.catalogue.strings.heading));
        head.appendChild(el('p', 'wtp-picker__subtitle', this.catalogue.strings.subheading));
        this.root.appendChild(head);

        this.body = el('div', 'wtp-picker__body');
        this.root.appendChild(this.body);

        this.themeInput = this.hiddenInput(this.catalogue.fields.theme, this.themeId);
        this.demoInput = this.hiddenInput(this.catalogue.fields.demo, this.demoId);

        var submit = findSubmit(this.form);
        if (submit && submit.parentNode) {
            var host = submit.closest ? (submit.closest('.form-group, .cart-buttons, div') || submit.parentNode)
                : submit.parentNode;
            host.parentNode.insertBefore(this.root, host);
        } else {
            this.form.appendChild(this.root);
        }

        this.form.addEventListener('submit', this.onSubmit.bind(this));
        this.render();
    };

    Picker.prototype.hiddenInput = function (name, value) {
        var existing = this.form.querySelector('input[name="' + name + '"]');
        if (existing) {
            existing.value = value || '';
            return existing;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value || '';
        this.form.appendChild(input);
        return input;
    };

    Picker.prototype.theme = function (id) {
        for (var i = 0; i < this.catalogue.themes.length; i++) {
            if (this.catalogue.themes[i].id === id) {
                return this.catalogue.themes[i];
            }
        }
        return null;
    };

    Picker.prototype.render = function () {
        this.body.innerHTML = '';

        if (this.error) {
            this.body.appendChild(el('div', 'wtp-error', this.error));
        }

        if (this.stage === 'themes') {
            this.renderThemes();
            return;
        }
        this.renderDemos();
    };

    Picker.prototype.renderThemes = function () {
        var grid = el('div', 'wtp-grid');
        var self = this;

        this.catalogue.themes.forEach(function (theme) {
            var card = el('div', 'wtp-card' + (theme.id === self.themeId ? ' is-selected' : ''));
            card.appendChild(media(theme.image, theme.name));

            var body = el('div', 'wtp-card__body');
            body.appendChild(el('h4', 'wtp-card__name', theme.name));
            if (theme.description) {
                body.appendChild(el('p', 'wtp-card__desc', theme.description));
            }
            card.appendChild(body);

            var actions = el('div', 'wtp-card__actions');
            var button = el('button', 'wtp-btn', self.catalogue.strings.select);
            button.type = 'button';
            button.addEventListener('click', function () {
                self.chooseTheme(theme.id);
            });
            actions.appendChild(button);
            actions.appendChild(el('span', 'wtp-link', theme.demos.length + ' demo'
                + (theme.demos.length === 1 ? '' : 's')));
            card.appendChild(actions);

            grid.appendChild(card);
        });

        this.body.appendChild(grid);
    };

    Picker.prototype.renderDemos = function () {
        var theme = this.theme(this.themeId);
        if (!theme) {
            this.stage = 'themes';
            this.render();
            return;
        }
        var self = this;

        var chosen = el('div', 'wtp-chosen');
        var thumb = el('div', 'wtp-chosen__thumb');
        var image = safeUrl(theme.image);
        if (image) {
            thumb.style.backgroundImage = 'url("' + image.replace(/["\\]/g, '') + '")';
        }
        chosen.appendChild(thumb);

        var text = el('div', 'wtp-chosen__text');
        text.appendChild(document.createTextNode(theme.name));
        var small = el('small', null, this.catalogue.strings.demoHeading.replace('%s', theme.name));
        text.appendChild(small);
        chosen.appendChild(text);

        var change = el('button', 'wtp-btn wtp-btn--ghost', this.catalogue.strings.change);
        change.type = 'button';
        change.addEventListener('click', function () {
            self.stage = 'themes';
            self.render();
        });
        chosen.appendChild(change);

        this.body.appendChild(chosen);

        var grid = el('div', 'wtp-grid');
        grid.style.marginTop = '14px';

        theme.demos.forEach(function (demo) {
            var selected = demo.id === self.demoId;
            var card = el('div', 'wtp-card' + (selected ? ' is-selected' : ''));
            card.appendChild(media(demo.image, demo.name));

            var body = el('div', 'wtp-card__body');
            body.appendChild(el('h4', 'wtp-card__name', demo.name));
            if (demo.description) {
                body.appendChild(el('p', 'wtp-card__desc', demo.description));
            }
            card.appendChild(body);

            var actions = el('div', 'wtp-card__actions');
            var button = el('button', 'wtp-btn' + (selected ? ' wtp-btn--selected' : ''),
                selected ? self.catalogue.strings.selected : self.catalogue.strings.select);
            button.type = 'button';
            button.addEventListener('click', function () {
                self.chooseDemo(demo.id);
            });
            actions.appendChild(button);

            var previewUrl = safeUrl(demo.preview);
            if (previewUrl) {
                var link = el('a', 'wtp-link', self.catalogue.strings.preview);
                link.href = previewUrl;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                actions.appendChild(link);
            }
            card.appendChild(actions);

            grid.appendChild(card);
        });

        this.body.appendChild(grid);
    };

    Picker.prototype.chooseTheme = function (themeId) {
        this.themeId = themeId;
        this.demoId = 0;
        this.themeInput.value = String(themeId);
        this.demoInput.value = '';
        this.error = null;
        this.stage = 'demos';
        this.render();
        this.root.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    Picker.prototype.chooseDemo = function (demoId) {
        this.demoId = demoId;
        this.demoInput.value = String(demoId);
        this.error = null;
        this.render();
    };

    Picker.prototype.onSubmit = function (event) {
        if (!this.catalogue.required) {
            return;
        }
        if (this.themeId > 0 && this.demoId > 0) {
            return;
        }
        event.preventDefault();
        this.error = this.catalogue.strings.required;
        this.stage = this.themeId > 0 ? 'demos' : 'themes';
        this.render();
        this.root.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    function boot() {
        if (window[MOUNTED_FLAG]) {
            return;
        }
        var catalogue = parseCatalogue();
        if (!catalogue || !catalogue.themes || catalogue.themes.length === 0) {
            return;
        }
        var form = findForm();
        if (!form) {
            return;
        }
        window[MOUNTED_FLAG] = true;
        new Picker(catalogue, form).mount();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
