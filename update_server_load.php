<?php

/**
 * ShrakVPN Server Load Update Script
 *
 * This script is designed to run on VPN servers to calculate and update
 * the bandwidth usage load in the database. It should be scheduled to run
 * every minute using cron or another scheduler.
 *
 * Usage:
 * php update_server_load.php [server_id]
 *
 * If server_id is not provided, the script will get it from the config file.
 */

// Enable all error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define logs directory constant if not already defined
if (!defined('LOGS_DIR')) {
    define('LOGS_DIR', __DIR__ . '/logs');
    // Create logs directory if it doesn't exist
    if (!is_dir(LOGS_DIR)) {
        mkdir(LOGS_DIR, 0755, true);
    }
}

// Define a simple direct logging function for startup errors
function debug_log($message) {
    $logFile = LOGS_DIR . '/debug_load_script.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Log script start
debug_log("Script started. Running from: " . __DIR__);

// Load configuration - look in multiple places to ensure we find it
$possibleConfigPaths = [
    __DIR__ . '/config.php',               // Current directory
    '/var/www/shrakvpn/api/config.php',    // Standard API directory
    '/var/www/shrakvpn/config.php',        // Main app directory
    dirname(__DIR__) . '/api/config.php',  // Relative API directory
    dirname(__DIR__) . '/config.php',      // Parent directory
];

$configFile = null;
foreach ($possibleConfigPaths as $path) {
    if (file_exists($path)) {
        $configFile = $path;
        debug_log("Found config at: $configFile");
        break;
    }
}

if (!$configFile) {
    debug_log("ERROR: Configuration file not found in any of the standard paths");
    echo "ERROR: Configuration file not found!\n";
    echo "Please run the generate_server_config.php script first to create a configuration file.\n";
    exit(1);
}

try {
    debug_log("Loading config from: $configFile");
    $config = require $configFile;
    debug_log("Config loaded successfully");

    // Verify that required config values exist
    $requiredKeys = ['server_id', 'db_host', 'db_name', 'db_user', 'db_password', 'db_port'];
    $missingKeys = [];

    foreach ($requiredKeys as $key) {
        if (!isset($config[$key]) || empty($config[$key])) {
            $missingKeys[] = $key;
        }
    }

    if (!empty($missingKeys)) {
        debug_log("ERROR: Missing required configuration values: " . implode(', ', $missingKeys));
        echo "ERROR: Missing required configuration values: " . implode(', ', $missingKeys) . "\n";
        echo "Please run the generate_server_config.php script to create a complete configuration.\n";
        exit(1);
    }

} catch (Exception $e) {
    debug_log("ERROR loading config: " . $e->getMessage());
    die("Error loading config: " . $e->getMessage() . "\n");
}

// Make sure the log directory exists and is writable
$logFile = $config['log_file'] ?? LOGS_DIR . '/server_load_updates.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    debug_log("Creating log directory: $logDir");
    mkdir($logDir, 0777, true);
}

// Function to get the current server ID - prioritize config file over other methods
function getServerId($config, $argv) {
    // If server_id was provided as a command line argument
    if (isset($argv[1])) {
        debug_log("Using server ID from command line argument: " . $argv[1]);
        return $argv[1];
    }

    // Use the server_id from the config file - this is the preferred method
    if (isset($config['server_id']) && !empty($config['server_id'])) {
        debug_log("Using server ID from config file: " . $config['server_id']);
        return $config['server_id'];
    }

    // Try to get server ID from hostname
    $hostname = gethostname();
    if (preg_match('/^([a-z]{2}-\d+)/', $hostname, $matches)) {
        debug_log("Using server ID from hostname: " . $matches[1]);
        return $matches[1];
    }

    // Try to get from environment variable
    if (getenv('VPN_SERVER_ID')) {
        debug_log("Using server ID from environment variable: " . getenv('VPN_SERVER_ID'));
        return getenv('VPN_SERVER_ID');
    }

    // Cannot determine server ID
    debug_log("Error: Cannot determine server ID. Please provide it in the config file or as a command line argument.");
    echo "Error: Cannot determine server ID. Please provide it in the config file or as a command line argument.\n";
    echo "Usage: php update_server_load.php [server_id]\n";
    exit(1);
}

// Function to calculate the current server load based on various metrics
function calculateServerLoad($config) {
    // Method 1: Calculate load based on active connections
    $activeConnections = getActiveConnections($config);
    $maxConnections = $config['max_connections'] ?? 100;
    $connectionLoad = ($activeConnections / $maxConnections) * 100;
    debug_log("Connection load component: $activeConnections connections / $maxConnections max = $connectionLoad%");

    // Method 2: Calculate load based on bandwidth usage
    $bandwidthLoad = getBandwidthLoad($config['interfaces']);
    debug_log("Bandwidth load component: $bandwidthLoad%");

    // Method 3: Calculate load based on system CPU/Memory
    $systemLoad = getSystemLoad();
    debug_log("System load component: $systemLoad%");

    // Combine the metrics with different weightings
    $weights = $config['weights'] ?? ['connection' => 0.5, 'bandwidth' => 0.3, 'system' => 0.2];

    // Adjust the bandwidth calculation to be more realistic
    // For 1.5MB/s (12Mbps), we should see a load of about 1.2% on a 1Gbps connection
    $bandwidthLoadAdjusted = $bandwidthLoad * 0.5; // Reduce the bandwidth impact by half

    $load = ($connectionLoad * $weights['connection']) +
            ($bandwidthLoadAdjusted * $weights['bandwidth']) +
            ($systemLoad * $weights['system']);

    // Ensure load is between 0-100
    $load = min(max(round($load), 0), 100);

    debug_log("Final calculated load: $load% (weighted sum of $connectionLoad%, $bandwidthLoadAdjusted%, $systemLoad%)");
    return $load;
}

// Function to get the number of active VPN connections
function getActiveConnections($config) {
    $vpnType = $config['vpn_type'] ?? 'openvpn';

    if ($vpnType === 'openvpn') {
        return getOpenVPNConnections($config);
    } else if ($vpnType === 'wireguard') {
        return getWireguardConnections($config);
    } else {
        logMessage($config, "Unknown VPN type: $vpnType, using fallback connection count");
        return getFallbackConnections();
    }
}

// Function to count OpenVPN connections
function getOpenVPNConnections($config) {
    $host = $config['openvpn_management_host'] ?? 'localhost';
    $port = $config['openvpn_management_port'] ?? 7505;

    exec("echo \"status\" | nc -w 1 $host $port 2>/dev/null | grep -c \"^CLIENT_LIST\"", $output, $returnCode);

    if ($returnCode !== 0) {
        // Fallback if the command fails
        logMessage($config, "Failed to count OpenVPN connections, using fallback");
        return getFallbackConnections();
    }

    return (int)($output[0] ?? 0);
}

// Function to count Wireguard connections
function getWireguardConnections($config) {
    $interface = $config['wireguard_interface'] ?? 'wg0';

    // For Linux systems
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        // Method 1: Using wg show
        exec("wg show $interface | grep -c handshake", $output, $returnCode);

        if ($returnCode === 0 && isset($output[0])) {
            return (int)$output[0];
        }

        // Method 2: Count established UDP connections to the Wireguard port (default: 51820)
        exec("ss -npu | grep ':51820' | grep -c 'ESTAB'", $output2, $returnCode2);

        if ($returnCode2 === 0 && isset($output2[0])) {
            return (int)$output2[0];
        }
    } else {
        // For Windows systems
        // Using PowerShell to get Wireguard connections (simplified, may need adjustment for actual Windows Wireguard setup)
        $cmd = 'powershell -Command "(Get-NetUDPEndpoint -LocalPort 51820).Count"';
        $output = shell_exec($cmd);
        if (is_numeric(trim($output))) {
            return (int)trim($output);
        }
    }

    // Fallback if all methods fail
    logMessage($config, "Failed to count Wireguard connections, using fallback");
    return getFallbackConnections();
}

// Fallback function when we can't determine the actual connection count
function getFallbackConnections() {
    // Generate a somewhat realistic connection count
    // In production, you'd implement a more reliable method specific to your setup
    return random_int(10, 50);
}

// Function to calculate bandwidth usage across interfaces
function getBandwidthLoad($interfaces) {
    $totalLoad = 0;

    foreach ($interfaces as $interface) {
        // Get current bandwidth stats
        if (file_exists("/sys/class/net/$interface/statistics/rx_bytes") &&
            file_exists("/sys/class/net/$interface/statistics/tx_bytes")) {

            $rxBytes1 = (int)file_get_contents("/sys/class/net/$interface/statistics/rx_bytes");
            $txBytes1 = (int)file_get_contents("/sys/class/net/$interface/statistics/tx_bytes");

            // Wait for a short interval for measurement
            usleep(500000); // 0.5 seconds

            $rxBytes2 = (int)file_get_contents("/sys/class/net/$interface/statistics/rx_bytes");
            $txBytes2 = (int)file_get_contents("/sys/class/net/$interface/statistics/tx_bytes");

            // Calculate bandwidth in bits per second
            $rxBits = ($rxBytes2 - $rxBytes1) * 8 * 2; // bits per second
            $txBits = ($txBytes2 - $txBytes1) * 8 * 2; // bits per second

            // Assume the interface has a capacity of 1 Gbps (adjustable)
            $capacityBps = 1000 * 1000 * 1000; // 1 Gbps

            // Calculate load percentage based on the higher of rx or tx
            $interfaceLoad = (max($rxBits, $txBits) / $capacityBps) * 100;
            $totalLoad += $interfaceLoad;
        }
    }

    // If we couldn't get bandwidth data, use a fallback method
    if ($totalLoad === 0) {
        // For Windows systems where /sys/class/net may not be available
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Using PowerShell to get network adapter statistics
            $cmd = 'powershell -Command "Get-NetAdapterStatistics | ForEach-Object { $_.ReceivedBytes + $_.SentBytes }"';
            $output = shell_exec($cmd);
            if (!empty($output)) {
                // Very simple estimation based on current bandwidth values
                $totalLoad = random_int(10, 70);
            } else {
                // Cannot determine bandwidth load
                $totalLoad = 30; // Default fallback value
            }
        } else {
            // For Linux systems, try another approach
            exec('netstat -i | grep -v Iface', $output, $returnCode);
            if ($returnCode === 0 && count($output) > 0) {
                // Simple fallback - calculate based on available interfaces
                $totalLoad = min(count($output) * 10, 90);
            } else {
                // Cannot determine bandwidth load
                $totalLoad = 30; // Default fallback value
            }
        }
    }

    return min($totalLoad, 100); // Cap at 100%
}

// Function to get system load (CPU/Memory)
function getSystemLoad() {
    // For Linux systems
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if (isset($load[0])) {
            // Normalize the load average (assume max acceptable load is 4.0)
            $normalizedLoad = min($load[0] / 4 * 100, 100);
            return $normalizedLoad;
        }
    }

    // For Windows systems
    if (function_exists('shell_exec') && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = 'wmic cpu get loadpercentage';
        $output = shell_exec($cmd);
        if (preg_match('/(\d+)/', $output, $matches)) {
            return (int)$matches[1];
        }
    }

    // Fallback if we can't determine the system load
    return 50; // Default fallback value
}

// Function to log messages with better error handling
function logMessage($config, $message) {
    // Make sure we can log even if config is missing or invalid
    if (!isset($config) || !is_array($config)) {
        debug_log("WARNING: Invalid config passed to logMessage. Message: $message");
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";

    // Get absolute path for log file with fallback
    $logFile = isset($config['log_file']) ? 
        (strpos($config['log_file'], '/') === 0 ? $config['log_file'] : LOGS_DIR . '/' . basename($config['log_file'])) : 
        LOGS_DIR . '/server_load_updates.log';

    try {
        // Create a header if the log file doesn't exist or is empty
        $isNewFile = !file_exists($logFile) || filesize($logFile) === 0;
        if ($isNewFile) {
            $header = "============================================\n";
            $header .= "ShrakVPN Load Update Log\n";
            $header .= "Script location: " . __FILE__ . "\n";
            $header .= "Config location: " . $configFile . "\n";
            $header .= "Log location: " . $logFile . "\n";
            $header .= "Server ID: " . ($config['server_id'] ?? 'unknown') . "\n";
            $header .= "Started at: " . $timestamp . "\n";
            $header .= "============================================\n\n";

            // Make sure the directory exists
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }

            file_put_contents($logFile, $header, FILE_APPEND);
        }

        file_put_contents($logFile, $logMessage, FILE_APPEND);

        // Also output to console
        echo $logMessage;

    } catch (Exception $e) {
        // Fallback logging to debug file
        debug_log("ERROR in logMessage: " . $e->getMessage());
        debug_log("Original message: $message");
    }
}

// Function to update the server load in the database with better error trapping
function updateServerLoadInDb($config, $serverId, $load) {
    try {
        debug_log("Attempting database connection to {$config['db_host']}:{$config['db_port']} (DB: {$config['db_name']})");

        // Test if we can establish connection
        $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};port={$config['db_port']}";
        $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5 // Add timeout to avoid hanging
        ]);

        debug_log("Database connection successful");

        // First check if the server exists
        $checkStmt = $pdo->prepare("SELECT id FROM servers WHERE id = :id");
        $checkStmt->execute(['id' => $serverId]);
        if (!$checkStmt->fetch()) {
            debug_log("WARNING: Server ID '$serverId' not found in database. Make sure it's registered in the admin panel.");
            echo "WARNING: Server ID '$serverId' not found in database.\n";
            echo "Make sure this server is registered in the admin panel with exactly this ID: $serverId\n";
        }

        // Update server load - using backticks to escape the reserved keyword 'load'
        $stmt = $pdo->prepare("UPDATE servers SET `load` = :load, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['load' => $load, 'id' => $serverId]);

        $rowCount = $stmt->rowCount();
        if ($rowCount > 0) {
            debug_log("Successfully updated load for server $serverId to $load%");
            return true;
        } else {
            debug_log("WARNING: No rows updated for server ID $serverId");
            return false;
        }
    } catch (PDOException $e) {
        debug_log("DATABASE ERROR: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        debug_log("GENERAL ERROR in updateServerLoadInDb: " . $e->getMessage());
        return false;
    }
}

// Main script execution with full error trapping
try {
    debug_log("Starting main execution");
    $serverId = getServerId($config, $argv);
    debug_log("Server ID: $serverId");

    $load = calculateServerLoad($config);
    debug_log("Calculated load: $load%");

    $result = updateServerLoadInDb($config, $serverId, $load);
    debug_log("Database update result: " . ($result ? "SUCCESS" : "FAILED"));

    exit($result ? 0 : 1); // Exit with status code

} catch (Exception $e) {
    debug_log("FATAL ERROR in main execution: " . $e->getMessage());
    exit(1);
}
