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
            'default' => 'تا',
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
        $regular = (float) $product->get_regular_price();
        $current = (float) $product->get_price();
        if ($regular <= 0 || $current <= 0 || $current >= $regular) {
            return;
        }
        $percent = (int) round((($regular - $current) / $regular) * 100);
        $prefix = trim((string) $this->get_settings_for_display('prefix'));
        $label = trim($prefix . ' ' . molavi_digits((string) $percent) . '٪');
        echo '<span class="molavi-deal" dir="rtl">' . esc_html($label) . '</span>';
    }
}
