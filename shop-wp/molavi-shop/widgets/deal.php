<?php
declare(strict_types=1);

namespace Molavi\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit;
}

class Deal extends Widget_Base
{
    public function get_name(): string
    {
        return 'molavi-deal';
    }

    public function get_title(): string
    {
        return 'نشان تخفیف';
    }

    public function get_icon(): string
    {
        return 'eicon-price-tag';
    }

    public function get_categories(): array
    {
        return ['molavi'];
    }

    public function get_keywords(): array
    {
        return ['تخفیف', 'sale', 'badge', 'molavi'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('section', ['label' => 'نشان']);
        $this->add_control('prefix', [
            'label' => 'متن',
            'type' => Controls_Manager::TEXT,
            'default' => 'تخفیف',
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style', [
            'label' => 'ظاهر',
            'tab' => Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('color', [
            'label' => 'رنگ نوشته',
            'type' => Controls_Manager::COLOR,
            'default' => '#FFFFFF',
            'selectors' => ['{{WRAPPER}} .molavi-deal' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('background', [
            'label' => 'پس‌زمینه',
            'type' => Controls_Manager::COLOR,
            'default' => '#E23D66',
            'selectors' => ['{{WRAPPER}} .molavi-deal' => 'background: {{VALUE}};'],
        ]);
        $this->add_control('radius', [
            'label' => 'گردی گوشه',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => ['px' => ['min' => 0, 'max' => 40]],
            'default' => ['size' => 999, 'unit' => 'px'],
            'selectors' => ['{{WRAPPER}} .molavi-deal' => 'border-radius: {{SIZE}}{{UNIT}};'],
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
        $profile = molavi_product_profile($product);
        $household = molavi_profile_curve($curve, $profile, 'household');
        $shop = molavi_profile_curve($curve, $profile, 'shop');
        $current = molavi_profile_curve($curve, $profile, molavi_buyer_type());
        $prefix = trim((string) $this->get_settings_for_display('prefix'));
        if ($prefix === '' || $prefix === 'تخفیف') {
            $prefix = 'تا';
        }
        $attributes = ' data-molavi-profile-deal data-product="' . esc_attr((string) $product->get_id())
            . ' data-prefix="' . esc_attr($prefix)
            . '" data-household-max="' . esc_attr((string) $household['max'])
            . '" data-shop-max="' . esc_attr((string) $shop['max']) . '"';
        if (($curve['max'] ?? 0) <= 0) {
            echo '<span class="molavi-deal molavi-deal-fixed"' . $attributes . '>قیمت ثابت</span>';
            return;
        }
        if ($prefix === 'تا') {
            echo '<span class="molavi-deal" dir="rtl"' . $attributes . '>' . esc_html('تا ' . molavi_percent((float) $current['max']) . ' تخفیف') . '</span>';
            return;
        }
        if ($prefix === '') {
            $prefix = 'تخفیف';
        }
        echo '<span class="molavi-deal" dir="rtl"' . $attributes . '>' . esc_html(molavi_percent((float) $current['max']) . ' ' . $prefix) . '</span>';
    }
}
