<?php

namespace Bricks\Elements;

use Bricks\Element;

if (!defined('ABSPATH')) exit;

class StockPerformanceChart extends Element
{
  public $category = 'custom';
  public $name = 'stock-performance-chart';
  public $label = 'Stock Performance Chart';
  public $icon = 'ti-bar-chart'; // Optional icon

  public function get_label() {
    return esc_html__('Stock Performance Chart', 'bricks');
  }

  public function render() {
    return '<div style="padding: 20px; background: #ffefef; border: 2px solid red;">✅ This is the stock chart module.</div>';
  }
  
}
