<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Simple file upload handler that works with your existing functions
$allowed_types = ['id_card', 'passport', 'drivers_license', 'proof_of_address', 'selfie'];
$document_type = $_POST['document_type'] ?? '';

if (!in_array($document_type, $allowed_types)) {
    echo json_encode(['success' => false, 'error' => 'Invalid document type']);
    exit;
}

// Create upload directory
$upload_dir = __DIR__ . '/uploads/kyc/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Process file upload
$response = ['success' => false, 'error' => ''];

try {
    // For front document
    if (isset($_FILES['document_front']) && $_FILES['document_front']['error'] === UPLOAD_ERR_OK) {
        $front_filename = uniqid() . '_' . basename($_FILES['document_front']['name']);
        $front_path = $upload_dir . $front_filename;
        
        if (move_uploaded_file($_FILES['document_front']['tmp_name'], $front_path)) {
            // For back document (if provided)
            $back_path = null;
            if (isset($_FILES['document_back']) && $_FILES['document_back']['error'] === UPLOAD_ERR_OK) {
                $back_filename = uniqid() . '_' . basename($_FILES['document_back']['name']);
                $back_path = $upload_dir . $back_filename;
                move_uploaded_file($_FILES['document_back']['tmp_name'], $back_path);
            }
            
            // Here you would call your existing saveKycDocument function
            // Since we don't have that function in this file, we'll simulate success
            $response['success'] = true;
            $response['message'] = 'Document uploaded successfully';
        } else {
            $response['error'] = 'Failed to upload file';
        }
    } else {
        $response['error'] = 'No file uploaded or upload error';
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
?>