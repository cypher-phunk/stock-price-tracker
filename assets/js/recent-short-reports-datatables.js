(function ($) {
    function initRecentShortReports() {
        console.log('[SDP] initRecentShortReports: start');
        const tableEl = document.getElementById('recent-short-reports');
        if (!tableEl) {
            console.warn('[SDP] #recent-short-reports not found in DOM.');
            return;
        }

        const cfg = (typeof sdpRecentShortReportsDt !== 'undefined') ? sdpRecentShortReportsDt : null;
        if (!cfg || !cfg.ajaxUrl) {
            console.warn('[SDP] sdpRecentShortReportsDt is not defined.');
            return;
        }

        const options = {
            processing: true,
            pageLength: 10,
            ajax: {
                url: cfg.ajaxUrl,
                type: 'POST',
                data: function (d) {
                    d.action = 'sdp_recent_short_reports_dt';
                    d.nonce = cfg.nonce;
                    return d;
                }
            },
            order: [[3, 'desc']],
            columns: [
                {
                    // researcher name
                    data: 0,
                    render: function (data, type, row) {
                        return '<a href="' + row[7] + '" target="_blank" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;"><img src="' + row[8] + '" alt="' + data + '" loading="lazy" style="width: 50px; height: 50px; object-fit: contain; border-radius: 50%;" /><span>' + data + '</span></a>';
                    }
                },
                { 
                    // company name
                    data: 1,
                    render: function (data, type, row) {
                        return '<a href="' + row[9] + '" target="_blank">' + data + '</a>';
                    }
                },
                { 
                    // ticker
                    data: 2,
                    render: function (data, type, row) {
                        return '<a href="' + row[9] + '" target="_blank">' + data + '</a>';
                    }
                },
                { 
                    // report date
                    data: 3 
                },
                { 
                    // price at report
                    data: 4 
                },
                {
                    // percent return
                    data: 5,
                    render: function (data, type, row) {
                        const value = parseFloat(data);
                        const color = value > 0 ? 'red' : 'green';
                        return `<span style="color: ${color};">${data}%</span>`;
                    }
                },
                {
                    // report url
                    data: 6,
                    visible: false
                },
                {
                    // researcher url
                    data: 7,
                    visible: false
                },
                {
                    // researcher image
                    data: 8,
                    visible: false
                },
                {
                    // ticker url
                    data: 9,
                    visible: false
                }
            ],
            createdRow: function (row, data, dataIndex) {
                // Link the row to the report URL
                $(row).click(function() {
                    window.open(data[6], '_blank');
                });
                // Add cursor pointer to indicate clickable row
                $(row).css('cursor', 'pointer');
            },
            search: false,
            info: false,
            lengthChange: false
        };

        // DataTables v2 (global constructor) vs legacy jQuery plugin form.
        if (typeof DataTable !== 'undefined') {
            console.log('[SDP] Initializing DataTables via global DataTable');
            DataTable.datetime('MM/DD/YY')
            new DataTable(tableEl, options);
            return;
        }

        if ($ && $.fn && typeof $.fn.DataTable === 'function') {
            console.log('[SDP] Initializing DataTables via jQuery plugin');
            $(tableEl).DataTable(options);
            return;
        }

        console.warn('[SDP] DataTables is not available (no DataTable global and no $.fn.DataTable).');
        return;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRecentShortReports);
    } else {
        initRecentShortReports();
    }
})(jQuery);
