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
        molavi_render_basket([
            'title' => (string) ($settings['title'] ?? ''),
            'save_label' => (string) ($settings['save_label'] ?? ''),
            'total_label' => (string) ($settings['total_label'] ?? ''),
            'button' => (string) ($settings['button'] ?? ''),
        ]);
    }
}
