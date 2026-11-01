(function () {
    if (!window.molaviShop) return;

    var catalog = molaviShop.catalog || {};
    var qtys = Object.assign({}, molaviShop.qtys || {});
    var serverQtys = Object.assign({}, molaviShop.qtys || {});
    var lastQuote = molaviShop.initial || { units: {}, qtys: serverQtys, progress: 0, unlock: molaviShop.unlock || 0 };
    var sending = false;
    var dirty = false;
    var retries = 0;

    document.addEventListener("click", function (event) {
        var button = event.target.closest("[data-molavi-step], [data-molavi-clear]");
        if (!button) return;
        var host = button.closest("[data-product]");
        if (!host) return;
        event.preventDefault();
        showStatus("");
        var id = host.getAttribute("data-product");
        if (!id) return;
        var row = catalog[id] || {};
        var min = Number(host.getAttribute("data-min")) || Number(row.min) || 1;
        var qty = Number(qtys[id] || 0);
        var next = 0;
        var removedForMinimum = false;
        if (button.hasAttribute("data-molavi-clear")) {
            next = 0;
        } else {
            var step = Number(button.getAttribute("data-molavi-step")) || 0;
            if (step > 0 && qty < 1) next = min;
            else next = qty + step;
            if (next < min) {
                removedForMinimum = step < 0 && qty > 0;
                next = 0;
            }
            if (next > 999) next = 999;
        }
        if (next > 0) qtys[id] = next;
        else delete qtys[id];
        paint(localQuote());
        if (removedForMinimum) showStatus("با کاهش از حداقل خرید " + digits(min) + " عدد، محصول از سبد حذف شد و قیمت‌ها دوباره محاسبه می‌شوند.");
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
        if (catalog[id]) {
            var publicRow = catalog[id];
            return {
                name: publicRow.name || "",
                image: publicRow.image || "",
                min: Number(publicRow.min) || 1,
                shelf: Number(publicRow.shelf) || 0,
                sale: Number(publicRow.fixed) || 0,
                floor: Number(publicRow.floor) || 0,
                ceiling: Number(publicRow.ceiling) || 0,
                discountable: Number(publicRow.discountable) === 1 ? 1 : 0
            };
        }
        var node = document.querySelector('[data-molavi-price][data-product="' + cssEscape(id) + '"]');
        if (!node) return null;
        return {
            name: node.getAttribute("data-name") || "",
            image: node.getAttribute("data-image") || "",
            min: 1,
            shelf: Number(node.getAttribute("data-shelf")) || 0,
            sale: Number(node.getAttribute("data-fixed-price")) || 0,
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
        var failed = false;
        fetch(molaviShop.qty, { method: "POST", body: body, credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    failed = true;
                    return;
                }
                retries = 0;
                lastQuote = payload.data.quote || lastQuote;
                serverQtys = Object.assign({}, payload.data.quote.qtys || {});
                if (!dirty) {
                    qtys = Object.assign({}, serverQtys);
                    paint(payload.data.quote || {});
                    applyFragments(payload.data.fragments || {});
                    showStatus("");
                }
            })
            .catch(function () {
                failed = true;
            })
            .then(function () {
                sending = false;
                if (failed) {
                    dirty = false;
                    retries += 1;
                    qtys = Object.assign({}, serverQtys);
                    paint(lastQuote);
                    showStatus("سبد به‌روز نشد و تغییر اخیر ذخیره نشده است. دوباره تلاش کنید.");
                    return;
                }
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
            var card = node.closest("[data-molavi-card]");
            if (card) card.setAttribute("data-price", String(unit));
            var rate = shelf > unit ? (shelf - unit) / shelf : 0;
            var rateNode = node.querySelector(".molavi-rate");
            if (rateNode) {
                if (rate > 0) rateNode.textContent = percent(rate);
            }
            var rateLabel = node.querySelector(".molavi-rate-label");
            if (rateLabel) rateLabel.hidden = rate <= 0;
            else if (rateNode) rateNode.hidden = rate <= 0;
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
            node.innerHTML = qty > 0 ? stepper(qty) : addButton(rowOf(id));
        });
        paintBasket(units, counts);
        var count = 0;
        Object.keys(counts).forEach(function (id) { count += Number(counts[id]) || 0; });
        document.querySelectorAll("[data-molavi-count]").forEach(function (badge) {
            badge.textContent = digits(count);
        });
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
        var referenceTotal = 0;
        var eligibleReference = 0;
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
            if (shelf > 0) {
                referenceTotal += shelf * qty;
                if (Number(row.discountable) === 1) eligibleReference += shelf * qty;
            }
            kinds += 1;
            html += '<article class="molavi-bline" data-product="' + escapeHtml(id) + '" data-min="' + min + '" data-qty="' + qty + '">'
                + '<div class="molavi-bline-image"><img src="' + escapeHtml(row.image || "") + '" alt="' + escapeHtml(row.name || "") + '" loading="lazy"></div>'
                + '<div class="molavi-bline-copy"><h3>' + escapeHtml(row.name || "") + "</h3><p><strong>"
                + toman(unit) + "</strong> × " + digits(qty) + "</p></div>"
                + stepper(qty) + "</article>";
        });
        lines.innerHTML = html || '<p class="molavi-basket-empty">سبد خالی است.</p>';
        var emptyLine = document.querySelector("[data-molavi-empty-basket]");
        if (emptyLine) emptyLine.hidden = kinds > 0;
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
        setText("[data-molavi-reference]", toman(referenceTotal));
        setText("[data-molavi-savings]", toman(saved));
        setText("[data-molavi-subtotal]", toman(total));
        setText("[data-molavi-savings-rate]", percent(referenceTotal > 0 ? saved / referenceTotal : 0));
        setText("[data-molavi-mobile-total]", toman(total));
        setText("[data-molavi-mobile-saving]", toman(saved));
        setText("[data-molavi-mobile-count]", digits(Object.keys(counts).reduce(function (sum, id) { return sum + (Number(counts[id]) || 0); }, 0)));
        var progress = eligibleReference > 0 && Number(molaviShop.unlock) > 0 ? Math.min(1, eligibleReference / Number(molaviShop.unlock)) : 0;
        var progressPct = Math.round(progress * 100);
        var progressBar = document.querySelector("[data-molavi-progressbar]");
        var progressFill = document.querySelector("[data-molavi-progress-fill]");
        if (progressBar) progressBar.setAttribute("aria-valuenow", String(progressPct));
        if (progressFill) progressFill.style.width = progressPct + "%";
        var progressTitle = document.querySelector("[data-molavi-progress-title]");
        var remainingNode = document.querySelector("[data-molavi-remaining]");
        if (progressTitle) progressTitle.textContent = progress >= 1 ? "بیشترین تخفیف فعال است" : "پیشرفت برای تخفیف بیشتر";
        if (remainingNode) remainingNode.textContent = progress >= 1 ? "به سقف تخفیف رسیده‌اید." : "مانده تا سقف تخفیف: " + toman(Math.max(0, Number(molaviShop.unlock) - eligibleReference));
    }

    function setText(selector, value) {
        var node = document.querySelector(selector);
        if (node) node.textContent = value;
    }

    function showStatus(message) {
        var node = document.querySelector("[data-molavi-status]");
        if (!node) return;
        node.textContent = message;
        node.hidden = !message;
    }

    function setupCatalog() {
        var root = document.querySelector("[data-molavi-storefront]");
        if (!root) return;
        var grid = root.querySelector("[data-molavi-grid]");
        var search = document.querySelector("[data-molavi-search]");
        var sort = root.querySelector("[data-molavi-sort]");
        var categorySelect = root.querySelector("[data-molavi-category-select]");
        var favoriteView = document.querySelector("[data-molavi-wishlist-view]");
        var viewButtons = root.querySelectorAll("[data-molavi-view]");
        var selectedCategory = "";
        var favoritesOnly = new URLSearchParams(window.location.search).get("molavi_favorites") === "1";
        var favorites;
        try {
            favorites = new Set(JSON.parse(window.localStorage.getItem("molavi-wishlist-v1") || "[]").map(String));
        } catch (error) {
            favorites = new Set();
        }

        function paintFavorites() {
            root.querySelectorAll("[data-molavi-favorite]").forEach(function (button) {
                var id = String(button.getAttribute("data-molavi-favorite") || "");
                var active = favorites.has(id);
                var card = button.closest("[data-molavi-card]");
                button.classList.toggle("is-active", active);
                button.setAttribute("aria-pressed", active ? "true" : "false");
                if (card) {
                    var name = card.getAttribute("data-name") || "";
                    button.setAttribute("aria-label", active ? "حذف " + name + " از علاقه‌مندی‌ها" : "افزودن " + name + " به علاقه‌مندی‌ها");
                }
            });
            if (favoriteView) favoriteView.setAttribute("aria-pressed", favoritesOnly ? "true" : "false");
        }

        function applyFilters() {
            if (!grid) return;
            var term = String(search ? search.value : "").trim().toLocaleLowerCase();
            var cards = Array.prototype.slice.call(grid.querySelectorAll("[data-molavi-card]"));
            var visible = 0;
            cards.forEach(function (card) {
                var text = String(card.getAttribute("data-search") || "").toLocaleLowerCase();
                var category = card.getAttribute("data-category") || "";
                var favoriteButton = card.querySelector("[data-molavi-favorite]");
                var id = favoriteButton ? String(favoriteButton.getAttribute("data-molavi-favorite") || "") : "";
                var show = (!term || text.indexOf(term) !== -1)
                    && (!selectedCategory || category === selectedCategory)
                    && (!favoritesOnly || favorites.has(id));
                card.hidden = !show;
                if (show) visible += 1;
            });
            var count = root.querySelector("[data-molavi-result-count]");
            var empty = root.querySelector("[data-molavi-empty]");
            if (count) count.textContent = digits(visible) + " کالا";
            if (empty) empty.hidden = visible > 0;
        }

        if (search) {
            search.addEventListener("input", applyFilters);
            if (search.form) {
                search.form.addEventListener("submit", function (event) {
                    event.preventDefault();
                    applyFilters();
                    root.scrollIntoView({ behavior: "smooth", block: "start" });
                });
            }
        }
        if (categorySelect) {
            categorySelect.addEventListener("change", function () {
                selectedCategory = categorySelect.value || "";
                applyFilters();
            });
        }
        root.querySelectorAll("[data-molavi-category]").forEach(function (button) {
            button.addEventListener("click", function () {
                selectedCategory = button.getAttribute("data-molavi-category") || "";
                root.querySelectorAll("[data-molavi-category]").forEach(function (other) {
                    other.classList.toggle("is-active", other === button);
                });
                applyFilters();
            });
        });
        root.addEventListener("click", function (event) {
            var button = event.target.closest("[data-molavi-favorite]");
            if (!button || !root.contains(button)) return;
            event.preventDefault();
            var id = String(button.getAttribute("data-molavi-favorite") || "");
            if (!id) return;
            if (favorites.has(id)) favorites.delete(id);
            else favorites.add(id);
            try { window.localStorage.setItem("molavi-wishlist-v1", JSON.stringify(Array.from(favorites))); } catch (error) {}
            paintFavorites();
            applyFilters();
        });
        if (favoriteView) {
            favoriteView.addEventListener("click", function () {
                favoritesOnly = !favoritesOnly;
                paintFavorites();
                applyFilters();
            });
        }
        Array.prototype.forEach.call(viewButtons, function (button) {
            button.addEventListener("click", function () {
                var view = button.getAttribute("data-molavi-view") === "list" ? "list" : "grid";
                if (grid) grid.classList.toggle("is-list-view", view === "list");
                Array.prototype.forEach.call(viewButtons, function (other) {
                    var active = other === button;
                    other.classList.toggle("is-active", active);
                    other.setAttribute("aria-pressed", active ? "true" : "false");
                });
            });
        });
        paintFavorites();
        applyFilters();
        if (sort && grid) {
            sort.addEventListener("change", function () {
                var cards = Array.prototype.slice.call(grid.querySelectorAll("[data-molavi-card]"));
                cards.sort(function (a, b) {
                    if (sort.value === "cheap" || sort.value === "dear") {
                        var difference = Number(a.getAttribute("data-price") || 0) - Number(b.getAttribute("data-price") || 0);
                        return sort.value === "dear" ? -difference : difference;
                    }
                    if (sort.value === "newest") {
                        return Number(b.getAttribute("data-created") || 0) - Number(a.getAttribute("data-created") || 0);
                    }
                    return String(a.getAttribute("data-name") || "").localeCompare(String(b.getAttribute("data-name") || ""), "fa");
                });
                cards.forEach(function (card) { grid.appendChild(card); });
            });
        }
    }

    function setupMobileBasket() {
        var root = document.querySelector("[data-molavi-storefront]");
        if (!root) return;
        var triggers = Array.prototype.slice.call(document.querySelectorAll("[data-molavi-open-basket]"));
        var dialog = root.querySelector("#molavi-basket-dialog");
        var backdrop = root.querySelector(".molavi-basket-backdrop");
        if (!triggers.length || !dialog || !backdrop) return;
        var mobileQuery = window.matchMedia("(max-width: 760px)");
        var lastTrigger = triggers[0];
        if (mobileQuery.matches) {
            dialog.setAttribute("inert", "");
            dialog.setAttribute("aria-hidden", "true");
        }

        function close() {
            dialog.classList.remove("is-open");
            dialog.setAttribute("aria-modal", "false");
            backdrop.hidden = true;
            triggers.forEach(function (trigger) { trigger.setAttribute("aria-expanded", "false"); });
            document.body.classList.remove("molavi-basket-open");
            if (mobileQuery.matches) {
                dialog.setAttribute("inert", "");
                dialog.setAttribute("aria-hidden", "true");
                lastTrigger.focus();
            } else {
                dialog.removeAttribute("inert");
                dialog.removeAttribute("aria-hidden");
            }
        }

        function open() {
            dialog.classList.add("is-open");
            dialog.setAttribute("aria-modal", "true");
            dialog.removeAttribute("inert");
            dialog.removeAttribute("aria-hidden");
            backdrop.hidden = false;
            triggers.forEach(function (trigger) { trigger.setAttribute("aria-expanded", "true"); });
            document.body.classList.add("molavi-basket-open");
            var closeButton = dialog.querySelector("[data-molavi-close-basket]");
            if (closeButton) closeButton.focus();
            else dialog.focus();
        }

        triggers.forEach(function (trigger) {
            trigger.addEventListener("click", function (event) {
                if (!mobileQuery.matches) {
                    dialog.scrollIntoView({ behavior: "smooth", block: "start" });
                    return;
                }
                lastTrigger = event.currentTarget;
                open();
            });
        });
        root.querySelectorAll("[data-molavi-close-basket]").forEach(function (button) {
            button.addEventListener("click", close);
        });
        document.addEventListener("keydown", function (event) {
            if (!dialog.classList.contains("is-open")) return;
            if (event.key === "Escape") {
                event.preventDefault();
                close();
                return;
            }
            if (event.key !== "Tab") return;
            var focusable = Array.prototype.slice.call(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (node) { return !node.hidden; });
            if (!focusable.length) {
                event.preventDefault();
                dialog.focus();
                return;
            }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
        window.addEventListener("resize", function () {
            if (window.matchMedia("(min-width: 761px)").matches) {
                if (dialog.classList.contains("is-open")) close();
                dialog.removeAttribute("inert");
                dialog.removeAttribute("aria-hidden");
            } else if (!dialog.classList.contains("is-open")) {
                dialog.setAttribute("inert", "");
                dialog.setAttribute("aria-hidden", "true");
            }
        });
    }

    setupCatalog();
    setupMobileBasket();

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

    function addButton(row) {
        var min = Number(row && row.min) || 1;
        var name = row && row.name ? row.name : "محصول";
        return '<button type="button" class="molavi-add" data-molavi-step="1" aria-label="افزودن حداقل ' + digits(min) + ' عدد ' + escapeHtml(name) + ' به سبد">' + cart() + "</button>";
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
        var value = Math.round(rate * 1000) / 10;
        var text = Math.abs(value - Math.round(value)) < 0.00001 ? String(Math.round(value)) : value.toFixed(1);
        return digits(text) + "٪";
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
