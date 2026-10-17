<?php
declare(strict_types=1);

namespace Molavi\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit;
}

class Catalog_Bar extends Widget_Base
{
    public function get_name(): string
    {
        return 'molavi-catalog-bar';
    }

    public function get_title(): string
    {
        return 'نوار فروشگاه';
    }

    public function get_icon(): string
    {
        return 'eicon-filter';
    }

    public function get_categories(): array
    {
        return ['molavi'];
    }

    public function get_keywords(): array
    {
        return ['مرتب', 'catalog', 'order', 'molavi'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('style', [
            'label' => 'ظاهر',
            'tab' => Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('color', [
            'label' => 'رنگ نوشته',
            'type' => Controls_Manager::COLOR,
            'default' => '#6F6256',
            'selectors' => ['{{WRAPPER}} .molavi-bar' => 'color: {{VALUE}};'],
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        if (!function_exists('woocommerce_catalog_ordering')) {
            return;
        }
        echo '<div class="molavi-bar">';
        woocommerce_catalog_ordering();
        woocommerce_result_count();
        echo '</div>';
    }
}
