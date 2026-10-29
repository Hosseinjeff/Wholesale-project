<?php
declare(strict_types=1);

namespace Molavi\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit;
}

class Facts extends Widget_Base
{
    public function get_name(): string
    {
        return 'molavi-facts';
    }

    public function get_title(): string
    {
        return 'توضیح کالا';
    }

    public function get_icon(): string
    {
        return 'eicon-editor-list-ul';
    }

    public function get_categories(): array
    {
        return ['molavi'];
    }

    public function get_keywords(): array
    {
        return ['حداقل', 'تأمین', 'product', 'molavi'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('section', ['label' => 'متن']);
        $this->add_control('min_label', [
            'label' => 'برچسب حداقل',
            'type' => Controls_Manager::TEXT,
            'default' => 'حداقل خرید',
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style', [
            'label' => 'ظاهر',
            'tab' => Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('color', [
            'label' => 'رنگ',
            'type' => Controls_Manager::COLOR,
            'default' => '#6F6256',
            'selectors' => ['{{WRAPPER}} .molavi-facts' => 'color: {{VALUE}};'],
        ]);
        $this->add_control('size', [
            'label' => 'اندازه نوشته',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => ['px' => ['min' => 10, 'max' => 22]],
            'default' => ['size' => 12, 'unit' => 'px'],
            'selectors' => ['{{WRAPPER}} .molavi-facts' => 'font-size: {{SIZE}}{{UNIT}};'],
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $product = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
        if (!$product) {
            return;
        }
        $parts = [];
        $supplier = trim((string) $product->get_meta('_molavi_supplier'));
        if ($supplier === '') {
            $supplier = trim(wp_strip_all_tags((string) get_post_field('post_excerpt', $product->get_id())));
        }
        $packaging = trim((string) $product->get_meta('_molavi_packaging'));
        if ($packaging !== '') {
            $parts[] = $packaging;
        }
        if ($supplier !== '') {
            $parts[] = $supplier;
        }
        $min = molavi_product_min($product);
        if ($min > 1) {
            $label = trim((string) $this->get_settings_for_display('min_label'));
            if ($label === '') {
                $label = 'حداقل خرید';
            }
            $parts[] = $label . ' ' . molavi_digits((string) $min) . ' عدد';
        }
        if ($parts === []) {
            return;
        }
        echo '<p class="molavi-facts">' . esc_html(implode(' · ', $parts)) . '</p>';
    }
}
