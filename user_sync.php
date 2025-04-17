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

// Function to log synchronization events with enhanced error handling
function sync_log($message, $level = 'INFO') {
    // Always ensure we have a minimal fallback
    $fallbackLog = __DIR__ . '/debug_sync_' . date('Ymd') . '.log';
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    
    // Directly output to PHP error log to ensure it's captured
    error_log("SHRAKVPN_SYNC: $logMessage");
    
    // Try to write to our primary log directory
    try {
        // Make sure log directory exists
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            if (!@mkdir($logDir, 0777, true)) {
                error_log("Failed to create log directory: $logDir");
                // Try writing to fallback
                @file_put_contents($fallbackLog, "[$timestamp] [ERROR] Failed to create log directory: $logDir\n", FILE_APPEND);
            } else {
                // Successfully created directory, try to set max permissions
                @chmod($logDir, 0777);
            }
        }
        
        $logFile = $logDir . '/user_sync.log';
        
        // Try to ensure the log file is writable
        if (file_exists($logFile) && !is_writable($logFile)) {
            @chmod($logFile, 0666);
        }
        
        if (@file_put_contents($logFile, $logMessage, FILE_APPEND) === false) {
            // Failed, write to fallback
            @file_put_contents($fallbackLog, "[$timestamp] [ERROR] Failed to write to $logFile\n", FILE_APPEND);
            @file_put_contents($fallbackLog, $logMessage, FILE_APPEND);
        }
    } catch (Exception $e) {
        // Catch any exceptions to prevent script termination
        @file_put_contents($fallbackLog, "[$timestamp] [ERROR] Exception in logging: " . $e->getMessage() . "\n", FILE_APPEND);
        @file_put_contents($fallbackLog, $logMessage, FILE_APPEND);
    }
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

    // Determine interface and configuration paths
    $wgInterface = $config['wireguard_interface'] ?? 'wg0';
    $configPath = "/etc/wireguard/$wgInterface.conf";
    
    // Absolute path for interface
    $absConfigPath = realpath($configPath);
    if ($absConfigPath) {
        $configPath = $absConfigPath;
        sync_log("Using absolute config path: $configPath", "INFO");
    } else {
        sync_log("Warning: Could not resolve absolute path for $configPath, using as is", "WARNING");
    }

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

        // Check if the file exists and is readable - with detailed diagnostics
        sync_log("Checking config file: $configPath", "DEBUG");
        if (!file_exists($configPath)) {
            sync_log("File check by file_exists: DOES NOT EXIST", "ERROR");
            
            // Try with is_file for additional check
            if (!is_file($configPath)) {
                sync_log("File check by is_file: DOES NOT EXIST", "ERROR");
            } else {
                sync_log("File check by is_file: EXISTS", "WARNING"); // This would be unusual
            }
            
            // Try direct shell check
            $fileCheck = shell_exec("[ -f $configPath ] && echo 'exists' || echo 'does not exist'");
            sync_log("Shell file check: $fileCheck", "DEBUG");
            
            // Directory check
            $dirPath = dirname($configPath);
            $dirCheck = shell_exec("[ -d $dirPath ] && echo 'exists' || echo 'does not exist'");
            sync_log("Directory check for $dirPath: $dirCheck", "DEBUG");
            
            // List directory contents
            $lsOutput = shell_exec("ls -la $dirPath 2>&1");
            sync_log("Directory contents of $dirPath: $lsOutput", "DEBUG");
            
            return [
                'success' => false,
                'message' => "WireGuard config file not found",
                'ip' => $assignedIp
            ];
        }
        
        // File exists, now check permissions
        if (!is_readable($configPath)) {
            sync_log("Config file exists but is not readable!", "ERROR");
            $perms = fileperms($configPath);
            $octal = substr(sprintf('%o', $perms), -4);
            $owner = posix_getpwuid(fileowner($configPath))['name'] ?? 'unknown';
            $group = posix_getgrgid(filegroup($configPath))['name'] ?? 'unknown';
            sync_log("File permissions: $octal, Owner: $owner, Group: $group", "DEBUG");
            
            // Try to fix permissions
            sync_log("Attempting to fix permissions with shell commands", "INFO");
            shell_exec("sudo chmod 644 $configPath 2>&1");
            
            // Verify fix
            if (!is_readable($configPath)) {
                sync_log("Still cannot read file after permission fix", "ERROR");
                return [
                    'success' => false,
                    'message' => "Config file is not readable",
                    'ip' => $assignedIp
                ];
            } else {
                sync_log("Successfully fixed file permissions", "INFO");
            }
        }

        // Now try to read the file with multiple methods
        $configContent = @file_get_contents($configPath);
        if ($configContent === false) {
            sync_log("Failed to read config file with file_get_contents", "ERROR");
            
            // Try with shell command
            $catOutput = shell_exec("cat $configPath 2>&1");
            if (!empty($catOutput)) {
                sync_log("Successfully read config file with shell command", "INFO");
                $configContent = $catOutput;
            } else {
                sync_log("Failed to read config file with shell command too", "ERROR");
                return [
                    'success' => false,
                    'message' => "Failed to read config file content",
                    'ip' => $assignedIp
                ];
            }
        }
        
        sync_log("Retrieved current config content of length: " . strlen($configContent), "DEBUG");
        
        // Prüfen, ob der Public Key bereits in der Konfiguration vorhanden ist
        $peerExists = strpos($configContent, $publicKey) !== false;
        sync_log("Peer already exists in config: " . ($peerExists ? "Yes" : "No"), "DEBUG");

        if (!$peerExists) {
            // Neuen Peer-Abschnitt hinzufügen
            $peerConfig = "\n[Peer]\nPublicKey = $publicKey\nAllowedIPs = $assignedIp\nPersistentKeepalive = 25\n";
            
            $newConfig = $configContent . $peerConfig;
            
            // Schreiben Sie die neue Konfiguration in die Datei
            sync_log("Writing new config with peer to $configPath", "INFO");
            
            // First check if file is writable
            if (!is_writable($configPath)) {
                sync_log("Config file is not writable!", "ERROR");
                
                // Try to use sudo to write the file
                sync_log("Attempting to write file with sudo", "INFO");
                $tmpFile = tempnam(sys_get_temp_dir(), 'wg_');
                file_put_contents($tmpFile, $newConfig);
                
                exec("sudo cp $tmpFile $configPath 2>&1", $cpOutput, $cpReturn);
                if ($cpReturn !== 0) {
                    sync_log("Failed to copy file with sudo: " . implode("\n", $cpOutput), "ERROR");
                    unlink($tmpFile);
                    return [
                        'success' => false,
                        'message' => "Failed to write to config file",
                        'ip' => $assignedIp
                    ];
                }
                
                unlink($tmpFile);
                sync_log("Successfully wrote config file with sudo", "INFO");
            } else {
                // File is writable, use direct method
                if (file_put_contents($configPath, $newConfig) === false) {
                    sync_log("Failed to write config file with file_put_contents!", "ERROR");
                    return [
                        'success' => false,
                        'message' => "Failed to write to config file",
                        'ip' => $assignedIp
                    ];
                }
                
                sync_log("Successfully wrote config file with file_put_contents", "INFO");
            }
            
            sync_log("Config file updated successfully", "INFO");
            
            // Versuche auch die laufende Konfiguration zu aktualisieren
            $addCmd = "sudo wg set $wgInterface peer $publicKey allowed-ips $assignedIp persistent-keepalive 25";
            sync_log("Running command: $addCmd", "DEBUG");
            exec($addCmd . " 2>&1", $output, $returnCode);
            
            if ($returnCode !== 0) {
                sync_log("Warning: Could not update runtime config with sudo wg set: " . implode("\n", $output), "WARNING");
                
                // Try wg directly without sudo
                $wgCmd = "wg set $wgInterface peer $publicKey allowed-ips $assignedIp persistent-keepalive 25";
                sync_log("Trying without sudo: $wgCmd", "INFO");
                exec($wgCmd . " 2>&1", $output2, $returnCode2);
                
                if ($returnCode2 !== 0) {
                    sync_log("Warning: Direct wg command also failed: " . implode("\n", $output2), "WARNING");
                    
                    // Try syncconf
                    $syncCmd = "sudo wg syncconf $wgInterface $configPath";
                    sync_log("Trying sudo wg syncconf: $syncCmd", "INFO");
                    exec($syncCmd . " 2>&1", $syncOutput, $syncCode);
                    
                    if ($syncCode !== 0) {
                        sync_log("Warning: sudo wg syncconf also failed: " . implode("\n", $syncOutput), "WARNING");
                        
                        // Last resort - restart service
                        sync_log("Attempting to restart WireGuard service", "WARNING");
                        exec("sudo systemctl restart wg-quick@$wgInterface 2>&1", $restartOutput, $restartCode);
                        
                        if ($restartCode !== 0) {
                            sync_log("Warning: Service restart failed: " . implode("\n", $restartOutput), "WARNING");
                        } else {
                            sync_log("Successfully restarted WireGuard service", "INFO");
                        }
                    } else {
                        sync_log("Successfully applied config with sudo wg syncconf", "INFO");
                    }
                } else {
                    sync_log("Successfully updated runtime config with direct wg set", "INFO");
                }
            } else {
                sync_log("Successfully updated runtime config with sudo wg set", "INFO");
            }
        } else {
            sync_log("Peer already exists in config, no changes needed", "INFO");
        }

        // Verifiziere die Konfiguration
        $verifyConfig = file_get_contents($configPath);
        if ($verifyConfig === false) {
            $verifyConfig = shell_exec("cat $configPath 2>&1");
        }
        
        if (strpos($verifyConfig, $publicKey) === false) {
            sync_log("ERROR: Peer not found in config file after update!", "ERROR");
            return [
                'success' => false,
                'message' => "Failed to add peer to configuration file",
                'ip' => $assignedIp
            ];
        }

        // Erfolg zurückgeben
        return [
            'success' => true,
            'message' => 'WireGuard configuration updated successfully',
            'ip' => $assignedIp
        ];

    } catch (Exception $e) {
        sync_log("Exception during WireGuard config: " . $e->getMessage(), "ERROR");
        return [
            'success' => false,
            'message' => 'Exception during configuration: ' . $e->getMessage(),
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
