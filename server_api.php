<?php

/**
 * ShrakVPN Server API
 *
 * This script serves as the central API endpoint for the VPN server,
 * handling requests from the admin panel and client applications.
 *
 * It should be placed on your VPN server and set up with a web server (e.g., Nginx)
 * to handle incoming HTTP requests at a URL like: https://yourserver.com/api/
 */

// Enable error reporting for debugging but disable display
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Define constants
define('LOGS_DIR', __DIR__ . '/logs');
define('API_LOG_FILE', LOGS_DIR . '/server_api.log');

// Create logs directory if it doesn't exist
if (!is_dir(LOGS_DIR)) {
    mkdir(LOGS_DIR, 0755, true);
}

// Load configuration
$config = require __DIR__ . '/config.php';

// Check if server API key exists in configuration
if (!isset($config['server_api_key']) || empty($config['server_api_key'])) {
    api_log("Server API key not configured", "ERROR");
    send_response(false, 'Server API key not configured', 500);
    exit;
}

// Function to log API requests and responses
function api_log($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    file_put_contents(API_LOG_FILE, $logMessage, FILE_APPEND);
}

// Function to send standardized API responses
function send_response($success, $message, $statusCode = 200, $data = []) {
    http_response_code($statusCode);
    header('Content-Type: application/json');

    $response = [
        'success' => $success,
        'message' => $message
    ];

    if (!empty($data)) {
        $response['data'] = $data;
    }

    echo json_encode($response);
    exit;
}

// Function to verify the request comes from an authorized source
function verify_api_request() {
    global $config;

    // Get all headers - handle different server configurations
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        // Manual header collection for servers without getallheaders()
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$name] = $value;
            } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
                $headers[$name] = $value;
            }
        }
    }

    // Debug headers
    $headerStr = json_encode($headers);
    api_log("Request headers: $headerStr", "DEBUG");
    api_log("Expected API key: " . $config['server_api_key'], "DEBUG");

    // Check for Bearer token - case insensitive
    $authorization = '';
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $authorization = $value;
            break;
        }
    }

    if (!empty($authorization)) {
        api_log("Found Authorization header: $authorization", "DEBUG");
        if (preg_match('/Bearer\s+(.*)$/i', $authorization, $matches)) {
            $token = $matches[1];
            if ($token === $config['server_api_key']) {
                return true;
            } else {
                api_log("Bearer token does not match API key", "DEBUG");
            }
        }
    }

    // Alternative: Check X-Server-API-Key or X-API-Key header
    $apiKey = '';
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'x-server-api-key' || strtolower($key) === 'x-api-key') {
            $apiKey = $value;
            break;
        }
    }

    if (!empty($apiKey)) {
        api_log("Found API key header: $apiKey", "DEBUG");
        if ($apiKey === $config['server_api_key']) {
            return true;
        } else {
            api_log("X-API-Key does not match server_api_key", "DEBUG");
        }
    }

    api_log("No valid authorization found in request", "WARNING");
    return false;
}

// Helper function to determine the current endpoint from URL
function get_current_endpoint() {
    // Try PATH_INFO first (common in many setups)
    if (isset($_SERVER['PATH_INFO']) && !empty($_SERVER['PATH_INFO'])) {
        return $_SERVER['PATH_INFO'];
    }

    // Try REQUEST_URI and parse out query string and script name
    if (isset($_SERVER['REQUEST_URI'])) {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $scriptName = basename($_SERVER['SCRIPT_NAME']);

        // Remove script name from URI if it's at the end
        if (substr($uri, -strlen($scriptName)) === $scriptName) {
            $uri = substr($uri, 0, -strlen($scriptName));
        }

        // If we're accessing root, return /ping as default for easy testing
        if ($uri === '/' || empty($uri)) {
            return '/ping';
        }

        return $uri;
    }

    // Default to /ping for root requests
    return '/ping';
}

// Main API request handler
try {
    // Log the request
    $method = $_SERVER['REQUEST_METHOD'];
    $endpoint = get_current_endpoint();
    $clientIP = $_SERVER['REMOTE_ADDR'];

    api_log("Received $method request to $endpoint from $clientIP");

    // Verify all API requests except for specific public endpoints
    // Change: Remove ping from public endpoints so it requires authentication
    $publicEndpoints = ['/public_ping.php'];

    if (!in_array($endpoint, $publicEndpoints) && !verify_api_request()) {
        api_log("Unauthorized API request attempt", "WARNING");
        send_response(false, 'Unauthorized', 401);
    }

    // Handle different API endpoints
    switch ($endpoint) {
        case '/ping':
            // Public endpoint to check if the server is online
            send_response(true, 'Server online', 200, ['server_id' => $config['server_id']]);
            break;

        case '/user-sync':
            // Handle user synchronization from admin panel
            if ($method !== 'POST') {
                send_response(false, 'Method not allowed', 405);
            }

            // Get JSON input
            $jsonInput = file_get_contents('php://input');
            $requestData = json_decode($jsonInput, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                api_log("JSON parse error: " . json_last_error_msg(), "ERROR");
                send_response(false, 'Invalid JSON input', 400);
            }

            // Use the user_sync.php script to process the request
            require_once __DIR__ . '/user_sync.php';
            $result = process_user_sync($requestData);

            send_response(
                $result['success'],
                $result['message'],
                $result['success'] ? 200 : 400,
                $result['data'] ?? []
            );
            break;

        case '/server-info':
            // Return information about the server
            $serverInfo = [
                'id' => $config['server_id'],
                'vpn_type' => $config['vpn_type'],
                'active_connections' => get_active_connection_count(),
                'load' => get_current_load(),
                'uptime' => get_server_uptime()
            ];
            
            // Add WireGuard public key if this is a WireGuard server
            if ($config['vpn_type'] === 'wireguard') {
                $serverInfo['wireguard_public_key'] = get_wireguard_public_key($config);
            }

            send_response(true, 'Server information retrieved', 200, $serverInfo);
            break;

        case '/verify-user':
            // Handle user verification requests
            if ($method !== 'POST') {
                send_response(false, 'Method not allowed', 405);
            }

            // Get JSON input
            $jsonInput = file_get_contents('php://input');
            $requestData = json_decode($jsonInput, true);

            if (json_last_error() !== JSON_ERROR_NONE ||
                !isset($requestData['username']) ||
                !isset($requestData['token'])) {
                send_response(false, 'Invalid request data', 400);
            }

            // Use the auth_verify.php script to verify credentials
            require_once __DIR__ . '/auth_verify.php';
            $result = verify_user_credentials(
                $requestData['username'],
                $requestData['token'],
                $config['server_id'],
                $config
            );

            send_response(
                $result,
                $result ? 'User verified successfully' : 'Verification failed',
                $result ? 200 : 401
            );
            break;

        default:
            // Unknown endpoint
            api_log("Request to unknown endpoint: $endpoint", "WARNING");
            send_response(false, 'Endpoint not found', 404);
            break;
    }
} catch (Exception $e) {
    api_log("Exception in API processing: " . $e->getMessage(), "ERROR");
    send_response(false, 'Internal server error', 500);
}

// Helper functions for server information

/**
 * Get the number of active VPN connections
 */
function get_active_connection_count() {
    global $config;

    if ($config['vpn_type'] === 'wireguard') {
        // Check active WireGuard connections
        $wgInterface = $config['wireguard_interface'] ?? 'wg0';
        $output = shell_exec("wg show $wgInterface peers | wc -l");
        return (int)trim($output);
    } else {
        // Check active OpenVPN connections
        $output = shell_exec("ps aux | grep openvpn | grep -v grep | wc -l");
        return (int)trim($output);
    }
}

/**
 * Get current server load
 */
function get_current_load() {
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        return $load[0];
    }

    return 0;
}

/**
 * Get server uptime
 */
function get_server_uptime() {
    $uptime = shell_exec('uptime -p');
    return trim($uptime);
}

/**
 * Get WireGuard public key with error handling and fallback methods
 */
function get_wireguard_public_key($config) {
    // Method 0: Try to read directly from our dedicated public key file (most reliable)
    $publicKeyFile = '/etc/wireguard/public.key';
    if (file_exists($publicKeyFile) && is_readable($publicKeyFile)) {
        $publicKey = trim(file_get_contents($publicKeyFile));
        if (!empty($publicKey) && preg_match('/^[A-Za-z0-9+\/]{43}=$/', $publicKey)) {
            api_log("Retrieved WireGuard public key from dedicated key file", "DEBUG");
            return $publicKey;
        }
    }
    
    $wgInterface = $config['wireguard_interface'] ?? 'wg0';
    
    // Method 1: Use wg command to get public key directly
    $publicKey = trim(shell_exec("wg show $wgInterface public-key 2>/dev/null"));
    
    // Check if we got a valid key
    if (!empty($publicKey) && preg_match('/^[A-Za-z0-9+\/]{43}=$/', $publicKey)) {
        api_log("Retrieved WireGuard public key using wg show command", "DEBUG");
        return $publicKey;
    }
    
    // Method 2: Try to get the key from the public key file
    $pubKeyFile = "/etc/wireguard/server_public.key";
    if (file_exists($pubKeyFile) && is_readable($pubKeyFile)) {
        $publicKey = trim(file_get_contents($pubKeyFile));
        if (!empty($publicKey) && preg_match('/^[A-Za-z0-9+\/]{43}=$/', $publicKey)) {
            api_log("Retrieved WireGuard public key from server_public.key file", "DEBUG");
            return $publicKey;
        }
    }
    
    // Method 3: Try to get the key from the configuration file
    $configFile = $config['wireguard_config_path'] ?? "/etc/wireguard/$wgInterface.conf";
    if (file_exists($configFile) && is_readable($configFile)) {
        // Get private key from config file and derive public key
        $content = file_get_contents($configFile);
        if (preg_match('/PrivateKey\s*=\s*([A-Za-z0-9+\/]{43}=)/', $content, $matches)) {
            $privateKey = $matches[1];
            // Write private key to temp file
            $tempFile = tempnam(sys_get_temp_dir(), 'wgkey');
            file_put_contents($tempFile, $privateKey);
            // Derive public key
            $pubKey = trim(shell_exec("cat $tempFile | wg pubkey 2>/dev/null"));
            unlink($tempFile); // Clean up
            
            if (!empty($pubKey) && preg_match('/^[A-Za-z0-9+\/]{43}=$/', $pubKey)) {
                api_log("Retrieved WireGuard public key from config file", "DEBUG");
                return $pubKey;
            }
        }
    }
    
    // Method 4: Try to find the public key in wg showconf output
    $showconf = shell_exec("wg showconf $wgInterface 2>/dev/null");
    if (!empty($showconf) && preg_match('/PublicKey\s*=\s*([A-Za-z0-9+\/]{43}=)/', $showconf, $matches)) {
        api_log("Retrieved WireGuard public key from wg showconf", "DEBUG");
        return $matches[1];
    }
    
    // Method 5: Check if key exists in config
    if (isset($config['wireguard_public_key']) && !empty($config['wireguard_public_key'])) {
        api_log("Retrieved WireGuard public key from config", "DEBUG");
        return $config['wireguard_public_key'];
    }
    
    // Detailed logging to help diagnose the issue
    api_log("Failed to retrieve WireGuard public key. Diagnostics:", "ERROR");
    api_log("WireGuard interface: $wgInterface", "ERROR");
    api_log("wg command exists: " . (shell_exec("which wg") ? "Yes" : "No"), "ERROR");
    api_log("Public key file exists: " . (file_exists($publicKeyFile) ? "Yes" : "No"), "ERROR");
    api_log("Server public key file exists: " . (file_exists($pubKeyFile) ? "Yes" : "No"), "ERROR");
    api_log("Config file exists: " . (file_exists($configFile) ? "Yes" : "No"), "ERROR");
    
    // Log failure and return placeholder
    api_log("Failed to retrieve WireGuard public key using any method", "ERROR");
    return "ERROR_RETRIEVING_KEY";
}
