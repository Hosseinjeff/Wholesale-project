<?php
declare(strict_types=1);

namespace Molavi\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit;
}

class Price extends Widget_Base
{
    public function get_name(): string
    {
        return 'molavi-price';
    }

    public function get_title(): string
    {
        return 'قیمت سبد';
    }

    public function get_icon(): string
    {
        return 'eicon-product-price';
    }

    public function get_categories(): array
    {
        return ['molavi'];
    }

    public function get_keywords(): array
    {
        return ['قیمت', 'price', 'تخفیف', 'molavi'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('style', [
            'label' => 'ظاهر',
            'tab' => Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('amount_color', [
            'label' => 'رنگ قیمت',
            'type' => Controls_Manager::COLOR,
            'default' => '#2B241E',
            'selectors' => ['{{WRAPPER}} .molavi-amount' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('was_color', [
            'label' => 'رنگ قیمت مصرف',
            'type' => Controls_Manager::COLOR,
            'default' => '#157A3A',
            'selectors' => ['{{WRAPPER}} .molavi-was' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('rate_color', [
            'label' => 'رنگ درصد',
            'type' => Controls_Manager::COLOR,
            'default' => '#B4234C',
            'selectors' => ['{{WRAPPER}} .molavi-rate' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('rate_background', [
            'label' => 'زمینه درصد',
            'type' => Controls_Manager::COLOR,
            'default' => '#FFD4E0',
            'selectors' => ['{{WRAPPER}} .molavi-rate' => 'background: {{VALUE}};'],
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $product = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
        if (!$product) {
            return;
        }
        $curve = molavi_curve($product);
        $unit = molavi_unit_price($curve, molavi_cart_progress());
        $shelf = (int) $curve['shelf'];
        $rate = $shelf > $unit ? ($shelf - $unit) / $shelf : 0.0;
        $image = (string) (wp_get_attachment_image_url((int) $product->get_image_id(), 'woocommerce_thumbnail') ?: '');
        echo '<div class="molavi-offer" data-molavi-price data-product="' . esc_attr((string) $product->get_id()) . '" data-name="' . esc_attr($product->get_name()) . '" data-image="' . esc_attr($image) . '" data-discountable="' . ($curve['discountable'] ? '1' : '0') . '" data-shelf="' . esc_attr((string) $shelf) . '" data-floor="' . esc_attr((string) $curve['floor']) . '" data-ceiling="' . esc_attr((string) $curve['ceiling']) . '" data-sale="' . esc_attr((string) $curve['sale']) . '" data-unit="' . esc_attr((string) $unit) . '">';
        echo '<p class="molavi-now">';
        echo '<span class="molavi-rate" dir="ltr"' . ($rate > 0 ? '' : ' hidden') . '>' . esc_html('−' . molavi_percent($rate)) . '</span>';
        echo '<span class="molavi-amount">' . esc_html(molavi_toman($unit)) . '</span>';
        echo '</p>';
        echo '<p class="molavi-sub"><span class="molavi-was"' . ($shelf > $unit ? '' : ' hidden') . '>' . esc_html(molavi_toman($shelf)) . '</span></p>';
        echo '</div>';
    }
}
