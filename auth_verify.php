<?php

/**
 * ShrakVPN User Authentication Verification Script
 *
 * This script verifies user credentials against the admin panel during VPN connection attempts.
 * It should be called by the VPN server's authentication hook.
 *
 * Usage:
 * Called by OpenVPN via auth-user-pass-verify or by WireGuard configs
 */

// Enable error reporting for debugging but disable display
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Define logs directory constant if not already defined
if (!defined('LOGS_DIR')) {
    define('LOGS_DIR', __DIR__ . '/logs');
    // Create logs directory if it doesn't exist
    if (!is_dir(LOGS_DIR)) {
        mkdir(LOGS_DIR, 0755, true);
    }
}

// Log file for authentication attempts
define('AUTH_LOG_FILE', LOGS_DIR . '/auth_attempts.log');

// Function to log authentication events
function auth_log($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    file_put_contents(AUTH_LOG_FILE, $logMessage, FILE_APPEND);
}

// Load configuration
$config = require __DIR__ . '/config.php';

// Get server ID from config
$serverId = $config['server_id'] ?? '';
if (empty($serverId)) {
    auth_log("Server ID not found in config", "ERROR");
    exit(1);
}

/**
 * Verify user credentials with the admin panel
 *
 * @param string $username The username (email) provided during connection
 * @param string $password The password or token provided during connection
 * @return bool Whether the authentication was successful
 */
function verify_user_credentials($username, $password, $serverId, $config) {
    $adminPanelUrl = $config['admin_panel_url'] ?? 'https://api.shrakvpn.com';
    $serverApiKey = $config['server_api_key'] ?? '';

    if (empty($serverApiKey)) {
        auth_log("Server API key not configured", "ERROR");
        return false;
    }

    try {
        auth_log("Verifying credentials for user: $username on server: $serverId");

        // Prepare verification data
        $verificationData = [
            'username' => $username,
            'token' => $password,
            'server_id' => $serverId
        ];

        // Use cURL to make the API request
        $ch = curl_init($adminPanelUrl . '/api/verify-credentials');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verificationData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Server-API-Key: ' . $serverApiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 second timeout

        // Send the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            auth_log("cURL error: " . curl_error($ch), "ERROR");
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        // Parse response
        $responseData = json_decode($response, true);

        if ($httpCode !== 200 || !isset($responseData['success']) || $responseData['success'] !== true) {
            auth_log("Authentication failed for user: $username, HTTP code: $httpCode");
            auth_log("Error message: " . ($responseData['message'] ?? 'Unknown error'));
            return false;
        }

        // Authentication successful
        auth_log("Authentication successful for user: $username");

        // Store user data for session management if needed
        $userData = $responseData['user'] ?? [];
        store_authenticated_user($username, $userData);

        return true;

    } catch (Exception $e) {
        auth_log("Exception during credential verification: " . $e->getMessage(), "ERROR");
        return false;
    }
}

/**
 * Store authenticated user data for session management
 */
function store_authenticated_user($username, $userData) {
    // Create a directory to store authenticated user sessions if it doesn't exist
    $authDir = __DIR__ . '/authenticated_users';
    if (!is_dir($authDir)) {
        mkdir($authDir, 0755, true);
    }

    // Store user data in a file named after the username (sanitized)
    $safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '_', $username);
    $userFile = "$authDir/$safeUsername.json";

    // Add timestamp to user data
    $userData['authenticated_at'] = time();

    file_put_contents($userFile, json_encode($userData));
    auth_log("Stored authenticated session for user: $username");
}

// Main execution - this script can be called in different ways

// Method 1: Command line with username and password as arguments
if (PHP_SAPI === 'cli' && isset($argv[1]) && isset($argv[2])) {
    $username = $argv[1];
    $password = $argv[2];

    $result = verify_user_credentials($username, $password, $serverId, $config);
    exit($result ? 0 : 1); // Return 0 for success, 1 for failure
}

// Method 2: Called by OpenVPN auth-user-pass-verify script
// In this case, username and password will be in the temporary file specified by OpenVPN
if (isset($_SERVER['openvpn_user_pass_file']) && file_exists($_SERVER['openvpn_user_pass_file'])) {
    $userPassFile = $_SERVER['openvpn_user_pass_file'];
    $lines = file($userPassFile, FILE_IGNORE_NEW_LINES);

    if (count($lines) >= 2) {
        $username = $lines[0];
        $password = $lines[1];

        $result = verify_user_credentials($username, $password, $serverId, $config);
        exit($result ? 0 : 1);
    } else {
        auth_log("Invalid user-pass file format", "ERROR");
        exit(1);
    }
}

// Method 3: Calling via HTTP endpoint from WireGuard or other services
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];

        $result = verify_user_credentials($username, $password, $serverId, $config);

        header('Content-Type: application/json');
        echo json_encode(['success' => $result]);
        exit;
    } else {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['success' => false, 'message' => 'Missing username or password']);
        exit;
    }
}

// If we get here, the script was called incorrectly
auth_log("Invalid usage of auth_verify.php", "ERROR");
exit(1);
