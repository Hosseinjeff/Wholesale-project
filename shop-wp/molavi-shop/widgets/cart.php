<?php
declare(strict_types=1);

namespace Molavi\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit;
}

class Cart_Button extends Widget_Base
{
    public function get_name(): string
    {
        return 'molavi-cart';
    }

    public function get_title(): string
    {
        return 'دکمه سبد';
    }

    public function get_icon(): string
    {
        return 'eicon-cart';
    }

    public function get_categories(): array
    {
        return ['molavi'];
    }

    public function get_keywords(): array
    {
        return ['سبد', 'cart', 'add', 'molavi'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('style', [
            'label' => 'ظاهر',
            'tab' => Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('background', [
            'label' => 'پس‌زمینه',
            'type' => Controls_Manager::COLOR,
            'default' => '#E86A12',
            'selectors' => ['{{WRAPPER}} .molavi-add' => 'background: {{VALUE}};'],
        ]);
        $this->add_control('color', [
            'label' => 'رنگ نشانه',
            'type' => Controls_Manager::COLOR,
            'default' => '#FFFFFF',
            'selectors' => ['{{WRAPPER}} .molavi-add' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('size', [
            'label' => 'اندازه',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => ['px' => ['min' => 28, 'max' => 64]],
            'default' => ['size' => 36, 'unit' => 'px'],
            'selectors' => [
                '{{WRAPPER}} .molavi-add' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
            ],
        ]);
        $this->add_control('radius', [
            'label' => 'گردی گوشه',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => ['px' => ['min' => 0, 'max' => 24]],
            'default' => ['size' => 10, 'unit' => 'px'],
            'selectors' => ['{{WRAPPER}} .molavi-add' => 'border-radius: {{SIZE}}{{UNIT}};'],
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $product = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
        if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
            return;
        }
        $min = molavi_product_min($product);
        $qty = molavi_cart_qty($product->get_id());
        $class = 'molavi-control' . ($qty > 0 ? ' is-in' : '');
        echo '<div class="' . esc_attr($class) . '" data-molavi-cart data-product="' . esc_attr((string) $product->get_id()) . '" data-min="' . esc_attr((string) $min) . '" data-qty="' . esc_attr((string) $qty) . '">';
        if ($qty > 0) {
            echo '<div class="molavi-actions"><div class="molavi-step"><button type="button" data-molavi-step="-1" aria-label="کم کردن">−</button><b>' . esc_html(molavi_digits((string) $qty)) . '</b><button type="button" data-molavi-step="1" aria-label="افزودن">+</button></div>';
            echo '<button type="button" class="molavi-trash" data-molavi-clear aria-label="حذف از سبد"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14"/><path d="M9.5 7V5.4A1.4 1.4 0 0 1 10.9 4h2.2a1.4 1.4 0 0 1 1.4 1.4V7"/><path d="M7.2 7.4l.7 11a1.2 1.2 0 0 0 1.2 1.1h5.8a1.2 1.2 0 0 0 1.2-1.1l.7-11"/><path d="M10.2 11v5"/><path d="M13.8 11v5"/></svg></button></div>';
        } else {
            echo '<button type="button" class="molavi-add" data-molavi-step="1" aria-label="افزودن به سبد"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6h1.6l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H8"/><circle cx="10" cy="19" r="1.2"/><circle cx="17" cy="19" r="1.2"/></svg></button>';
        }
        echo '</div>';
    }
}
