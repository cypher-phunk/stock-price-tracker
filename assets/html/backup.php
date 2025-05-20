<script>
  jQuery(document).ready(function($) {
    $('#reportTable').DataTable({
      pageLength: 10,
      order: [
        [3, 'desc']
      ]
    });
  });
</script>

<div class="report-table">
  <div class="table-header">
    <div>Researcher</div>
    <div>Company</div>
    <div>Ticker</div>
    <div>Exchange</div>
    <div>Date</div>
  </div>

  <?php
  $args = array(
    'post_type' => 'report',
    'posts_per_page' => 100,
    'orderby' => 'meta_value',
    'meta_key' => 'report_date',
    'order' => 'DESC',
  );
  $query = new WP_Query($args);
  if ($query->have_posts()) :
    while ($query->have_posts()) : $query->the_post();
      // Get ACF Fields
      $researcher = get_field('research_company');
      $ticker = get_field('symbol');
      $exchange = get_field('exchange');
      $report_date = get_field('report_date');
  ?>
      <div class="table-row">
        <div class="company-posts">
          <?php
          $researcher = get_field('research_company');
          if ($researcher) {
            // If it returns a WP_Post object
            if (isset($researcher[0]) && $researcher[0] instanceof WP_Post) {
              echo '<a href="' . esc_url(get_permalink($researcher[0])) . '">'
                . esc_html($researcher[0]->post_title) .
                '</a>';
            }
            // If it returns an ID
            elseif (is_numeric($researcher)) {
              echo esc_html(get_the_title((int) $researcher));
            }
          } else {
            echo '—';
          }
          ?>
        </div>
        <div>
          <?php
          $symbol = get_field('symbol');
          if ($researcher) {
            // If it returns a WP_Post object
            if (isset($symbol[0]) && $symbol[0] instanceof WP_Post) {
              $company_name = get_field('company_name', $symbol[0]->ID);
              echo '<a href="' . esc_url(get_permalink($symbol[0])) . '">'
                . esc_html($company_name) .
                '</a>';
            }
            // If it returns an ID
            elseif (is_numeric($symbol)) {
              echo esc_html(get_the_title((int) $symbol));
            }
          } else {
            echo '—';
          }
          ?></div>
        <div>
          <?php
          $symbol = get_field('symbol');
          if ($researcher) {
            // If it returns a WP_Post object
            if (isset($symbol[0]) && $symbol[0] instanceof WP_Post) {
              echo '<a href="' . esc_url(get_permalink($symbol[0])) . '">'
                . esc_html($symbol[0]->post_title) .
                '</a>';
            }
            // If it returns an ID
            elseif (is_numeric($symbol)) {
              echo esc_html(get_the_title((int) $symbol));
            }
          } else {
            echo '—';
          }
          ?>
        </div>
        <div><?php
              $stock = get_field('symbol'); // or your actual field name
              if ($stock instanceof WP_Post) {
                $exchange = get_field('stock_exchange', $stock->ID);
                echo esc_html($exchange);
              } elseif (is_array($stock) && isset($stock[0]) && $stock[0] instanceof WP_Post) {
                $exchange = get_field('stock_exchange', $stock[0]->ID);
                if ($exchange === "") {
                  $exchange = "NYSE";
                }
                echo esc_html($exchange);
              } else {
                echo '—';
              }

              ?></div>
        <div><?php echo esc_html(date('m/d/Y', strtotime($report_date))); ?></div>
    <?php endwhile;
    wp_reset_postdata();
  endif; ?>
      </div>
</div>