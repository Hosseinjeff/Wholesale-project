(function () {
    if (!window.molaviShop) return;

    var customerId = Number(molaviShop.customerId) || 0;
    var accountFavorites = null;
    var favoriteKey = customerId ? 'molavi-wishlist-user-' + customerId : 'molavi-wishlist-v1';
    var catalog = molaviShop.catalog || {};
    var qtys = Object.assign({}, molaviShop.qtys || {});
    var serverQtys = Object.assign({}, molaviShop.qtys || {});
    var lastQuote = molaviShop.initial || { units: {}, qtys: serverQtys, progress: 0, unlock: molaviShop.unlock || 0 };
    var buyerType = molaviShop.buyerType === "shop" ? "shop" : "household";
    var sending = false;
    var listRestoring = false;
    var nativeCartUpdating = false;
    var pricingRevision = 0;
    var dirty = false;
    var retries = 0;
    var buyerRequestId = 0;
    var buyerChanging = false;
    var recommendationIndex = 0;
    var recommendationBuyerType = "";
    var recommendationProducts = [];

    document.addEventListener("click", function (event) {
        var button = event.target.closest("[data-molavi-buyer]");
        if (!button) return;
        event.preventDefault();
        var requested = button.getAttribute("data-molavi-buyer");
        if (requested !== "household" && requested !== "shop") return;
        chooseBuyer(requested);
    });

    document.addEventListener("click", function (event) {
        var button = event.target.closest("[data-molavi-step], [data-molavi-clear], [data-molavi-set-qty]");
        if (!button) return;
        if (buyerChanging || listRestoring || nativeCartUpdating) {
            event.preventDefault();
            return;
        }
        var host = button.closest("[data-product]");
        if (!host) return;
        event.preventDefault();
        var id = host.getAttribute("data-product");
        if (!id) return;
        showStatus("");
        var row = catalog[id] || {};
        var min = Number(host.getAttribute("data-min")) || Number(row.min) || 1;
        var qty = Number(qtys[id] || 0);
        var next = 0;
        var removedForMinimum = false;
        if (button.hasAttribute("data-molavi-set-qty")) {
            next = Number(button.getAttribute("data-molavi-set-qty")) || 0;
        } else if (button.hasAttribute("data-molavi-clear")) {
            next = 0;
        } else {
            var step = Number(button.getAttribute("data-molavi-step")) || 0;
            if (step > 0 && qty < 1) next = min;
            else next = qty + step;
            if (next < min) {
                removedForMinimum = step < 0 && qty > 0;
                next = 0;
            }
            if (next > 999) next = Math.floor(999 / min) * min;
        }
        if (next > 0) qtys[id] = next;
        else delete qtys[id];
        paint(localQuote());
        pulseProduct(id);
        if (removedForMinimum) showStatus("با کاهش تعداد به کمتر از گام سفارش، این کالا از سبد حذف شد.");
        sync();
    });

    document.addEventListener("click", function (event) {
        var checkout = event.target.closest("[data-molavi-checkout]");
        if (!checkout) return;
        if (buyerChanging || sending || dirty || listRestoring) {
            event.preventDefault();
            showStatus("سبد و قیمت‌ها در حال ذخیره است؛ پس از پایان، ادامه دهید.");
            return;
        }
        if (checkout.getAttribute("aria-disabled") !== "true") return;
        event.preventDefault();
        var firstInvalid = document.querySelector(".molavi-bline[data-invalid='1']");
        if (firstInvalid) {
            firstInvalid.setAttribute("tabindex", "-1");
            firstInvalid.focus({ preventScroll: true });
            firstInvalid.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
        showStatus("برای ادامه، تعداد کالاهای مشخص‌شده را با گام سفارش هماهنگ کنید.");
    });

    function localQuote() {
        return Object.assign({}, lastQuote, {
            units: lastQuote.units || {},
            qtys: Object.assign({}, qtys),
            buyer_type: buyerType,
            invalid_lines: invalidLines(qtys),
            switch_suggestion: suggestion(qtys)
        });
    }

    function rowOf(id) {
        if (catalog[id]) {
            var publicRow = catalog[id];
            var currentUnit = buyerType === "shop" ? Number(publicRow.shop_unit) : Number(publicRow.household_unit);
            var currentThreshold = buyerType === "shop" ? Number(publicRow.shop_threshold_units) : Number(publicRow.household_threshold_units);
            return {
                name: publicRow.name || "",
                image: publicRow.image || "",
                min: currentUnit || Number(publicRow.min) || 1,
                householdUnit: Number(publicRow.household_unit) || 1,
                shopUnit: Number(publicRow.shop_unit) || 1,
                packSize: Number(publicRow.pack_size) || 1,
                thresholdUnits: currentThreshold || 1,
                shelf: Number(publicRow.shelf) || 0,
                discountable: Number(publicRow.discountable) || 0,
                householdMax: Number(publicRow.household_max_discount) || 0,
                shopMax: Number(publicRow.shop_max_discount) || 0
            };
        }
        var node = document.querySelector('[data-molavi-price][data-product="' + cssEscape(id) + '"]');
        if (!node) return null;
        var profileMin = buyerType === "shop"
            ? Number(node.getAttribute("data-shop-min")) : Number(node.getAttribute("data-household-min"));
        return {
            name: node.getAttribute("data-name") || "",
            image: node.getAttribute("data-image") || "",
            min: profileMin || Number(node.getAttribute("data-min")) || 1,
            householdUnit: Number(node.getAttribute("data-household-min")) || 1,
            shopUnit: Number(node.getAttribute("data-shop-min")) || 1,
            thresholdUnits: 1,
            shelf: Number(node.getAttribute("data-shelf")) || 0,
            discountable: Number(node.getAttribute("data-discountable")) || 0,
            householdMax: Number(node.getAttribute("data-household-max")) || 0,
            shopMax: Number(node.getAttribute("data-shop-max")) || 0
        };
    }

    function invalidLines(sourceQtys) {
        var invalid = [];
        Object.keys(sourceQtys || {}).forEach(function (id) {
            var quantity = Number(sourceQtys[id]) || 0;
            var row = rowOf(id);
            if (!row || quantity < 1) return;
            var step = Math.max(1, Number(row.min) || 1);
            if (quantity >= step && quantity % step === 0) return;
            invalid.push({ product_id: String(id), name: row.name || "کالا", quantity: quantity,
                order_unit: step, needed_quantity: Math.max(step, Math.ceil(quantity / step) * step) });
        });
        return invalid;
    }

    function suggestion(sourceQtys) {
        var eligibleReference = 0;
        var householdValid = true;
        Object.keys(sourceQtys || {}).forEach(function (id) {
            var qty = Number(sourceQtys[id]) || 0;
            var product = rowOf(id) || {};
            if (qty < 1) return;
            var maxDiscount = buyerType === "shop" ? product.shopMax : product.householdMax;
            if (maxDiscount > 0) eligibleReference += Number(product.shelf) * qty;
            var householdUnit = Math.max(1, Number(product.householdUnit) || 1);
            if (qty < householdUnit || qty % householdUnit !== 0) householdValid = false;
        });
        var householdTarget = Number(lastQuote.household_unlock) || 5000000;
        var shopTarget = Number(lastQuote.shop_unlock) || householdTarget * 4;
        if (buyerType === "household" && eligibleReference > householdTarget) {
            return { buyer_type: "shop", reason: "household_threshold_exceeded", eligible_reference: eligibleReference };
        }
        if (buyerType === "shop" && eligibleReference < shopTarget && householdValid) {
            return { buyer_type: "household", reason: "household_order", eligible_reference: eligibleReference };
        }
        return null;
    }

    function updateBuyerControls() {
        document.querySelectorAll("[data-molavi-buyer]").forEach(function (button) {
            button.setAttribute("aria-pressed", button.getAttribute("data-molavi-buyer") === buyerType ? "true" : "false");
        });
        document.querySelectorAll("[data-molavi-buyer-current]").forEach(function (node) {
            node.textContent = buyerType === "shop" ? "مغازه‌دار" : "خانوار";
        });
        document.querySelectorAll("[data-molavi-buyer-icon-house]").forEach(function (icon) {
            icon.hidden = buyerType !== "household";
        });
        document.querySelectorAll("[data-molavi-buyer-icon-store]").forEach(function (icon) {
            icon.hidden = buyerType !== "shop";
        });
        document.querySelectorAll("[data-molavi-buyer-note]").forEach(function (node) {
            node.textContent = buyerType === "shop"
                ? "قیمت و گام سفارش فروشگاه نمایش داده می‌شود."
                : "قیمت و گام سفارش خانوار نمایش داده می‌شود.";
        });
        document.querySelectorAll("[data-molavi-buyer-help]").forEach(function (node) {
            node.textContent = "«خانوار» برای مصرف خانه و «فروشگاه» برای خرید مغازه است. هرکدام قیمت و گام سفارش خود را دارد. با تغییر نوع خرید، تعداد سبد حفظ می‌شود؛ اگر لازم باشد، دکمهٔ «اصلاح تعداد» نشان داده می‌شود.";
        });
    }

    function showBuyerFeedback(quote) {
        var invalid = Array.isArray(quote && quote.invalid_lines) ? quote.invalid_lines : [];
        var switchSuggestion = quote && quote.switch_suggestion;
        var storeRoot = document.querySelector("[data-molavi-storefront]");
        var basket = document.querySelector("#molavi-basket-dialog");
        var standaloneBasket=basket&&basket.closest('.molavi-global-basket-shell');
        var cartVisible = basket && (basket.classList.contains("is-open")
            || (!standaloneBasket && window.matchMedia("(min-width: 901px)").matches));
        var feedbackNodes = document.querySelectorAll("[data-molavi-buyer-feedback]");
        feedbackNodes.forEach(function (node) {
            node.replaceChildren();
            node.hidden = true;
        });
        var globalFeedback = cartVisible && basket
            ? basket.querySelector("[data-molavi-buyer-feedback]")
            : storeRoot ? storeRoot.querySelector(".molavi-product-area [data-molavi-buyer-feedback]")
                : Array.from(feedbackNodes).find(function(node){return !basket||!basket.contains(node);});
        if (!globalFeedback) globalFeedback = feedbackNodes[0];
        if (globalFeedback) {
            if (switchSuggestion && switchSuggestion.buyer_type) {
                var shopSuggested=switchSuggestion.buyer_type==='shop';
                var heading=document.createElement('strong');heading.className='molavi-suggestion-title';
                heading.textContent=shopSuggested?'پیشنهاد برای این سبد: خرید فروشگاهی':'پیشنهاد برای این سبد: خرید خانوار';globalFeedback.appendChild(heading);
                var reference = Number(switchSuggestion.eligible_reference) || 0;
                var reason=document.createElement('p');
                reason.textContent=shopSuggested
                    ? 'ارزش مرجع همهٔ کالاهای تخفیف‌پذیر سبد شما '+toman(reference)+' است و از حد مسیر خانوار ('+toman(Number(quote.household_unlock)||5000000)+') بیشتر شده است. مسیر فروشگاه برای خرید با حجم و گام‌های بزرگ‌تر در نظر گرفته شده است.'
                    : 'تعدادهای فعلی با گام‌های خانوار سازگارند و ارزش مرجع سبد ('+toman(reference)+') هنوز کمتر از حد مسیر فروشگاه ('+toman(Number(quote.shop_unlock)||20000000)+') است. به همین دلیل بررسی مسیر خانوار پیشنهاد می‌شود.';
                globalFeedback.appendChild(reason);
                var effect=document.createElement('p');effect.textContent='با تغییر نوع خرید، قیمت و تخفیف دوباره محاسبه می‌شود؛ کالاها و تعداد فعلی خودکار عوض نمی‌شوند. اگر تعداد با گام جدید هماهنگ نباشد، مقدار پیشنهادی «اصلاح تعداد» را پیش از ادامه سفارش بررسی و تأیید کنید.';globalFeedback.appendChild(effect);
                var switchButton = document.createElement("button");
                switchButton.type = "button";
                switchButton.className = "molavi-buyer-suggestion";
                switchButton.setAttribute("data-molavi-buyer", switchSuggestion.buyer_type);
                switchButton.textContent = switchSuggestion.buyer_type === "shop"
                    ? "تغییر نوع خرید به فروشگاه" : "تغییر نوع خرید به خانوار";
                globalFeedback.appendChild(switchButton);
            }
            if (invalid.length) {
                globalFeedback.appendChild(document.createTextNode(
                    "تعداد فعلی حفظ شده است. " + digits(invalid.length) + " کالا با گام جدید سازگار نیست؛ دکمهٔ «اصلاح تعداد» برای هر کالا، تعداد فعلی و تعداد پیشنهادی را نشان می‌دهد و فقط با انتخاب شما تغییر می‌کند."
                ));
            }
            globalFeedback.hidden = !globalFeedback.childNodes.length;
        }
    }

    function chooseBuyer(requested) {
        if (!molaviShop.buyer || requested === buyerType || buyerChanging || listRestoring) return;
        if (sending) {
            window.setTimeout(function () { chooseBuyer(requested); }, 80);
            return;
        }
        var previous = buyerType;
        var requestId = ++buyerRequestId;
        buyerChanging = true;
        pricingRevision++;
        updateCheckoutBusy();
        showStatus("قیمت و واحد سفارش در حال به‌روزرسانی است…");
        document.querySelectorAll("[data-molavi-buyer]").forEach(function (button) { button.disabled = true; });
        var body = new FormData();
        body.append("nonce", molaviShop.nonce);
        body.append("buyer_type", requested);
        fetch(molaviShop.buyer, { method: "POST", body: body, credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (requestId !== buyerRequestId) return;
                if (!payload || !payload.success || !payload.data || !payload.data.quote) throw new Error("buyer_profile_failed");
                buyerType = payload.data.quote.buyer_type === "shop" ? "shop" : "household";
                lastQuote = payload.data.quote;
                serverQtys = Object.assign({}, payload.data.quote.qtys || {});
                qtys = Object.assign({}, serverQtys);
                updateBuyerControls();
                if (document.body.classList.contains("woocommerce-cart")
                    && !document.querySelector("[data-molavi-storefront]")) {
                    window.location.reload();
                    return;
                }
                paint(payload.data.quote);
                document.querySelectorAll("[data-molavi-buyer-disclosure]").forEach(function (disclosure) {
                    disclosure.open = false;
                });
                showStatus(payload.data.quote.invalid_lines && payload.data.quote.invalid_lines.length
                    ? "نوع خرید تغییر کرد؛ تعدادهای سبد حفظ شدند. کالاهای مشخص‌شده را پیش از ادامه اصلاح کنید."
                    : "نوع خرید تغییر کرد؛ تعدادهای سبد حفظ شدند.");
            })
            .catch(function () {
                if (requestId !== buyerRequestId) return;
                buyerType = previous;
                updateBuyerControls();
                paint(lastQuote);
                showStatus("نوع خرید ذخیره نشد. دوباره تلاش کنید.");
            })
            .then(function () {
                if (requestId !== buyerRequestId) return;
                buyerChanging = false;
                updateCheckoutBusy();
                document.dispatchEvent(new CustomEvent("molavi:buyer"));
                document.querySelectorAll("[data-molavi-buyer]").forEach(function (button) { button.disabled = false; });
            });
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
        pricingRevision++;
        updateCheckoutBusy();
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
                    refreshNativeCart();
                }
            })
            .catch(function () {
                failed = true;
            })
            .then(function () {
                sending = false;
                updateCheckoutBusy();
                document.dispatchEvent(new CustomEvent("molavi:cart"));
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
        if (quote && (quote.buyer_type === "shop" || quote.buyer_type === "household")) buyerType = quote.buyer_type;
        updateBuyerControls();
        var units = quote.units || {};
        var counts = quote.qtys || qtys;
        document.querySelectorAll("[data-molavi-price]").forEach(function (node) {
            var id = node.getAttribute("data-product");
            if (units[id] == null) return;
            var unit = Number(units[id]);
            var shelf = Number(node.getAttribute("data-shelf")) || 0;
            var amount = node.querySelector(".molavi-amount");
            var row = rowOf(id);
            if (row) {
                node.setAttribute("data-min", String(row.min));
                node.setAttribute("data-household-min", String(row.householdUnit));
                node.setAttribute("data-shop-min", String(row.shopUnit));
                var orderUnits = node.closest("[data-molavi-card]")?.querySelector("[data-molavi-order-units]");
                if (orderUnits) orderUnits.innerHTML = "گام سفارش: <strong data-molavi-step-value>" + digits(row.min) + " عدد</strong>";
                if (orderUnits) orderUnits.title = "خانوار " + digits(row.householdUnit) + " عدد؛ فروشگاه " + digits(row.shopUnit) + " عدد";
            }
            if (amount) {
                var unitLabel = toman(unit);
                if (amount.textContent !== unitLabel) amount.textContent = unitLabel;
                amount.title = unitLabel;
            }
            node.setAttribute("data-unit", String(unit));
            var card = node.closest("[data-molavi-card]");
            if (card) {
                card.setAttribute("data-price", String(unit));
                card.setAttribute("data-has-discount", shelf > unit ? "1" : "0");
                var fullPack = card.querySelector("[data-molavi-full-pack-info]");
                if (fullPack) {
                    var packSize = Number((quote.full_pack_sizes || {})[id]) || 0;
                    fullPack.hidden = !packSize || Number(counts[id]) !== Number(serverQtys[id]);
                }
            }
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
                if (shelf > unit) {
                    was.textContent = toman(shelf);
                    was.title = toman(shelf);
                }
            }
        });
        document.querySelectorAll("[data-molavi-profile-deal]").forEach(function (node) {
            var row = rowOf(node.getAttribute("data-product") || "");
            if (!row) return;
            var maximum = buyerType === "shop" ? row.shopMax : row.householdMax;
            var prefix = node.getAttribute("data-prefix") || "تا";
            node.classList.toggle("molavi-deal-fixed", maximum <= 0);
            var dealText = maximum > 0
                ? (prefix === "تا" ? "تا " + percent(maximum) + " تخفیف" : percent(maximum) + " " + prefix)
                : "قیمت ثابت";
            var shortDeal = maximum > 0 ? "تا " + percent(maximum) : "ثابت";
            node.setAttribute("data-short", shortDeal);
            node.setAttribute("data-short-compact", maximum > 0 ? percent(maximum) : "ثابت");
            var dealCopy = node.querySelector(".molavi-deal-copy");
            if (!dealCopy) {
                node.replaceChildren();
                dealCopy = document.createElement("span");
                dealCopy.className = "molavi-deal-copy";
                node.appendChild(dealCopy);
            }
            dealCopy.textContent = dealText;
            node.setAttribute("aria-label", maximum > 0 ? "حداکثر " + percent(maximum) + " تخفیف" : "قیمت ثابت");
        });
        document.querySelectorAll(".e-loop-item [data-molavi-cart]").forEach(function (node) {
            var id = node.getAttribute("data-product");
            var row = rowOf(id);
            var qty = Number(counts[id] || 0);
            updateCardFacts(node.closest("[data-molavi-card]"), qty, row);
            updateCardControl(node, qty, row);
        });
        paintBasket(units, counts, quote);
        showBuyerFeedback(quote);
        updateRecommendations(quote);
        paintCustomerDetails(quote);
        var count = 0;
        Object.keys(counts).forEach(function (id) { count += Number(counts[id]) || 0; });
        document.querySelectorAll("[data-molavi-count]").forEach(function (badge) {
            badge.textContent = digits(count>99?"99+":count);
            badge.hidden = count < 1;
        });
        document.querySelectorAll(".elementor-button-icon-qty").forEach(function (badge) {
            badge.textContent = digits(count);
            badge.setAttribute("data-counter", String(count));
        });
    }

    function updateCardFacts(card, qty, row) {
        if (!card || !row) return;
        var step = Math.max(1, Number(row.min) || 1);
        var invalid = qty > 0 && (qty < step || qty % step !== 0);
        var orderUnits = card.querySelector("[data-molavi-order-units]");
        var category = card.querySelector(".molavi-card-category");
        var warning = card.querySelector("[data-molavi-card-step-warning]");
        var warningText = card.querySelector("[data-molavi-card-step-warning-text]");
        if (orderUnits) {
            orderUnits.innerHTML = "گام سفارش: <strong data-molavi-step-value>" + digits(step) + " عدد</strong>";
            orderUnits.title = "خانوار " + digits(row.householdUnit) + " عدد؛ فروشگاه " + digits(row.shopUnit) + " عدد";
            orderUnits.hidden = false;
        }
        if (category) category.hidden = invalid;
        if (warning) warning.hidden = !invalid;
        if (warningText) warningText.textContent = invalid
            ? "تعداد " + digits(qty) + " · گام " + digits(step)
            : "";
    }

    function cardAdjustment(qty, row) {
        var step = Math.max(1, Number(row && row.min) || 1);
        var next = Math.max(step, Math.ceil(qty / step) * step);
        if (next > 999) next = Math.max(step, Math.floor(999 / step) * step);
        var label = "اصلاح تعداد از " + digits(qty) + " به " + digits(next);
        return '<button type="button" class="molavi-card-adjust" data-molavi-set-qty="' + next
            + '" aria-label="' + escapeHtml(label) + '"><span class="molavi-card-adjust-label" data-compact-label="اصلاح">اصلاح تعداد</span>'
            + '<span class="molavi-count-shift" aria-hidden="true"><b>' + digits(qty) + '</b><i>→</i><b>' + digits(next)
            + '</b></span></button>';
    }

    function updateCardControl(node, qty, row) {
        var min = Math.max(1, Number(row && row.min) || Number(node.getAttribute("data-min")) || 1);
        var inCart = node.classList.contains("is-in");
        var invalid = qty > 0 && (qty < min || qty % min !== 0);
        node.setAttribute("data-min", String(min));
        node.setAttribute("data-qty", String(qty));

        if (qty > 0 && inCart && !invalid) {
            var count = node.querySelector(".molavi-step b");
            var buttons = node.querySelectorAll(".molavi-step button[data-molavi-step]");
            if (count && count.textContent !== digits(qty)) count.textContent = digits(qty);
            if (buttons.length >= 2) {
                var minusDelta = -min;
                var plusDelta = min;
                var minusLabel = "کم کردن " + digits(min) + " عدد";
                var plusLabel = "افزودن " + digits(min) + " عدد";
                var disablePlus = false;
                if (qty < min || qty % min !== 0) {
                    var previousValid = Math.floor(Math.max(0, qty - 1) / min) * min;
                    var nextValid = Math.min(999 - (999 % min), Math.max(min, Math.ceil(qty / min) * min));
                    minusDelta = previousValid - qty;
                    plusDelta = nextValid - qty;
                    minusLabel = "تغییر تعداد به " + digits(previousValid) + " عدد";
                    plusLabel = "تغییر تعداد به " + digits(nextValid) + " عدد";
                    disablePlus = nextValid <= qty;
                }
                buttons[0].setAttribute("data-molavi-step", String(minusDelta));
                buttons[1].setAttribute("data-molavi-step", String(plusDelta));
                buttons[0].setAttribute("aria-label", minusLabel);
                buttons[1].setAttribute("aria-label", plusLabel);
                buttons[1].disabled = disablePlus;
                return;
            }
        }
        if (qty < 1 && !inCart) {
            var addCopy=node.querySelector('.molavi-add span');
            if(addCopy){addCopy.textContent='افزودن '+digits(min)+' عدد';return;}
        }
        if (invalid) {
            var currentAdjustment = node.querySelector(".molavi-card-adjust");
            var expectedAdjustment = String(Math.max(min, Math.ceil(qty / min) * min));
            if (Number(expectedAdjustment) > 999) expectedAdjustment = String(Math.max(min, Math.floor(999 / min) * min));
            if (currentAdjustment && currentAdjustment.getAttribute("data-molavi-set-qty") === expectedAdjustment) return;
        }

        var active = node.contains(document.activeElement) ? document.activeElement : null;
        var activeAction = active && active.hasAttribute("data-molavi-clear") ? "clear"
            : active && active.hasAttribute("data-molavi-set-qty") ? "adjust"
            : active && Number(active.getAttribute("data-molavi-step")) < 0 ? "minus" : "plus";
        node.classList.toggle("is-in", qty > 0);
        node.innerHTML = qty > 0 ? (invalid ? cardAdjustment(qty, row) : stepper(qty, row)) : addButton(row);

        if (active) {
            var replacement = qty < 1 ? node.querySelector(".molavi-add")
                : activeAction === "clear" ? node.querySelector("[data-molavi-clear]")
                : activeAction === "adjust" ? Array.prototype.slice.call(node.querySelectorAll(".molavi-step button[data-molavi-step]"))
                    .find(function (button) { return Number(button.getAttribute("data-molavi-step")) > 0; })
                : activeAction === "minus" ? node.querySelector('.molavi-step button[data-molavi-step^="-"]')
                : Array.prototype.slice.call(node.querySelectorAll(".molavi-step button[data-molavi-step]"))
                    .find(function (button) { return Number(button.getAttribute("data-molavi-step")) > 0; });
            if (replacement) replacement.focus({ preventScroll: true });
        }
    }

    function paintBasket(units, counts, quote) {
        var lines = document.querySelector("[data-molavi-basket-lines]");
        if (!lines) return;
        var oldScrollTop = lines.scrollTop;
        var active = lines.contains(document.activeElement) ? document.activeElement : null;
        var activeLine = active && active.closest(".molavi-bline");
        var focusProduct = activeLine ? activeLine.getAttribute("data-product") : "";
        var focusAction = active && active.hasAttribute("data-molavi-clear") ? "clear"
            : active ? active.getAttribute("data-molavi-step") : null;
        var saved = 0;
        var total = 0;
        var referenceTotal = 0;
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
            }
            kinds += 1;
            var invalidLine = Array.isArray(quote && quote.invalid_lines)
                ? quote.invalid_lines.find(function (line) { return String(line.product_id) === String(id); }) : null;
            var stepMarkup = '<small class="molavi-order-step">گام سفارش: <strong>' + digits(min) + ' عدد</strong></small>';
            if (invalidLine) {
                stepMarkup += '<small class="molavi-buyer-line-warning">تعداد فعلی با این گام هماهنگ نیست.</small>'
                    + '<button type="button" class="molavi-line-update" data-molavi-set-qty="'
                    + Number(invalidLine.needed_quantity) + '">تغییر به ' + digits(invalidLine.needed_quantity) + ' عدد</button>';
            }
            html += '<article class="molavi-bline' + (invalidLine ? ' is-invalid' : '') + '" data-product="' + escapeHtml(id) + '" data-min="' + min + '" data-qty="' + qty + '" data-invalid="' + (invalidLine ? '1' : '0') + '">'
                + '<div class="molavi-bline-image"><img src="' + escapeHtml(row.image || "") + '" alt="' + escapeHtml(row.name || "") + '" loading="lazy"></div>'
                + '<div class="molavi-bline-copy"><h3>' + escapeHtml(row.name || "") + "</h3><p><strong>"
                + toman(unit) + "</strong> × " + digits(qty)
                + (shelf > unit ? ' <span class="molavi-rate">' + percent(1 - unit / shelf) + '</span>' : '')
                + "</p>" + stepMarkup
                + "</div>"
                + stepper(qty, row) + "</article>";
        });
        lines.innerHTML = html || '<p class="molavi-basket-empty" data-molavi-empty-basket>سبد خالی است.</p>';
        lines.scrollTop = oldScrollTop;
        if (focusProduct && focusAction !== null) {
            var newLine = Array.prototype.slice.call(lines.querySelectorAll(".molavi-bline"))
                .find(function (line) { return line.getAttribute("data-product") === focusProduct; });
            var focusTarget = newLine && (focusAction === "clear" ? newLine.querySelector("[data-molavi-clear]")
                : newLine.querySelector('button[data-molavi-step="' + cssEscape(focusAction) + '"]'));
            if (focusTarget) focusTarget.focus({ preventScroll: true });
        }
        var emptyLine = document.querySelector("[data-molavi-empty-basket]");
        if (emptyLine) emptyLine.hidden = kinds > 0;
        var save = document.querySelector("[data-molavi-basket-save]");
        if (save) {
            save.hidden = saved < 1;
            var amount = save.querySelector("[data-molavi-basket-save-amount]");
            if (amount) amount.textContent = toman(saved);
            var rate = save.querySelector("[data-molavi-basket-save-rate]");
            if (rate) rate.textContent = percent(referenceTotal > 0 ? saved / referenceTotal : 0);
        }
        var totalNode = document.querySelector("[data-molavi-basket-total]");
        if (totalNode) totalNode.textContent = toman(total);
        var countNode = document.querySelector("[data-molavi-basket-count]");
        if (countNode) countNode.textContent = kinds > 0 ? "(" + digits(kinds) + " محصول)" : "";
        var checkout = document.querySelector("[data-molavi-checkout]");
        var mobileBasketTrigger = document.querySelector(".molavi-mobile-basket[data-molavi-open-basket]");
        if (mobileBasketTrigger) {
            mobileBasketTrigger.hidden = false;
            mobileBasketTrigger.setAttribute("aria-label", "سبد خرید و علاقه‌مندی‌ها؛ " + digits(kinds) + " کالا؛ " + percent(referenceTotal > 0 ? saved / referenceTotal : 0) + " تخفیف فعلی؛ " + toman(total));
        }
        var invalidCount = Array.isArray(quote && quote.invalid_lines) ? quote.invalid_lines.length : 0;
        var checkoutHelp = document.querySelector("[data-molavi-checkout-help]");
        if (checkout) {
            checkout.hidden = kinds < 1;
            checkout.classList.toggle("is-invalid", invalidCount > 0);
            checkout.setAttribute("aria-disabled", invalidCount > 0 ? "true" : "false");
            checkout.href = invalidCount > 0 ? "#molavi-basket-lines" : (molaviShop.checkout || checkout.href);
            var checkoutLabel = checkout.querySelector("[data-molavi-checkout-label]") || checkout.querySelector("span") || checkout;
            checkoutLabel.textContent = invalidCount > 0 ? "اصلاح تعداد برای ادامه" : "ادامه سفارش";
        }
        if (checkoutHelp) {
            checkoutHelp.hidden = invalidCount < 1;
            checkoutHelp.textContent = invalidCount > 0
                ? "برای ادامه، تعداد کالاهای مشخص‌شده در سبد را اصلاح کنید."
                : "";
        }
        setText("[data-molavi-reference]", toman(referenceTotal));
        setText("[data-molavi-savings]", toman(saved));
        setText("[data-molavi-subtotal]", toman(total));
        setText("[data-molavi-savings-rate]", percent(referenceTotal > 0 ? saved / referenceTotal : 0));
        setText("[data-molavi-mobile-total]", toman(total));
        setText("[data-molavi-mobile-saving]", toman(saved));
        setText("[data-molavi-mobile-rate]", percent(referenceTotal > 0 ? saved / referenceTotal : 0));
        var cartCount=Object.keys(counts).reduce(function(sum,id){return sum+(Number(counts[id])||0);},0);
        var floatingCount=document.querySelector('[data-molavi-mobile-count]');if(floatingCount){floatingCount.hidden=cartCount<1;floatingCount.textContent=digits(cartCount>99?'99+':cartCount);}
        var progress = Math.max(0, Math.min(1, Number(quote && quote.progress) || 0));
        var progressPct = Math.round(progress * 100);
        var progressBar = document.querySelector("[data-molavi-progressbar]");
        var progressFill = document.querySelector("[data-molavi-progress-fill]");
        if (progressBar) progressBar.setAttribute("aria-valuenow", String(progressPct));
        if (progressFill) progressFill.style.width = progressPct + "%";
        var progressTitle = document.querySelector("[data-molavi-progress-title]");
        var remainingNode = document.querySelector("[data-molavi-remaining]");
        var packCount = Object.keys(quote.full_pack_sizes || {}).length;
        var packNotice = document.querySelector("[data-molavi-full-pack-progress]");
        if (packNotice) {
            packNotice.hidden = packCount === 0;
            packNotice.textContent = packCount
                ? digits(packCount) + " کالا با تخفیف بستهٔ کامل، " + toman(Number(quote.full_pack_savings) || 0)
                    + " از صرفه‌جویی کل سبد را تأمین می‌کنند. ارزش این کالاها جدا از نوار پیشرفت محاسبه می‌شود."
                : "";
        }
        if (progressTitle) progressTitle.textContent = progress >= 1 ? "سقف تخفیف تکمیل شده"
            : packCount ? "پیشرفت تخفیف سایر کالاها" : "پیشرفت تخفیف";
        if (remainingNode) remainingNode.textContent = progress >= 1
            ? "سقف تخفیف " + (buyerType === "shop" ? "مغازه‌دار" : "خانوار") + " فعال است."
            : "تا سقف " + (buyerType === "shop" ? "مغازه‌دار" : "خانوار") + ": " + toman(Number(quote && quote.remaining) || 0);
    }

    function normalizeSearch(value) {
        return String(value||'').normalize('NFKC').toLocaleLowerCase('fa').replace(/[يى]/g,'ی').replace(/ك/g,'ک')
            .replace(/[۰-۹]/g,function(c){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(c);}).replace(/[٠-٩]/g,function(c){return '٠١٢٣٤٥٦٧٨٩'.indexOf(c);})
            .replace(/[\s\u200c]+/g,' ').trim();
    }
    function updateCheckoutBusy() {
        var pending=sending||buyerChanging||listRestoring||dirty||nativeCartUpdating;
        document.querySelectorAll('[data-molavi-checkout]').forEach(function(link){
            var invalid=Array.isArray(lastQuote.invalid_lines)&&lastQuote.invalid_lines.length>0;
            link.setAttribute('aria-disabled',pending||invalid?'true':'false');
            link.classList.toggle('is-pending',pending);
        });
    }
    function paintCustomerDetails(quote) {
        document.querySelectorAll('[data-molavi-product-detail]').forEach(function(panel){
            var id=panel.getAttribute('data-product'),row=rowOf(id);
            if(!row)return;
            var input=document.querySelector('form.cart input.qty');
            var step=Math.max(1,Number(row.min)||1);
            if(input){input.min=step;input.step=step;}
            var count=input?Math.max(step,Number(input.value)||step):step;
            var label=panel.querySelector('[data-molavi-detail-quantity]');
            if(label)label.textContent='حداقل خرید و گام تغییر تعداد: '+digits(step)+' عدد';
            var summary=panel.closest('.summary'),unit=Number(quote.units&&quote.units[id]||0);
            if(summary){
                var current=summary.querySelector('.molavi-public-current');if(current)current.textContent=toman(unit);
                var reference=summary.querySelector('.molavi-public-reference');if(reference)reference.hidden=!(row.shelf>unit);
                var rate=summary.querySelector('.molavi-rate-label');if(rate){rate.hidden=!(row.shelf>unit);rate.textContent='تخفیف فعلی '+percent(row.shelf>unit?(row.shelf-unit)/row.shelf:0);}
            }
            var total=panel.querySelector('[data-molavi-detail-total]');
            if(total)total.textContent=toman(Number(quote.units&&quote.units[id]||0)*count);
            var totalLabel=panel.querySelector('[data-molavi-detail-total-label]');if(totalLabel)totalLabel.textContent='مبلغ '+digits(count)+' عدد با قیمت فعلی: ';
        });
        document.querySelectorAll('[data-molavi-favorite-price]').forEach(function(node){var id=node.getAttribute('data-molavi-favorite-price');if(quote.units&&quote.units[id]!=null)node.textContent=toman(quote.units[id]);});
        var guide=document.querySelector('[data-molavi-guide]');
        if(guide)guide.textContent=(quote.invalid_lines||[]).length
            ? 'برای ادامهٔ سفارش، تعداد کالاهای مشخص‌شده را اصلاح کنید.'
            : Number(quote.count)>0
                ? (Number(quote.progress)>=1?'سقف تخفیف سبد فعال است؛ می‌توانید سفارش را ادامه دهید.':'قیمت‌های نمایش‌داده‌شده شامل تخفیف فعلی سبد شماست.')
                : 'نوع خرید را انتخاب کنید، بعد کالاها را به سبد اضافه کنید.';
        updateCheckoutBusy();
    }
    function setupShoppingHelp() {
        document.addEventListener('click',function(event){
            var opener=event.target.closest('[data-molavi-open-help]'),dialog=document.getElementById('molavi-shopping-help');
            if(!opener||!dialog)return;event.preventDefault();dialog.showModal();
        });
        document.addEventListener("click", function (event) {
            var opener = event.target.closest("[data-molavi-full-pack-info]");
            var dialog = document.getElementById("molavi-full-pack-help");
            if (!opener || !dialog || dialog.open) return;
            var id = opener.closest("[data-molavi-card]").getAttribute("data-product");
            var packSize = Number((lastQuote.full_pack_sizes || {})[id]);
            var quantity = Number(serverQtys[id]);
            if (!packSize || quantity !== Number(qtys[id])) return;
            var name = opener.closest("[data-molavi-card]").getAttribute("data-name");
            var profile = buyerType === "shop" ? "فروشگاه" : "خانوار";
            dialog.querySelector("[data-molavi-full-pack-copy]").textContent = "تعداد «" + name + "» در سبد شما "
                + digits(quantity) + " عدد است؛ یعنی " + digits(quantity / packSize) + " بستهٔ کاملِ "
                + digits(packSize) + " عددی. به همین دلیل حداکثر تخفیف این کالا برای خرید " + profile + " فعال شده است.";
            dialog.showModal();
        });
        document.addEventListener('input',function(event){if(event.target.matches('form.cart input.qty'))paintCustomerDetails(lastQuote);});
    }
    function uiIcon(name) {
        var paths={cart:'<path d="M3 4h2l3 12h10l3-9H6M10 20h.01M18 20h.01"/>',heart:'<path d="M20 9c0 5-8 10-8 10S4 14 4 9a4 4 0 0 1 8-2 4 4 0 0 1 8 2Z"/>',edit:'<path d="m16 3 5 5-12 12-6 1 1-6Z"/><path d="m14 5 5 5"/>',check:'<path d="m5 12 4 4L19 6"/>',close:'<path d="m6 6 12 12M18 6 6 18"/>',trash:'<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>'};
        return '<svg viewBox="0 0 24 24" aria-hidden="true">'+(paths[name]||paths.edit)+'</svg>';
    }
    function readFavoriteIds() {
        if (customerId && accountFavorites !== null) return accountFavorites.slice();
        try {
            var value=JSON.parse(localStorage.getItem(favoriteKey)||'[]');
            return Array.isArray(value)?Array.from(new Set(value.map(String).filter(function(id){return /^[1-9][0-9]*$/.test(id);}))) : [];
        } catch(error) { return []; }
    }
    function setupFavoritesLibrary() {
        var host=document.querySelector('#molavi-favorites-dialog');if(!host||host._molaviCollectionsReady)return;
        host._molaviCollectionsReady=true;
        var feedback=host.querySelector('[data-molavi-lists-status]'),products=host.querySelector('[data-molavi-favorite-products]'),saved=host.querySelector('[data-molavi-saved-carts]');
        var key=customerId?'molavi-shopping-lists-user-'+customerId:'molavi-shopping-lists-v1',lists=[],pendingRestore=null,loading=false,available=null,loadVersion=0,controller=null,lastOpener=null,reloadTimer=null;
        var accountBusy=false,accountReady=!customerId,pendingAccount=null,pendingItem=null,deletedCart=null,accountFailed=false,lastAccountRead=0,accountRevision=-1;
        var syncStatus=host.querySelector('[data-molavi-account-status]'),undo=host.querySelector('[data-molavi-undo-delete]');
        var importButton=host.querySelector('[data-molavi-import-library]');
        var toast=host.querySelector('[data-molavi-toast]'),toastTimer=null;
        new MutationObserver(function(){
            window.clearTimeout(toastTimer);toast.hidden=!feedback.textContent.trim();
            if(!toast.hidden)toastTimer=window.setTimeout(function(){toast.hidden=true;},8000);
        }).observe(feedback,{childList:true,characterData:true,subtree:true});
        ['pointerenter','focusin'].forEach(function(name){toast.addEventListener(name,function(){window.clearTimeout(toastTimer);});});
        ['pointerleave','focusout'].forEach(function(name){toast.addEventListener(name,function(){if(!toast.matches(':focus-within'))toastTimer=window.setTimeout(function(){toast.hidden=true;},8000);});});
        var prompt=document.querySelector('[data-molavi-library-prompt]');
        var legacySave=host.querySelector('[data-molavi-save-list]');if(legacySave)legacySave.remove();
        function ask(options){
            if(prompt.open)return Promise.resolve(null);
            return new Promise(function(resolve){
                var result=null,opener=document.activeElement,form=prompt.querySelector('form');
                prompt.querySelector('h2').textContent=options.title;
                prompt.querySelector('[data-molavi-prompt-message]').textContent=options.message;
                var name=form.elements.list_name,target=form.elements.target;
                prompt.querySelector('[data-molavi-prompt-name-wrap]').hidden=options.name==null;name.required=options.name!=null;name.value=options.name||'';
                prompt.querySelector('[data-molavi-prompt-target-wrap]').hidden=!options.choices;target.required=!!options.choices;target.replaceChildren();
                (options.choices||[]).forEach(function(item){target.add(new Option(item.name,item.id));});
                var confirm=prompt.querySelector('[data-molavi-prompt-confirm]');confirm.textContent=options.confirm||'تأیید';confirm.classList.toggle('is-danger',!!options.danger);
                form.onsubmit=function(event){event.preventDefault();if(!form.reportValidity())return;if(name.required&&!name.value.trim()){name.setCustomValidity('نام سبد را وارد کنید.');name.reportValidity();return;}result={name:name.value.trim(),target:target.value};prompt.close();};
                name.oninput=function(){name.setCustomValidity('');};name.setCustomValidity('');
                prompt.querySelector('[data-molavi-prompt-cancel]').onclick=function(){prompt.close();};
                prompt.onclose=function(){display();if(opener&&opener.isConnected)opener.focus({preventScroll:true});resolve(result);};
                prompt.onclick=function(event){if(event.target===prompt){var rect=prompt.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)prompt.close();}};
                prompt.showModal();display();
                (options.danger?prompt.querySelector('[data-molavi-prompt-cancel]'):name.required?name:options.choices?target:confirm).focus();
                if(name.required)name.select();
            });
        }
        var tabs=Array.from(host.querySelectorAll('[data-molavi-library-tab]'));
        function readLists(){
            try{var value=JSON.parse(localStorage.getItem(key)||'[]');lists=Array.isArray(value)?value:[];}catch(error){lists=[];}
            lists=lists.filter(function(item){return item&&typeof item.id==='string'&&typeof item.name==='string'&&item.items&&typeof item.items==='object'&&!Array.isArray(item.items);});
        }
        readLists();
        function busy(){return prompt.open||accountBusy||!accountReady||sending||dirty||buyerChanging||listRestoring||nativeCartUpdating;}
        function currentItems(){
            if(!document.body.classList.contains('woocommerce-cart')||document.querySelector('[data-molavi-storefront]'))return Object.assign({},serverQtys);
            var current={};document.querySelectorAll('.woocommerce-cart-form__cart-item').forEach(function(line){
                var product=line.querySelector('[data-product_id]'),input=line.querySelector('input.qty');
                if(product&&input&&Number(input.value)>0)current[product.getAttribute('data-product_id')]=Number(input.value);
            });return current;
        }
        function setTab(name,focus){
            tabs.forEach(function(tab){var active=tab.getAttribute('data-molavi-library-tab')===name;tab.setAttribute('aria-selected',active?'true':'false');tab.tabIndex=active?0:-1;if(active&&focus)tab.focus();});
            host.querySelectorAll('[data-molavi-library-panel]').forEach(function(panel){panel.hidden=panel.getAttribute('data-molavi-library-panel')!==name;});
        }
        function display(){
            var favorites=readFavoriteIds();host.querySelector('[data-molavi-favorites-count]').textContent=digits(favorites.length);host.querySelector('[data-molavi-saved-count]').textContent=digits(lists.length);
            host.querySelector('[data-molavi-new-cart]').disabled=busy();
            host.querySelector('[data-molavi-save-favorites]').disabled=busy()||!favorites.length;
            host.querySelector('[data-molavi-move-existing]').disabled=busy()||!favorites.length;
            host.querySelectorAll('[data-molavi-load-list],[data-molavi-replace-list],[data-molavi-delete-list],[data-molavi-rename-list],[data-molavi-list-favorites],[data-molavi-edit-items]').forEach(function(button){button.disabled=busy();});
            document.querySelectorAll('[data-molavi-favorite]').forEach(function(button){button.disabled=accountBusy||!accountReady;});
            undo.hidden=!deletedCart;undo.disabled=busy();
            host.querySelector('[data-molavi-sync-retry]').hidden=!customerId||!accountFailed;
            importButton.hidden=!customerId||!guestSelection();importButton.disabled=busy();
            host.setAttribute('aria-busy',loading||listRestoring||accountBusy?'true':'false');
        }
        function randomRequestId(){var bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);return Array.from(bytes,function(b){return b.toString(16).padStart(2,'0');}).join('');}
        function applyAccount(data){
            accountRevision=Number(data.revision)||0;lists=data.carts||[];
            try{localStorage.setItem(key,JSON.stringify(lists));localStorage.setItem(favoriteKey,JSON.stringify(data.favorites||[]));}
            catch(error){/* Account data is already safely stored on the server. */}
            accountFavorites=data.favorites||[];
            renderSaved();display();document.dispatchEvent(new CustomEvent('molavi:favorites'));
        }
        async function accountRequest(ops){
            if(accountBusy)return false;
            accountBusy=true;display();var announce=!!ops||!accountReady||accountFailed;if(announce)syncStatus.textContent='در حال همگام‌سازی حساب…';
            var requestController=new AbortController(),requestTimer=window.setTimeout(function(){requestController.abort();},20000);
            try{
                var url=new URL(molaviShop.collections,location.href),options={credentials:'same-origin',cache:'no-store',signal:requestController.signal};
                if(ops){
                    var fingerprint=JSON.stringify(ops);
                    if(!pendingAccount||pendingAccount.fingerprint!==fingerprint)pendingAccount={fingerprint:fingerprint,id:randomRequestId()};
                    var body=new FormData();body.append('nonce',molaviShop.nonce);body.append('operations',fingerprint);body.append('request_id',pendingAccount.id);
                    options.method='POST';options.body=body;
                }else{url.searchParams.set('nonce',molaviShop.nonce);}
                var response=await fetch(url,options),result=await response.json();
                if(!response.ok||!result.success){
                    if(result.data&&result.data.library)applyAccount(result.data.library);
                    var failure=new Error(result.data&&result.data.message||'همگام‌سازی انجام نشد. دوباره تلاش کنید.');failure.hasLibrary=!!(result.data&&result.data.library);throw failure;
                }
                pendingAccount=null;accountReady=true;accountFailed=false;lastAccountRead=Date.now();if(ops||accountRevision!==Number(result.data.revision))applyAccount(result.data);
                if(ops)document.dispatchEvent(new CustomEvent('molavi:library'));
                if(announce)syncStatus.textContent='در حساب شما ذخیره شد؛ در دستگاه‌های دیگر هم در دسترس است.';
                return true;
            }catch(error){accountFailed=!error.hasLibrary;feedback.textContent=/[\u0600-\u06ff]/.test(error.message||'')?error.message:'همگام‌سازی انجام نشد. دوباره تلاش کنید.';syncStatus.textContent=error.hasLibrary?'فهرست حساب تازه شد؛ مقدار تازه را بررسی کنید.':'اتصال به حساب کامل نشد؛ دوباره تلاش کنید.';return false;}
            finally{window.clearTimeout(requestTimer);accountBusy=false;display();if(host.open&&accountReady)loadFacts();}
        }
        async function persist(next){
            if(customerId){
                var ops=[];
                lists.forEach(function(item){if(!next.some(function(row){return row.id===item.id;}))ops.push({type:'cart_delete',id:item.id});});
                next.forEach(function(item){
                    var previous=lists.find(function(row){return row.id===item.id;});
                    if(!previous){ops.push({type:'cart_save',cart:item});return;}
                    if(previous.name!==item.name)ops.push({type:'cart_rename',id:item.id,name:item.name});
                    var changes={},expected={};Array.from(new Set(Object.keys(previous.items).concat(Object.keys(item.items)))).forEach(function(id){var before=Number(previous.items[id]||0),after=Number(item.items[id]||0);if(before!==after){changes[id]=after;expected[id]=before;}});
                    if(Object.keys(changes).length)ops.push({type:'cart_edit',id:item.id,changes:changes,expected:expected});
                });
                return !ops.length||await accountRequest(ops);
            }
            try{localStorage.setItem(key,JSON.stringify(next));lists=next;renderSaved();display();return true;}
            catch(error){feedback.textContent='مرورگر اجازهٔ ذخیره نداد؛ تنظیمات ذخیره‌سازی را بررسی کنید.';return false;}
        }
        function makeItem(items){
            var ids=Object.keys(items);if(!ids.length)return false;
            if(lists.length>=10){feedback.textContent='۱۰ سبد ذخیره دارید؛ پیش از ذخیرهٔ سبد جدید، یکی را حذف کنید. هیچ سبدی خودکار حذف نمی‌شود.';return false;}
            if(ids.length>40){feedback.textContent='هر سبد ذخیره‌شده می‌تواند حداکثر ۴۰ کالا داشته باشد.';return false;}
            if(pendingItem&&JSON.stringify(pendingItem.items)===JSON.stringify(items))return pendingItem;
            var number=1;while(lists.some(function(item){return item.name==='سبد '+digits(number);}))number++;
            pendingItem={id:Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,8),name:'سبد '+digits(number),items:Object.assign({},items),favorite_ids:[]};
            return pendingItem;
        }
        async function saveItems(items){
            var item=makeItem(items);if(!item||!await persist([item].concat(lists)))return false;
            pendingItem=null;return item;
        }
        async function writeFavorites(ids){
            if(customerId){
                var previous=readFavoriteIds(),added=ids.filter(function(id){return previous.indexOf(id)<0;}),removed=previous.filter(function(id){return ids.indexOf(id)<0;}),ops=[];
                if(added.length)ops.push({type:'favorites_add',ids:added});if(removed.length)ops.push({type:'favorites_remove',ids:removed});
                return !ops.length||await accountRequest(ops);
            }
            try{localStorage.setItem(favoriteKey,JSON.stringify(ids));}
            catch(error){feedback.textContent='ذخیرهٔ علاقه‌مندی‌ها انجام نشد؛ انتخاب‌هایتان حفظ شدند.';return false;}
            document.dispatchEvent(new CustomEvent('molavi:favorites'));return true;
        }
        function guestSelection(){
            if(!customerId)return null;
            try{
                var data={favorites:JSON.parse(localStorage.getItem('molavi-wishlist-v1')||'[]'),carts:JSON.parse(localStorage.getItem('molavi-shopping-lists-v1')||'[]')};
                if(!Array.isArray(data.favorites))data.favorites=[];if(!Array.isArray(data.carts))data.carts=[];
                if((!data.favorites.length&&!data.carts.length)||localStorage.getItem('molavi-library-import-'+customerId)===JSON.stringify(data))return null;
                return data;
            }catch(error){return null;}
        }
        importButton.addEventListener('click',async function(){
            if(busy())return;var data=guestSelection();if(!data)return;
            var ops=data.carts.map(function(cart){return {type:'cart_save',cart:cart};});
            if(data.favorites.length)ops.push({type:'favorites_add',ids:data.favorites});
            if(await accountRequest(ops)){try{localStorage.setItem('molavi-library-import-'+customerId,JSON.stringify(data));}catch(error){}display();feedback.textContent='انتخاب‌های این مرورگر به حساب اضافه شدند.';}
        });
        host.querySelector('[data-molavi-sync-retry]').addEventListener('click',function(){accountRequest();});
        undo.addEventListener('click',async function(){
            if(busy()||!deletedCart)return;
            if(lists.length>=10){feedback.textContent='برای بازگرداندن سبد، ابتدا یک جای خالی ایجاد کنید.';return;}
            var item=deletedCart;
            if(await persist([item].concat(lists.filter(function(row){return row.id!==item.id;})))){deletedCart=null;display();feedback.textContent='«'+item.name+'» بازگردانده شد.';}
        });
        function productPhoto(id){var row=catalog[id];return row&&row.image?'<img class="molavi-list-thumb" src="'+escapeHtml(row.image)+'" alt="" loading="lazy">':'<span class="molavi-list-thumb is-placeholder" aria-hidden="true">'+uiIcon('cart')+'</span>';}
        function preview(item){
            return '<ul class="molavi-saved-items">'+Object.keys(item.items).map(function(id){var row=catalog[id];return '<li>'+productPhoto(id)+'<span>'+escapeHtml(row&&row.name||'کالایی که فعلاً در دسترس نیست')+'</span><b>'+digits(Number(item.items[id])||0)+' عدد</b></li>';}).join('')+'</ul>';
        }
        function renderSaved(){
            var opened=Array.from(saved.querySelectorAll('details[open]')).map(function(node){return node.getAttribute('data-molavi-saved-cart');});
            saved.innerHTML=lists.length?lists.map(function(item){
                var id=escapeHtml(item.id),count=Object.keys(item.items).length,origin=Array.from(new Set(item.favorite_ids||[])).length;
                return '<details class="molavi-saved-cart" data-molavi-saved-cart="'+id+'"'+(opened.indexOf(item.id)>=0?' open':'')+'><summary><span><span class="molavi-saved-title"><strong data-molavi-list-name>'+escapeHtml(item.name.slice(0,60))+'</strong><button type="button" class="molavi-icon-button molavi-rename-button" data-molavi-rename-list="'+id+'" aria-label="ویرایش نام '+escapeHtml(item.name.slice(0,60))+'" title="ویرایش نام">'+uiIcon('edit')+'</button><button type="button" class="molavi-icon-button molavi-text-danger" data-molavi-delete-list="'+id+'" aria-label="حذف سبد" title="حذف سبد">'+uiIcon('trash')+'</button></span><small data-molavi-list-count>'+digits(count)+' کالا'+(origin?' · '+digits(origin)+' مورد از علاقه‌مندی‌ها':'')+'</small></span><span class="molavi-saved-chevron" aria-hidden="true">⌄</span></summary>'
                    +'<div class="molavi-saved-cart-body"><div data-molavi-saved-preview>'+preview(item)+'</div><div class="molavi-saved-actions">'
                    +'<button type="button" data-molavi-load-list="'+id+'" title="افزودن اقلام این فهرست به سبد خرید فعلی">'+uiIcon('cart')+'<span>به سبد خرید</span></button>'
                    +'<button type="button" data-molavi-list-favorites="'+id+'" title="افزودن کالاهای این فهرست به علاقه‌مندی‌ها">'+uiIcon('heart')+'<span>علاقه‌مندی‌ها</span></button>'
                    +'<button type="button" data-molavi-edit-items="'+id+'" title="ویرایش تعداد کالاهای این فهرست">'+uiIcon('edit')+'<span>تعدادها</span></button>'
                    +'</div></div></details>';
            }).join(''):'<div class="molavi-library-empty"><strong>سبدهای خریدتان را اینجا نگه دارید</strong><p>با «+ سبد جدید» سبد خرید فعلی را به یک فهرست تبدیل کنید، یا علاقه‌مندی‌ها را به سبد جدید منتقل کنید.</p></div>';
            saved.querySelectorAll('details[open]').forEach(function(node){node._molaviRestoredOpen=true;});
        }
        function revealList(id){setTab('carts',true);var details=Array.from(saved.querySelectorAll('[data-molavi-saved-cart]')).find(function(node){return node.getAttribute('data-molavi-saved-cart')===id;});if(details)details.open=true;}
        async function createFromCurrent(){
            if(busy())return;var items=currentItems(),ids=Object.keys(items);
            if(!ids.length){var next=await ask({title:'سبد خرید فعلی خالی است',message:'«سبد جدید» از کالاهای سبد خرید فعلی یک فهرست ذخیره‌شده می‌سازد. ابتدا کالاهایی را به سبد خرید اضافه کنید، یا در بخش کالاها از «انتقال همه به سبد جدید» استفاده کنید.',confirm:readFavoriteIds().length?'دیدن علاقه‌مندی‌ها':'دیدن فروشگاه'});if(next){if(readFavoriteIds().length)setTab('products',true);else location.href=molaviShop.shopUrl||'/';}return;}
            var item=makeItem(items);if(!item)return;
            var result=await ask({title:'ذخیره در سبد جدید',message:'از '+digits(ids.length)+' کالا در سبد خرید فعلی، یک فهرست با نام دلخواه می‌سازیم. سبد خرید و تعدادهای آن تغییر نمی‌کنند؛ بعداً می‌توانید تعدادهای این فهرست را ویرایش یا دوباره خرید کنید.',name:item.name,confirm:'ذخیرهٔ سبد'});
            if(!result)return;item.name=result.name;
            if(await persist([item].concat(lists))){pendingItem=null;revealList(item.id);feedback.textContent='«'+item.name+'» ذخیره شد؛ سبد خرید فعلی تغییر نکرد.';}
        }
        function editItems(item,details){
            if(details.querySelector('[data-molavi-items-editor]'))return;
            var form=document.createElement('form');form.className='molavi-items-editor';form.setAttribute('data-molavi-items-editor','');
            form.innerHTML='<p>فقط تعدادهای این فهرست تغییر می‌کنند؛ گام خرید هنگام افزودن به سبد بررسی می‌شود.</p>'+Object.keys(item.items).map(function(id){var row=catalog[id];return '<label class="molavi-list-edit-row">'+productPhoto(id)+'<span>'+escapeHtml(row&&row.name||'کالا')+'</span><input type="number" inputmode="numeric" min="1" max="999" step="1" required data-list-product="'+escapeHtml(id)+'" aria-label="تعداد '+escapeHtml(row&&row.name||'کالا')+'" value="'+Number(item.items[id])+'"></label>';}).join('')+'<div class="molavi-item-editor-actions"><button type="submit" class="molavi-icon-button" aria-label="ذخیرهٔ تعدادها" title="ذخیرهٔ تعدادها">'+uiIcon('check')+'</button><button type="button" class="molavi-icon-button" data-cancel-items aria-label="انصراف از ویرایش تعدادها" title="انصراف">'+uiIcon('close')+'</button></div>';
            var previewNode=details.querySelector('[data-molavi-saved-preview]'),actions=details.querySelector('.molavi-saved-actions');previewNode.hidden=true;actions.hidden=true;previewNode.after(form);
            function finish(){form.remove();previewNode.hidden=false;actions.hidden=false;details.querySelector('[data-molavi-edit-items]').focus();}
            form.querySelector('[data-cancel-items]').onclick=finish;
            form.addEventListener('keydown',function(event){if(event.key==='Escape'){event.preventDefault();event.stopPropagation();finish();}});
            form.onsubmit=async function(event){event.preventDefault();if(busy()||!form.reportValidity())return;var items={};form.querySelectorAll('[data-list-product]').forEach(function(input){items[input.getAttribute('data-list-product')]=Number(input.value);});var edited=Object.assign({},item,{items:items});if(await persist(lists.map(function(row){return row.id===item.id?edited:row;}))){feedback.textContent='تعدادهای «'+item.name+'» ذخیره شدند.';revealList(item.id);}};
            form.querySelector('input').focus();
        }
        async function moveFavorites(existing){
            if(busy())return;var ids=readFavoriteIds(),items={};ids.forEach(function(id){var row=rowOf(id);items[id]=row?row.min:1;});if(!ids.length)return;
            var item;
            if(existing){
                if(!lists.length){var create=await ask({title:'هنوز سبد ذخیره‌شده ندارید',message:'می‌توانید همهٔ علاقه‌مندی‌ها را به یک سبد جدید منتقل کنید؛ سبد خرید فعلی تغییر نمی‌کند.',confirm:'ساخت سبد جدید'});if(create)return moveFavorites(false);return;}
                var target=await ask({title:'انتقال به سبد موجود',message:digits(ids.length)+' کالا به سبد انتخاب‌شده افزوده و پس از ذخیره از علاقه‌مندی‌ها منتقل می‌شوند. تعداد کالاهای تکراری در فهرست مقصد حفظ می‌شود؛ سبد خرید فعلی تغییر نمی‌کند.',choices:lists,confirm:'انتقال کالاها'});if(!target)return;
                var previous=lists.find(function(row){return row.id===target.target;});if(!previous){feedback.textContent='سبد مقصد پیدا نشد؛ دوباره انتخاب کنید.';return;}
                var merged=Object.assign({},previous.items);ids.forEach(function(id){if(!merged[id])merged[id]=items[id];});
                if(Object.keys(merged).length>40){feedback.textContent='سبد مقصد با این انتقال بیشتر از ۴۰ کالا خواهد داشت؛ علاقه‌مندی‌ها حفظ شدند.';return;}
                item=Object.assign({},previous,{items:merged,favorite_ids:Array.from(new Set((previous.favorite_ids||[]).concat(ids)))});
                if(customerId){if(!await accountRequest([{type:'cart_merge_favorites',id:item.id,items:items},{type:'favorites_remove',ids:ids}]))return;}
                else if(!await persist(lists.map(function(row){return row.id===item.id?item:row;})))return;
            }else{
                item=makeItem(items);if(!item)return;item.favorite_ids=ids.slice();
                if(customerId){if(!await accountRequest([{type:'cart_save',cart:item},{type:'favorites_remove',ids:ids}]))return;}
                else if(!await persist([item].concat(lists)))return;
            }
            if(!customerId&&!await writeFavorites(readFavoriteIds().filter(function(id){return ids.indexOf(id)===-1;}))){pendingItem=null;revealList(item.id);feedback.textContent='سبد ذخیره شد؛ علاقه‌مندی‌ها به‌دلیل خطای مرورگر حفظ شدند.';return;}
            pendingItem=null;revealList(item.id);feedback.textContent='علاقه‌مندی‌ها به «'+item.name+'» منتقل شدند؛ سبد خرید فعلی تغییر نکرد.';
        }
        function renderProducts(){
            var focused=products.contains(document.activeElement)?document.activeElement:null,focusedRow=focused&&focused.closest('[data-product]');
            var focusedId=focusedRow&&focusedRow.getAttribute('data-product'),focusAction=focused&&focused.hasAttribute('data-molavi-favorite')?'favorite':focused&&focused.hasAttribute('data-molavi-clear')?'clear':focused&&Number(focused.getAttribute('data-molavi-step'))<0?'minus':'plus';
            var ids=readFavoriteIds();
            products.innerHTML=ids.length?ids.map(function(id){var row=catalog[id],active=row&&available&&available.has(id),profile=rowOf(id),name=active?row.name:'این کالا فعلاً قابل خرید نیست';
                return '<article class="molavi-library-product e-loop-item" data-product="'+escapeHtml(id)+'">'+(active?'<a class="molavi-library-photo" href="'+escapeHtml(row.url||'#')+'"><img src="'+escapeHtml(row.image||'')+'" alt="" loading="lazy"></a>':'<span class="molavi-library-photo is-unavailable" aria-hidden="true">♡</span>')+'<div class="molavi-library-product-copy"><h3>'+(active?'<a href="'+escapeHtml(row.url||'#')+'">'+escapeHtml(name)+'</a>':escapeHtml(name))+'</h3>'+(active?'<strong class="molavi-library-price" data-molavi-favorite-price="'+escapeHtml(id)+'">'+toman(lastQuote.units&&lastQuote.units[id])+'</strong><div class="molavi-control" data-molavi-cart data-product="'+escapeHtml(id)+'" data-min="'+profile.min+'"></div>':'<small>می‌توانید آن را از علاقه‌مندی‌ها حذف کنید.</small>')+'</div><button class="molavi-library-remove" type="button" data-molavi-favorite="'+escapeHtml(id)+'" aria-pressed="true" aria-label="حذف '+escapeHtml(name)+' از علاقه‌مندی‌ها"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 8.8c0 4.4-8.8 10-8.8 10s-8.8-5.6-8.8-10a4.6 4.6 0 0 1 8.8-1.9 4.6 4.6 0 0 1 8.8 1.9Z"></path></svg></button></article>';
            }).join(''):'<div class="molavi-library-empty"><span aria-hidden="true">♡</span><strong>انتخاب‌های بعدی شما</strong><p>قلب کنار هر کالا را بزنید تا اینجا پیدایش کنید.</p></div>';
            paint(lastQuote);
            if(focused&&host.open){
                var row=products.querySelector('[data-product="'+cssEscape(focusedId||'')+'"]'),target=row&&(focusAction==='favorite'?row.querySelector('[data-molavi-favorite]'):focusAction==='clear'?row.querySelector('[data-molavi-clear]'):row.querySelector(focusAction==='minus'?'.molavi-step button[data-molavi-step^="-"]':'.molavi-step button[data-molavi-step]:not([data-molavi-step^="-"])'));
                target=target||row&&row.querySelector('.molavi-add')||products.querySelector('[data-molavi-favorite]')||tabs[0];target.focus({preventScroll:true});
            }
        }
        function selectionIds(){
            var ids=readFavoriteIds();saved.querySelectorAll('details[open]').forEach(function(node){var item=lists.find(function(row){return row.id===node.getAttribute('data-molavi-saved-cart');});if(item)ids=ids.concat(Object.keys(item.items));});
            return Array.from(new Set(ids)).slice(0,1000);
        }
        async function loadFacts(){
            if(!host.open||busy())return;
            var ids=selectionIds(),version=++loadVersion,revision=pricingRevision;
            if(controller)controller.abort();controller=new AbortController();
            if(!ids.length){loading=false;available=new Set();renderProducts();display();return;}
            loading=true;display();host.querySelector('[data-molavi-library-retry]').hidden=true;
            if(!available)products.innerHTML='<p class="molavi-library-loading" role="status">در حال دریافت کالاها…</p>';
            var facts={},quote=null,page=1,more=true;
            try{
                while(more){
                    var url=new URL(molaviShop.catalogEndpoint,window.location.href);url.searchParams.set('ids',ids.join(','));url.searchParams.set('format','collection');url.searchParams.set('catalog_page',String(page));
                    var response=await fetch(url,{credentials:'same-origin',signal:controller.signal}),result=await response.json();
                    if(version!==loadVersion)return;
                    if(revision!==pricingRevision||busy()||result.data&&result.data.buyer_type!==buyerType){window.clearTimeout(reloadTimer);reloadTimer=window.setTimeout(loadFacts,250);return;}
                    if(!response.ok||!result.success||!result.data||!result.data.quote)throw new Error('read');
                    Object.assign(facts,result.data.catalog||{});quote=result.data.quote;more=!!result.data.has_more;page++;
                    if(more&&page>42)throw new Error('limit');
                }
                Object.assign(catalog,facts);available=new Set(Object.keys(facts));lastQuote=quote;serverQtys=Object.assign({},quote.qtys||{});qtys=Object.assign({},serverQtys);
                renderProducts();saved.querySelectorAll('[data-molavi-saved-preview]').forEach(function(node){var item=lists.find(function(row){return row.id===node.closest('[data-molavi-saved-cart]').getAttribute('data-molavi-saved-cart');});if(item)node.innerHTML=preview(item);});
            }catch(error){if(error.name!=='AbortError'){feedback.textContent='دریافت کالاها کامل نشد. دوباره امتحان کنید؛ انتخاب‌های شما محفوظ است.';host.querySelector('[data-molavi-library-retry]').hidden=false;}}
            finally{if(version===loadVersion){loading=false;display();}}
        }
        async function restore(item,mode){
            if(!item||busy())return;
            var fingerprint=mode+':'+JSON.stringify(item.items),backup=null;
            if(!pendingRestore||pendingRestore.fingerprint!==fingerprint){var bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);pendingRestore={fingerprint:fingerprint,id:Array.from(bytes,function(b){return b.toString(16).padStart(2,'0');}).join(''),backup:false};}
            if(mode==='replace'&&!pendingRestore.backup&&Object.keys(currentItems()).length){backup=await saveItems(currentItems());if(!backup)return;pendingRestore.backup=true;}
            listRestoring=true;pricingRevision++;display();updateCheckoutBusy();feedback.textContent='در حال بررسی کالاها و قیمت‌های فعلی…';
            var completed=false;
            try{
                var body=new FormData();body.append('nonce',molaviShop.nonce);body.append('items',JSON.stringify(item.items));body.append('mode',mode);body.append('request_id',pendingRestore.id);
                var response=await fetch(molaviShop.restoreList,{method:'POST',credentials:'same-origin',body:body}),result=await response.json();
                if(!response.ok||!result.success||!result.data||!result.data.quote)throw new Error(result&&result.data&&result.data.message||'سبد به‌روز نشد.');
                pendingRestore=null;Object.assign(catalog,result.data.catalog||{});lastQuote=result.data.quote;serverQtys=Object.assign({},lastQuote.qtys||{});qtys=Object.assign({},serverQtys);paint(lastQuote);completed=true;
                feedback.textContent=(result.data.skipped.length?digits(result.data.skipped.length)+' کالا فعلاً قابل خرید نبود. ':'')+(result.data.adjusted.length?'تعداد '+digits(result.data.adjusted.length)+' کالا با گام خرید هماهنگ شد.':'');
                refreshNativeCart();
            }catch(error){feedback.textContent=/[\u0600-\u06ff]/.test(error.message||'')?error.message:'نتیجه تأیید نشد؛ همین درخواست را دوباره امتحان کنید.';}
            finally{listRestoring=false;display();updateCheckoutBusy();if(completed)document.dispatchEvent(new CustomEvent('molavi:cart'));}
        }
        async function addFavorites(ids){
            return await writeFavorites(Array.from(new Set(readFavoriteIds().concat(ids))));
        }
        function open(opener){
            if(host.open)return;var cart=document.querySelector('.molavi-store-basket.is-open [data-molavi-close-basket]');if(cart)cart.click();
            lastOpener=opener;feedback.textContent='';if(!customerId)readLists();renderSaved();display();host.showModal();document.body.classList.add('molavi-library-open');document.dispatchEvent(new CustomEvent('molavi:library-open'));
            document.querySelectorAll('[data-molavi-open-library]').forEach(function(node){node.setAttribute('aria-expanded','true');});if(customerId)accountRequest();else loadFacts();
        }
        host.addEventListener('close',function(){document.dispatchEvent(new CustomEvent('molavi:library-close'));loadVersion++;if(controller)controller.abort();loading=false;document.body.classList.remove('molavi-library-open');document.querySelectorAll('[data-molavi-open-library]').forEach(function(node){node.setAttribute('aria-expanded','false');});if(lastOpener&&lastOpener.isConnected)lastOpener.focus();});
        host.querySelector('[data-molavi-close-library]').addEventListener('click',function(){host.close();});
        host.addEventListener('click',function(event){if(event.target===host){var box=host.getBoundingClientRect();if(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom)host.close();}});
        tabs.forEach(function(tab,index){tab.addEventListener('click',function(){setTab(tab.getAttribute('data-molavi-library-tab'));});tab.addEventListener('keydown',function(event){var next=event.key==='ArrowLeft'?index+1:event.key==='ArrowRight'?index-1:event.key==='Home'?0:event.key==='End'?tabs.length-1:null;if(next===null)return;event.preventDefault();setTab(tabs[(next+tabs.length)%tabs.length].getAttribute('data-molavi-library-tab'),true);});});
        document.addEventListener('click',async function(event){
            var opener=event.target.closest('[data-molavi-open-library]');if(opener){event.preventDefault();open(opener);return;}
            var button=event.target.closest('[data-molavi-favorite]');if(!button)return;event.preventDefault();if(accountBusy||!accountReady)return;
            var id=button.getAttribute('data-molavi-favorite'),ids=readFavoriteIds(),index=ids.indexOf(id);
            if(index<0)ids.push(id);else ids.splice(index,1);
            await writeFavorites(ids);
        });
        host.querySelector('[data-molavi-new-cart]').addEventListener('click',createFromCurrent);
        host.querySelector('[data-molavi-save-favorites]').addEventListener('click',function(){moveFavorites(false);});
        host.querySelector('[data-molavi-move-existing]').addEventListener('click',function(){moveFavorites(true);});
        saved.addEventListener('toggle',function(event){var details=event.target;if(!details.matches('[data-molavi-saved-cart]')||!details.open)return;if(details._molaviRestoredOpen){details._molaviRestoredOpen=false;return;}saved.querySelectorAll('details[open]').forEach(function(other){if(other!==details)other.open=false;});loadFacts();},true);
        saved.addEventListener('click',async function(event){
            var button=event.target.closest('button');if(!button||busy())return;
            if(button.closest('[data-molavi-inline-rename]'))return;
            var id=button.getAttribute('data-molavi-load-list')||button.getAttribute('data-molavi-list-favorites')||button.getAttribute('data-molavi-rename-list')||button.getAttribute('data-molavi-delete-list')||button.getAttribute('data-molavi-edit-items'),item=lists.find(function(row){return row.id===id;});if(!item)return;
            event.preventDefault();
            if(button.hasAttribute('data-molavi-load-list'))return restore(item,'merge');
            if(button.hasAttribute('data-molavi-list-favorites')){if(await addFavorites(Object.keys(item.items)))feedback.textContent='کالاهای «'+item.name+'» به علاقه‌مندی‌ها اضافه شدند.';return;}
            if(button.hasAttribute('data-molavi-edit-items')){editItems(item,button.closest('details'));return;}
            if(button.hasAttribute('data-molavi-delete-list')){var confirmed=await ask({title:'حذف سبد «'+item.name+'»؟',message:'این فهرست با '+digits(Object.keys(item.items).length)+' کالا از سبدهای ذخیره‌شده حذف می‌شود. سبد خرید فعلی و علاقه‌مندی‌ها تغییر نمی‌کنند. پس از حذف، دکمهٔ بازگرداندن نیز در دسترس است.',confirm:'حذف سبد',danger:true});if(!confirmed)return;if(await persist(lists.filter(function(row){return row.id!==id;}))){deletedCart=item;display();undo.focus();feedback.textContent='«'+item.name+'» حذف شد. می‌توانید آن را بازگردانید.';}return;}
            var title=button.closest('.molavi-saved-title'),nameNode=title.querySelector('[data-molavi-list-name]'),editor=document.createElement('span');
            editor.className='molavi-inline-rename';editor.setAttribute('data-molavi-inline-rename','');
            editor.innerHTML='<input aria-label="نام سبد" maxlength="60" autocomplete="off"><button type="button" class="molavi-icon-button" data-save aria-label="ذخیرهٔ نام" title="ذخیره">'+uiIcon('check')+'</button><button type="button" class="molavi-icon-button" data-cancel aria-label="انصراف از ویرایش" title="انصراف">'+uiIcon('close')+'</button>';
            nameNode.hidden=true;button.hidden=true;title.append(editor);
            var input=editor.querySelector('input');input.value=item.name;input.focus();input.select();
            function finish(){editor.remove();nameNode.hidden=false;button.hidden=false;button.focus();}
            async function save(){if(busy())return;var name=input.value.trim();if(!name){input.setCustomValidity('نام سبد را وارد کنید.');input.reportValidity();return;}
                var next=lists.map(function(row){return row.id===id?Object.assign({},row,{name:name}):row;});
                if(!await persist(next))return;feedback.textContent='نام سبد تغییر کرد.';
                var row=Array.from(saved.querySelectorAll('[data-molavi-saved-cart]')).find(function(node){return node.getAttribute('data-molavi-saved-cart')===id;});if(row)row.querySelector('[data-molavi-rename-list]').focus();
            }
            editor.addEventListener('click',function(e){e.stopPropagation();if(e.target.closest('[data-cancel]')){e.preventDefault();finish();}else if(e.target.closest('[data-save]')){e.preventDefault();save();}});
            input.addEventListener('input',function(){input.setCustomValidity('');});
            input.addEventListener('keydown',function(e){if(e.key==='Escape'||e.key==='Enter'){e.preventDefault();e.stopPropagation();if(e.key==='Escape')finish();else save();}});
        });
        host.querySelector('[data-molavi-library-retry]').addEventListener('click',loadFacts);
        document.addEventListener('molavi:favorites',function(){display();if(host.open){renderProducts();loadFacts();}});
        ['molavi:cart','molavi:buyer'].forEach(function(name){document.addEventListener(name,function(){display();if(host.open)loadFacts();});});
        window.addEventListener('storage',function(event){if(event.key===key){readLists();renderSaved();display();}if(event.key===favoriteKey){if(customerId)accountFavorites=null;document.dispatchEvent(new CustomEvent('molavi:favorites'));}});
        renderSaved();display();
        if(customerId)accountRequest();
        function automaticAccountRefresh(){
            if(!customerId||accountBusy||document.hidden||prompt.open||host.querySelector('[data-molavi-inline-rename],[data-molavi-items-editor]')||sending||dirty||listRestoring)return;
            accountRequest();
        }
        window.addEventListener('focus',automaticAccountRefresh);
        window.addEventListener('online',automaticAccountRefresh);
        document.addEventListener('visibilitychange',function(){if(!document.hidden)automaticAccountRefresh();});
        document.addEventListener('molavi:library-refresh',automaticAccountRefresh);
        window.setInterval(function(){if(host.open&&Date.now()-lastAccountRead>25000)automaticAccountRefresh();},30000);
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

    function pulseProduct(id) {
        var card = document.querySelector('[data-molavi-card][data-product="' + cssEscape(String(id)) + '"]');
        if (card) {
            card.classList.remove("is-updating");
            void card.offsetWidth;
            card.classList.add("is-updating");
            window.setTimeout(function () { card.classList.remove("is-updating"); }, 460);
        }
        var line = document.querySelector('.molavi-bline[data-product="' + cssEscape(String(id)) + '"]');
        if (line) {
            line.classList.remove("is-updating");
            void line.offsetWidth;
            line.classList.add("is-updating");
            window.setTimeout(function () { line.classList.remove("is-updating"); }, 460);
        }
    }

    function setupStickyControls(root) {
        var controls = root.querySelector("[data-molavi-store-controls]");
        if (!controls) return null;
        var advisory=controls.querySelector('[data-molavi-buyer-feedback]');if(advisory)controls.after(advisory);
        var header = document.querySelector(".molavi-site-header");
        var expandedBuyer = controls.querySelector(".molavi-buyer-expanded");
        var compactBuyer = controls.querySelector(".molavi-buyer-compact");
        var expandedHelp = controls.querySelector(".molavi-buyer-help-trigger");
        var toolbar = controls.querySelector(".molavi-product-toolbar");
        var panelTriggers = Array.prototype.slice.call(controls.querySelectorAll("[data-molavi-open-panel]"));
        var panels = Array.prototype.slice.call(root.querySelectorAll("[data-molavi-control-panel]"));
        var toolbarSearch = controls.querySelector("[data-molavi-toolbar-search]");
        var searchToggles = Array.prototype.slice.call(controls.querySelectorAll("[data-molavi-search-toggle]"));
        var activePanel = null;
        var activeTrigger = null;
        var frame = 0;

        function panelFor(name) {
            return panels.filter(function (panel) {
                return panel.getAttribute("data-molavi-control-panel") === name;
            })[0] || null;
        }

        function canFocus(node) {
            return Boolean(node && node.getClientRects().length && !node.disabled);
        }

        function focusTargetFor(name, fallback) {
            var compact = controls.classList.contains("is-compact");
            if (name === "help") {
                var helpTarget = compact ? compactBuyer : expandedHelp;
                if (canFocus(helpTarget)) return helpTarget;
            }
            if (name === "buyer") {
                if (compactBuyer && canFocus(compactBuyer)) return compactBuyer;
                return expandedBuyer && expandedBuyer.querySelector('[data-molavi-buyer][aria-pressed="true"]');
            }
            if (fallback && canFocus(fallback)) return fallback;
            return controls.querySelector('[data-molavi-open-panel="' + name + '"]');
        }

        function nextControlAfterPanel(name) {
            var compact = controls.classList.contains("is-compact");
            if (name === "buyer") return controls.querySelector(".molavi-compact-search-trigger");
            if (name === "help") {
                return compact
                    ? controls.querySelector(".molavi-compact-search-trigger")
                    : controls.querySelector(".molavi-category-trigger");
            }
            if (name === "category") return controls.querySelector(".molavi-sort-trigger");
            if (name === "sort") return controls.querySelector('[data-molavi-view="grid"]');
            return null;
        }

        function positionPanel(panel, trigger) {
            if (!panel || !trigger) return;
            var rect = trigger.getBoundingClientRect();
            var width = Math.max(1, Math.min(330, window.innerWidth - 24));
            panel.style.width = width + "px";
            panel.style.left = "12px";
            panel.style.right = "auto";
            panel.style.top = "12px";
            var panelRect = panel.getBoundingClientRect();
            var left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12));
            var top = rect.bottom + 8;
            if (top + panelRect.height > window.innerHeight - 12) {
                var headerBottom = header ? header.getBoundingClientRect().bottom : 0;
                top = Math.max(Math.max(12, headerBottom + 8), rect.top - panelRect.height - 8);
            }
            panel.style.left = left + "px";
            panel.style.top = top + "px";
        }

        function positionSearchOverlay() {
            if (!toolbarSearch) return;
            if (toolbarSearch.hidden || !controls.classList.contains("is-compact") || !toolbar) {
                toolbarSearch.style.removeProperty("left");
                toolbarSearch.style.removeProperty("right");
                toolbarSearch.style.removeProperty("width");
                toolbarSearch.style.removeProperty("top");
                return;
            }
            var controlsRect = controls.getBoundingClientRect();
            var toolbarRect = toolbar.getBoundingClientRect();
            toolbarSearch.style.left = (toolbarRect.left - controlsRect.left) + "px";
            toolbarSearch.style.right = "auto";
            toolbarSearch.style.width = toolbarRect.width + "px";
            toolbarSearch.style.top = (toolbarRect.top - controlsRect.top + Math.max(0, (toolbarRect.height - 38) / 2)) + "px";
        }

        function closePanel(restoreFocus) {
            if (!activePanel) return;
            var previousPanel = activePanel;
            var previousTrigger = activeTrigger;
            previousPanel.hidden = true;
            if (previousTrigger) previousTrigger.setAttribute("aria-expanded", "false");
            activePanel = null;
            activeTrigger = null;
            if (restoreFocus) {
                var name = previousPanel.getAttribute("data-molavi-control-panel");
                var returnTo = focusTargetFor(name, previousTrigger);
                if (canFocus(returnTo)) returnTo.focus({ preventScroll: true });
            }
            schedule();
        }

        function openPanel(name, trigger) {
            var panel = panelFor(name);
            if (!panel || !trigger) return;
            if (activePanel === panel) {
                closePanel(false);
                return;
            }
            closePanel(false);
            if (toolbarSearch && !toolbarSearch.hidden) {
                toolbarSearch.hidden = true;
                controls.classList.remove("is-search-open");
                searchToggles.forEach(function (toggle) { toggle.setAttribute("aria-expanded", "false"); });
                positionSearchOverlay();
            }
            activePanel = panel;
            activeTrigger = trigger;
            trigger.setAttribute("aria-expanded", "true");
            panel.hidden = false;
            positionPanel(panel, trigger);
            var firstFocus = panel.querySelector("[data-molavi-panel-close]") || panel;
            window.requestAnimationFrame(function () {
                if (activePanel === panel && canFocus(firstFocus)) firstFocus.focus({ preventScroll: true });
            });
        }

        panelTriggers.forEach(function (trigger) {
            trigger.addEventListener("click", function () {
                openPanel(trigger.getAttribute("data-molavi-open-panel"), trigger);
            });
        });
        root.addEventListener("click", function (event) {
            var closeButton = event.target.closest("[data-molavi-panel-close]");
            if (closeButton && activePanel && activePanel.contains(closeButton)) {
                closePanel(true);
                return;
            }
            var buyerOption = event.target.closest("[data-molavi-buyer]");
            if (buyerOption && activePanel && activePanel.contains(buyerOption)) closePanel(true);
        });
        panels.forEach(function (panel) {
            panel.addEventListener("focusout", function () {
                window.requestAnimationFrame(function () {
                    if (activePanel === panel && !panel.contains(document.activeElement)) closePanel(false);
                });
            });
        });

        function update() {
            frame = 0;
            var headerBottom = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0;
            controls.style.setProperty("--molavi-header-offset", headerBottom + "px");
            if (activePanel) {
                positionPanel(activePanel, activeTrigger);
                positionSearchOverlay();
                return;
            }
            var shouldCompact = window.scrollY > 0
                && controls.getBoundingClientRect().top <= headerBottom + 1;
            var isCompact = controls.classList.contains("is-compact");
            if (shouldCompact === isCompact) {
                positionSearchOverlay();
                if (activePanel) positionPanel(activePanel, activeTrigger);
                return;
            }

            var focused = document.activeElement;
            var focusInExpanded = expandedBuyer && expandedBuyer.contains(focused);
            var focusInCompactBuyer = compactBuyer && compactBuyer === focused;
            var nextFocus = null;
            if (shouldCompact && focusInExpanded) {
                if (focused.closest("[data-molavi-search-toggle]")) {
                    nextFocus = controls.querySelector(".molavi-compact-search-trigger");
                } else if (focused === expandedHelp) {
                    nextFocus = compactBuyer;
                } else {
                    nextFocus = compactBuyer;
                }
            } else if (!shouldCompact && focusInCompactBuyer && expandedBuyer) {
                nextFocus = expandedBuyer.querySelector('[data-molavi-buyer][aria-pressed="true"]');
            } else if (focused.matches && focused.matches("[data-molavi-search-toggle]")) {
                nextFocus = shouldCompact
                    ? controls.querySelector(".molavi-compact-search-trigger")
                    : controls.querySelector(".molavi-buyer-search-trigger");
            }

            controls.classList.toggle("is-compact", shouldCompact);
            positionSearchOverlay();
            if (activePanel) closePanel(false);
            if (nextFocus) {
                window.requestAnimationFrame(function () {
                    if (canFocus(nextFocus)) nextFocus.focus({ preventScroll: true });
                });
            }
        }

        function schedule() {
            if (!frame) frame = window.requestAnimationFrame(update);
        }

        window.addEventListener("scroll", schedule, { passive: true });
        window.addEventListener("resize", schedule);
        document.addEventListener("click", function (event) {
            var trigger = event.target.closest && event.target.closest("[data-molavi-open-panel]");
            if (activePanel && !activePanel.contains(event.target) && trigger !== activeTrigger) closePanel(false);
        });
        controls.addEventListener("focusout", function () {
            window.requestAnimationFrame(function () { if (!activePanel) schedule(); });
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Tab" && activePanel) {
                var panelStops = Array.prototype.slice.call(activePanel.querySelectorAll("button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\"-1\"])")).filter(canFocus);
                var firstStop = panelStops[0];
                var lastStop = panelStops[panelStops.length - 1];
                if (event.shiftKey && document.activeElement === firstStop && canFocus(activeTrigger)) {
                    event.preventDefault();
                    var returnTo = activeTrigger;
                    closePanel(false);
                    returnTo.focus({ preventScroll: true });
                    return;
                }
                if (!event.shiftKey && document.activeElement === lastStop) {
                    var nextControl = nextControlAfterPanel(activePanel.getAttribute("data-molavi-control-panel"));
                    if (canFocus(nextControl)) {
                        event.preventDefault();
                        closePanel(false);
                        nextControl.focus({ preventScroll: true });
                        return;
                    }
                }
            }
            if (event.key === "Escape" && activePanel) {
                event.preventDefault();
                closePanel(true);
            }
        });
        window.addEventListener("scroll", function () {
            if (activePanel) positionPanel(activePanel, activeTrigger);
        }, { passive: true });
        schedule();

        return { closePanel: closePanel, positionSearchOverlay: positionSearchOverlay };
    }

    function setupCatalog() {
        var root = document.querySelector("[data-molavi-storefront]");
        if (!root) return;
        var stickyControls = setupStickyControls(root);
        var grid = root.querySelector("[data-molavi-grid]");
        var searches = Array.prototype.slice.call(document.querySelectorAll("[data-molavi-search]"));
        var categoryOptions = Array.prototype.slice.call(root.querySelectorAll("[data-molavi-category-option]"));
        var sortOptions = Array.prototype.slice.call(root.querySelectorAll("[data-molavi-sort-option]"));
        var toolbar = root.querySelector(".molavi-product-toolbar");
        var storeControls = root.querySelector("[data-molavi-store-controls]");
        var toolbarSearch = root.querySelector("[data-molavi-toolbar-search]");
        var searchToggles = Array.prototype.slice.call(root.querySelectorAll("[data-molavi-search-toggle]"));
        var searchClose = root.querySelector("[data-molavi-search-close]");
        var searchInput = toolbarSearch ? toolbarSearch.querySelector("[data-molavi-search]") : null;
        var activeSearchToggle = null;
        var query = searches.reduce(function (value, input) {
            return value || String(input.value || "").trim();
        }, "");
        searches.forEach(function (input) { input.value = query; });
        var viewButtons = root.querySelectorAll("[data-molavi-view]");
        var selectedCategory = "";
        var selectedSort = "newest";
        var favoritesOnly = new URLSearchParams(window.location.search).get("molavi_favorites") === "1";
        var favorites;
        try {
            favorites = new Set(readFavoriteIds());
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
        }

        var catalogPage=Number(root.getAttribute('data-catalog-page'))||1;
        var catalogTimer=null,catalogController=null,catalogRequest=0;
        var more=root.querySelector('[data-molavi-more]');
        async function loadCatalog(append) {
            if(!molaviShop.catalogEndpoint)return;
            var version=++catalogRequest,revision=pricingRevision;
            if(catalogController)catalogController.abort();catalogController=new AbortController();
            var page=append?catalogPage+1:1;
            var url=new URL(molaviShop.catalogEndpoint,window.location.href);
            url.searchParams.set('catalog_page',page);url.searchParams.set('molavi_q',query);
            url.searchParams.set('category',selectedCategory);url.searchParams.set('sort',selectedSort);
            if(favoritesOnly)url.searchParams.set('ids',Array.from(favorites).join(','));
            if(more)more.disabled=true;grid.setAttribute('aria-busy','true');
            try{
                var response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:catalogController.signal});var result=await response.json();
                if(version!==catalogRequest)return;
                if(revision!==pricingRevision||sending||buyerChanging||listRestoring){scheduleCatalog(append);return;}
                if(!response.ok||!result.success||!result.data||typeof result.data.html!=='string')throw new Error('catalog_failed');
                var data=result.data;
                if(data.buyer_type!==buyerType){scheduleCatalog(append);return;}
                Object.assign(catalog,data.catalog||{});
                if(!append)grid.replaceChildren();grid.insertAdjacentHTML('beforeend',data.html);
                catalogPage=Number(data.page)||page;
                var count=root.querySelector('[data-molavi-result-value]');if(count)count.textContent=digits(data.total);
                var empty=root.querySelector('[data-molavi-empty]');if(empty)empty.hidden=data.total>0;
                if(more)more.hidden=!data.has_more;
                paintFavorites();paint(lastQuote);showStatus('');
            }catch(error){if(error.name!=='AbortError')showStatus('فهرست تازه نشد؛ دوباره تلاش کنید.');}
            finally{if(version===catalogRequest){grid.removeAttribute('aria-busy');if(more)more.disabled=false;}}
        }
        function scheduleCatalog(append){clearTimeout(catalogTimer);catalogTimer=setTimeout(function(){loadCatalog(Boolean(append));},180);}
        if(more)more.addEventListener('click',function(){loadCatalog(true);});
        document.addEventListener('molavi:buyer',function(){if(molaviShop.catalogEndpoint)scheduleCatalog(false);});
        document.addEventListener('molavi:cart',function(){if(molaviShop.catalogEndpoint&&['cheap','dear'].includes(selectedSort))scheduleCatalog(false);});
        function applyFilters() {
            if(molaviShop.catalogEndpoint){scheduleCatalog(false);return;}
            if (!grid) return;
            var term = normalizeSearch(query);
            var cards = Array.prototype.slice.call(grid.querySelectorAll("[data-molavi-card]"));
            var visible = 0;
            cards.forEach(function (card) {
                var text = normalizeSearch(card.getAttribute("data-search") || "");
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
            if (count) {
                var countValue = count.querySelector("[data-molavi-result-value]");
                if (countValue) countValue.textContent = digits(visible);
                else count.textContent = digits(visible) + " کالا";
                count.setAttribute("aria-label", digits(visible) + " کالا");
            }
            if (empty) empty.hidden = visible > 0;
        }

        function updateToolbarMenuState() {
            categoryOptions.forEach(function (button) {
                var active = (button.getAttribute("data-molavi-category-option") || "") === selectedCategory;
                button.setAttribute("aria-pressed", active ? "true" : "false");
            });
            sortOptions.forEach(function (button) {
                var active = button.getAttribute("data-molavi-sort-option") === selectedSort;
                button.setAttribute("aria-pressed", active ? "true" : "false");
            });
            var categoryTrigger = root.querySelector(".molavi-category-trigger");
            var sortTrigger = root.querySelector(".molavi-sort-trigger");
            if (categoryTrigger) {
                var categoryButton = categoryOptions.filter(function (button) {
                    return (button.getAttribute("data-molavi-category-option") || "") === selectedCategory;
                })[0];
                var categoryLabelNode = categoryButton && categoryButton.querySelector("span:first-child");
                var categoryName = categoryLabelNode ? categoryLabelNode.textContent.trim() : "همه دسته‌بندی‌ها";
                var categoryLabel = selectedCategory ? "فیلتر دسته‌بندی: " + categoryName : "فیلتر دسته‌بندی محصولات";
                categoryTrigger.classList.toggle("has-active", selectedCategory !== "");
                categoryTrigger.setAttribute("aria-label", categoryLabel);
                categoryTrigger.title = categoryLabel;
                var categoryCurrent = categoryTrigger.querySelector("[data-molavi-category-current]");
                if (categoryCurrent) categoryCurrent.textContent = selectedCategory ? categoryName : "همه";
            }
            if (sortTrigger) {
                var sortButton = sortOptions.filter(function (button) {
                    return button.getAttribute("data-molavi-sort-option") === selectedSort;
                })[0];
                var sortLabelNode = sortButton && sortButton.querySelector("span:first-child");
                var sortName = sortLabelNode ? sortLabelNode.textContent.trim() : "جدیدترین";
                var sortLabel = "مرتب‌سازی: " + sortName;
                sortTrigger.classList.toggle("has-active", selectedSort !== "newest");
                sortTrigger.setAttribute("aria-label", sortLabel);
                sortTrigger.title = sortLabel;
                var sortCurrent = sortTrigger.querySelector("[data-molavi-sort-current]");
                if (sortCurrent) sortCurrent.textContent = sortName;
            }
        }

        function sortCards() {
            if(molaviShop.catalogEndpoint){scheduleCatalog(false);return;}
            if (!grid) return;
            var cards = Array.prototype.slice.call(grid.querySelectorAll("[data-molavi-card]"));
            cards.sort(function (a, b) {
                if (selectedSort === "cheap" || selectedSort === "dear") {
                    var difference = Number(a.getAttribute("data-price") || 0) - Number(b.getAttribute("data-price") || 0);
                    return selectedSort === "dear" ? -difference : difference;
                }
                if (selectedSort === "name") {
                    return String(a.getAttribute("data-name") || "").localeCompare(String(b.getAttribute("data-name") || ""), "fa");
                }
                return Number(b.getAttribute("data-created") || 0) - Number(a.getAttribute("data-created") || 0);
            });
            cards.forEach(function (card) { grid.appendChild(card); });
        }

        function updateSearch(source) {
            query = String(source.value || "").trim();
            searches.forEach(function (input) {
                if (input !== source && input.value !== source.value) input.value = source.value;
            });
            applyFilters();
        }

        function currentSearchTrigger() {
            if (activeSearchToggle && activeSearchToggle.getClientRects().length) return activeSearchToggle;
            return searchToggles.filter(function (toggle) { return toggle.getClientRects().length > 0; })[0] || null;
        }

        function nextControlAfterSearch() {
            if (storeControls.classList.contains("is-compact")) {
                return storeControls.querySelector(".molavi-category-trigger");
            }
            return storeControls.querySelector('.molavi-buyer-expanded .molavi-buyer-choice-buttons [data-molavi-buyer="household"]');
        }

        searches.forEach(function (input) {
            input.addEventListener("input", function () { updateSearch(input); });
            // Native clear button of <input type="search"> fires "search" (not
            // always "input") in some browsers; keep the filter in sync either way.
            input.addEventListener("search", function () { updateSearch(input); });
            if (input.form) {
                input.form.addEventListener("submit", function (event) {
                    event.preventDefault();
                    updateSearch(input);
                    root.scrollIntoView({ behavior: "smooth", block: "start" });
                });
            }
        });

        function setToolbarSearchOpen(open, restoreFocus) {
            if (!toolbarSearch || !toolbar || !storeControls || !searchToggles.length) return;
            if (open) {
                if (stickyControls) stickyControls.closePanel(false);
            }
            toolbarSearch.hidden = !open;
            storeControls.classList.toggle("is-search-open", open);
            if (stickyControls) stickyControls.positionSearchOverlay();
            searchToggles.forEach(function (toggle) { toggle.setAttribute("aria-expanded", open ? "true" : "false"); });
            if (open && searchInput) {
                searchInput.value = query;
                window.requestAnimationFrame(function () { searchInput.focus({ preventScroll: true }); });
            } else if (restoreFocus) {
                var returnTo = currentSearchTrigger();
                if (returnTo) returnTo.focus({ preventScroll: true });
            }
            if (!open) activeSearchToggle = null;
        }

        // Closing search with the × (or Escape) used to hide the overlay while
        // leaving the filter active, so the grid stayed filtered with no
        // visible search box. Clearing the query first restores all cards.
        function clearSearchAndClose(restoreFocus) {
            if (searchInput) {
                searchInput.value = "";
                updateSearch(searchInput);
            }
            setToolbarSearchOpen(false, restoreFocus);
        }

        if (searchToggles.length && toolbarSearch) {
            searchToggles.forEach(function (toggle) {
                toggle.addEventListener("click", function () {
                    if (!toolbarSearch.hidden) setToolbarSearchOpen(false, true);
                    else {
                        if (stickyControls) stickyControls.closePanel(false);
                        activeSearchToggle = toggle;
                        setToolbarSearchOpen(true, false);
                    }
                });
            });
            if (searchClose) searchClose.addEventListener("click", function () { clearSearchAndClose(true); });
            toolbarSearch.addEventListener("keydown", function (event) {
                if (event.key === "Escape" && !toolbarSearch.hidden) {
                    event.preventDefault();
                    clearSearchAndClose(true);
                    return;
                }
                if (event.key === "Tab" && !toolbarSearch.hidden) {
                    if (event.shiftKey && document.activeElement === searchInput) {
                        var returnTo = currentSearchTrigger();
                        if (returnTo) {
                            event.preventDefault();
                            setToolbarSearchOpen(false, false);
                            returnTo.focus({ preventScroll: true });
                        }
                    } else if (!event.shiftKey && document.activeElement === searchClose) {
                        var nextControl = nextControlAfterSearch();
                        if (nextControl && nextControl.getClientRects().length && !nextControl.disabled) {
                            event.preventDefault();
                            setToolbarSearchOpen(false, false);
                            nextControl.focus({ preventScroll: true });
                        }
                    }
                }
            });
            toolbarSearch.addEventListener("focusout", function () {
                window.requestAnimationFrame(function () {
                    var focusOnSearchToggle = searchToggles.some(function (toggle) { return toggle === document.activeElement; });
                    if (!toolbarSearch.hidden && !toolbarSearch.contains(document.activeElement) && !focusOnSearchToggle) {
                        setToolbarSearchOpen(false, false);
                    }
                });
            });
            document.addEventListener("click", function (event) {
                var clickOnSearchToggle = searchToggles.some(function (toggle) { return toggle.contains(event.target); });
                if (!toolbarSearch.hidden && !toolbarSearch.contains(event.target) && !clickOnSearchToggle) {
                    setToolbarSearchOpen(false, false);
                }
            });
        }
        categoryOptions.forEach(function (button) {
            button.addEventListener("click", function () {
                selectedCategory = button.getAttribute("data-molavi-category-option") || "";
                applyFilters();
                updateToolbarMenuState();
                if (stickyControls) stickyControls.closePanel(true);
            });
        });
        sortOptions.forEach(function (button) {
            button.addEventListener("click", function () {
                selectedSort = button.getAttribute("data-molavi-sort-option") || "newest";
                sortCards();
                updateToolbarMenuState();
                if (stickyControls) stickyControls.closePanel(true);
            });
        });
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
            var featuredAction = event.target.closest("[data-molavi-recommendation-product]");
            if (featuredAction && root.contains(featuredAction)) {
                event.preventDefault();
                var productId = featuredAction.getAttribute("data-molavi-recommendation-product");
                var productCard = root.querySelector('[data-molavi-card][data-product="' + cssEscape(productId) + '"]');
                var cartControl = productCard && productCard.querySelector("[data-molavi-cart]");
                var addStep = cartControl && cartControl.querySelector("[data-molavi-step]");
                if (addStep) addStep.click();
                return;
            }
        });
        document.addEventListener("molavi:favorites", function () { favorites = new Set(readFavoriteIds()); paintFavorites(); if (favoritesOnly) applyFilters(); });
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
        if(!molaviShop.catalogEndpoint){applyFilters();sortCards();}
        else if(favoritesOnly)scheduleCatalog(false);
        updateToolbarMenuState();
    }

    function setupRecommendations() {
        var root = document.querySelector("[data-molavi-recommendations]");
        if (!root) return;
        var previous = root.querySelector("[data-molavi-recommendation-prev]");
        var next = root.querySelector("[data-molavi-recommendation-next]");
        function move(amount) {
            if (recommendationProducts.length < 2) return;
            recommendationIndex = (recommendationIndex + amount + recommendationProducts.length) % recommendationProducts.length;
            updateRecommendations(localQuote());
        }
        if (previous) previous.addEventListener("click", function () { move(-1); });
        if (next) next.addEventListener("click", function () { move(1); });
        root.addEventListener("click", function (event) {
            var dot = event.target.closest("[data-molavi-recommendation-dot]");
            if (!dot) return;
            var index = Number(dot.getAttribute("data-molavi-recommendation-dot")) || 0;
            recommendationIndex = Math.max(0, Math.min(recommendationProducts.length - 1, index));
            updateRecommendations(localQuote());
        });
    }

    function updateRecommendations() {
        var root = document.querySelector("[data-molavi-recommendations]");
        if (!root) return;
        var slideNode = root.querySelector("[data-molavi-recommendation-slide]");
        var countNode = root.querySelector("[data-molavi-recommendation-count]");
        var dots = root.querySelector("[data-molavi-recommendation-dots]");
        var controls = root.querySelector(".molavi-recommendation-controls");
        if (!slideNode) return;
        if (recommendationBuyerType !== buyerType) {
            recommendationBuyerType = buyerType;
            recommendationIndex = 0;
        }

        // The carousel and catalog are siblings in the storefront layout, so
        // cards must be read from the document rather than from the carousel.
        recommendationProducts = Array.prototype.slice.call(document.querySelectorAll("[data-molavi-card]"))
            .map(function (card) {
                var price = card.querySelector("[data-molavi-price]");
                var image = card.querySelector(".molavi-card-photo img");
                var titleLink = card.querySelector(".molavi-card-body h3 a");
                var maxDiscount = Number(card.getAttribute("data-" + buyerType + "-max")) || 0;
                if (!price || maxDiscount <= 0) return null;
                var control = card.querySelector("[data-molavi-cart]");
                return {
                    id: String(card.getAttribute("data-product") || ""),
                    name: card.getAttribute("data-name") || (titleLink ? titleLink.textContent.trim() : "کالا"),
                    url: titleLink ? titleLink.href : "#molavi-products",
                    image: image ? (image.currentSrc || image.src || image.getAttribute("data-src") || "") : "",
                    maxDiscount: maxDiscount,
                    currentPrice: Number(price.getAttribute("data-unit")) || 0,
                    referencePrice: Number(price.getAttribute("data-shelf")) || 0,
                    inCart: Number(control && control.getAttribute("data-qty")) > 0
                };
            })
            .filter(function (item) { return item && item.id; })
            .sort(function (a, b) {
                return (b.maxDiscount - a.maxDiscount) || a.name.localeCompare(b.name, "fa");
            })
            .slice(0, 5);

        recommendationIndex = recommendationProducts.length
            ? Math.max(0, Math.min(recommendationProducts.length - 1, recommendationIndex))
            : 0;
        if (controls) controls.hidden = recommendationProducts.length < 2;
        if (dots) dots.hidden = recommendationProducts.length < 2;
        if (countNode) countNode.textContent = recommendationProducts.length
            ? digits(recommendationIndex + 1) + " از " + digits(recommendationProducts.length)
            : "۰ از ۰";
        if (!recommendationProducts.length) {
            slideNode.textContent = "در حال حاضر کالای تخفیف‌دار موجود نیست.";
            if (dots) dots.replaceChildren();
            return;
        }

        var item = recommendationProducts[recommendationIndex];
        var featured = document.createElement("article");
        featured.className = "molavi-featured-product";
        var imageLink = document.createElement("a");
        imageLink.className = "molavi-featured-image";
        imageLink.href = item.url;
        imageLink.tabIndex = -1;
        imageLink.setAttribute("aria-hidden", "true");
        if (item.image) {
            var productImage = document.createElement("img");
            productImage.src = item.image;
            productImage.alt = item.name;
            productImage.loading = "eager";
            productImage.decoding = "async";
            imageLink.appendChild(productImage);
        }

        var copy = document.createElement("div");
        copy.className = "molavi-featured-copy";
        var badge = document.createElement("span");
        badge.className = "molavi-featured-discount";
        badge.textContent = "تا " + percent(item.maxDiscount) + " تخفیف";
        var title = document.createElement("h3");
        var titleLink = document.createElement("a");
        titleLink.href = item.url;
        titleLink.textContent = item.name;
        title.appendChild(titleLink);
        var note = document.createElement("p");
        note.textContent = "بیشترین تخفیف قابل دریافت در فهرست است؛ قیمت نهایی با نوع خرید و مبلغ سبد محاسبه می‌شود.";
        var prices = document.createElement("div");
        prices.className = "molavi-featured-prices";
        if (item.referencePrice > item.currentPrice) {
            var reference = document.createElement("s");
            reference.textContent = toman(item.referencePrice);
            prices.appendChild(reference);
        }
        var current = document.createElement("strong");
        current.textContent = toman(item.currentPrice);
        var priceLabel = document.createElement("span");
        priceLabel.textContent = "قیمت فعلی";
        prices.append(current, priceLabel);
        var action = document.createElement("button");
        action.type = "button";
        action.className = "molavi-featured-action";
        action.setAttribute("data-molavi-recommendation-product", item.id);
        action.setAttribute("aria-label", (item.inCart ? "افزایش یک گام از " : "افزودن ") + item.name + " به سبد");
        action.textContent = item.inCart ? "افزایش یک گام" : "افزودن به سبد";
        copy.append(badge, title, note, prices);
        featured.append(imageLink, copy, action);

        slideNode.classList.remove("is-changing");
        void slideNode.offsetWidth;
        slideNode.replaceChildren(featured);
        slideNode.classList.add("is-changing");
        if (dots) {
            dots.innerHTML = recommendationProducts.map(function (_, index) {
                return '<button type="button" data-molavi-recommendation-dot="' + index + '" aria-label="نمایش پیشنهاد '
                    + digits(index + 1) + '" aria-current="' + (index === recommendationIndex ? 'true' : 'false') + '"></button>';
            }).join("");
        }
    }

    function setupCartRefresh(){
        if(!molaviShop.quoteEndpoint||document.body._molaviCartReadReady)return;
        document.body._molaviCartReadReady=true;
        var refreshing=false,timer=null,queuedRefresh=null;
        async function refresh(initial){
            if(document.hidden)return;
            if(refreshing){queuedRefresh=!!initial||!!queuedRefresh;return;}
            if(sending||dirty||buyerChanging||listRestoring||nativeCartUpdating){window.clearTimeout(timer);timer=window.setTimeout(function(){refresh(initial);},500);return;}
            refreshing=true;var revision=pricingRevision,controller=new AbortController(),timeout=window.setTimeout(function(){controller.abort();},20000);
            try{
                var url=new URL(molaviShop.quoteEndpoint,location.href);url.searchParams.set('nonce',molaviShop.nonce);
                var response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal}),result=await response.json();
                if(!response.ok||!result.success||!result.data.quote)return;
                if(revision!==pricingRevision||sending||dirty||buyerChanging||listRestoring||nativeCartUpdating){timer=window.setTimeout(function(){refresh(initial);},500);return;}
                pricingRevision++;Object.assign(catalog,result.data.catalog||{});lastQuote=result.data.quote;serverQtys=Object.assign({},lastQuote.qtys||{});qtys=Object.assign({},serverQtys);paint(lastQuote);
                document.dispatchEvent(new CustomEvent('molavi:cart',{detail:{remote:true,initial:initial}}));
            }catch(error){/* The current page snapshot stays available; focus or connectivity restoration retries. */}
            finally{window.clearTimeout(timeout);refreshing=false;if(queuedRefresh!==null){var next=queuedRefresh;queuedRefresh=null;window.clearTimeout(timer);timer=window.setTimeout(function(){refresh(next);},150);}}
        }
        window.addEventListener('focus',function(){refresh(false);});
        window.addEventListener('online',function(){refresh(false);});
        window.addEventListener('pageshow',function(event){if(event.persisted)refresh(true);});
        document.addEventListener('visibilitychange',function(){if(!document.hidden)refresh(true);});
        if(window.jQuery)jQuery(document.body).on('added_to_cart removed_from_cart updated_wc_div wc_cart_emptied',function(){refresh(false);});
        refresh(true);
    }

    function setupMobileBasket() {
        var root = document.querySelector("[data-molavi-floating-shell]");
        if (!root || root._molaviBasketReady) return;
        root._molaviBasketReady=true;
        var standalone=root.classList.contains('molavi-global-basket-shell');
        var triggers = Array.prototype.slice.call(document.querySelectorAll("[data-molavi-open-basket]"));
        var dialog = root.querySelector("#molavi-basket-dialog");
        var backdrop = root.querySelector(".molavi-basket-backdrop");
        if (!triggers.length || !dialog || !backdrop) return;
        var mobileQuery = window.matchMedia("(max-width: 900px)");
        var lastTrigger = triggers[0];
        var floating=root.querySelector('.molavi-mobile-basket'),peekTimer=null;
        if(floating)document.body.appendChild(floating);
        var library=document.querySelector('#molavi-favorites-dialog'),cartLayer=document.createElement('div');
        cartLayer.className='molavi-cart-over-library';cartLayer.setAttribute('popover','manual');document.body.appendChild(cartLayer);
        function positionLibraryCart(){
            if(!library||!library.open)return;var box=library.getBoundingClientRect(),width=Math.min(370,window.innerWidth-32);
            cartLayer.style.top=Math.max(12,box.top-70)+'px';
            cartLayer.style.left=Math.max(16,Math.min(box.left,window.innerWidth-width-16))+'px';
        }
        function dockLibraryCart(){
            if(!floating||!library)return;
            library.appendChild(cartLayer);cartLayer.appendChild(floating);positionLibraryCart();
            if(typeof cartLayer.showPopover==='function'&&!cartLayer.matches(':popover-open'))cartLayer.showPopover();
        }
        function undockLibraryCart(){
            retract();if(typeof cartLayer.hidePopover==='function'&&cartLayer.matches(':popover-open'))cartLayer.hidePopover();
            if(floating)document.body.appendChild(floating);document.body.appendChild(cartLayer);
        }
        document.addEventListener('molavi:library-open',dockLibraryCart);
        document.addEventListener('molavi:library-close',undockLibraryCart);
        window.addEventListener('resize',positionLibraryCart);

        var cartSignature=JSON.stringify([serverQtys,lastQuote.subtotal,lastQuote.savings,buyerType]);
        function retract(){if(floating)floating.classList.remove('is-peeking');window.clearTimeout(peekTimer);}
        function peek(event){
            var signature=JSON.stringify([serverQtys,lastQuote.subtotal,lastQuote.savings,buyerType]);
            if(signature===cartSignature&&!(event&&event.detail&&event.detail.initial&&Number(lastQuote.count)>0))return;cartSignature=signature;
            if(!floating||dialog.classList.contains('is-open'))return;
            floating.classList.add('is-peeking');window.clearTimeout(peekTimer);peekTimer=window.setTimeout(retract,4200);
        }
        document.addEventListener('molavi:cart',peek);document.addEventListener('molavi:buyer',peek);

        if ((standalone || mobileQuery.matches)) {
            dialog.setAttribute("inert", "");
            dialog.setAttribute("aria-hidden", "true");
        }

        function close() {
            dialog.classList.remove("is-open");
            dialog.setAttribute("aria-modal", "false");
            backdrop.hidden = true;
            triggers.forEach(function (trigger) { trigger.setAttribute("aria-expanded", "false"); });
            document.body.classList.remove("molavi-basket-open");
            if ((standalone || mobileQuery.matches)) {
                dialog.setAttribute("inert", "");
                dialog.setAttribute("aria-hidden", "true");
                lastTrigger.focus();
            } else {
                dialog.removeAttribute("inert");
                dialog.removeAttribute("aria-hidden");
            }
            showBuyerFeedback(localQuote());
        }

        function open() {
            retract();
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
            showBuyerFeedback(localQuote());
        }

        triggers.forEach(function (trigger) {
            trigger.addEventListener("click", function (event) {
                if(library&&library.open){
                    event.preventDefault();lastTrigger=event.currentTarget;library.close();
                    window.setTimeout(function(){if(!standalone&&!mobileQuery.matches)dialog.scrollIntoView({behavior:'smooth',block:'start'});else open();},0);
                    return;
                }
                if (!standalone && !mobileQuery.matches) {
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
            var focusable = Array.prototype.slice.call(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (node) { return !node.hidden && node.getClientRects().length > 0; });
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
            if (!standalone && window.matchMedia("(min-width: 901px)").matches) {
                if (dialog.classList.contains("is-open")) close();
                dialog.removeAttribute("inert");
                dialog.removeAttribute("aria-hidden");
            } else if (!dialog.classList.contains("is-open")) {
                dialog.setAttribute("inert", "");
                dialog.setAttribute("aria-hidden", "true");
            }
            showBuyerFeedback(localQuote());
        });
    }

    var pageInteractionsInitialized = false;
    var storefrontControlsInitialized = false;
    var storefrontObserver = null;

    function initializeStorefrontControls() {
        if (storefrontControlsInitialized) return;
        var root = document.querySelector("[data-molavi-storefront]");
        if (!root) {
            if (document.body && document.body.classList.contains("woocommerce-shop") && !storefrontObserver) {
                storefrontObserver = new MutationObserver(function () {
                    if (document.querySelector("[data-molavi-storefront]")) {
                        storefrontObserver.disconnect();
                        storefrontObserver = null;
                        initializeStorefrontControls();
                    }
                });
                storefrontObserver.observe(document.documentElement, { childList: true, subtree: true });
            }
            return;
        }

        storefrontControlsInitialized = true;
        if (storefrontObserver) {
            storefrontObserver.disconnect();
            storefrontObserver = null;
        }
        setupCatalog();
        setupRecommendations();
        setupMobileBasket();
        updateBuyerControls();
        paint(lastQuote);
    }

    function initializePageInteractions() {
        if (pageInteractionsInitialized) return;
        pageInteractionsInitialized = true;
        setupFavoritesLibrary();
        setupShoppingHelp();
        setupNativeCartAutoUpdate();
        setupMobileBasket();
        setupCartRefresh();
        updateBuyerControls();
        paint(lastQuote);
        initializeStorefrontControls();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initializePageInteractions, { once: true });
    } else {
        initializePageInteractions();
    }

    function applyFragments(fragments) {
        if (!window.jQuery) return;
        jQuery.each(fragments, function (selector, html) {
            var target = jQuery(selector);
            if (target.length) target.replaceWith(html);
        });
    }

    function refreshNativeCart() {
        if(!document.body.classList.contains('woocommerce-cart')||document.querySelector('[data-molavi-storefront]'))return;
        nativeCartUpdating=true;updateCheckoutBusy();
        // Re-read Woo's server-rendered cart; posting the previous form could overwrite the new quantities.
        fetch(window.location.href,{credentials:'same-origin',cache:'no-store'})
            .then(function(response){if(!response.ok)throw new Error('cart');return response.text();})
            .then(function(html){
                var parsed=new DOMParser().parseFromString(html,'text/html'),fresh=parsed.querySelector('.molavi-customer-main .woocommerce'),current=document.querySelector('.molavi-customer-main .woocommerce');
                if(!fresh||!current)throw new Error('cart');current.replaceWith(document.importNode(fresh,true));
                nativeCartUpdating=false;setupNativeCartAutoUpdate();
                if(window.jQuery)jQuery(document.body).trigger('updated_wc_div');
                else document.dispatchEvent(new CustomEvent('molavi:cart'));
            })
            .catch(function(){window.location.reload();});
    }

    function setupNativeCartAutoUpdate() {
        if (!document.body.classList.contains("woocommerce-cart")) return;
        var forms = Array.prototype.slice.call(document.querySelectorAll("form.woocommerce-cart-form"));
        if (!forms.length) return;
        forms.forEach(function (form) {
            if(form._molaviAutoUpdateReady)return;form._molaviAutoUpdateReady=true;
            form.addEventListener('submit',function(){nativeCartUpdating=true;document.dispatchEvent(new CustomEvent('molavi:cart'));});
            form.addEventListener("change", function (event) {
                var input = event.target.closest("input.qty");
                if (!input || !form.contains(input)) return;
                nativeCartUpdating=true;document.dispatchEvent(new CustomEvent('molavi:cart'));
                var updateButton = form.querySelector('[name="update_cart"]');
                if (!updateButton) return;
                window.clearTimeout(form._molaviUpdateTimer);
                form._molaviUpdateTimer = window.setTimeout(function () {
                    updateButton.disabled = false;
                    if (typeof form.requestSubmit === "function") form.requestSubmit(updateButton);
                    else updateButton.click();
                }, 300);
            });
        });
        if(window.jQuery&&!document.body._molaviCartRefreshReady){
            document.body._molaviCartRefreshReady=true;
            jQuery(document.body).on('updated_wc_div wc_cart_emptied',function(){
                nativeCartUpdating=false;setupNativeCartAutoUpdate();
                document.dispatchEvent(new CustomEvent('molavi:cart'));
            });
        }
        document.body.classList.add("molavi-auto-cart-update-ready");
    }

    function stepper(qty, row) {
        var step = Math.max(1, Number(row && row.min) || 1);
        var minusDelta = -step;
        var plusDelta = step;
        var minusLabel = "کم کردن " + digits(step) + " عدد";
        var plusLabel = "افزودن " + digits(step) + " عدد";
        var disablePlus = false;
        if (qty > 0 && (qty < step || qty % step !== 0)) {
            var previousValid = Math.floor(Math.max(0, qty - 1) / step) * step;
            var nextValid = Math.min(999 - (999 % step), Math.max(step, Math.ceil(qty / step) * step));
            minusDelta = previousValid - qty;
            plusDelta = nextValid - qty;
            minusLabel = "تغییر تعداد به " + digits(previousValid) + " عدد";
            plusLabel = "تغییر تعداد به " + digits(nextValid) + " عدد";
            disablePlus = nextValid <= qty;
        }
        return '<div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="' + minusDelta + '" aria-label="' + escapeHtml(minusLabel) + '">−</button><b>'
            + digits(qty) + '</b><button type="button" data-molavi-step="' + plusDelta + '" aria-label="' + escapeHtml(plusLabel) + '"' + (disablePlus ? ' disabled' : '') + '>+</button></div>'
            + '<button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد">' + trash() + "</button></div>";
    }

    function addButton(row) {
        var min = Number(row && row.min) || 1;
        var name = row && row.name ? row.name : "محصول";
        return '<button type="button" class="molavi-add" data-molavi-step="1" aria-label="افزودن حداقل ' + digits(min) + ' عدد ' + escapeHtml(name) + ' به سبد">' + cart() + '<span>افزودن ' + digits(Number(row && row.min) || 1) + ' عدد</span></button>';
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
