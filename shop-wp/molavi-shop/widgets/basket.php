<?php
declare(strict_types=1);

namespace Molavi\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit;
}

class Basket extends Widget_Base
{
    public function get_name(): string
    {
        return 'molavi-basket';
    }

    public function get_title(): string
    {
        return 'سبد کناری';
    }

    public function get_icon(): string
    {
        return 'eicon-cart-medium';
    }

    public function get_categories(): array
    {
        return ['molavi'];
    }

    public function get_keywords(): array
    {
        return ['سبد', 'cart', 'molavi'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('section', ['label' => 'متن‌ها']);
        $this->add_control('title', [
            'label' => 'عنوان',
            'type' => Controls_Manager::TEXT,
            'default' => 'سبد خرید',
        ]);
        $this->add_control('save_label', [
            'label' => 'برچسب تخفیف',
            'type' => Controls_Manager::TEXT,
            'default' => 'تخفیف‌های شما',
        ]);
        $this->add_control('total_label', [
            'label' => 'برچسب جمع',
            'type' => Controls_Manager::TEXT,
            'default' => 'مبلغ کل سبد خرید',
        ]);
        $this->add_control('button', [
            'label' => 'دکمه',
            'type' => Controls_Manager::TEXT,
            'default' => 'تکمیل فرایند خرید',
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style', [
            'label' => 'ظاهر',
            'tab' => Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('button_background', [
            'label' => 'رنگ دکمه',
            'type' => Controls_Manager::COLOR,
            'default' => '#E86A12',
            'selectors' => ['{{WRAPPER}} .molavi-checkout' => 'background: {{VALUE}};'],
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $title = trim((string) ($settings['title'] ?? '')) ?: 'سبد خرید';
        $saveLabel = trim((string) ($settings['save_label'] ?? '')) ?: 'تخفیف‌های شما';
        $totalLabel = trim((string) ($settings['total_label'] ?? '')) ?: 'مبلغ کل سبد خرید';
        $button = trim((string) ($settings['button'] ?? '')) ?: 'تکمیل فرایند خرید';
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
        $shelfTotal = 0;
        foreach ($lines as $line) {
            $shelfTotal += (int) $line['shelf'] * (int) $line['qty'];
        }
        $cartRate = $shelfTotal > 0 ? $saved / $shelfTotal : 0;
        echo '<aside class="molavi-basket" data-molavi-basket>';
        echo '<div class="molavi-basket-head"><h2>' . esc_html($title) . '</h2><span data-molavi-basket-count>' . esc_html($count > 0 ? '(' . molavi_digits((string) $count) . ' محصول)' : '') . '</span></div>';
        echo '<div class="molavi-basket-lines" data-molavi-basket-lines>';
        if ($lines === []) {
            echo '<p class="molavi-basket-empty">سبد خالی است.</p>';
        }
        foreach ($lines as $line) {
            $row = $line['row'];
            echo '<article class="molavi-bline" data-product="' . esc_attr($line['id']) . '" data-min="' . esc_attr((string) $row['min']) . '" data-qty="' . esc_attr((string) $line['qty']) . '">';
            $lineRate = $line['shelf'] > $line['unit'] ? ($line['shelf'] - $line['unit']) / $line['shelf'] : 0;
            echo '<div class="molavi-bline-copy"><h3>' . esc_html((string) $row['name']) . '</h3>';
            echo '<p>';
            if ($lineRate > 0) {
                echo '<span class="molavi-rate" dir="ltr">' . esc_html('−' . molavi_percent($lineRate)) . '</span> ';
            }
            echo '<strong data-n="' . (int) $line['unit'] . '">' . esc_html(molavi_toman((int) $line['unit'])) . '</strong>';
            if ($line['shelf'] > $line['unit']) {
                echo ' <s>' . esc_html(molavi_toman((int) $line['shelf'])) . '</s>';
            }
            echo '</p></div>';
            echo '<div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-1" aria-label="کم کردن">−</button><b data-n="' . (int) $line['qty'] . '">' . esc_html(molavi_digits((string) $line['qty'])) . '</b><button type="button" data-molavi-step="1" aria-label="افزودن">+</button></div>';
            echo '<button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد">' . molavi_basket_trash_svg() . '</button></div>';
            echo '</article>';
        }
        echo '</div>';
        echo '<div class="molavi-basket-save"' . ($saved > 0 ? '' : ' hidden') . ' data-molavi-basket-save><span>' . esc_html($saveLabel) . '</span><b class="molavi-rate" data-molavi-basket-save-rate>' . esc_html(molavi_percent($cartRate)) . '</b><b data-molavi-basket-save-amount>' . esc_html(molavi_toman($saved)) . '</b></div>';
        echo '<div class="molavi-basket-total"><span>' . esc_html($totalLabel) . '</span><strong data-molavi-basket-total>' . esc_html(molavi_toman($total)) . '</strong></div>';
        echo '<a class="molavi-checkout" data-molavi-checkout href="' . esc_url(function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '#') . '"' . ($count > 0 ? '' : ' hidden') . '>' . molavi_basket_lock_svg() . '<span>' . esc_html($button) . '</span></a>';
        echo '<ul class="molavi-trust"><li>' . molavi_basket_shield_svg() . '<span>تضمین کیفیت</span></li><li>' . molavi_basket_lock_svg() . '<span>پرداخت امن</span></li><li>' . molavi_basket_truck_svg() . '<span>ارسال سریع</span></li></ul>';
        echo '</aside>';
    }
}

function molavi_basket_trash_svg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14"/><path d="M9.5 7V5.4A1.4 1.4 0 0 1 10.9 4h2.2a1.4 1.4 0 0 1 1.4 1.4V7"/><path d="M7.2 7.4l.7 11a1.2 1.2 0 0 0 1.2 1.1h5.8a1.2 1.2 0 0 0 1.2-1.1l.7-11"/><path d="M10.2 11v5"/><path d="M13.8 11v5"/></svg>';
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
