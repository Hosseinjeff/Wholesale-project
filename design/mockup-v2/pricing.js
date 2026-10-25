/* Public-price demonstration model. Supplier costs are not part of this payload. */
(function (root) {
    'use strict';
    function halfUp(value) {
        const nearest = Math.round(value * 1e8) / 1e8;
        const whole = Math.trunc(nearest);
        return nearest - whole >= 0.5 - 1e-8 ? whole + 1 : whole;
    }
    function quote(products, quantities, unlock) {
        let regularTotal = 0, eligibleTotal = 0, payTotal = 0, count = 0;
        products.forEach(p => {
            const qty = Number(quantities[p.id]) || 0;
            regularTotal += p.regular * qty;
            count += qty;
            if (p.discountable) eligibleTotal += p.regular * qty;
        });
        const progress = eligibleTotal > 0 && unlock > 0 ? Math.min(1, eligibleTotal / unlock) : 0;
        const units = {}, rates = {}, lines = [];
        products.forEach(p => {
            const qty = Number(quantities[p.id]) || 0;
            const raw = p.ceiling + (p.floor - p.ceiling) * progress;
            const unit = Math.max(Math.min(p.floor, p.ceiling), Math.min(p.regular, Math.max(p.floor, p.ceiling), halfUp(raw)));
            units[p.id] = unit;
            rates[p.id] = p.regular > 0 ? (p.regular - unit) / p.regular : 0;
            if (qty > 0) {
                payTotal += unit * qty;
                lines.push({id:p.id, qty, unit, total:unit * qty, saving:(p.regular-unit)*qty});
            }
        });
        return {progress, eligibleTotal, regularTotal, payTotal, count, units, rates, lines,
            saved:regularTotal-payTotal, rate:regularTotal ? (regularTotal-payTotal)/regularTotal : 0,
            remaining:Math.max(0, unlock-eligibleTotal)};
    }
    root.MolaviDemoPricing = {quote};
    if (typeof module !== 'undefined' && module.exports) module.exports = {quote};
})(typeof window !== 'undefined' ? window : globalThis);
