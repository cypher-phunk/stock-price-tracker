jQuery(document).ready(function($) {
    $('#ticker-search').on('input', function() {
        const query = $(this).val();

        if (query.length < 2) {
            $('#ticker-suggestions').empty();
            return;
        }

        $.ajax({
            url: ajaxurl,
            method: 'GET',
            data: {
                action: 'search_market_tickers',
                term: query
            },
            success: function(response) {
                $('#ticker-suggestions').empty();
                if (response.length) {
                    response.forEach(ticker => {
                        $('#ticker-suggestions').append(`
                            <div class="ticker-option" data-symbol="${ticker.symbol}">
                                ${ticker.symbol} — ${ticker.name}
                            </div>
                        `);
                    });
                } else {
                    $('#ticker-suggestions').html('<div>No matches found.</div>');
                }
            }
        });
    });

    // Optional: handle clicking to add
    $('#ticker-suggestions').on('click', '.ticker-option', function() {
        const symbol = $(this).data('symbol');
        // Trigger backend to add symbol to stock_tickers (you can also do this via AJAX)
        alert(`You selected: ${symbol}`);
    });
});
