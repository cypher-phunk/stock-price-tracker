// /assets/js/stock-chart.js

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', displayStockChart);
} else {
    displayStockChart();
}

function displayStockChart() {
    if (!sdpStockData) {
        console.log("No sdpStockData found.");
        return;
    }

    const chartContainer = document.getElementById('stock-chart');
    agCharts.AgCharts.create({
        container: chartContainer,
        data: sdpStockData,
        title: 'Stock Price Chart',
        series: [
            {
                type: 'area',
                xKey: 'date',
                xName: 'Date',
                yKey: 'price',
                yName: 'Price',
                marker: {
                    enabled: false,
                },
                interpolation: {
                    type: 'smooth'
                },
                fill: {
                    type: 'gradient',
                    colorStops:
                    { 
                        color:rgb(255, 255, 255),
                    }
                },
            },
        ],
        axes: [
            {
                type: 'unit-time',
                position: 'bottom',
                title: { text: 'Date' },
                label: {
                    spacing: 8,
                    format: {
                        day: "%e",
                        month: "%b",
                    },
                },
                parentLevel: {
                    enabled: true,
                    tick: {
                        width: 1,
                        size: 4,
                    },
                    label: {
                        spacing: 4,
                        format: {
                            month: "%e\n%b",
                            year: "%b\n%Y",
                        },
                    },
                },
            },
            {
                type: 'number',
                position: 'left',
                title: { text: 'Price' }
            }
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