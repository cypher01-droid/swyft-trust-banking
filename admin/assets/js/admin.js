// admin/assets/js/admin.js
// All your JavaScript functions go here

// Global loading overlay
function showLoading() {
    document.getElementById('loadingOverlay').style.display = 'block';
}

function hideLoading() {
    document.getElementById('loadingOverlay').style.display = 'none';
}

// Toast notifications
function showToast(message, type = 'success') {
    // Toast implementation
    console.log(`${type}: ${message}`);
    alert(message); // Replace with proper toast
}

// View user details
function viewUserDetails(userId) {
    showLoading();
    fetch(`ajax/get_user_details.php?id=${userId}`)
        .then(response => response.text())
        .then(html => {
            // Load modal content
            document.getElementById('userDetailsContent').innerHTML = html;
            document.getElementById('userDetailsModal').style.display = 'block';
            hideLoading();
        })
        .catch(error => {
            hideLoading();
            showToast('Error loading user details', 'error');
            console.error(error);
        });
}

// Update user balance
function updateUserBalance(userId) {
    const form = document.querySelector('#balanceForm');
    if (!form) {
        showToast('Form not found!', 'error');
        return;
    }
    
    const formData = new FormData(form);
    
    // Validation...
    showLoading();
    
    fetch('ajax/update_balance.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message || 'Balance updated!');
            closeModal('adjustBalanceModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Network error', 'error');
    })
    .finally(() => {
        hideLoading();
    });
}

// Close modal
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Tab switching
function switchTab(tab) {
    window.location.href = `?tab=${tab}`;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Add any initialization code here
});