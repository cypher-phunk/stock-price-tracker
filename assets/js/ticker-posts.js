jQuery(document).ready(function($) {
  $('#add-ticker-posts').on('click', function() {
      const button = $(this);
      button.prop('disabled', true).text('Fetching...');

      $('#fetch-status').text('');

      $.ajax({
          url: ajaxurl,
          method: 'POST',
          data: {
              action: 'fetch_all_company_info'
          },
          success: function(response) {
              if (response.success) {
                  $('#fetch-status').html(`<p><strong>Done!</strong> Processed ${response.data.total} tickers.</p>`);
              } else {
                  $('#fetch-status').html('<p style="color:red;">Something went wrong.</p>');
              }
              button.prop('disabled', false).text('Fetch Company Info for All Tickers');
          },
          error: function() {
              $('#fetch-status').html('<p style="color:red;">Server error.</p>');
              button.prop('disabled', false).text('Fetch Company Info for All Tickers');
          }
      });
  });
});
