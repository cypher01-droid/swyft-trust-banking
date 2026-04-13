<?php
// admin/includes/header.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Dashboard - SwyftTrust Bank</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* All your CSS from the original file goes here */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }
        
        :root {
            --primary: #9d50ff;
            --primary-dark: #6a11cb;
            --danger: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
            --info: #3b82f6;
            --dark-bg: #0a0a0c;
            --card-bg: #111113;
            --text: #ffffff;
            --text-secondary: #94a3b8;
            --border: rgba(157, 80, 255, 0.1);
            --sidebar-width: 250px;
        }
        
        body {
            background: var(--dark-bg);
            font-family: 'Inter', -apple-system, sans-serif;
            color: var(--text);
            display: flex;
            min-height: 100vh;
        }
        
        /* ... Include ALL CSS from your original file ... */
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        
        .status-pending { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .status-approved { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-rejected { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .status-completed { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .status-processing { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; }
        .status-unverified { background: rgba(148, 163, 184, 0.1); color: var(--text-secondary); }
        .status-verified { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-declined { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        
        /* Action Buttons */
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        /* ... Rest of CSS ... */
    </style>
</head>
<body>