// auth.js - Zeus Security Logic
let currentStep = 1;
const pinField = document.getElementById('pinInput');

// 1. Step Navigation Logic
function nextStep(step) {
    // Hide all steps
    document.querySelectorAll('.auth-step').forEach(el => el.classList.remove('active'));
    
    // Show target step
    const targetStep = document.getElementById('step' + step);
    targetStep.classList.add('active');
    
    // Update Progress Bar
    const progress = document.getElementById('authProgress');
    const width = (step / 3) * 100;
    progress.style.width = width + '%';
    
    currentStep = step;
}

// 2. Tactile PIN Pad Logic
function pressPin(value) {
    if (value === 'clear') {
        pinField.value = '';
        return;
    }
    
    // Max 6 digits for Zeus Level Security
    if (pinField.value.length < 6) {
        pinField.value += value;
    }
    
    // Optional: Haptic feedback feel
    if (window.navigator.vibrate) {
        window.navigator.vibrate(20);
    }
}
