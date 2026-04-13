<?php
// refresh_crypto.php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

function refreshRates() {
    const btn = document.getElementById('ratesRefresh');
    const grid = document.getElementById('ratesGrid');
    
    btn.classList.add('loading');
    grid.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;">Refreshing...</div>';
    
    fetch('ajax/refresh_rates.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '';
                data.rates.forEach(rate => {
                    const changeClass = rate.change >= 0 ? 'positive' : 'negative';
                    
                    html += `
                    <div class="market-item">
                        <div class="coin-info">
                            <div class="coin-symbol">${rate.from}/${rate.to}</div>
                            <div class="coin-name">${rate.name}</div>
                        </div>
                        <div class="price-info">
                            <div class="current-price">${rate.rate.toFixed(4)}</div>
                            <div class="price-change ${changeClass}">
                                ${rate.change >= 0 ? '+' : ''}${rate.change.toFixed(2)}%
                            </div>
                        </div>
                    </div>`;
                });
                grid.innerHTML = html;
            } else {
                grid.innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;">Update failed</div>';
            }
        })
        .catch(error => {
            grid.innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;">Connection error</div>';
        })
        .finally(() => {
            btn.classList.remove('loading');
        });
}

$prices = getLiveCryptoPrices();
echo json_encode(['success' => true, 'prices' => $prices]);
?>