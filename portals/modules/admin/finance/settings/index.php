<?php
// modules/admin/finance/settings/index.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Settings - Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --light: #f8fafc;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: var(--light);
            min-height: 100vh;
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .header h1 {
            color: var(--dark);
            font-size: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header p {
            color: #64748b;
            margin-top: 0.5rem;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .setting-card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: inherit;
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 4px solid var(--primary);
        }

        .setting-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .card-icon {
            width: 50px;
            height: 50px;
            background: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
        }

        .card-desc {
            color: #64748b;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            margin-top: 2rem;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-cog"></i>
                Finance Settings
            </h1>
            <p>Configure and manage all financial settings for the academy</p>
        </div>

        <div class="settings-grid">
            <a href="payment_gateways.php" class="setting-card">
                <div class="card-header">
                    <div class="card-icon" style="background: #3b82f6;">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <div class="card-title">Payment Gateways</div>
                </div>
                <div class="card-desc">
                    Configure online payment providers like Paystack, Flutterwave, and manage API keys, webhooks, and payment methods.
                </div>
            </a>

            <a href="tax_settings.php" class="setting-card">
                <div class="card-header">
                    <div class="card-icon" style="background: #10b981;">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="card-title">Tax Settings</div>
                </div>
                <div class="card-desc">
                    Set up tax rates, VAT configurations, and tax calculations for different programs and payment types.
                </div>
            </a>

            <a href="automation.php" class="setting-card">
                <div class="card-header">
                    <div class="card-icon" style="background: #f59e0b;">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="card-title">Automation Rules</div>
                </div>
                <div class="card-desc">
                    Configure automatic payment reminders, late fee calculations, and student suspension rules.
                </div>
            </a>

            <a href="../fees/index.php" class="setting-card">
                <div class="card-header">
                    <div class="card-icon" style="background: #8b5cf6;">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <div class="card-title">Fee Structures</div>
                </div>
                <div class="card-desc">
                    Manage program fee structures, payment plans, fee waivers, and discount configurations.
                </div>
            </a>

            <a href="../notifications/templates.php" class="setting-card">
                <div class="card-header">
                    <div class="card-icon" style="background: #ef4444;">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="card-title">Notification Templates</div>
                </div>
                <div class="card-desc">
                    Customize email and SMS templates for payment reminders, invoices, receipts, and financial alerts.
                </div>
            </a>

            <a href="../reports/export.php" class="setting-card">
                <div class="card-header">
                    <div class="card-icon" style="background: #06b6d4;">
                        <i class="fas fa-file-export"></i>
                    </div>
                    <div class="card-title">Export Settings</div>
                </div>
                <div class="card-desc">
                    Configure data export formats, scheduling, and automate financial report generation.
                </div>
            </a>
        </div>

        <a href="../dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Finance Dashboard
        </a>
    </div>
</body>

</html>