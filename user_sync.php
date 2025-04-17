<?php

/**
 * ShrakVPN User Synchronization Script
 *
 * This script handles receiving and storing user data from the admin panel.
 * It maintains a local database of authorized users for the VPN server.
 *
 * It's called by the server_api.php script when the admin panel sends user data.
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

// Define constants
define('USER_SYNC_LOG', LOGS_DIR . '/user_sync.log');
define('USER_DB_DIR', __DIR__ . '/user_db');

// Create user database directory if it doesn't exist
if (!is_dir(USER_DB_DIR)) {
    mkdir(USER_DB_DIR, 0755, true);
}

// Function to log synchronization events
function sync_log($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    file_put_contents(USER_SYNC_LOG, $logMessage, FILE_APPEND);
}

/**
 * Process user synchronization
 *
 * @param array $requestData Data from the admin panel
 * @return array Result of the operation
 */
function process_user_sync($requestData) {
    global $config;

    if (!isset($requestData['action']) || !isset($requestData['user'])) {
        return [
            'success' => false,
            'message' => 'Invalid request data'
        ];
    }

    $action = $requestData['action'];
    $userData = $requestData['user'];

    // Validate required user data
    if (!isset($userData['id']) || !isset($userData['email']) || !isset($userData['token'])) {
        return [
            'success' => false,
            'message' => 'Missing required user data'
        ];
    }

    // Determine user storage path
    $userDir = $config['user_config_dir'] ?? __DIR__ . '/user_db';
    if (!is_dir($userDir)) {
        mkdir($userDir, 0755, true);
    }

    // User identifier
    $userId = $userData['id'];
    $email = $userData['email'];
    $userFile = $userDir . '/user_' . $userId . '.json';

    // Log operation and config type for debugging
    sync_log("Processing user sync for user $userId ($email), action: $action, VPN type: {$config['vpn_type']}");

    if ($action === 'add' || $action === 'update') {
        // Store user data in JSON format
        $userData['updated_at'] = date('Y-m-d H:i:s');

        // WireGuard-specific configuration - force wireguard configuration if public key exists
        if (isset($userData['wg_public_key'])) {
            sync_log("WireGuard public key found for user $userId, configuring WireGuard peer", "INFO");
            $wgResult = configure_wireguard_user($userData, $config);
            $userData['wg_config_status'] = $wgResult['success'] ? 'configured' : 'failed';
            $userData['wg_config_message'] = $wgResult['message'];
            $userData['wg_assigned_ip'] = $wgResult['ip'] ?? null;

            // Log detailed result
            sync_log("WireGuard configuration result: " . json_encode($wgResult), "INFO");
        } else {
            sync_log("No WireGuard public key provided for user $userId", "WARNING");
            $userData['wg_config_status'] = 'missing_key';
        }

        file_put_contents($userFile, json_encode($userData, JSON_PRETTY_PRINT));

        return [
            'success' => true,
            'message' => "User $userId synchronized successfully",
            'data' => [
                'user_id' => $userId,
                'action' => $action,
                'wg_status' => $userData['wg_config_status'] ?? 'not_applicable'
            ]
        ];
    } elseif ($action === 'remove') {
        // If we have user data with a WireGuard public key, remove it
        if (file_exists($userFile)) {
            $existingData = json_decode(file_get_contents($userFile), true);
            if (isset($existingData['wg_public_key'])) {
                sync_log("Removing WireGuard configuration for user $userId", "INFO");
                remove_wireguard_user($userId, $config);
            }
            unlink($userFile);
        }

        return [
            'success' => true,
            'message' => "User $userId removed successfully",
            'data' => [
                'user_id' => $userId,
                'action' => 'remove'
            ]
        ];
    } else {
        return [
            'success' => false,
            'message' => "Unknown action '$action'"
        ];
    }
}

/**
 * Configure a WireGuard user
 *
 * @param array $userData User data including public key
 * @param array $config Server configuration
 * @return array Result of the operation
 */
function configure_wireguard_user($userData, $config) {
    $userId = $userData['id'];
    $email = $userData['email'];
    $publicKey = $userData['wg_public_key'] ?? null;

    if (empty($publicKey)) {
        sync_log("No public key provided for WireGuard user $userId", "ERROR");
        return [
            'success' => false,
            'message' => 'No public key provided'
        ];
    }

    // Determine interface
    $wgInterface = $config['wireguard_interface'] ?? 'wg0';

    // Log the interface being used
    sync_log("Using WireGuard interface: $wgInterface for user $userId", "INFO");

    // Calculate IP - simple algorithm based on user ID
    $ipLastOctet = ($userId % 254) + 1; // Avoid 0 and 255
    $assignedIp = "10.8.0.$ipLastOctet/32";

    // Log the IP assignment
    sync_log("Assigning IP $assignedIp to user $userId", "INFO");

    // Create peer configuration
    try {
        // First check if the WireGuard interface exists
        $interfaceCheck = shell_exec("ip link show $wgInterface 2>&1");
        if (strpos($interfaceCheck, 'does not exist') !== false) {
            sync_log("WireGuard interface $wgInterface does not exist", "ERROR");
            return [
                'success' => false,
                'message' => "WireGuard interface $wgInterface does not exist",
                'ip' => $assignedIp
            ];
        }

        // Check if WireGuard tools are available
        $wgCheck = shell_exec("which wg 2>&1");
        if (empty($wgCheck) || strpos($wgCheck, 'no wg in') !== false) {
            sync_log("WireGuard tools not installed", "ERROR");
            return [
                'success' => false,
                'message' => "WireGuard tools not installed on the server",
                'ip' => $assignedIp
            ];
        }

        // Check if peer already exists
        $peerListCmd = "wg show $wgInterface peers";
        sync_log("Running command: $peerListCmd", "DEBUG");
        $peerList = shell_exec($peerListCmd);
        sync_log("Peer list result: " . trim($peerList), "DEBUG");

        $existingPeer = strpos($peerList, $publicKey) !== false;

        if ($existingPeer) {
            // Update existing peer
            $updateCmd = "wg set $wgInterface peer $publicKey allowed-ips $assignedIp";
            sync_log("Running command: $updateCmd", "DEBUG");
            $result = shell_exec($updateCmd . " 2>&1");
            if (!empty($result)) {
                sync_log("Command output: $result", "DEBUG");
            }
            sync_log("Updated WireGuard peer for user $userId", "INFO");
        } else {
            // Add new peer
            $addCmd = "wg set $wgInterface peer $publicKey allowed-ips $assignedIp persistent-keepalive 25";
            sync_log("Running command: $addCmd", "DEBUG");
            $result = shell_exec($addCmd . " 2>&1");
            if (!empty($result)) {
                sync_log("Command output: $result", "DEBUG");
            }
            sync_log("Added new WireGuard peer for user $userId", "INFO");
        }

        // Save configuration permanently
        $saveCmd = "wg-quick save $wgInterface";
        sync_log("Running command: $saveCmd", "DEBUG");
        $saveResult = shell_exec($saveCmd . " 2>&1");
        if (!empty($saveResult)) {
            sync_log("Save command output: $saveResult", "DEBUG");
        }

        // Verify the peer was actually added
        $verifyCmd = "wg show $wgInterface peers | grep -w $publicKey";
        sync_log("Running verification command: $verifyCmd", "DEBUG");
        $verifyResult = shell_exec($verifyCmd);

        if (empty($verifyResult)) {
            sync_log("Verification failed, peer not found after configuration", "WARNING");
            // Try an alternative way to save the configuration
            $altSaveCmd = "wg showconf $wgInterface > /etc/wireguard/$wgInterface.conf";
            sync_log("Trying alternative save method: $altSaveCmd", "INFO");
            shell_exec($altSaveCmd);

            // Add the peer directly to the config file if needed
            $configFile = "/etc/wireguard/$wgInterface.conf";
            if (file_exists($configFile)) {
                $configContent = file_get_contents($configFile);
                if (strpos($configContent, $publicKey) === false) {
                    $peerConfig = "\n[Peer]\nPublicKey = $publicKey\nAllowedIPs = $assignedIp\nPersistentKeepalive = 25\n";
                    file_put_contents($configFile, $configContent . $peerConfig);
                    sync_log("Added peer directly to config file", "INFO");

                    // Apply the configuration
                    shell_exec("wg syncconf $wgInterface /etc/wireguard/$wgInterface.conf");
                }
            }
        }

        return [
            'success' => true,
            'message' => 'WireGuard configuration updated successfully',
            'ip' => $assignedIp
        ];
    } catch (Exception $e) {
        sync_log("Failed to configure WireGuard for user $userId: " . $e->getMessage(), "ERROR");
        return [
            'success' => false,
            'message' => 'Failed to configure WireGuard: ' . $e->getMessage(),
            'ip' => $assignedIp
        ];
    }
}

/**
 * Remove a WireGuard user
 *
 * @param int $userId User ID
 * @param array $config Server configuration
 * @return bool Success or failure
 */
function remove_wireguard_user($userId, $config) {
    $userDir = $config['user_config_dir'] ?? __DIR__ . '/user_db';
    $userFile = $userDir . '/user_' . $userId . '.json';

    // Get user data to find the public key
    if (!file_exists($userFile)) {
        sync_log("User file not found for user $userId during WireGuard removal", "WARNING");
        return false;
    }

    $userData = json_decode(file_get_contents($userFile), true);
    if (!isset($userData['wg_public_key'])) {
        sync_log("No WireGuard public key found for user $userId during removal", "WARNING");
        return false;
    }

    $publicKey = $userData['wg_public_key'];
    $wgInterface = $config['wireguard_interface'] ?? 'wg0';

    try {
        // Remove peer from WireGuard
        shell_exec("wg set $wgInterface peer $publicKey remove");
        shell_exec("wg-quick save $wgInterface");

        sync_log("Removed WireGuard peer for user $userId", "INFO");
        return true;
    } catch (Exception $e) {
        sync_log("Failed to remove WireGuard peer for user $userId: " . $e->getMessage(), "ERROR");
        return false;
    }
}

// This script can be called directly for testing or from server_api.php
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    // Example for command line testing:
    // php user_sync.php '{"action":"add","user":{"id":1,"email":"test@example.com","token":"abc123"}}'
    $inputJson = $argv[1];
    $requestData = json_decode($inputJson, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sync_log("JSON parse error: " . json_last_error_msg(), "ERROR");
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit(1);
    }

    $result = process_user_sync($requestData);
    echo json_encode($result);
    exit($result['success'] ? 0 : 1);
}
