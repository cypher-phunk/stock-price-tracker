// /assets/js/stock-chart.js

// edited 08-31-25

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', displayChart);
} else {
    displayChart();
}

function displayChart() {
    // If url has /stock/ displayStockChart
    if (window.location.pathname.includes('/stock/')) {
        displayStockChart();
    }
    // if url has /report/ displayReportChart
    if (window.location.pathname.includes('/report/')) {
        displayReportChart();
    }
}

function displayReportChart() {
    if (!sdpStockData) {
        console.log("No sdpStockData found.");
        return;
    }
    agCharts.LicenseManager.setLicenseKey(sdpAgChartKey);
    const chartContainer = document.getElementById('stock-chart');
    /* const startDate = sdpStockData[0].date;
    let endDate = sdpStockData[sdpStockData.length - 1].date;
    if (sdpStockData.length < 30) {
        // TODO not working right now, might need to implement dummy data... not sure
        endDate = new Date(startDate);
        endDate.setDate(endDate.getDate() + 30);
        endDate = endDate.getTime();
        console.log("End date adjusted to 30 days after start date:", endDate);
    }*/

    for (const [index, point] of sdpStockData.entries()) {
        point.date = new Date(point.date);
    }

    console.log("Report date:", sdpReportDate);
    agCharts.AgCharts.create({
        theme: 'ag-default',
        container: chartContainer,
        animation: {
            enabled: true,
        },
        data: sdpStockData,
        title: {
            text: 'Stock Report Chart',
        },
        
        series: [
            {
                type: 'line',
                xKey: 'date',
                yKey: 'price',
                yName: 'Price',
                marker: {
                    enabled: false,
                },
                stroke: 'rgb(14, 29, 41)',
                strokeWidth: 2,

                /* fill: {
                    type: 'gradient',
                    colorStops:
                    { 
                        color:rgb(255, 255, 255),
                    }
                }, */
            },
        ],
        annotations: {
            enabled: true,
            toolbar: {
                enabled: false,
            }
        },
        axes: [
            {
                type: 'time',
                position: 'bottom',
                title: { text: 'Date' },
            },
            {
                type: 'number',
                position: 'left',
                title: { text: 'Price' }
            },
        ],
        initialState: {
            annotations: [
                {
                    type: 'vertical-line',
                    value: {
                        __type: 'date',
                        value: sdpDayBeforeReport,
                    },
                    text: {
                        label: 'Close Price Before Report',
                        position: 'bottom',
                        alignment: 'center',
                    },
                    lineStyle: "dotted",
                    locked: true,
                },

                {
                    type: 'note',
                    x: {
                        __type: 'date',
                        value: sdpReportDate,
                    },
                    y: sdpReportDayPrice,
                    text: 'Day of report: ' + sdpDayBeforeReport
                }
            ],
        }
    });
}

function displayStockChart() {
    if (!sdpStockData) {
        console.log("No sdpStockData found.");
        return;
    }
    agCharts.LicenseManager.setLicenseKey(sdpAgChartKey);
    const chartContainer = document.getElementById('stock-chart');
    for (const [index, point] of sdpStockData.entries()) {
        point.date = new Date(point.date);
    }
    agCharts.AgCharts.create({
        container: chartContainer,
        animation: {
            enabled: true,
        },
        data: sdpStockData,
        title: 'Stock Price Chart',
        series: [
            {
                type: 'line',
                xKey: 'date',
                yKey: 'price',
                yName: 'Price',
                marker: {
                    enabled: false,
                },
                /* fill: {
                    type: 'gradient',
                    colorStops:
                    { 
                        color:rgb(255, 255, 255),
                    }
                }, */
            },
        ],
        axes: [
            {
                type: 'time',
                position: 'bottom',
                title: { text: 'Date' },
                parentLevel: {
                    enabled: true,
                    format: {
                        day: {
                            format: "%e",
                        },
                        month: {
                            format: "%b",
                        },
                        year: {
                            format: "%Y",
                        },
                    },
                },
            },
            {
                type: 'number',
                position: 'left',
                title: { text: 'Price' }
            },
        ],
        zoom: {
            enabled: true,
            anchorPointX: 'pointer',
            anchorPointY: 'pointer',
            autoScaling: {
                enabled: true,
            },
        },
        navigator: {
            enabled: true,
        },
    });
}