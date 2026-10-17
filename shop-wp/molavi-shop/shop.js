(function () {
    if (!window.molaviShop) return;

    var busy = {};

    document.addEventListener("click", function (event) {
        var button = event.target.closest("[data-molavi-step], [data-molavi-clear]");
        if (!button) return;
        var control = button.closest("[data-molavi-cart]");
        if (!control) return;
        event.preventDefault();
        var id = control.getAttribute("data-product");
        if (!id || busy[id]) return;
        var min = Number(control.getAttribute("data-min")) || 1;
        var qty = Number(control.getAttribute("data-qty")) || 0;
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
        busy[id] = true;
        control.setAttribute("aria-busy", "true");
        var body = new FormData();
        body.append("product_id", id);
        body.append("qty", String(next));
        body.append("nonce", molaviShop.nonce);
        fetch(molaviShop.qty, { method: "POST", body: body, credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (!payload || !payload.success) return;
                paint(payload.data.quote || {});
                applyFragments(payload.data.fragments || {});
            })
            .catch(function () {})
            .then(function () {
                busy[id] = false;
                var current = document.querySelector('[data-molavi-cart][data-product="' + id + '"]');
                if (current) current.removeAttribute("aria-busy");
            });
    });

    if (window.jQuery) {
        jQuery(document.body).on("wc_fragments_refreshed removed_from_cart updated_wc_div", function () {
            fetch(molaviShop.quote, { credentials: "same-origin" })
                .then(function (res) { return res.json(); })
                .then(function (payload) {
                    if (payload && payload.success) paint(payload.data || {});
                })
                .catch(function () {});
        });
    }

    function paint(quote) {
        var units = quote.units || {};
        var qtys = quote.qtys || {};
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
        document.querySelectorAll("[data-molavi-cart]").forEach(function (node) {
            var id = node.getAttribute("data-product");
            var qty = Number(qtys[id] || 0);
            node.setAttribute("data-qty", String(qty));
            node.classList.toggle("is-in", qty > 0);
            node.innerHTML = qty > 0 ? stepper(qty) : addButton();
        });
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
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" stroke="none" d="M4.4 6.15h15.2c.5 0 .9.4.9.9s-.4.9-.9.9H4.4c-.5 0-.9-.4-.9-.9s.4-.9.9-.9z"></path><path fill="currentColor" stroke="none" d="M10 3.3h4c.55 0 1 .4 1 .95V6.1h-6V4.25c0-.55.45-.95 1-.95z"></path><path fill="currentColor" stroke="none" fill-rule="evenodd" d="M6.35 9.2h11.3c.25 0 .45.22.43.48l-.78 10.05c-.08.85-.8 1.5-1.65 1.5H8.35c-.85 0-1.57-.65-1.65-1.5L5.92 9.68c-.02-.26.18-.48.43-.48zM9.15 12h1.4v5.15h-1.4V12zm4.3 0h1.4v5.15h-1.4V12z"></path></svg>';
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
})();
