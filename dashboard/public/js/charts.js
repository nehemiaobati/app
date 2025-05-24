// public/js/charts.js
function renderMiniChart(canvasId, klineData, tradingSymbol) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) {
        console.error(`Canvas element with ID '${canvasId}' not found.`);
        return;
    }

    // Filter for only 'close' prices and labels (timestamps)
    const labels = klineData.map(k => new Date(k.openTime).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
    const data = klineData.map(k => parseFloat(k.close));

    // Determine color based on last price change
    const lastPrice = data[data.length - 1];
    const firstPrice = data[0];
    const lineColor = lastPrice >= firstPrice ? '#34D399' : '#EF4444'; // Green or Red

    if (window.miniChartInstance) {
        window.miniChartInstance.destroy(); // Destroy previous instance if it exists
    }

    window.miniChartInstance = new Chart(ctx, {
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
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        title: (tooltipItems) => {
                            return `Time: ${tooltipItems[0].label}`;
                        },
                        label: (tooltipItem) => {
                            return `Close: ${tooltipItem.raw}`;
                        }
                    }
                },
            },
            scales: {
                x: {
                    display: false, // Hide x-axis
                    grid: { display: false },
                    ticks: { display: false }
                },
                y: {
                    display: false, // Hide y-axis
                    grid: { display: false },
                    ticks: { display: false }
                }
            },
            elements: {
                line: {
                    borderJoinStyle: 'round'
                }
            },
            hover: {
                mode: 'nearest',
                intersect: true
            }
        }
    });
}