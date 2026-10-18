<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function molavi_render_basket(array $options = []): void
{
    $title = trim((string) ($options['title'] ?? '')) ?: 'سبد خرید';
    $saveLabel = trim((string) ($options['save_label'] ?? '')) ?: 'تخفیف‌های شما';
    $totalLabel = trim((string) ($options['total_label'] ?? '')) ?: 'مبلغ کل سبد خرید';
    $button = trim((string) ($options['button'] ?? '')) ?: 'تکمیل فرایند خرید';
    $progress = function_exists('molavi_cart_progress') ? molavi_cart_progress() : 0.0;
    $map = function_exists('molavi_catalog_map') ? molavi_catalog_map() : [];
    $lines = [];
    $saved = 0;
    $total = 0;
    if (function_exists('WC') && WC()->cart) {
        foreach (WC()->cart->get_cart() as $item) {
            $id = (string) $item['product_id'];
            $row = $map[$id] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $qty = (int) $item['quantity'];
            $unit = molavi_unit_price($row, $progress);
            $shelf = (int) $row['shelf'];
            $saved += $shelf > $unit ? ($shelf - $unit) * $qty : 0;
            $total += $unit * $qty;
            $lines[] = ['id' => $id, 'row' => $row, 'qty' => $qty, 'unit' => $unit, 'shelf' => $shelf];
        }
    }
    $count = count($lines);
    echo '<aside class="molavi-basket" data-molavi-basket>';
    echo '<div class="molavi-basket-head"><h2>' . esc_html($title) . '</h2><span data-molavi-basket-count>' . esc_html($count > 0 ? '(' . molavi_digits((string) $count) . ' محصول)' : '') . '</span>';
    echo '<button type="button" class="molavi-cart-close" data-molavi-cart-close aria-label="بستن">×</button></div>';
    echo '<div class="molavi-basket-lines" data-molavi-basket-lines>';
    if ($lines === []) {
        echo '<p class="molavi-basket-empty">سبد خالی است.</p>';
    }
    foreach ($lines as $line) {
        echo molavi_basket_line_html($line);
    }
    echo '</div>';
    echo '<div class="molavi-basket-save"' . ($saved > 0 ? '' : ' hidden') . ' data-molavi-basket-save><span>' . esc_html($saveLabel) . '</span><b data-molavi-basket-save-amount>' . esc_html(molavi_toman($saved)) . '</b></div>';
    echo '<div class="molavi-basket-total"><span>' . esc_html($totalLabel) . '</span><strong data-molavi-basket-total>' . esc_html(molavi_toman($total)) . '</strong></div>';
    echo '<a class="molavi-checkout" data-molavi-checkout href="' . esc_url(function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '#') . '"' . ($count > 0 ? '' : ' hidden') . '>' . molavi_basket_lock_svg() . '<span>' . esc_html($button) . '</span></a>';
    echo '<ul class="molavi-trust"><li>' . molavi_basket_shield_svg() . '<span>تضمین کیفیت</span></li><li>' . molavi_basket_lock_svg() . '<span>پرداخت امن</span></li><li>' . molavi_basket_truck_svg() . '<span>ارسال سریع</span></li></ul>';
    echo '</aside>';
}

function molavi_basket_line_html(array $line): string
{
    $row = $line['row'];
    $image = (string) ($row['image'] ?? '');
    $rate = $line['shelf'] > $line['unit'] && $line['shelf'] > 0
        ? (int) round((($line['shelf'] - $line['unit']) / $line['shelf']) * 100)
        : 0;
    $html = '<article class="molavi-bline' . ($image !== '' ? ' has-photo' : '') . '" data-product="' . esc_attr((string) $line['id']) . '" data-min="' . esc_attr((string) $row['min']) . '" data-qty="' . esc_attr((string) $line['qty']) . '">';
    if ($image !== '') {
        $html .= '<img src="' . esc_url($image) . '" alt="">';
    }
    $html .= '<div class="molavi-bline-copy"><h3>' . esc_html((string) $row['name']) . '</h3><p>';
    if ($rate > 0) {
        $html .= '<span class="molavi-rate" dir="ltr">−' . esc_html(molavi_digits((string) $rate)) . '٪</span> ';
    }
    $html .= '<strong>' . esc_html(molavi_toman((int) $line['unit'])) . '</strong>';
    if ($line['shelf'] > $line['unit']) {
        $html .= ' <s>' . esc_html(molavi_toman((int) $line['shelf'])) . '</s>';
    }
    $html .= '</p></div>';
    $html .= '<div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-1" aria-label="کم کردن">−</button><b>' . esc_html(molavi_digits((string) $line['qty'])) . '</b><button type="button" data-molavi-step="1" aria-label="افزودن">+</button></div>';
    $html .= '<button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد">' . molavi_basket_trash_svg() . '</button></div>';
    $html .= '</article>';
    return $html;
}

function molavi_basket_trash_svg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" stroke="none" d="M4.4 6.15h15.2c.5 0 .9.4.9.9s-.4.9-.9.9H4.4c-.5 0-.9-.4-.9-.9s.4-.9.9-.9z"/><path fill="currentColor" stroke="none" d="M10 3.3h4c.55 0 1 .4 1 .95V6.1h-6V4.25c0-.55.45-.95 1-.95z"/><path fill="currentColor" stroke="none" d="M6.35 9.2h11.3c.25 0 .45.22.43.48l-.78 10.05c-.08.85-.8 1.5-1.65 1.5H8.35c-.85 0-1.57-.65-1.65-1.5L5.92 9.68c-.02-.26.18-.48.43-.48z"/></svg>';
}

function molavi_basket_lock_svg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>';
}

function molavi_basket_shield_svg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v6c0 4.2-2.8 7.2-7 9-4.2-1.8-7-4.8-7-9V6l7-3z"></path></svg>';
}

function molavi_basket_truck_svg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"></path><circle cx="7" cy="17.5" r="1.4"></circle><circle cx="17" cy="17.5" r="1.4"></circle></svg>';
}
