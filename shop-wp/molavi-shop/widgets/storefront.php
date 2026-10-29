<?php
declare(strict_types=1);

namespace Molavi\Elementor;

use Elementor\Widget_Base;

if (!defined('ABSPATH')) {
    exit;
}

class Storefront extends Widget_Base
{
    public function get_name(): string
    {
        return 'molavi-storefront';
    }

    public function get_title(): string
    {
        return 'فروشگاه مولوی‌سل';
    }

    public function get_icon(): string
    {
        return 'eicon-products';
    }

    public function get_categories(): array
    {
        return ['molavi'];
    }

    public function get_keywords(): array
    {
        return ['فروشگاه', 'کالا', 'سبد', 'storefront', 'molavi'];
    }

    protected function render(): void
    {
        molavi_render_storefront();
    }
}
