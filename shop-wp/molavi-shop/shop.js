(function () {
    if (!window.molaviShop) return;

    var catalog = molaviShop.catalog || {};
    var qtys = Object.assign({}, molaviShop.qtys || {});
    var serverQtys = Object.assign({}, molaviShop.qtys || {});
    var lastQuote = molaviShop.initial || { units: {}, qtys: serverQtys, progress: 0, unlock: molaviShop.unlock || 0 };
    var buyerType = molaviShop.buyerType === "shop" ? "shop" : "household";
    var sending = false;
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
        if (buyerChanging) {
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
        if (buyerChanging) {
            event.preventDefault();
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
            if (Number(product.discountable) === 1 && Number(product.shelf) > 0) eligibleReference += Number(product.shelf) * qty;
            var householdUnit = Math.max(1, Number(product.householdUnit) || 1);
            if (qty < householdUnit || qty % householdUnit !== 0) householdValid = false;
        });
        var householdTarget = Number(lastQuote.household_unlock) || 5000000;
        var shopTarget = Number(lastQuote.shop_unlock) || householdTarget * 4;
        if (buyerType === "household" && eligibleReference > householdTarget) return { buyer_type: "shop", reason: "household_threshold_exceeded" };
        if (buyerType === "shop" && eligibleReference < shopTarget && householdValid) {
            return { buyer_type: "household", reason: "household_order" };
        }
        return null;
    }

    function updateBuyerControls() {
        document.querySelectorAll("[data-molavi-buyer]").forEach(function (button) {
            button.setAttribute("aria-pressed", button.getAttribute("data-molavi-buyer") === buyerType ? "true" : "false");
        });
        document.querySelectorAll("[data-molavi-buyer-current]").forEach(function (node) {
            node.textContent = buyerType === "shop" ? "فروشگاه" : "خانوار";
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
            var household = Number(lastQuote.household_unlock) || 5000000;
            var shop = Number(lastQuote.shop_unlock) || household * 4;
            node.textContent = "با انتخاب نوع خرید، قیمت‌ها و گام سفارش هر کالا تغییر می‌کند؛ تعداد سبد خودکار تغییر نمی‌کند. اگر تعداد با گام تازه سازگار نباشد، پیش از ادامه آن را خودتان اصلاح کنید. آستانه تخفیف خانوار "
                + toman(household) + " و فروشگاه " + toman(shop)
                + " است؛ هزینه ارسال جداگانه محاسبه می‌شود.";
        });
    }

    function showBuyerFeedback(quote) {
        var invalid = Array.isArray(quote && quote.invalid_lines) ? quote.invalid_lines : [];
        var switchSuggestion = quote && quote.switch_suggestion;
        var storeRoot = document.querySelector("[data-molavi-storefront]");
        var basket = document.querySelector("#molavi-basket-dialog");
        var cartVisible = basket && (!storeRoot || window.matchMedia("(min-width: 901px)").matches
            || basket.classList.contains("is-open"));
        var feedbackNodes = document.querySelectorAll("[data-molavi-buyer-feedback]");
        feedbackNodes.forEach(function (node) {
            node.replaceChildren();
            node.hidden = true;
        });
        var globalFeedback = cartVisible && basket
            ? basket.querySelector("[data-molavi-buyer-feedback]")
            : storeRoot && storeRoot.querySelector(".molavi-product-area [data-molavi-buyer-feedback]");
        if (!globalFeedback) globalFeedback = feedbackNodes[0];
        if (globalFeedback) {
            if (switchSuggestion && switchSuggestion.buyer_type) {
                globalFeedback.appendChild(document.createTextNode(
                    switchSuggestion.buyer_type === "shop"
                        ? "برای این سبد، خرید فروشگاهی پیشنهاد می‌شود. "
                        : "برای این سبد، خرید خانوار پیشنهاد می‌شود. "
                ));
                var switchButton = document.createElement("button");
                switchButton.type = "button";
                switchButton.className = "molavi-buyer-suggestion";
                switchButton.setAttribute("data-molavi-buyer", switchSuggestion.buyer_type);
                switchButton.textContent = switchSuggestion.buyer_type === "shop"
                    ? "انتخاب فروشگاه" : "انتخاب خانوار";
                globalFeedback.appendChild(switchButton);
            }
            if (invalid.length) {
                globalFeedback.appendChild(document.createTextNode(
                    "تعداد سبد حفظ شده است؛ برای ادامه، تعداد " + digits(invalid.length) + " کالا را با گام جدید هماهنگ کنید."
                ));
            }
            globalFeedback.hidden = !globalFeedback.childNodes.length;
        }
    }

    function chooseBuyer(requested) {
        if (!molaviShop.buyer || requested === buyerType || buyerChanging) return;
        if (sending) {
            window.setTimeout(function () { chooseBuyer(requested); }, 80);
            return;
        }
        var previous = buyerType;
        var requestId = ++buyerRequestId;
        buyerChanging = true;
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
                var unitText = "گام سفارش: " + digits(row.min) + " عدد";
                if (orderUnits && orderUnits.textContent !== unitText) orderUnits.textContent = unitText;
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

    function updateCardFacts(card, qty, row) {
        if (!card || !row) return;
        var step = Math.max(1, Number(row.min) || 1);
        var invalid = qty > 0 && (qty < step || qty % step !== 0);
        var orderUnits = card.querySelector("[data-molavi-order-units]");
        var category = card.querySelector(".molavi-card-category");
        var warning = card.querySelector("[data-molavi-card-step-warning]");
        var warningText = card.querySelector("[data-molavi-card-step-warning-text]");
        if (orderUnits) {
            orderUnits.textContent = "گام سفارش: " + digits(step) + " عدد";
            orderUnits.title = "خانوار " + digits(row.householdUnit) + " عدد؛ فروشگاه " + digits(row.shopUnit) + " عدد";
            orderUnits.hidden = invalid;
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
        if (qty < 1 && !inCart) return;
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
            var invalidLine = Array.isArray(quote && quote.invalid_lines)
                ? quote.invalid_lines.find(function (line) { return String(line.product_id) === String(id); }) : null;
            var stepMarkup = '<small class="molavi-order-step">گام سفارش: ' + digits(min) + ' عدد</small>';
            if (invalidLine) {
                stepMarkup += '<small class="molavi-buyer-line-warning">تعداد فعلی با این گام هماهنگ نیست.</small>'
                    + '<button type="button" class="molavi-line-update" data-molavi-set-qty="'
                    + Number(invalidLine.needed_quantity) + '">تغییر به ' + digits(invalidLine.needed_quantity) + ' عدد</button>';
            }
            html += '<article class="molavi-bline' + (invalidLine ? ' is-invalid' : '') + '" data-product="' + escapeHtml(id) + '" data-min="' + min + '" data-qty="' + qty + '" data-invalid="' + (invalidLine ? '1' : '0') + '">'
                + '<div class="molavi-bline-image"><img src="' + escapeHtml(row.image || "") + '" alt="' + escapeHtml(row.name || "") + '" loading="lazy"></div>'
                + '<div class="molavi-bline-copy"><h3>' + escapeHtml(row.name || "") + "</h3><p><strong>"
                + toman(unit) + "</strong> × " + digits(qty) + "</p>" + stepMarkup
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
        }
        var totalNode = document.querySelector("[data-molavi-basket-total]");
        if (totalNode) totalNode.textContent = toman(total);
        var countNode = document.querySelector("[data-molavi-basket-count]");
        if (countNode) countNode.textContent = kinds > 0 ? "(" + digits(kinds) + " محصول)" : "";
        var checkout = document.querySelector("[data-molavi-checkout]");
        var mobileBasketTrigger = document.querySelector(".molavi-mobile-basket[data-molavi-open-basket]");
        if (mobileBasketTrigger) mobileBasketTrigger.hidden = kinds < 1;
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
        setText("[data-molavi-mobile-count]", digits(Object.keys(counts).reduce(function (sum, id) { return sum + (Number(counts[id]) || 0); }, 0)));
        var progress = Math.max(0, Math.min(1, Number(quote && quote.progress) || 0));
        var progressPct = Math.round(progress * 100);
        var progressBar = document.querySelector("[data-molavi-progressbar]");
        var progressFill = document.querySelector("[data-molavi-progress-fill]");
        if (progressBar) progressBar.setAttribute("aria-valuenow", String(progressPct));
        if (progressFill) progressFill.style.width = progressPct + "%";
        var progressTitle = document.querySelector("[data-molavi-progress-title]");
        var remainingNode = document.querySelector("[data-molavi-remaining]");
        if (progressTitle) progressTitle.textContent = progress >= 1 ? "سقف تخفیف تکمیل شده" : "پیشرفت تخفیف";
        if (remainingNode) remainingNode.textContent = progress >= 1
            ? "سقف تخفیف " + (buyerType === "shop" ? "فروشگاه" : "خانوار") + " فعال است."
            : "تا سقف " + (buyerType === "shop" ? "فروشگاه" : "خانوار") + ": " + toman(Number(quote && quote.remaining) || 0);
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
        var favoriteView = document.querySelector("[data-molavi-wishlist-view]");
        var viewButtons = root.querySelectorAll("[data-molavi-view]");
        var selectedCategory = "";
        var selectedSort = "newest";
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
            var term = query.toLocaleLowerCase();
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
            if (searchClose) searchClose.addEventListener("click", function () { setToolbarSearchOpen(false, true); });
            toolbarSearch.addEventListener("keydown", function (event) {
                if (event.key === "Escape" && !toolbarSearch.hidden) {
                    event.preventDefault();
                    setToolbarSearchOpen(false, true);
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
        sortCards();
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

    function setupMobileBasket() {
        var root = document.querySelector("[data-molavi-storefront]");
        if (!root) return;
        var triggers = Array.prototype.slice.call(document.querySelectorAll("[data-molavi-open-basket]"));
        var dialog = root.querySelector("#molavi-basket-dialog");
        var backdrop = root.querySelector(".molavi-basket-backdrop");
        if (!triggers.length || !dialog || !backdrop) return;
        var mobileQuery = window.matchMedia("(max-width: 900px)");
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
            showBuyerFeedback(localQuote());
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
            showBuyerFeedback(localQuote());
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
            if (window.matchMedia("(min-width: 901px)").matches) {
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

    function setupCardStepHelp() {
        var selector = ".molavi-card-step-help";
        var activeHelp = null;
        var activeNodes = null;
        var activeOverlay = null;
        var activePlaceholder = null;
        var activeDialogAttributes = null;
        var nextHelpId = 0;

        function parts(details) {
            if (!details) return null;
            var summary = details.querySelector(":scope > summary");
            var panel = details.querySelector(".molavi-card-step-popover");
            if (summary && panel) {
                if (!panel.id) {
                    do {
                        nextHelpId += 1;
                        panel.id = "molavi-step-help-" + nextHelpId;
                    } while (document.getElementById(panel.id) !== panel);
                }
                summary.setAttribute("aria-controls", panel.id);
                if (!summary.hasAttribute("aria-expanded")) {
                    summary.setAttribute("aria-expanded", details.open ? "true" : "false");
                }
            }
            return summary && panel ? { summary: summary, panel: panel } : null;
        }

        function closeHelp(details, restoreFocus) {
            details = details || activeHelp;
            if (!details) return;
            var isActive = activeHelp === details;
            var nodes = isActive && activeNodes ? activeNodes : parts(details);

            if (isActive && nodes) {
                if (activePlaceholder && activePlaceholder.parentNode) {
                    var parent = activePlaceholder.parentNode;
                    parent.insertBefore(nodes.panel, activePlaceholder);
                    parent.removeChild(activePlaceholder);
                } else if (!nodes.panel.parentNode) {
                    details.appendChild(nodes.panel);
                }
                if (activeOverlay && activeOverlay.parentNode) {
                    activeOverlay.parentNode.removeChild(activeOverlay);
                }
                nodes.panel.removeAttribute("data-molavi-positioned");
                ["role", "aria-modal", "aria-label", "tabindex"].forEach(function (name) {
                    var value = activeDialogAttributes && activeDialogAttributes[name];
                    if (value === null || value === undefined) nodes.panel.removeAttribute(name);
                    else nodes.panel.setAttribute(name, value);
                });
                activeNodes = null;
                activeOverlay = null;
                activePlaceholder = null;
                activeDialogAttributes = null;
            }

            details.open = false;
            if (nodes) {
                nodes.summary.setAttribute("aria-expanded", "false");
            }
            if (isActive) activeHelp = null;
            if (restoreFocus && nodes) nodes.summary.focus();
        }

        function openHelp(details) {
            if (activeHelp && activeHelp !== details) closeHelp(activeHelp);
            var nodes = parts(details);
            if (!nodes || !document.body) return;

            activeHelp = details;
            activeNodes = nodes;
            activePlaceholder = document.createComment("molavi-step-help-panel");
            nodes.panel.parentNode.insertBefore(activePlaceholder, nodes.panel);

            activeDialogAttributes = {
                role: nodes.panel.getAttribute("role"),
                "aria-modal": nodes.panel.getAttribute("aria-modal"),
                "aria-label": nodes.panel.getAttribute("aria-label"),
                tabindex: nodes.panel.getAttribute("tabindex")
            };
            activeOverlay = document.createElement("div");
            activeOverlay.className = "molavi-card-step-overlay";
            nodes.panel.setAttribute("role", "dialog");
            nodes.panel.setAttribute("aria-modal", "true");
            nodes.panel.setAttribute("aria-label", "راهنمای گام سفارش");
            nodes.panel.setAttribute("tabindex", "-1");
            activeOverlay.appendChild(nodes.panel);
            document.body.appendChild(activeOverlay);
            nodes.summary.setAttribute("aria-expanded", "true");

            window.requestAnimationFrame(function () {
                if (activeHelp === details && details.open) nodes.panel.focus();
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll(selector), function (details) {
            parts(details);
        });

        document.addEventListener("pointerdown", function (event) {
            if (!activeHelp) return;
            var target = event.target && event.target.nodeType === 1 ? event.target : event.target.parentElement;
            var clickedSummary = target && target.closest
                ? target.closest(".molavi-card-step-help > summary") : null;
            if (!clickedSummary || clickedSummary.parentElement !== activeHelp) closeHelp(activeHelp);
        }, true);

        document.addEventListener("click", function (event) {
            var target = event.target && event.target.nodeType === 1 ? event.target : event.target.parentElement;
            var summary = target && target.closest
                ? target.closest(".molavi-card-step-help > summary") : null;
            if (!summary) {
                if (activeHelp) closeHelp(activeHelp);
                return;
            }
            var details = summary.parentElement;
            window.requestAnimationFrame(function () {
                if (details.open) openHelp(details);
                else closeHelp(details);
            });
        }, true);

        document.addEventListener("keydown", function (event) {
            if (!activeHelp) return;
            if (event.key === "Escape") {
                event.preventDefault();
                closeHelp(activeHelp, true);
            } else if (event.key === "Tab") {
                closeHelp(activeHelp, true);
            }
        }, true);
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
        setupCardStepHelp();
        setupNativeCartAutoUpdate();
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

    function setupNativeCartAutoUpdate() {
        if (!document.body.classList.contains("woocommerce-cart")) return;
        var forms = Array.prototype.slice.call(document.querySelectorAll("form.woocommerce-cart-form"));
        if (!forms.length) return;
        forms.forEach(function (form) {
            form.addEventListener("change", function (event) {
                var input = event.target.closest("input.qty");
                if (!input || !form.contains(input)) return;
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
        return '<button type="button" class="molavi-add" data-molavi-step="1" aria-label="افزودن حداقل ' + digits(min) + ' عدد ' + escapeHtml(name) + ' به سبد">' + cart() + '<span>افزودن</span></button>';
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
