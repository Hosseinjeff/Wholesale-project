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

    public function get_script_depends(): array
    {
        return ['wc-add-to-cart'];
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
        $url = add_query_arg('quantity', $min, $product->add_to_cart_url());
        echo '<a href="' . esc_url($url) . '" data-quantity="' . esc_attr((string) $min) . '" data-product_id="' . esc_attr((string) $product->get_id()) . '" data-product_sku="' . esc_attr($product->get_sku()) . '" class="button product_type_simple add_to_cart_button ajax_add_to_cart molavi-add" aria-label="افزودن به سبد" rel="nofollow">';
        echo '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6h1.6l1.4 8.2a1 1 0 0 0 1 .8h7.8a1 1 0 0 0 1-.8L20 8H8"/><circle cx="10" cy="19" r="1.2"/><circle cx="17" cy="19" r="1.2"/></svg>';
        echo '</a>';
    }
}
