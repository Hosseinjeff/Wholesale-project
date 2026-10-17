(function () {
    if (!window.molaviShop) return;

    var catalog = molaviShop.catalog || {};
    var qtys = Object.assign({}, molaviShop.qtys || {});
    var serverQtys = Object.assign({}, molaviShop.qtys || {});
    var sending = false;
    var dirty = false;
    var retries = 0;

    document.addEventListener("click", function (event) {
        var button = event.target.closest("[data-molavi-step], [data-molavi-clear]");
        if (!button) return;
        var host = button.closest("[data-product]");
        if (!host) return;
        event.preventDefault();
        var id = host.getAttribute("data-product");
        if (!id) return;
        var row = catalog[id] || {};
        var min = Number(host.getAttribute("data-min")) || Number(row.min) || 1;
        var qty = Number(qtys[id] || 0);
        var next = 0;
        if (button.hasAttribute("data-molavi-clear")) {
            next = 0;
        } else {
            var step = Number(button.getAttribute("data-molavi-step")) || 0;
            if (step > 0 && qty < 1) next = min;
            else next = qty + step;
            if (next < min) next = 0;
            if (next > 999) next = 999;
        }
        if (next > 0) qtys[id] = next;
        else delete qtys[id];
        paint(localQuote());
        sync();
    });

    function localQuote() {
        var unlock = Number(molaviShop.unlock) || 0;
        var measure = 0;
        Object.keys(qtys).forEach(function (id) {
            var row = rowOf(id);
            var qty = Number(qtys[id] || 0);
            if (row && qty > 0 && Number(row.discountable) === 1) {
                measure += (Number(row.shelf) || 0) * qty;
            }
        });
        var progress = measure > 0 && unlock > 0 ? Math.min(1, measure / unlock) : 0;
        var units = {};
        var seen = {};
        Object.keys(catalog).forEach(function (id) { seen[id] = true; });
        document.querySelectorAll("[data-molavi-price]").forEach(function (node) {
            var id = node.getAttribute("data-product");
            if (id) seen[id] = true;
        });
        Object.keys(seen).forEach(function (id) {
            var row = rowOf(id);
            if (row) units[id] = unitOf(row, progress);
        });
        return { units: units, qtys: qtys };
    }

    function rowOf(id) {
        if (catalog[id]) return catalog[id];
        var node = document.querySelector('[data-molavi-price][data-product="' + cssEscape(id) + '"]');
        if (!node) return null;
        return {
            name: node.getAttribute("data-name") || "",
            min: 1,
            shelf: Number(node.getAttribute("data-shelf")) || 0,
            sale: Number(node.getAttribute("data-sale")) || 0,
            floor: Number(node.getAttribute("data-floor")) || 0,
            ceiling: Number(node.getAttribute("data-ceiling")) || 0,
            discountable: node.getAttribute("data-discountable") === "1" ? 1 : 0
        };
    }

    function unitOf(row, progress) {
        var shelf = Number(row.shelf) || 0;
        var sale = Number(row.sale) || 0;
        if (!(shelf > 0)) return sale;
        var ceiling = Number(row.ceiling) || 0;
        var floor = Number(row.floor) || 0;
        var unit = halfUp(ceiling + (floor - ceiling) * progress);
        var low = Math.min(floor, ceiling);
        var high = Math.min(Math.max(floor, ceiling, low), shelf);
        if (unit < low) unit = low;
        if (unit > high) unit = high;
        return unit;
    }

    function halfUp(value) {
        var nearest = Math.round(value * 1e8) / 1e8;
        var whole = Math.trunc(nearest);
        if (nearest - whole >= 0.5 - 1e-8) return whole + 1;
        return whole;
    }

    function sync() {
        if (sending) {
            dirty = true;
            return;
        }
        var changed = desiredDelta();
        var ids = Object.keys(changed);
        if (!ids.length) return;
        sending = true;
        dirty = false;
        var body = new FormData();
        body.append("nonce", molaviShop.nonce);
        body.append("items", JSON.stringify(changed));
        fetch(molaviShop.qty, { method: "POST", body: body, credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    dirty = retries < 2;
                    retries += 1;
                    return;
                }
                retries = 0;
                serverQtys = Object.assign({}, payload.data.quote.qtys || {});
                if (!dirty) {
                    qtys = Object.assign({}, serverQtys);
                    paint(payload.data.quote || {});
                    applyFragments(payload.data.fragments || {});
                }
            })
            .catch(function () {
                dirty = retries < 2;
                retries += 1;
            })
            .then(function () {
                sending = false;
                if (dirty) sync();
            });
    }

    function desiredDelta() {
        var changed = {};
        var seen = {};
        Object.keys(serverQtys).forEach(function (id) { seen[id] = true; });
        Object.keys(qtys).forEach(function (id) { seen[id] = true; });
        Object.keys(seen).forEach(function (id) {
            if ((Number(qtys[id]) || 0) !== (Number(serverQtys[id]) || 0)) changed[id] = Number(qtys[id]) || 0;
        });
        return changed;
    }

    function paint(quote) {
        var units = quote.units || {};
        var counts = quote.qtys || qtys;
        document.querySelectorAll("[data-molavi-price]").forEach(function (node) {
            var id = node.getAttribute("data-product");
            if (units[id] == null) return;
            var unit = Number(units[id]);
            var shelf = Number(node.getAttribute("data-shelf")) || 0;
            var amount = node.querySelector(".molavi-amount");
            if (amount) amount.textContent = toman(unit);
            node.setAttribute("data-unit", String(unit));
            var rate = shelf > unit ? (shelf - unit) / shelf : 0;
            var rateNode = node.querySelector(".molavi-rate");
            if (rateNode) {
                rateNode.hidden = rate <= 0;
                if (rate > 0) rateNode.textContent = "\u2212" + percent(rate);
            }
            var was = node.querySelector(".molavi-was");
            if (was) {
                was.hidden = !(shelf > unit);
                if (shelf > unit) was.textContent = toman(shelf);
            }
        });
        document.querySelectorAll(".e-loop-item [data-molavi-cart]").forEach(function (node) {
            var id = node.getAttribute("data-product");
            var qty = Number(counts[id] || 0);
            node.setAttribute("data-qty", String(qty));
            node.classList.toggle("is-in", qty > 0);
            node.innerHTML = qty > 0 ? stepper(qty) : addButton();
        });
        paintBasket(units, counts);
        var count = 0;
        Object.keys(counts).forEach(function (id) { count += Number(counts[id]) || 0; });
        document.querySelectorAll(".elementor-button-icon-qty").forEach(function (badge) {
            badge.textContent = digits(count);
            badge.setAttribute("data-counter", String(count));
        });
    }

    function paintBasket(units, counts) {
        var lines = document.querySelector("[data-molavi-basket-lines]");
        if (!lines) return;
        var saved = 0;
        var total = 0;
        var kinds = 0;
        var html = "";
        Object.keys(counts).forEach(function (id) {
            var qty = Number(counts[id] || 0);
            var row = rowOf(id);
            if (!row || qty < 1 || units[id] == null) return;
            var unit = Number(units[id]);
            var shelf = Number(row.shelf) || 0;
            var min = Number(row.min) || 1;
            saved += shelf > unit ? (shelf - unit) * qty : 0;
            total += unit * qty;
            kinds += 1;
            html += '<article class="molavi-bline" data-product="' + escapeHtml(id) + '" data-min="' + min + '" data-qty="' + qty + '">'
                + '<div class="molavi-bline-copy"><h3>' + escapeHtml(row.name || "") + "</h3><p><strong>"
                + toman(unit) + "</strong>" + (shelf > unit ? " <s>" + toman(shelf) + "</s>" : "") + "</p></div>"
                + stepper(qty) + "</article>";
        });
        lines.innerHTML = html || '<p class="molavi-basket-empty">سبد خالی است.</p>';
        var save = document.querySelector("[data-molavi-basket-save]");
        if (save) {
            save.hidden = saved < 1;
            var amount = save.querySelector("[data-molavi-basket-save-amount]");
            if (amount) amount.textContent = toman(saved);
        }
        var totalNode = document.querySelector("[data-molavi-basket-total]");
        if (totalNode) totalNode.textContent = toman(total);
        var countNode = document.querySelector("[data-molavi-basket-count]");
        if (countNode) countNode.textContent = kinds > 0 ? "(" + digits(kinds) + " محصول)" : "";
        var checkout = document.querySelector("[data-molavi-checkout]");
        if (checkout) checkout.hidden = kinds < 1;
    }

    function applyFragments(fragments) {
        if (!window.jQuery) return;
        jQuery.each(fragments, function (selector, html) {
            var target = jQuery(selector);
            if (target.length) target.replaceWith(html);
        });
    }

    function stepper(qty) {
        return '<div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-1" aria-label="کم کردن">−</button><b>'
            + digits(qty) + '</b><button type="button" data-molavi-step="1" aria-label="افزودن">+</button></div>'
            + '<button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد">' + trash() + "</button></div>";
    }

    function addButton() {
        return '<button type="button" class="molavi-add" data-molavi-step="1" aria-label="افزودن به سبد">' + cart() + "</button>";
    }

    function cart() {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h1.6l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H8"></path><circle cx="10" cy="19" r="1.2"></circle><circle cx="17" cy="19" r="1.2"></circle></svg>';
    }

    function trash() {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" stroke="none" d="M4.4 6.15h15.2c.5 0 .9.4.9.9s-.4.9-.9.9H4.4c-.5 0-.9-.4-.9-.9s.4-.9.9-.9z"/><path fill="currentColor" stroke="none" d="M10 3.3h4c.55 0 1 .4 1 .95V6.1h-6V4.25c0-.55.45-.95 1-.95z"/><path fill="currentColor" stroke="none" d="M6.35 9.2h11.3c.25 0 .45.22.43.48l-.78 10.05c-.08.85-.8 1.5-1.65 1.5H8.35c-.85 0-1.57-.65-1.65-1.5L5.92 9.68c-.02-.26.18-.48.43-.48z"/></svg>';
    }

    function digits(value) {
        return String(value).replace(/\d/g, function (d) {
            return "۰۱۲۳۴۵۶۷۸۹"[d];
        }).replace(/,/g, "٬");
    }

    function toman(amount) {
        return digits(Number(amount || 0).toLocaleString("en-US")) + " تومان";
    }

    function percent(rate) {
        return digits(Math.round(rate * 100)) + "٪";
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
        });
    }

    function cssEscape(value) {
        if (window.CSS && CSS.escape) return CSS.escape(value);
        return String(value).replace(/"/g, "");
    }
})();
