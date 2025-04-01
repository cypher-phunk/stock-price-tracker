jQuery(document).ready(function($) {
    let debounceTimer;

    $('#ticker-search').on('input', function() {
        const query = $(this).val();

        if (query.length < 2) {
            $('#results').empty();
            return;
        }

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'search_market_tickers',
                    search_term: query
                },
                success: function(response) {
                    if (response.success) {
                        let output = '<table><thead><tr><th>Symbol</th></tr></thead><tbody>';
                        response.data.forEach(row => {
                            output += `<tr><td>${row.symbol}</td></tr>`;
                        });
                        output += '</tbody></table>';
                        $('#results').html(output);
                    } else {
                        $('#results').html('<p>No results found.</p>');
                    }
                }
            });
        }, 300);
    });
    $(document).on('click', '#check-tickers', function() {
        const input = $('#bulk-tickers').val();
        const tickers = input.split(',').map(t => t.trim().toUpperCase()).filter(t => t);

        if (tickers.length === 0) {
            $('#check-results').html('<p>Please enter at least one symbol.</p>');
            return;
        }

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'check_stock_tickers',
                tickers: tickers
            },
            success: function(response) {
                if (response.success) {
                    let html = '';
                    
                    if (response.data.existing.length > 0) {
                        html += '<p><strong>Already Tracked:</strong> ' + response.data.existing.join(', ') + '</p>';
                    }
                    if (response.data.invalid.length > 0) {
                        html += '<p style="color: red;"><strong>Invalid Tickers:</strong> ' + response.data.invalid.join(', ') + '</p>';
                    }
                    if (response.data.missing.length > 0) {
                        html += '<p style="color: green;"><strong>Missing Tickers 1:</strong> ' + response.data.missing.join(', ') + '</p>';
                        html += '<button id="add-tickers" class="button">Add Missing Tickers</button>';
                        $('#check-results').data('to-add', response.data.missing);
                    }

                    $('#check-results').html(html);
                }
            }
        });
    });

    $(document).on('click', '#add-tickers', function() {
        const toAdd = $('#check-results').data('to-add');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'add_stock_tickers',
                tickers: toAdd
            },
            success: function(response) {
                if (response.success) {
                    let html = '';
                    
                    if (response.data.existing && response.data.existing.length > 0) {
                        html += '<p><strong>Already Tracked:</strong> ' + response.data.existing.join(', ') + '</p>';
                    }
                    if (response.data.missing && response.data.missing.length > 0) {
                        html += '<p><strong>Can Be Added:</strong> ' + response.data.missing.join(', ') + '</p>';
                        html += '<button id="add-tickers" class="button">Add Missing Tickers</button>';
                        $('#check-results').data('to-add', response.data.missing);
                    }
                    if (response.data.invalid && response.data.invalid.length > 0) {
                        html += '<p style="color: red;"><strong>Invalid Tickers (not found in database):</strong> ' + response.data.invalid.join(', ') + '</p>';
                    }
                
                    $('#check-results').html(html);
                    // Reload the page
                    loadTrackedTickers();
                }
            }
        });
    });
});

function loadTrackedTickers(page = 1, perPage = 25) {
    jQuery.ajax({
        url: ajaxurl,
        method: 'POST',
        data: {
            action: 'get_stock_tickers_paginated',
            page: page,
            per_page: perPage
        },
        success: function(response) {
            if (response.success) {
                const tbody = jQuery('#tracked-ticker-table tbody');
                tbody.empty();

                response.data.tickers.forEach(symbol => {
                    tbody.append(`
                        <tr>
                            <td>${symbol}</td>
                            <td><button class="remove-ticker button" data-symbol="${symbol}">Remove</button></td>
                        </tr>
                    `);
                });

                // Render pagination
                const pagination = jQuery('#pagination-controls');
                pagination.empty();
                for (let i = 1; i <= response.data.total_pages; i++) {
                    const btn = `<button class="page-btn button${i === page ? ' active' : ''}" data-page="${i}">${i}</button>`;
                    pagination.append(btn);
                }
            }
        }
    });
}

// Event: click remove
jQuery(document).on('click', '.remove-ticker', function () {
    const symbol = jQuery(this).data('symbol');
    jQuery.ajax({
        url: ajaxurl,
        method: 'POST',
        data: {
            action: 'remove_stock_ticker',
            symbol: symbol
        },
        success: function (response) {
            if (response.success) {
                loadTrackedTickers(); // reload after remove
            }
        }
    });
});

// Event: change page
jQuery(document).on('click', '.page-btn', function () {
    const page = parseInt(jQuery(this).data('page'));
    loadTrackedTickers(page);
});

// Initial load
jQuery(document).ready(function () {
    loadTrackedTickers();
});