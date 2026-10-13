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

    document.addEventListener("click", function (event) {
        var wish = event.target.closest(".heart");
        if (wish) {
            toggleWish(wish.closest(".card").dataset.pid);
            return;
        }
        var step = event.target.closest("[data-step]");
        if (step) {
            event.preventDefault();
            var host = step.closest("[data-pid]");
            if (!host) return;
            var id = host.dataset.pid;
            var next = (state.cart[id] || 0) + Number(step.dataset.step);
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
            change(id, (state.cart[id] || 0) + 1, true);
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
        state.floor = data.floor;
        state.ceiling = data.ceiling;
        state.count = data.count;
        paint();
    }

    function recompute() {
        var measure = 0;
        Object.keys(state.cart).forEach(function (id) {
            var item = state.book[id];
            if (!item) return;
            var basis = item.shelf > 0 ? item.shelf : item.sale;
            measure += basis * state.cart[id];
        });
        state.measure = measure;
        state.progress = progressOf(measure, state.floor, state.ceiling);
        state.units = {};
        state.total = 0;
        state.lines = [];
        Object.keys(state.book).forEach(function (id) {
            var item = state.book[id];
            var unit = priceOf(item.sale, item.shelf, state.progress);
            state.units[id] = unit;
            var qty = state.cart[id] || 0;
            if (qty > 0) {
                state.total += unit * qty;
                state.lines.push({
                    id: id,
                    name: item.name,
                    qty: qty,
                    unit: unit,
                    shelf: item.shelf,
                    sale: item.sale,
                    total: unit * qty,
                    image: item.image,
                    sku: item.sku
                });
            }
        });
        state.count = state.lines.reduce(function (sum, line) { return sum + line.qty; }, 0);
    }

    function paint() {
        document.querySelectorAll(".card").forEach(function (card) {
            var id = card.dataset.pid;
            var unit = state.units[id];
            if (unit == null) return;
            var now = card.querySelector(".now");
            now.textContent = toman(unit);
            now.dataset.unit = String(unit);
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
            control.innerHTML = qty > 0 ? stepperHtml(qty) : addHtml();
            var heart = card.querySelector(".heart");
            var saved = state.wishes.indexOf(id) !== -1;
            heart.classList.toggle("on", saved);
            heart.setAttribute("aria-pressed", saved ? "true" : "false");
        });
        renderLines(drawerLines, true);
        renderLines(document.getElementById("checkout-lines"), false);
        var checkoutTotal = document.getElementById("checkout-total");
        if (checkoutTotal) checkoutTotal.textContent = toman(state.total || 0);
        if (pay) pay.textContent = toman(state.total || 0);
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
    }

    function renderLines(node, emptyCopy) {
        if (!node) return;
        if (!state.lines.length) {
            node.innerHTML = emptyCopy ? '<p class="note">سبد خالی است.</p>' : "";
            return;
        }
        node.innerHTML = state.lines.map(function (line) {
            var was = line.shelf > line.unit ? '<s class="was">' + toman(line.shelf) + "</s>" : "";
            return '<article class="dline" data-pid="' + esc(line.id) + '">'
                + '<img src="' + esc(line.image) + '" alt="">'
                + '<div class="dtext"><h3>' + esc(line.name) + "</h3><p><strong>" + toman(line.unit) + "</strong> " + was + "</p>"
                + '<p class="sku">' + esc(line.sku) + "</p></div>"
                + stepperHtml(line.qty)
                + "</article>";
        }).join("");
    }

    function renderMeter() {
        var count = state.count || 0;
        if (meter) meter.hidden = count < 1;
        var percent = Math.round((state.progress || 0) * 100);
        if (meterBar) meterBar.style.width = percent + "%";
        var text = meterTextFor(percent);
        if (meterText) meterText.textContent = text;
        if (drawerNote) drawerNote.textContent = text;
    }

    function meterTextFor(percent) {
        if ((state.count || 0) < 1) return "";
        if (!state.floor) return "جمع سبد " + toman(state.total || 0);
        if ((state.measure || 0) <= state.floor) {
            return "تخفیف از جمع " + toman(state.floor) + " با قیمت مصرف شروع می‌شود. الان " + toman(state.measure || 0) + " است.";
        }
        if (percent >= 100) return "قیمت فروش تأمین‌کننده روی این سبد اعمال شده است.";
        return percent + "٪ از راه تا قیمت فروش تأمین‌کننده. سقف این تخفیف " + toman(state.ceiling) + " است.";
    }

    function renderBadges() {
        var count = state.count || 0;
        cartBadge.hidden = count < 1;
        cartBadge.textContent = count > 99 ? "99+" : String(count);
        var known = state.wishes.filter(function (id) { return state.book[id]; });
        wishBadge.hidden = known.length < 1;
        wishBadge.textContent = String(known.length);
        if (live) live.textContent = count ? ("سبد، " + count + " عدد، " + toman(state.total || 0)) : "سبد خالی است";
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
                && (!shelfOnly || Number(card.dataset.shelf) > Number(card.dataset.sale))
                && (!state.wishOnly || state.wishes.indexOf(card.dataset.pid) !== -1);
            card.hidden = !ok;
            if (ok) shown += 1;
        });
        var countNode = document.getElementById("count");
        if (countNode) {
            countNode.textContent = shown
                ? "نمایش ۱ تا " + shown + " از " + total + " محصول"
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

    function stepperHtml(qty) {
        return '<div class="stepper"><button type="button" data-step="-1" aria-label="کم کردن">−</button><b>'
            + qty + '</b><button type="button" data-step="1" aria-label="افزودن">+</button></div>';
    }
    function addHtml() {
        return '<button type="button" class="add" data-step="1" aria-label="افزودن به سبد">' + cartSvg() + "</button>";
    }
    function cartSvg() {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h1.6l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H8"></path><circle cx="10" cy="19" r="1.2"></circle><circle cx="17" cy="19" r="1.2"></circle></svg>';
    }
    function toman(amount) {
        return Number(amount || 0).toLocaleString("en-US") + " تومان";
    }
    function priceOf(sale, shelf, progress) {
        sale = Number(sale);
        shelf = Number(shelf);
        if (shelf > sale) {
            var unit = Math.round((shelf - progress * (shelf - sale)) / 1000) * 1000;
            return Math.max(sale, Math.min(shelf, unit));
        }
        return sale;
    }
    function progressOf(measure, floor, ceiling) {
        if (floor > 0 && ceiling > floor && measure > floor) {
            return Math.min(1, (measure - floor) / (ceiling - floor));
        }
        return 0;
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
