// public/js/charts.js
function renderMiniChart(canvasId, klineData, tradingSymbol) {
    const canvasElement = document.getElementById(canvasId);
    if (!canvasElement) {
        console.error(`Canvas element with ID '${canvasId}' not found.`);
        // Provide visual feedback if canvas is not found
        const parentDiv = document.querySelector(`#${canvasId}`).parentElement;
        if (parentDiv) {
            parentDiv.innerHTML = `<div class="text-red-400 text-center py-4">Error: Chart canvas element not found!</div>`;
        }
        return;
    }

    // Filter for only 'close' prices and labels (timestamps)
    // klineData is an array of arrays: [openTime, open, high, low, close, ...]
    const labels = klineData.map(k => new Date(k[0]).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
    const data = klineData.map(k => parseFloat(k[4]));

    //console.log('renderMiniChart: klineData received:', klineData);
    //console.log('renderMiniChart: Processed labels:', labels);
    //console.log('renderMiniChart: Processed data:', data);

    if (data.length === 0 || labels.length === 0) {
        console.warn('renderMiniChart: No valid data or labels to render chart.');
        // Provide visual feedback if data is empty
        const parentDiv = canvasElement.parentElement;
        if (parentDiv) {
            parentDiv.innerHTML = `<div class="text-yellow-400 text-center py-4">No chart data available.</div>`;
        }
        return;
    }

    // Determine color based on last price change
    const lastPrice = data[data.length - 1]; // data.length is guaranteed > 0 here
    const firstPrice = data[0];
    const lineColor = lastPrice >= firstPrice ? '#34D399' : '#EF4444'; // Green or Red

    if (window.miniChartInstance) {
        window.miniChartInstance.destroy(); // Destroy previous instance if it exists
    }

    window.miniChartInstance = new Chart(canvasElement, { // Use canvasElement instead of ctx
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: `${tradingSymbol} Price`,
                data: data,
                borderColor: lineColor,
                backgroundColor: 'transparent',
                borderWidth: 1.5,
                pointRadius: 0, // No points
                tension: 0.1,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            // Removed all other complex options for simplification
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false } // Disable tooltips for simplicity
            },
            scales: {
                x: { display: false },
                y: { display: false }
            }
        }
    });
}
