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
        series: [{
            type: 'line',
            xKey: 'date',
            xName: 'Date',
            yKey: 'price',
            yName: 'Price'
        }],
        axes: [
            { type: 'category', position: 'bottom', title: { text: 'Date' } },
            { type: 'number', position: 'left', title: { text: 'Price' } }
        ]
    });
}