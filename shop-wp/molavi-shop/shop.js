(function () {
    if (!window.molaviShop) return;

    var catalog = molaviShop.catalog || {};
    var qtys = Object.assign({}, molaviShop.qtys || {});
    var serverQtys = Object.assign({}, molaviShop.qtys || {});
    var sending = false;
    var dirty = false;
    var retries = 0;
    var armed = false;
    var wave = [];
    var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

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
            if (amount) present(amount, unit, toman(unit), "shop");
            node.setAttribute("data-unit", String(unit));
            var rate = shelf > unit ? (shelf - unit) / shelf : 0;
            var rateNode = node.querySelector(".molavi-rate");
            if (rateNode) {
                rateNode.hidden = rate <= 0;
                if (rate > 0) present(rateNode, Math.round(rate * 100), "\u2212" + percent(rate), "shop");
            }
            var was = node.querySelector(".molavi-was");
            if (was) {
                was.hidden = !(shelf > unit);
                if (shelf > unit) was.textContent = toman(shelf);
            }
        });
        document.querySelectorAll("[data-molavi-cart]").forEach(function (node) {
            if (node.closest("[data-molavi-basket-lines]")) return;
            var id = node.getAttribute("data-product");
            var qty = Number(counts[id] || 0);
            var prev = node.querySelector(".molavi-step b");
            var prevKey = prev ? prev.dataset.n : (node.querySelector(".molavi-add") ? "0" : null);
            var prevText = prev ? (prev.dataset.t || prev.textContent) : digits(0);
            node.setAttribute("data-qty", String(qty));
            node.classList.toggle("is-in", qty > 0);
            if ((qty > 0) !== !!prev) node.innerHTML = qty > 0 ? stepper(qty) : addButton();
            var next = node.querySelector(".molavi-step b");
            if (next) present(next, qty, digits(qty), "shop", prevKey, prevText);
        });
        paintBasket(units, counts);
        var count = 0;
        Object.keys(counts).forEach(function (id) { count += Number(counts[id]) || 0; });
        document.querySelectorAll(".elementor-button-icon-qty").forEach(function (badge) {
            present(badge, count, digits(count), "shop");
            badge.setAttribute("data-counter", String(count));
        });
        flushWave();
        armed = true;
    }

    function paintBasket(units, counts) {
        var lines = document.querySelector("[data-molavi-basket-lines]");
        if (!lines) return;
        var previous = {};
        lines.querySelectorAll(".molavi-bline").forEach(function (line) {
            var strong = line.querySelector("strong");
            var rate = line.querySelector(".molavi-rate");
            var qty = line.querySelector(".molavi-step b");
            previous[line.getAttribute("data-product")] = {
                unit: strong ? strong.dataset.n : null,
                unitText: strong ? (strong.dataset.t || strong.textContent) : "",
                rate: rate ? rate.dataset.n : null,
                rateText: rate ? (rate.dataset.t || rate.textContent) : "",
                qty: qty ? qty.dataset.n : null,
                qtyText: qty ? (qty.dataset.t || qty.textContent) : ""
            };
        });
        var saved = 0;
        var shelfTotal = 0;
        var total = 0;
        var built = [];
        Object.keys(counts).forEach(function (id) {
            var qty = Number(counts[id] || 0);
            var row = rowOf(id);
            if (!row || qty < 1 || units[id] == null) return;
            var unit = Number(units[id]);
            var shelf = Number(row.shelf) || 0;
            var min = Number(row.min) || 1;
            var lineRate = shelf > unit ? (shelf - unit) / shelf : 0;
            saved += shelf > unit ? (shelf - unit) * qty : 0;
            shelfTotal += shelf * qty;
            total += unit * qty;
            built.push({ id: id, qty: qty, min: min, row: row, unit: unit, shelf: shelf, lineRate: lineRate });
        });
        var existing = Array.prototype.slice.call(lines.querySelectorAll(".molavi-bline"));
        var sameLines = existing.length === built.length && existing.every(function (el, index) {
            var item = built[index];
            return el.getAttribute("data-product") === item.id && !!el.querySelector(".molavi-rate") === (item.lineRate > 0);
        });
        if (!sameLines) {
            var html = built.map(function (item) {
                return '<article class="molavi-bline" data-product="' + escapeHtml(item.id) + '" data-min="' + item.min + '" data-qty="' + item.qty + '">'
                    + '<div class="molavi-bline-copy"><h3>' + escapeHtml(item.row.name || "") + "</h3><p>"
                    + (item.lineRate > 0 ? '<span class="molavi-rate" dir="ltr"></span> ' : "")
                    + "<strong></strong>" + (item.shelf > item.unit ? " <s>" + toman(item.shelf) + "</s>" : "") + "</p></div>"
                    + stepper(item.qty) + "</article>";
            }).join("");
            lines.innerHTML = html || '<p class="molavi-basket-empty">سبد خالی است.</p>';
        } else {
            existing.forEach(function (el, index) {
                el.setAttribute("data-qty", String(built[index].qty));
                el.setAttribute("data-min", String(built[index].min));
            });
        }
        var kinds = built.length;
        lines.querySelectorAll(".molavi-bline").forEach(function (line) {
            var id = line.getAttribute("data-product");
            var row = rowOf(id);
            var unit = Number(units[id]);
            var shelf = row ? Number(row.shelf) || 0 : 0;
            var lineRate = shelf > unit ? (shelf - unit) / shelf : 0;
            var prior = previous[id] || {};
            var price = line.querySelector("strong");
            present(price, unit, toman(unit), "cart", prior.unit, prior.unitText);
            var rateNode = line.querySelector(".molavi-rate");
            if (rateNode) present(rateNode, Math.round(lineRate * 100), "\u2212" + percent(lineRate), "cart", prior.rate, prior.rateText);
            var qtyNode = line.querySelector(".molavi-step b");
            present(qtyNode, Number(line.getAttribute("data-qty")), digits(line.getAttribute("data-qty")), "cart", prior.qty, prior.qtyText);
        });
        var save = document.querySelector("[data-molavi-basket-save]");
        if (save) {
            save.hidden = saved < 1;
            var amount = save.querySelector("[data-molavi-basket-save-amount]");
            if (amount) present(amount, saved, toman(saved), "cart");
            var saveRate = save.querySelector("[data-molavi-basket-save-rate]");
            if (saveRate && shelfTotal > 0) present(saveRate, Math.round(saved / shelfTotal * 100), percent(saved / shelfTotal), "cart");
        }
        var totalNode = document.querySelector("[data-molavi-basket-total]");
        if (totalNode) present(totalNode, total, toman(total), "cart");
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
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14"></path><path d="M9.5 7V5.4A1.4 1.4 0 0 1 10.9 4h2.2a1.4 1.4 0 0 1 1.4 1.4V7"></path><path d="M7.2 7.4l.7 11a1.2 1.2 0 0 0 1.2 1.1h5.8a1.2 1.2 0 0 0 1.2-1.1l.7-11"></path><path d="M10.2 11v5"></path><path d="M13.8 11v5"></path></svg>';
    }

    function present(el, key, text, scope, prevKey, prevText) {
        if (!el) return;
        var nextKey = String(key);
        var priorKey = prevKey != null ? String(prevKey) : el.dataset.n;
        var priorText = prevText != null && prevText !== "" ? prevText : (el.dataset.t || el.textContent);
        el.dataset.n = nextKey;
        el.dataset.t = text;
        if (!armed || reduceMotion || priorKey == null || priorKey === "" || priorKey === nextKey) {
            if (!el.querySelector(".tick")) el.textContent = text;
            return;
        }
        wave.push({ el: el, from: priorText, to: text, scope: scope });
    }

    function flushWave() {
        var batch = wave;
        wave = [];
        var groups = {};
        batch.forEach(function (item) {
            if (!groups[item.scope]) groups[item.scope] = [];
            groups[item.scope].push(item);
        });
        Object.keys(groups).forEach(function (scope) {
            var list = groups[scope].filter(function (item) {
                return item.el.getClientRects().length > 0;
            });
            groups[scope].forEach(function (item) {
                if (item.el.getClientRects().length === 0) item.el.textContent = item.to;
            });
            if (!list.length) return;
            var tops = list.map(function (item) { return item.el.getBoundingClientRect().top; });
            var min = Math.min.apply(null, tops);
            var max = Math.max.apply(null, tops);
            var span = Math.max(1, max - min);
            list.forEach(function (item) {
                var delay = Math.round(((item.el.getBoundingClientRect().top - min) / span) * 180);
                startReel(item.el, item.from, item.to, delay);
            });
        });
    }

    function startReel(el, fromText, toText, delay) {
        var to = Array.from(toText || "");
        var fromDigits = [];
        Array.from(fromText || "").forEach(function (ch) {
            if (digitChar(ch)) fromDigits.push(ch);
        });
        var toPlaces = [];
        to.forEach(function (ch, index) {
            if (digitChar(ch)) toPlaces.push(index);
        });
        var oldAt = {};
        toPlaces.forEach(function (index, place) {
            var fromPlace = fromDigits.length - toPlaces.length + place;
            oldAt[index] = fromPlace >= 0 ? fromDigits[fromPlace] : "";
        });
        if (!toPlaces.some(function (index) { return oldAt[index] !== to[index]; })) {
            el.textContent = toText;
            return;
        }
        var lastDigit = -1;
        to.forEach(function (ch, index) {
            if (digitChar(ch)) lastDigit = index;
        });
        var root = document.createElement("span");
        root.className = "tick";
        root.dir = "ltr";
        var pending = 0;
        var token = (el._roll || 0) + 1;
        el._roll = token;
        to.slice(0, lastDigit + 1).forEach(function (ch, index) {
            if (!digitChar(ch) || oldAt[index] === ch) {
                root.appendChild(document.createTextNode(ch));
                return;
            }
            var cell = document.createElement("i");
            var strip = document.createElement("b");
            strip.style.animationDelay = delay + "ms";
            var next = document.createElement("span");
            next.textContent = ch;
            var prev = document.createElement("span");
            prev.textContent = oldAt[index] || "\u2007";
            strip.appendChild(next);
            strip.appendChild(prev);
            cell.appendChild(strip);
            root.appendChild(cell);
            pending++;
            strip.addEventListener("animationend", function () {
                if (el._roll !== token) return;
                pending--;
                if (pending <= 0) el.textContent = toText;
            }, { once: true });
        });
        el.textContent = "";
        el.appendChild(root);
        var suffix = to.slice(lastDigit + 1).join("");
        if (suffix) el.appendChild(document.createTextNode(suffix));
        if (pending === 0) el.textContent = toText;
    }

    function digitChar(ch) {
        return /[0-9۰-۹]/.test(ch);
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

    document.querySelectorAll("[data-molavi-price]").forEach(function (node) {
        var amount = node.querySelector(".molavi-amount");
        if (amount) {
            amount.dataset.n = node.getAttribute("data-unit") || "";
            amount.dataset.t = amount.textContent;
        }
        var rateNode = node.querySelector(".molavi-rate");
        if (rateNode) {
            rateNode.dataset.t = rateNode.textContent;
            rateNode.dataset.n = rateNode.textContent.replace(/[^\d۰-۹]/g, "");
        }
    });
    document.querySelectorAll("[data-molavi-cart], .molavi-bline").forEach(function (node) {
        var qty = node.querySelector(".molavi-step b");
        if (qty) {
            qty.dataset.n = node.getAttribute("data-qty") || "0";
            qty.dataset.t = qty.textContent;
        }
        var strong = node.querySelector("strong");
        if (strong && !strong.dataset.n) strong.dataset.t = strong.textContent;
    });
    armed = true;
})();
