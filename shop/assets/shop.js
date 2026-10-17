(function () {
    var bootNode = document.getElementById("shop-boot");
    if (!bootNode) return;
    var state = JSON.parse(bootNode.textContent);
    state.cart = {};
    state.lines.forEach(function (line) { state.cart[line.id] = line.qty; });
    state.wishes = loadWishes();
    state.wishOnly = false;
    state.view = localStorage.getItem("molavi-view") || "auto";

    var pending = {};
    var running = {};
    var toastTimer = 0;

    var cartBadge = document.getElementById("cart-badge");
    var wishBadge = document.getElementById("wish-badge");
    var wishButton = document.getElementById("wish");
    var meter = document.getElementById("meter");
    var meterText = document.getElementById("meter-text");
    var meterBar = document.getElementById("meter-bar");
    var drawer = document.getElementById("drawer");
    var shade = document.getElementById("shade");
    var sheet = document.getElementById("sheet");
    var drawerLines = document.getElementById("drawer-lines");
    var pay = document.getElementById("pay");
    var drawerNote = document.getElementById("drawer-note");
    var live = document.getElementById("live");
    var catalog = document.querySelector(".catalog");
    var armed = false;
    var wave = [];
    var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    document.addEventListener("click", function (event) {
        var wish = event.target.closest(".heart");
        if (wish) {
            toggleWish(wish.closest(".card").dataset.pid);
            return;
        }
        var wipe = event.target.closest("[data-clear]");
        if (wipe) {
            event.preventDefault();
            var wiped = wipe.closest("[data-pid]");
            if (!wiped) return;
            change(wiped.dataset.pid, 0);
            return;
        }
        var step = event.target.closest("[data-step]");
        if (step) {
            event.preventDefault();
            var host = step.closest("[data-pid]");
            if (!host) return;
            var id = host.dataset.pid;
            var next = stepped(id, Number(step.dataset.step));
            change(id, next, (state.cart[id] || 0) === 0 && next > 0);
            return;
        }
        if (event.target.closest("#open-cart")) openDrawer();
        else if (event.target.closest("#close-drawer") || (event.target === shade && !sheet.hidden)) closeAll();
        else if (event.target === shade) closeAll();
        else if (event.target.closest("#open-menu")) openMenu();
        else if (event.target.closest("#wish")) {
            state.wishOnly = !state.wishOnly;
            wishButton.setAttribute("aria-pressed", state.wishOnly ? "true" : "false");
            wishButton.classList.toggle("on", state.wishOnly);
            applyFilters();
        } else if (event.target.closest("#search-toggle")) {
            document.getElementById("search").classList.toggle("open");
            var input = document.getElementById("q");
            if (document.getElementById("search").classList.contains("open")) input.focus();
        } else if (event.target.closest("[data-view]")) {
            state.view = event.target.closest("[data-view]").dataset.view;
            localStorage.setItem("molavi-view", state.view);
            paintView();
        } else if (event.target.closest("[data-cat]")) {
            var cat = event.target.closest("[data-cat]").dataset.cat;
            var select = document.getElementById("cat");
            if (select) select.value = cat;
            applyFilters();
        } else if (event.target.closest("#filter-toggle")) {
            var pop = document.getElementById("filter-pop");
            if (pop) pop.hidden = !pop.hidden;
        } else if (event.target.closest("#filter-clear")) {
            document.querySelectorAll("[data-supplier-filter]").forEach(function (box) { box.checked = false; });
            var shelf = document.getElementById("only-shelf");
            if (shelf) shelf.checked = false;
            applyFilters();
        }
    });

    document.addEventListener("submit", function (event) {
        if (event.target.classList && event.target.classList.contains("control")) {
            event.preventDefault();
            var id = event.target.dataset.pid;
            change(id, stepped(id, 1), true);
        }
        if (event.target.classList && event.target.classList.contains("order")) {
            var button = event.target.querySelector("button[type=submit]");
            if (button) {
                button.disabled = true;
                button.textContent = "در حال ثبت…";
            }
        }
    });

    document.addEventListener("input", function (event) {
        if (event.target.id === "q") applyFilters();
    });
    document.addEventListener("change", function (event) {
        if (event.target.id === "sort" || event.target.id === "cat" || event.target.id === "only-shelf" || event.target.matches("[data-supplier-filter]")) {
            applyFilters();
        }
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeAll();
    });

    function change(id, qty) {
        var added = arguments[2] === true;
        qty = Math.max(0, Math.min(999, qty));
        if (qty === 0) delete state.cart[id];
        else state.cart[id] = qty;
        recompute();
        paint();
        if (added) toast("به سبد اضافه شد");
        pending[id] = qty;
        if (running[id]) return;
        running[id] = true;
        drain(id);
    }

    function drain(id) {
        var qty = pending[id];
        pending[id] = null;
        post(id, qty).then(function (data) {
            if (pending[id] != null) {
                drain(id);
                return;
            }
            running[id] = false;
            applyServer(data);
        }).catch(function () {
            running[id] = false;
            toast("اتصال برقرار نشد");
            return fetch("api.php", { headers: { "Accept": "application/json" } })
                .then(function (res) { return res.json(); })
                .then(applyServer)
                .catch(function () {});
        });
    }

    function post(id, qty) {
        return fetch("api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify({ token: state.token, id: id, qty: qty })
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok || !data.ok) throw new Error(data.error || "bad");
                return data;
            });
        });
    }

    function applyServer(data) {
        if (!data || !data.ok) return;
        state.cart = {};
        state.lines = data.lines || [];
        state.lines.forEach(function (line) { state.cart[line.id] = line.qty; });
        state.units = data.units || {};
        state.total = data.total;
        state.measure = data.measure;
        state.progress = data.progress;
        state.unlock = data.unlock;
        state.discount_total = data.discount_total || 0;
        state.discount_rate = data.discount_rate || 0;
        state.count = data.count;
        paint();
    }

    function recompute() {
        var measure = 0;
        Object.keys(state.cart).forEach(function (id) {
            var item = state.book[id];
            if (!item) return;
            if (Number(item.shelf) > Number(item.floor)) {
                measure += Number(item.shelf) * state.cart[id];
            }
        });
        state.measure = measure;
        state.progress = progressOf(measure, state.unlock);
        state.units = {};
        state.total = 0;
        state.discount_total = 0;
        state.lines = [];
        Object.keys(state.book).forEach(function (id) {
            var item = state.book[id];
            var unit = priceOf(item, state.progress);
            state.units[id] = unit;
            var qty = state.cart[id] || 0;
            if (qty > 0) {
                var saved = Number(item.shelf) > unit ? (Number(item.shelf) - unit) * qty : 0;
                state.total += unit * qty;
                state.discount_total += saved;
                state.lines.push({
                    id: id,
                    name: item.name,
                    qty: qty,
                    unit: unit,
                    shelf: item.shelf,
                    total: unit * qty,
                    discount_amount: saved,
                    discount_rate: Number(item.shelf) > unit ? (Number(item.shelf) - unit) / Number(item.shelf) : 0,
                    image: item.image,
                    sku: item.sku
                });
            }
        });
        state.count = state.lines.reduce(function (sum, line) { return sum + line.qty; }, 0);
        state.discount_rate = state.measure > 0 ? state.discount_total / state.measure : 0;
    }

    function paint() {
        document.querySelectorAll(".card").forEach(function (card) {
            var id = card.dataset.pid;
            var unit = state.units[id];
            if (unit == null) return;
            var now = card.querySelector(".now");
            var amount = now.querySelector(".amount");
            if (amount) present(amount, unit, toman(unit), "shop");
            else present(now, unit, toman(unit), "shop");
            now.dataset.unit = String(unit);
            var rateNode = now.querySelector(".rate");
            var shelfPrice = Number(card.dataset.shelf);
            var current = shelfPrice > unit ? (shelfPrice - unit) / shelfPrice : 0;
            if (rateNode) {
                rateNode.hidden = current <= 0;
                if (current > 0) present(rateNode, Math.round(current * 100), "\u2212" + percent(current), "shop");
            }
            var was = card.querySelector(".was");
            var shelf = Number(card.dataset.shelf);
            if (shelf > unit) {
                was.hidden = false;
                was.textContent = toman(shelf);
            } else {
                was.hidden = true;
            }
            var control = card.querySelector(".control");
            var qty = state.cart[id] || 0;
            var qtyNode = control.querySelector(".stepper b");
            var prevQty = qtyNode ? qtyNode.dataset.n : (control.querySelector(".add") ? "0" : null);
            var prevQtyText = qtyNode ? (qtyNode.dataset.t || qtyNode.textContent) : digits(0);
            control.innerHTML = qty > 0 ? stepperHtml(qty) : addHtml();
            var nextQty = control.querySelector(".stepper b");
            if (nextQty) present(nextQty, qty, digits(qty), "shop", prevQty, prevQtyText);
            var heart = card.querySelector(".heart");
            if (heart) {
                var saved = state.wishes.indexOf(id) !== -1;
                heart.classList.toggle("on", saved);
                heart.setAttribute("aria-pressed", saved ? "true" : "false");
            }
        });
        renderLines(drawerLines, true);
        renderLines(document.getElementById("checkout-lines"), false);
        var emptyCart = (state.count || 0) < 1;
        var footGo = document.querySelector(".drawer-foot .go");
        var footPay = document.querySelector(".drawer-foot .payline");
        if (footGo) footGo.hidden = emptyCart;
        if (footPay) footPay.hidden = emptyCart;
        renderMeter();
        renderBadges();
        if (catalog && document.getElementById("sort") && document.getElementById("sort").value !== "new") {
            sortCards();
        }
        flushWave();
        armed = true;
    }

    function lineSnap(node) {
        var map = {};
        if (!node) return map;
        node.querySelectorAll(".dline").forEach(function (line) {
            var strong = line.querySelector(".dprice");
            var rate = line.querySelector(".rate");
            var qty = line.querySelector(".stepper b");
            map[line.dataset.pid] = {
                unit: strong ? strong.dataset.n : null,
                unitText: strong ? (strong.dataset.t || strong.textContent) : "",
                rate: rate ? rate.dataset.n : null,
                rateText: rate ? (rate.dataset.t || rate.textContent) : "",
                qty: qty ? qty.dataset.n : null,
                qtyText: qty ? (qty.dataset.t || qty.textContent) : ""
            };
        });
        return map;
    }

    function renderLines(node, emptyCopy) {
        if (!node) return;
        var previous = lineSnap(node);
        if (!state.lines.length) {
            node.innerHTML = emptyCopy ? '<p class="note">سبد خالی است.</p>' : "";
            return;
        }
        node.innerHTML = state.lines.map(function (line) {
            var was = line.shelf > line.unit ? '<s class="was">' + toman(line.shelf) + "</s>" : "";
            var rate = line.shelf > line.unit ? '<span class="rate" dir="ltr"></span>' : "";
            return '<article class="dline" data-pid="' + esc(line.id) + '">'
                + '<img src="' + esc(line.image) + '" alt="">'
                + '<div class="dtext"><h3>' + esc(line.name) + "</h3><p>" + rate + '<strong class="dprice"></strong> ' + was + "</p>"
                + '<p class="sku">' + esc(line.sku) + " · حداقل " + digits(minimum(line.id)) + "</p></div>"
                + stepperHtml(line.qty)
                + "</article>";
        }).join("");
        node.querySelectorAll(".dline").forEach(function (line) {
            var id = line.dataset.pid;
            var data = null;
            state.lines.forEach(function (item) { if (item.id === id) data = item; });
            if (!data) return;
            var prior = previous[id] || {};
            var price = line.querySelector(".dprice");
            present(price, data.unit, toman(data.unit), "cart", prior.unit, prior.unitText);
            var rateNode = line.querySelector(".rate");
            if (rateNode) {
                present(rateNode, Math.round(data.discount_rate * 100), "\u2212" + percent(data.discount_rate), "cart", prior.rate, prior.rateText);
            }
            var qtyNode = line.querySelector(".stepper b");
            present(qtyNode, data.qty, digits(data.qty), "cart", prior.qty, prior.qtyText);
        });
    }

    function renderMeter() {
        var count = state.count || 0;
        var saved = state.discount_total || 0;
        var hasSave = saved > 0;
        if (meter) meter.hidden = count < 1;
        var progress = Math.round((state.progress || 0) * 100);
        if (meterBar) meterBar.style.width = progress + "%";
        if (meterText) meterText.textContent = meterTextFor();
        var saving = document.getElementById("drawer-saving");
        if (saving) saving.hidden = !hasSave;
        if (drawerNote) {
            drawerNote.hidden = hasSave || count < 1;
            drawerNote.textContent = (!hasSave && count > 0) ? "تخفیف سبد هنوز باز نشده است." : "";
        }
        if (hasSave) {
            present(document.getElementById("drawer-rate"), Math.round((state.discount_rate || 0) * 100), percent(state.discount_rate || 0), "cart");
            present(document.getElementById("drawer-save"), saved, toman(saved), "cart");
        }
        var checkoutRate = document.getElementById("discount-rate");
        var checkoutSave = document.getElementById("discount-total");
        if (checkoutRate) present(checkoutRate, Math.round((state.discount_rate || 0) * 100), percent(state.discount_rate || 0), "cart");
        if (checkoutSave) present(checkoutSave, saved, toman(saved), "cart");
        if (pay) present(pay, state.total || 0, toman(state.total || 0), "cart");
        var checkoutTotal = document.getElementById("checkout-total");
        if (checkoutTotal) present(checkoutTotal, state.total || 0, toman(state.total || 0), "cart");
    }

    function discountText() {
        return percent(state.discount_rate || 0) + " · " + toman(state.discount_total || 0);
    }
    function meterTextFor() {
        if ((state.count || 0) < 1) return "";
        if ((state.discount_total || 0) < 1) return "تخفیف سبد هنوز باز نشده است.";
        return "تخفیف سبد " + discountText();
    }

    function renderBadges() {
        var count = state.count || 0;
        cartBadge.hidden = count < 1;
        present(cartBadge, count, count > 99 ? digits(99) + "+" : digits(count), "shop");
        var known = state.wishes.filter(function (id) { return state.book[id]; });
        wishBadge.hidden = known.length < 1;
        wishBadge.textContent = digits(known.length);
        if (live) live.textContent = count ? ("سبد، " + digits(count) + " عدد، " + toman(state.total || 0)) : "سبد خالی است";
    }

    function applyFilters() {
        if (!catalog) return;
        var query = norm((document.getElementById("q") || {}).value || "");
        var cat = (document.getElementById("cat") || {}).value || "";
        var shelfOnly = document.getElementById("only-shelf") && document.getElementById("only-shelf").checked;
        var suppliers = Array.prototype.map.call(
            document.querySelectorAll("[data-supplier-filter]:checked"),
            function (box) { return box.value; }
        );
        var shown = 0;
        var total = 0;
        catalog.querySelectorAll(".card").forEach(function (card) {
            total += 1;
            var ok = (!query || norm(card.dataset.name).indexOf(query) !== -1)
                && (!cat || card.dataset.cat === cat)
                && (!suppliers.length || suppliers.indexOf(card.dataset.supplier) !== -1)
                && (!shelfOnly || Number(card.dataset.shelf) > 0)
                && (!state.wishOnly || state.wishes.indexOf(card.dataset.pid) !== -1);
            card.hidden = !ok;
            if (ok) shown += 1;
        });
        var countNode = document.getElementById("count");
        if (countNode) {
            countNode.textContent = shown
                ? "نمایش " + digits(1) + " تا " + digits(shown) + " از " + digits(total) + " محصول"
                : "کالایی با این مشخصات پیدا نشد";
        }
        document.querySelectorAll(".chips button").forEach(function (button) {
            button.classList.toggle("on", button.dataset.cat === cat);
        });
        var filterButton = document.getElementById("filter-toggle");
        if (filterButton) filterButton.classList.toggle("on", suppliers.length > 0 || shelfOnly);
        sortCards();
        var empty = document.getElementById("empty");
        if (empty) empty.hidden = shown !== 0;
    }

    function sortCards() {
        if (!catalog) return;
        var mode = (document.getElementById("sort") || {}).value || "new";
        var cards = Array.prototype.slice.call(catalog.querySelectorAll(".card"));
        cards.sort(function (a, b) {
            if (mode === "name") return a.dataset.name.localeCompare(b.dataset.name, "fa");
            if (mode === "cheap" || mode === "dear") {
                var av = Number(a.querySelector(".now").dataset.unit || 0);
                var bv = Number(b.querySelector(".now").dataset.unit || 0);
                return mode === "cheap" ? av - bv : bv - av;
            }
            return Number(a.dataset.index) - Number(b.dataset.index);
        });
        cards.forEach(function (card) { catalog.appendChild(card); });
    }

    function paintView() {
        if (!catalog) return;
        catalog.classList.remove("view-auto", "view-grid", "view-list");
        catalog.classList.add("view-" + state.view);
        document.querySelectorAll("[data-view]").forEach(function (button) {
            button.classList.toggle("on", button.dataset.view === state.view);
        });
    }

    function toggleWish(id) {
        var at = state.wishes.indexOf(id);
        if (at === -1) state.wishes.push(id);
        else state.wishes.splice(at, 1);
        localStorage.setItem("molavi-wish", JSON.stringify(state.wishes));
        paint();
        applyFilters();
    }

    function openDrawer() {
        drawer.hidden = false;
        shade.hidden = false;
        sheet.hidden = true;
        document.body.classList.add("locked");
    }
    function openMenu() {
        sheet.hidden = !sheet.hidden;
        shade.hidden = sheet.hidden;
        drawer.hidden = true;
        document.body.classList.toggle("locked", !sheet.hidden);
    }
    function closeAll() {
        drawer.hidden = true;
        sheet.hidden = true;
        shade.hidden = true;
        document.body.classList.remove("locked");
    }

    function toast(message) {
        var node = document.getElementById("toast");
        node.textContent = message;
        node.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { node.hidden = true; }, 1800);
    }

    function minimum(id) {
        var item = state.book[id];
        var min = item && Number(item.min);
        if (!(min > 0)) {
            var card = document.querySelector('.card[data-pid="' + id + '"]');
            min = card ? Number(card.dataset.min) : 1;
        }
        return min > 0 ? min : 1;
    }
    function stepped(id, delta) {
        var current = state.cart[id] || 0;
        var min = minimum(id);
        if (delta > 0 && current < 1) return min;
        var next = current + delta;
        if (next < min) return 0;
        return Math.min(999, next);
    }
    function stepperHtml(qty) {
        return '<div class="actions"><div class="stepper"><button type="button" data-step="-1" aria-label="کم کردن">−</button><b>'
            + digits(qty) + '</b><button type="button" data-step="1" aria-label="افزودن">+</button></div>'
            + '<button type="button" class="trash" data-clear aria-label="حذف از سبد">' + trashSvg() + "</button></div>";
    }
    function addHtml() {
        return '<button type="button" class="add" data-step="1" aria-label="افزودن به سبد">' + cartSvg() + "</button>";
    }
    function cartSvg() {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h1.6l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H8"></path><circle cx="10" cy="19" r="1.2"></circle><circle cx="17" cy="19" r="1.2"></circle></svg>';
    }
    function digits(value) {
        return String(value).replace(/\d/g, function (d) {
            return "۰۱۲۳۴۵۶۷۸۹"[d];
        }).replace(/,/g, "٬");
    }
    function toman(amount) {
        return digits(Number(amount || 0).toLocaleString("en-US")) + " تومان";
    }
    function priceOf(item, progress) {
        var shelf = Number(item.shelf);
        var ceiling = Number(item.ceiling);
        var floor = Number(item.floor);
        if (!(shelf > 0)) return 0;
        var unit = halfUp(ceiling + (floor - ceiling) * progress);
        var low = Math.min(floor, ceiling);
        var high = Math.min(Math.max(floor, ceiling, low), shelf);
        if (unit < low) unit = low;
        if (unit > high) unit = high;
        return unit;
    }
    function progressOf(measure, unlock) {
        if (measure > 0 && unlock > 0) return Math.min(1, measure / unlock);
        return 0;
    }
    function halfUp(value) {
        var nearest = Math.round(value * 1e8) / 1e8;
        var whole = Math.trunc(nearest);
        if (nearest - whole >= 0.5 - 1e-8) return whole + 1;
        return whole;
    }
    function percent(rate) {
        return digits(Math.round(rate * 100)) + "٪";
    }
    function trashSvg() {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" stroke="none" d="M4.4 6.15h15.2c.5 0 .9.4.9.9s-.4.9-.9.9H4.4c-.5 0-.9-.4-.9-.9s.4-.9.9-.9z"></path><path fill="currentColor" stroke="none" d="M10 3.3h4c.55 0 1 .4 1 .95V6.1h-6V4.25c0-.55.45-.95 1-.95z"></path><path fill="currentColor" stroke="none" fill-rule="evenodd" d="M6.35 9.2h11.3c.25 0 .45.22.43.48l-.78 10.05c-.08.85-.8 1.5-1.65 1.5H8.35c-.85 0-1.57-.65-1.65-1.5L5.92 9.68c-.02-.26.18-.48.43-.48zM9.15 12h1.4v5.15h-1.4V12zm4.3 0h1.4v5.15h-1.4V12z"></path></svg>';
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
        var changed = toPlaces.some(function (index) { return oldAt[index] !== to[index]; });
        if (!changed) {
            el.textContent = toText;
            return;
        }
        var lastDigit = -1;
        to.forEach(function (ch, index) {
            if (digitChar(ch)) lastDigit = index;
        });
        var body = to.slice(0, lastDigit + 1);
        var suffix = to.slice(lastDigit + 1).join("");
        var token = (el._roll || 0) + 1;
        el._roll = token;
        var root = document.createElement("span");
        root.className = "tick";
        root.dir = "ltr";
        var pending = 0;
        body.forEach(function (ch, index) {
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
        if (suffix) el.appendChild(document.createTextNode(suffix));
        if (pending === 0) el.textContent = toText;
    }
    function digitChar(ch) {
        return /[0-9۰-۹]/.test(ch);
    }
    function norm(value) {
        return String(value || "").replace(/ي/g, "ی").replace(/ك/g, "ک").replace(/کارتن/g, " ").replace(/درب/g, " ").replace(/\s+/g, " ").trim().toLowerCase();
    }
    function esc(value) {
        return String(value || "").replace(/[&<>"']/g, function (ch) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch];
        });
    }
    function loadWishes() {
        try { return JSON.parse(localStorage.getItem("molavi-wish") || "[]"); }
        catch (e) { return []; }
    }

    var params = new URLSearchParams(location.search);
    var queryInput = document.getElementById("q");
    if (queryInput && params.get("q")) {
        queryInput.value = params.get("q");
        document.getElementById("search").classList.add("open");
    }
    paintView();
    paint();
    applyFilters();
    if (queryInput && !catalog) {
        queryInput.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                location.href = "index.php?q=" + encodeURIComponent(queryInput.value);
            }
        });
    }
})();
