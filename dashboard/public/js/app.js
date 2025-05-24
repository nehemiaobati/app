// public/js/app.js
document.addEventListener('DOMContentLoaded', async () => {
    // Check if user is logged in
    try {
        await apiClient.get('bot/status'); // A simple authenticated endpoint to test session
        // If successful, continue to load dashboard
        loadDashboard();
    } catch (error) {
        if (error.status === 401) {
            window.location.href = '/login.html';
        } else {
            console.error("Failed to verify session:", error);
            // Optionally show a generic error message or redirect
            document.getElementById('botStatusMessage').textContent = 'Error: Failed to connect to API.';
            document.getElementById('botStatusMessage').classList.add('text-red-400');
        }
    }
});

let dashboardPollingInterval; // To store the interval ID
let botConfig = {}; // To store the bot's current configuration

async function loadDashboard() {
    await fetchDashboardData();
    dashboardPollingInterval = setInterval(fetchDashboardData, 5000); // Poll every 5 seconds
    setupEventListeners();
}

async function fetchDashboardData() {
    try {
        const [
            botStatus,
            performanceSummary,
            recentOrders,
            aiLogs,
            currentPositionData, // Includes live price and position info
            botConfiguration, // Bot's static config
            user // Fetch user data
        ] = await Promise.all([
            apiClient.get('bot/status'),
            apiClient.get('data/performance'),
            apiClient.get('data/orders'),
            apiClient.get('data/ai_logs'),
            apiClient.get('data/position'),
            apiClient.get('bot/config'),
            apiClient.get('user') // New API call for user data
        ]);

        botConfig = botConfiguration; // Store latest config

        updateBotStatusUI(botStatus);
        updatePerformanceSummaryUI(performanceSummary);
        renderRecentTrades(recentOrders);
        renderAiLogs(aiLogs);
        updateCurrentPositionUI(currentPositionData);
        updateBotConfigForm(botConfiguration);
        updateUserUI(user); // Update UI with user data

    } catch (error) {
        console.error("Error fetching dashboard data:", error);
        // Display a general error or specific error messages on UI
        const statusElement = document.getElementById('botStatusMessage');
        statusElement.textContent = `Error: ${error.message || 'Failed to load data.'}`;
        statusElement.classList.add('text-red-400');
    }
}

function updateUserUI(userData) {
    const usernameElement = document.getElementById('loggedInUsername');
    if (usernameElement && userData && userData.username) {
        usernameElement.textContent = userData.username;
    }
}

function updateBotStatusUI(statusData) {
    const statusElement = document.getElementById('botStatus');
    const uptimeElement = document.getElementById('botUptime');
    const lastHeartbeatElement = document.getElementById('botLastHeartbeat');
    const statusMessageElement = document.getElementById('botStatusMessage');

    statusElement.textContent = statusData.status;
    statusElement.className = 'text-2xl font-bold leading-tight'; // Reset classes
    if (statusData.status === 'running') {
        statusElement.classList.add('text-emerald-400');
    } else if (statusData.status === 'stopped' || statusData.status === 'initializing') {
        statusElement.classList.add('text-yellow-400');
    } else if (statusData.status === 'error' || statusData.status === 'shutdown') {
        statusElement.classList.add('text-red-400');
    } else {
        statusElement.classList.add('text-slate-400');
    }

    uptimeElement.textContent = statusData.process_id ? "Running" : "N/A"; // Simplified: assume if PID exists, it's 'up'
    lastHeartbeatElement.textContent = statusData.last_heartbeat ? new Date(statusData.last_heartbeat).toLocaleString() : 'N/A';
    statusMessageElement.textContent = statusData.error_message || '';
    statusMessageElement.classList.toggle('text-red-400', !!statusData.error_message);

    // Update bot control buttons
    const startBtn = document.getElementById('startBotBtn');
    const stopBtn = document.getElementById('stopBotBtn');
    if (startBtn && stopBtn) {
        startBtn.disabled = statusData.status === 'running' || statusData.status === 'initializing';
        stopBtn.disabled = statusData.status === 'stopped' || statusData.status === 'shutdown';
    }
}

function updatePerformanceSummaryUI(summary) {
    document.getElementById('totalProfit').textContent = `$${parseFloat(summary.total_pnl).toFixed(2)}`;
    // Simplistic color for profit
    if (summary.total_pnl > 0) document.getElementById('totalProfit').classList.add('text-emerald-400');
    else if (summary.total_pnl < 0) document.getElementById('totalProfit').classList.add('text-red-400');
    else document.getElementById('totalProfit').classList.add('text-slate-100');

    document.getElementById('tradesExecuted').textContent = summary.total_trades;
    document.getElementById('winRate').textContent = `${parseFloat(summary.win_rate).toFixed(2)}%`;
    document.getElementById('lastTradeAgo').textContent = summary.last_trade_ago || 'No trades yet';
}

function renderRecentTrades(orders) {
    const tableBody = document.getElementById('recentTradesTableBody');
    tableBody.innerHTML = ''; // Clear existing rows

    if (!orders || orders.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="6" class="px-4 py-3 text-center text-slate-400">No recent trades.</td></tr>';
        return;
    }

    orders.forEach(order => {
        const row = document.createElement('tr');
        const pnlClass = order.realized_pnl_usdt > 0 ? 'text-emerald-400' : (order.realized_pnl_usdt < 0 ? 'text-red-400' : 'text-slate-300');
        const sideClass = order.side === 'BUY' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400';
        const pnlText = order.realized_pnl_usdt ? `$${parseFloat(order.realized_pnl_usdt).toFixed(2)}` : 'N/A';

        row.innerHTML = `
            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-slate-100">${order.symbol}</td>
            <td class="px-4 py-3 whitespace-nowrap text-sm">
                <span class="px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${sideClass}">${order.side}</span>
            </td>
            <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-300">${parseFloat(order.quantity_involved).toFixed(4)}</td>
            <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-300">$${parseFloat(order.price_point).toFixed(2)}</td>
            <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-300">${new Date(order.bot_event_timestamp_utc + 'Z').toLocaleString()}</td>
            <td class="px-4 py-3 whitespace-nowrap text-sm ${pnlClass}">${pnlText}</td>
        `;
        tableBody.appendChild(row);
    });
}

function renderAiLogs(logs) {
    const container = document.getElementById('aiLogsContainer');
    container.innerHTML = ''; // Clear existing

    if (!logs || logs.length === 0) {
        container.innerHTML = '<p class="text-slate-400 text-center py-4">No recent AI interactions.</p>';
        return;
    }

    logs.forEach(log => {
        const logDiv = document.createElement('div');
        logDiv.className = 'flex flex-col gap-2 rounded-lg p-4 bg-slate-800 border border-slate-700 shadow cursor-pointer ai-log-entry';
        logDiv.dataset.fullLog = JSON.stringify(log); // Store full log for modal

        const statusClass = log.executed_action_by_bot.startsWith('ERROR') ? 'text-red-400' :
                            log.executed_action_by_bot.startsWith('WARN') ? 'text-yellow-400' : 'text-emerald-400';

        const aiDecisionSummary = log.ai_decision_params ?
            (log.ai_decision_params.action === 'OPEN_POSITION' ?
                `Open ${log.ai_decision_params.side} @ ${parseFloat(log.ai_decision_params.entryPrice).toFixed(2)} SL:${parseFloat(log.ai_decision_params.stopLossPrice).toFixed(2)} TP:${parseFloat(log.ai_decision_params.takeProfitPrice).toFixed(2)}` :
                log.ai_decision_params.action) :
            'N/A';

        logDiv.innerHTML = `
            <p class="text-slate-300 text-xs">${new Date(log.log_timestamp_utc + 'Z').toLocaleString()}</p>
            <p class="text-slate-100 text-sm font-medium ${statusClass}">${log.executed_action_by_bot}</p>
            <p class="text-slate-400 text-sm">${log.bot_feedback?.message || 'No specific feedback.'}</p>
            <p class="text-slate-500 text-xs italic">AI Decision: ${aiDecisionSummary}</p>
        `;
        container.appendChild(logDiv);
    });

    // Add event listeners for modal
    document.querySelectorAll('.ai-log-entry').forEach(entry => {
        entry.addEventListener('click', function() {
            const fullLog = JSON.parse(this.dataset.fullLog);
            showAiLogModal(fullLog);
        });
    });
}

function showAiLogModal(log) {
    const modal = document.getElementById('aiLogModal');
    if (!modal) {
        console.error("AI Log Modal element not found!");
        return;
    }

    document.getElementById('modalTimestamp').textContent = new Date(log.log_timestamp_utc + 'Z').toLocaleString();
    document.getElementById('modalAction').textContent = log.executed_action_by_bot;
    document.getElementById('modalBotFeedback').textContent = JSON.stringify(log.bot_feedback, null, 2);
    document.getElementById('modalAiDecision').textContent = JSON.stringify(log.ai_decision_params, null, 2);
    // You would fetch full_data_for_ai_json and raw_ai_response_json via a separate API call if needed,
    // as they are large and not included in getAiLogs by default.
    // For now, just show placeholders.
    document.getElementById('modalFullDataPlaceholder').textContent = 'Full data for AI not loaded from this API endpoint. Requires dedicated fetch.';
    document.getElementById('modalRawResponsePlaceholder').textContent = 'Raw AI response not loaded from this API endpoint. Requires dedicated fetch.';


    modal.classList.remove('hidden');
    modal.classList.add('flex'); // Make it flex to center
}

function hideAiLogModal() {
    const modal = document.getElementById('aiLogModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function updateCurrentPositionUI(positionData) {
    const positionSection = document.getElementById('currentPositionSection');
    const tradingSymbolElement = document.getElementById('tradingSymbolDisplay');
    const currentPriceElement = document.getElementById('currentMarketPrice');

    // Update global symbol and current price
    tradingSymbolElement.textContent = positionData.trading_symbol || 'N/A';
    currentPriceElement.textContent = positionData.current_market_price ? `$${parseFloat(positionData.current_market_price).toFixed(2)}` : 'N/A';

    // Update mini chart (assuming klineData is available, though DataController doesn't provide it here)
    // For this to work, you'd need DataController.getCurrentPosition to also fetch recent klines.
    // For now, we'll just show the market price.
    // If you integrate a live price stream or fetch klines for the chart, this part updates.
    // Example: if (positionData.historical_klines && positionData.trading_symbol) {
    //     renderMiniChart('miniPriceChart', positionData.historical_klines, positionData.trading_symbol);
    // }

    if (!positionData.current_position_details) {
        positionSection.innerHTML = `
            <h2 class="text-slate-100 text-xl md:text-2xl font-semibold leading-tight tracking-[-0.015em] mb-4">Current Position</h2>
            <div class="bg-slate-800 rounded-lg p-6 text-center text-slate-400">
                No active position.
            </div>
        `;
        document.getElementById('criticalAlertContainer').classList.add('hidden');
        return;
    }

    const pos = positionData.current_position_details;
    const pnlClass = pos.unrealizedPnl > 0 ? 'text-emerald-400' : (pos.unrealizedPnl < 0 ? 'text-red-400' : 'text-slate-100');
    const sideClass = pos.side === 'LONG' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400';

    // Assume current bot status is passed or fetched separately to check for missing protective orders
    // For simplicity, let's assume if SL/TP are null, they're missing
    const isMissingSL = !pos.activeSlOrderId; // Bot's internal activeSlOrderId
    const isMissingTP = !pos.activeTpOrderId; // Bot's internal activeTpOrderId
    const criticalAlertHidden = !isMissingSL && !isMissingTP;
    document.getElementById('criticalAlertContainer').classList.toggle('hidden', criticalAlertHidden);
    document.getElementById('criticalAlertMessage').textContent = 'CRITICAL: Position open without ' + (isMissingSL && isMissingTP ? 'SL and TP orders!' : (isMissingSL ? 'SL order!' : 'TP order!'));


    positionSection.innerHTML = `
        <h2 class="text-slate-100 text-xl md:text-2xl font-semibold leading-tight tracking-[-0.015em] mb-4">Current Position</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-slate-800 rounded-lg p-6 shadow-lg border border-slate-700">
            <div>
                <p class="text-slate-300 text-base font-medium">Symbol</p>
                <p class="text-slate-100 text-2xl font-bold">${pos.symbol}</p>
            </div>
            <div>
                <p class="text-slate-300 text-base font-medium">Side</p>
                <span class="px-2.5 py-1 inline-flex text-xl leading-5 font-semibold rounded-full ${sideClass}">${pos.side}</span>
            </div>
            <div>
                <p class="text-slate-300 text-base font-medium">Quantity</p>
                <p class="text-slate-100 text-2xl font-bold">${parseFloat(pos.quantity).toFixed(4)}</p>
            </div>
            <div>
                <p class="text-slate-300 text-base font-medium">Entry Price</p>
                <p class="text-slate-100 text-2xl font-bold">$${parseFloat(pos.entryPrice).toFixed(2)}</p>
            </div>
            <div>
                <p class="text-slate-300 text-base font-medium">Mark Price</p>
                <p class="text-slate-100 text-2xl font-bold">$${parseFloat(pos.markPrice).toFixed(2)}</p>
            </div>
            <div>
                <p class="text-slate-300 text-base font-medium">Unrealized PnL</p>
                <p class="text-2xl font-bold ${pnlClass}">$${parseFloat(pos.unrealizedPnl).toFixed(2)}</p>
            </div>
            <div>
                <p class="text-slate-300 text-base font-medium">Leverage</p>
                <p class="text-slate-100 text-2xl font-bold">${pos.leverage}x</p>
            </div>
            <div>
                <p class="text-slate-300 text-base font-medium">Initial Margin</p>
                <p class="text-slate-100 text-2xl font-bold">$${parseFloat(pos.initialMargin).toFixed(2)}</p>
            </div>
            <!-- SL/TP details - AI suggested values, needs to pull from bot config or bot state -->
            <div>
                <p class="text-slate-300 text-base font-medium">Stop Loss Price</p>
                <p class="text-slate-100 text-2xl font-bold">${pos.aiSuggestedSlPrice ? '$' + parseFloat(pos.aiSuggestedSlPrice).toFixed(2) : 'N/A'} ${isMissingSL ? '<span class="text-red-500 text-xs">(Missing!)</span>' : ''}</p>
            </div>
            <div>
                <p class="text-slate-300 text-base font-medium">Take Profit Price</p>
                <p class="text-slate-100 text-2xl font-bold">${pos.aiSuggestedTpPrice ? '$' + parseFloat(pos.aiSuggestedTpPrice).toFixed(2) : 'N/A'} ${isMissingTP ? '<span class="text-red-500 text-xs">(Missing!)</span>' : ''}</p>
            </div>
        </div>
    `;
}

function updateBotConfigForm(config) {
    document.getElementById('configId').value = config.id;
    document.getElementById('configName').value = config.name;
    document.getElementById('tradingSymbolInput').value = config.symbol;
    document.getElementById('klineIntervalInput').value = config.kline_interval;
    document.getElementById('marginAssetInput').value = config.margin_asset;
    document.getElementById('defaultLeverageInput').value = config.default_leverage;
    document.getElementById('orderCheckIntervalInput').value = config.order_check_interval_seconds;
    document.getElementById('aiUpdateIntervalInput').value = config.ai_update_interval_seconds;
    document.getElementById('useTestnetInput').checked = config.use_testnet;
    document.getElementById('initialMarginTargetInput').value = parseFloat(config.initial_margin_target_usdt).toFixed(2);
    document.getElementById('takeProfitTargetInput').value = parseFloat(config.take_profit_target_usdt).toFixed(2);
    document.getElementById('pendingOrderTimeoutInput').value = config.pending_entry_order_cancel_timeout_seconds;
}

function setupEventListeners() {
    document.getElementById('logoutBtn').addEventListener('click', async () => {
        try {
            await apiClient.post('logout');
            window.location.href = '/login.html';
        } catch (error) {
            console.error('Logout failed:', error);
            alert('Logout failed. Please try again.');
        }
    });

    document.getElementById('startBotBtn').addEventListener('click', async () => {
        try {
            const response = await apiClient.post('bot/start');
            alert(response.message);
            fetchDashboardData(); // Refresh data to show new status
        } catch (error) {
            alert(`Failed to start bot: ${error.data?.error || error.message}`);
        }
    });

    document.getElementById('stopBotBtn').addEventListener('click', async () => {
        try {
            const response = await apiClient.post('bot/stop');
            alert(response.message);
            fetchDashboardData(); // Refresh data
        } catch (error) {
            alert(`Failed to stop bot: ${error.data?.error || error.message}`);
        }
    });

    document.getElementById('botConfigForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const configData = {
            name: form.configName.value,
            symbol: form.tradingSymbolInput.value,
            kline_interval: form.klineIntervalInput.value,
            margin_asset: form.marginAssetInput.value,
            default_leverage: parseInt(form.defaultLeverageInput.value),
            order_check_interval_seconds: parseInt(form.orderCheckIntervalInput.value),
            ai_update_interval_seconds: parseInt(form.aiUpdateIntervalInput.value),
            use_testnet: form.useTestnetInput.checked,
            initial_margin_target_usdt: parseFloat(form.initialMarginTargetInput.value),
            take_profit_target_usdt: parseFloat(form.takeProfitTargetInput.value),
            pending_entry_order_cancel_timeout_seconds: parseInt(form.pendingOrderTimeoutInput.value)
        };

        try {
            const response = await apiClient.put('bot/config', configData);
            alert(response.message);
            fetchDashboardData(); // Refresh data
        } catch (error) {
            alert(`Failed to update config: ${error.data?.error || error.message}`);
        }
    });

    document.getElementById('closeAiLogModalBtn').addEventListener('click', hideAiLogModal);
    document.getElementById('aiLogModal').addEventListener('click', function(event) {
        if (event.target === this) { // Only close if clicking on the backdrop
            hideAiLogModal();
        }
    });
}
