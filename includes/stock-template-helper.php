<?php
add_action('wp_footer', 'sdp_inject_stock_information');

function sdp_inject_stock_information()
{
  if (!is_singular('stock')) return;

  global $wpdb;

  // Get symbol from the current post (adjust if you're using ACF or custom field)
  $symbol = get_field('ticker_symbol'); // adjust this if needed

  if (!$symbol) return;

  $ticker_id = $wpdb->get_var($wpdb->prepare("
        SELECT id FROM {$wpdb->prefix}stock_tickers WHERE symbol = %s LIMIT 1
    ", $symbol));
  if (!$ticker_id) return;

  $row = $wpdb->get_row($wpdb->prepare("
        SELECT latest_close, percent_change, updated_at
        FROM {$wpdb->prefix}stock_metrics
        WHERE ticker_id = %d
    ", $ticker_id));

  if (!$row) return;

  $formatted_price = '$' . number_format($row->latest_close, 2);
  $formatted_change = number_format($row->percent_change, 2) . '%';
  $updated_at_ts = strtotime($row->updated_at);
  $timeAgo = human_time_diff($updated_at_ts, current_time('timestamp')) . ' ago';
?>

  <script>
    document.addEventListener("DOMContentLoaded", function() {
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
      while (walker.nextNode()) {
        const node = walker.currentNode;
        if (node.nodeValue.includes("{stock_price}")) {
          node.nodeValue = node.nodeValue.replace("{stock_price}", "<?= esc_js($formatted_price) ?>");
        }
        if (node.nodeValue.includes("{stock_percent_change}")) {
          console.log("Replacing percent change text");
          const span = document.createElement("span");
          span.textContent = "<?= esc_js($formatted_change) ?>";
          span.style.color = <?= $row->percent_change >= 0 ? '"green"' : '"red"' ?>;
          node.parentNode.replaceChild(span, node);
        }
        if (node.nodeValue.includes("{stock_last_updated}")) {
          console.log("Replacing last updated text");
          const replacedText = node.nodeValue.replace("{stock_last_updated}", "<?= esc_js($timeAgo) ?>");
          const newTextNode = document.createTextNode(replacedText);
          node.parentNode.replaceChild(newTextNode, node);
        }
      }
    });
  </script>

<?php
}
