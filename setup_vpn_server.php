<?php

/**
 * ShrakVPN Server Installation and Configuration Script
 *
 * This script automates the process of installing and configuring a new VPN server
 * that communicates with the ShrakVPN admin panel. It handles:
 *
 * - VPN software installation (OpenVPN or WireGuard)
 * - Server configuration
 * - Apache setup for the API
 * - Configuration file creation
 * - Cron job setup for update_server_load.php
 *
 * Usage:
 * sudo php setup_vpn_server.php
 *
 * Note: This script must be run with root privileges.
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if script is run as root
if (posix_getuid() !== 0) {
    echo "Error: This script must be run with root privileges (sudo).\n";
    exit(1);
}

// Define constants
define('SCRIPT_DIR', __DIR__);
define('CONFIG_FILE', SCRIPT_DIR . '/config.php');
define('LOG_FILE', SCRIPT_DIR . '/setup_vpn_server.log');

// Setup logging
function log_message($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
    echo $logMessage;
}

// Helper function to run shell commands
function run_command($command) {
    log_message("Executing: $command", 'DEBUG');
    $output = [];
    $returnVar = 0;
    exec($command . ' 2>&1', $output, $returnVar);
    $outputString = implode("\n", $output);

    if ($returnVar !== 0) {
        log_message("Command failed: $outputString", 'ERROR');
        return false;
    }

    log_message("Command output: $outputString", 'DEBUG');
    return $outputString;
}

// Get server's public IP address
function get_public_ip() {
    log_message("Detecting server's public IP address...");
    $ip = run_command("curl -s https://ipinfo.io/ip");

    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
        // Fallback methods
        $ip = run_command("curl -s https://api.ipify.org");

        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            log_message("Failed to detect public IP automatically", 'WARNING');
            return false;
        }
    }

    log_message("Detected public IP: $ip");
    return $ip;
}

// Function to prompt for user input
function prompt($question, $default = null, $options = null) {
    $defaultText = $default !== null ? " [$default]" : "";
    $optionsText = $options !== null ? " (" . implode("/", $options) . ")" : "";

    echo $question . $optionsText . $defaultText . ": ";
    $answer = trim(fgets(STDIN));

    if (empty($answer) && $default !== null) {
        return $default;
    }

    if ($options !== null) {
        while (!in_array($answer, $options) && !($answer === "" && in_array($default, $options))) {
            echo "Please enter one of: " . implode(", ", $options) . "\n";
            echo $question . $optionsText . $defaultText . ": ";
            $answer = trim(fgets(STDIN));

            if (empty($answer) && $default !== null) {
                return $default;
            }
        }
    }

    return $answer;
}

// Main setup function
function setup_vpn_server() {
    log_message("Starting ShrakVPN server installation and configuration");

    // Check if it's a fresh install or update
    $isUpdate = file_exists(CONFIG_FILE);
    if ($isUpdate) {
        log_message("Existing configuration found. Running in update mode.");
    } else {
        log_message("No existing configuration found. Running fresh installation.");
    }

    // Get server information
    $serverInfo = [];

    // Get server ID
    if ($isUpdate) {
        $existingConfig = require(CONFIG_FILE);
        $serverInfo['server_id'] = $existingConfig['server_id'];
        log_message("Using existing server ID: {$serverInfo['server_id']}");
    } else {
        $serverInfo['server_id'] = prompt("Enter the server ID (e.g., de-01)");
    }

    // Get public IP
    $publicIp = get_public_ip();
    if ($publicIp) {
        $serverInfo['public_ip'] = $publicIp;
    } else {
        $serverInfo['public_ip'] = prompt("Enter the server's public IP address");
    }

    // Choose VPN type
    if ($isUpdate) {
        $serverInfo['vpn_type'] = $existingConfig['vpn_type'];
        log_message("Using existing VPN type: {$serverInfo['vpn_type']}");
    } else {
        $serverInfo['vpn_type'] = prompt("Select VPN type", "wireguard", ["wireguard", "openvpn"]);
    }

    // Admin panel connection details
    $serverInfo['admin_panel_url'] = prompt("Enter admin panel API URL", "https://1upone.com/api");
    $serverInfo['server_api_key'] = prompt("Enter the API key generated from admin panel");

    // Database connection info
    $serverInfo['db_host'] = prompt("Enter database host", "85.215.238.89");
    $serverInfo['db_name'] = prompt("Enter database name", "api_db");
    $serverInfo['db_user'] = prompt("Enter database username", "api_user");
    $serverInfo['db_password'] = prompt("Enter database password", "weilisso001");
    $serverInfo['db_port'] = prompt("Enter database port", "3306");

    // Install required software
    install_required_packages($serverInfo);

    // Install and configure VPN software
    if ($serverInfo['vpn_type'] === 'wireguard') {
        $wireguardInfo = setup_wireguard($serverInfo);
        $serverInfo = array_merge($serverInfo, $wireguardInfo);
    } else {
        setup_openvpn($serverInfo);
    }

    // Configure Apache for the API
    setup_apache_for_api($serverInfo);

    // Create configuration file
    create_config_file($serverInfo);

    // Setup cron job for server load updates
    setup_cron_job();

    // Final steps and verification
    verify_installation($serverInfo);

    log_message("ShrakVPN server setup completed successfully!");
    log_message("Server ID: {$serverInfo['server_id']}");
    log_message("VPN Type: {$serverInfo['vpn_type']}");
    log_message("IP Address: {$serverInfo['public_ip']}");

    return true;
}

// Install required packages
function install_required_packages($serverInfo) {
    log_message("Installing required packages...");

    // Update package lists
    run_command("apt-get update");

    // Common packages
    $commonPackages = "apache2 php php-curl php-mysql php-json curl unzip git";
    run_command("apt-get install -y $commonPackages");

    // VPN specific packages
    if ($serverInfo['vpn_type'] === 'wireguard') {
        run_command("apt-get install -y wireguard");
    } else {
        run_command("apt-get install -y openvpn easy-rsa");
    }

    log_message("Required packages installed successfully");
}

// Setup WireGuard
function setup_wireguard($serverInfo) {
    log_message("Setting up WireGuard VPN...");

    // Create WireGuard directory if it doesn't exist
    run_command("mkdir -p /etc/wireguard");

    // Generate server private key
    run_command("wg genkey > /etc/wireguard/server_private.key");

    // Get the server private key and store it in wg0.conf
    $privateKey = trim(run_command("cat /etc/wireguard/server_private.key"));
    
    // Get the server public key
    $publicKey = trim(run_command("echo $privateKey | wg pubkey"));

    // Store the public key
    run_command("echo $publicKey > /etc/wireguard/server_public.key");

    // Create WireGuard server config
    $wgConfig = <<<EOT
[Interface]
PrivateKey = $privateKey
Address = 10.0.0.1/24
ListenPort = 51820
SaveConfig = false
PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE

# Clients will be added dynamically
EOT;

    file_put_contents("/etc/wireguard/wg0.conf", $wgConfig);

    // For API access - Create a simple text file with just the public key
    // This will be easier for the API to retrieve
    file_put_contents("/etc/wireguard/public.key", $publicKey);

    // Enable IP forwarding
    run_command("echo 'net.ipv4.ip_forward=1' > /etc/sysctl.d/99-sysctl.conf");
    run_command("sysctl -p /etc/sysctl.d/99-sysctl.conf");

    // Enable and start WireGuard
    run_command("systemctl enable wg-quick@wg0");
    run_command("systemctl start wg-quick@wg0");

    log_message("WireGuard VPN setup completed");
    log_message("Server public key: $publicKey");
    
    // Save the public key in the configuration
    return [
        'wireguard_public_key' => $publicKey
    ];
}

// Setup OpenVPN
function setup_openvpn($serverInfo) {
    log_message("Setting up OpenVPN...");

    // Create directory for easy-rsa
    run_command("mkdir -p /etc/openvpn/easy-rsa");

    // Copy easy-rsa files
    run_command("cp -r /usr/share/easy-rsa/* /etc/openvpn/easy-rsa/");

    // Initialize the PKI
    run_command("cd /etc/openvpn/easy-rsa && ./easyrsa init-pki");

    // Build CA
    $caPassphrase = bin2hex(random_bytes(16));
    file_put_contents("/etc/openvpn/ca_passphrase.txt", $caPassphrase);
    run_command("cd /etc/openvpn/easy-rsa && echo '$caPassphrase' | ./easyrsa build-ca nopass");

    // Generate server certificate and key
    run_command("cd /etc/openvpn/easy-rsa && ./easyrsa build-server-full server nopass");

    // Generate Diffie-Hellman parameters
    run_command("cd /etc/openvpn/easy-rsa && ./easyrsa gen-dh");

    // Generate HMAC signature
    run_command("cd /etc/openvpn/easy-rsa && openvpn --genkey --secret pki/ta.key");

    // Copy the files to OpenVPN directory
    run_command("cp /etc/openvpn/easy-rsa/pki/ca.crt /etc/openvpn/");
    run_command("cp /etc/openvpn/easy-rsa/pki/issued/server.crt /etc/openvpn/");
    run_command("cp /etc/openvpn/easy-rsa/pki/private/server.key /etc/openvpn/");
    run_command("cp /etc/openvpn/easy-rsa/pki/dh.pem /etc/openvpn/");
    run_command("cp /etc/openvpn/easy-rsa/pki/ta.key /etc/openvpn/");

    // Create server configuration
    $serverConfig = <<<EOT
port 1194
proto udp
dev tun
ca ca.crt
cert server.crt
key server.key
dh dh.pem
auth SHA256
tls-auth ta.key 0
topology subnet
server 10.8.0.0 255.255.255.0
ifconfig-pool-persist ipp.txt
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS 8.8.8.8"
push "dhcp-option DNS 8.8.4.4"
keepalive 10 120
cipher AES-256-CBC
user nobody
group nogroup
persist-key
persist-tun
status openvpn-status.log
log-append openvpn.log
verb 3
management localhost 7505
EOT;

    file_put_contents("/etc/openvpn/server.conf", $serverConfig);

    // Enable IP forwarding
    run_command("echo 'net.ipv4.ip_forward=1' > /etc/sysctl.d/99-sysctl.conf");
    run_command("sysctl -p /etc/sysctl.d/99-sysctl.conf");

    // Setup IP tables
    run_command("iptables -t nat -A POSTROUTING -s 10.8.0.0/24 -o eth0 -j MASQUERADE");
    run_command("iptables-save > /etc/iptables/rules.v4");

    // Enable and start OpenVPN
    run_command("systemctl enable openvpn@server");
    run_command("systemctl start openvpn@server");

    log_message("OpenVPN setup completed");
}

// Configure Apache for API
function setup_apache_for_api($serverInfo) {
    log_message("Setting up Apache for the API...");

    // Create API directory
    run_command("mkdir -p /var/www/shrakvpn/api");

    // Copy all scripts to the API directory
    run_command("cp " . SCRIPT_DIR . "/*.php /var/www/shrakvpn/api/");

    // Create Apache virtual host configuration
    $vhostConfig = <<<EOT
<VirtualHost *:80>
    ServerName api.{$serverInfo['server_id']}.shrakvpn.com
    ServerAlias {$serverInfo['public_ip']}

    DocumentRoot /var/www/shrakvpn/api

    <Directory /var/www/shrakvpn/api>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/api-error.log
    CustomLog \${APACHE_LOG_DIR}/api-access.log combined
</VirtualHost>
EOT;

    file_put_contents("/etc/apache2/sites-available/shrakvpn-api.conf", $vhostConfig);

    // Enable the site
    run_command("a2ensite shrakvpn-api.conf");

    // Enable required Apache modules
    run_command("a2enmod rewrite");

    // Create .htaccess for the API
    $htaccessContent = <<<EOT
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ server_api.php/$1 [L]
</IfModule>
EOT;

    file_put_contents("/var/www/shrakvpn/api/.htaccess", $htaccessContent);

    // Restart Apache
    run_command("systemctl restart apache2");

    log_message("Apache setup for API completed");
}

// Create configuration file
function create_config_file($serverInfo) {
    log_message("Creating configuration file...");

    // Create logs directory in the API folder
    run_command("mkdir -p /var/www/shrakvpn/api/logs");
    run_command("chmod 755 /var/www/shrakvpn/api/logs");
    
    // Prepare configuration array
    $configArray = [
        // Server identification
        'server_id' => $serverInfo['server_id'],

        // VPN type
        'vpn_type' => $serverInfo['vpn_type'],

        // Server IP
        'public_ip' => $serverInfo['public_ip'],

        // Database connection details
        'db_host' => $serverInfo['db_host'],
        'db_name' => $serverInfo['db_name'],
        'db_user' => $serverInfo['db_user'],
        'db_password' => $serverInfo['db_password'],
        'db_port' => (int)$serverInfo['db_port'],

        // VPN server settings
        'interfaces' => $serverInfo['vpn_type'] === 'wireguard' ? ['wg0', 'eth0'] : ['tun0', 'eth0'],
        'max_connections' => 100,

        // Load calculation weights
        'weights' => [
            'connection' => 0.5,
            'bandwidth' => 0.3,
            'system' => 0.2,
        ],

        // OpenVPN specific settings
        'openvpn_management_host' => 'localhost',
        'openvpn_management_port' => 7505,

        // Wireguard specific settings
        'wireguard_interface' => 'wg0',
        'wireguard_public_key' => $serverInfo['wireguard_public_key'] ?? null,

        // Logging settings
        'log_file' => '/var/www/shrakvpn/api/logs/server_load_updates.log',
        'log_enabled' => true,

        // Admin panel API communication
        'admin_panel_url' => $serverInfo['admin_panel_url'],
        'server_api_key' => $serverInfo['server_api_key'],

        // User configuration storage
        'user_config_dir' => '/var/www/shrakvpn/api/user_db',
        'auth_log_file' => '/var/www/shrakvpn/api/logs/auth_attempts.log',
    ];

    // Create the config file content
    $configContent = "<?php\n\n/**\n * Configuration for the VPN Server\n *\n * Generated by setup_vpn_server.php on " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($configArray, true) . ";\n";

    // Save to both the script directory and the API directory
    file_put_contents(CONFIG_FILE, $configContent);
    file_put_contents('/var/www/shrakvpn/api/config.php', $configContent);

    // Create user db directory
    run_command("mkdir -p {$configArray['user_config_dir']}");
    run_command("chmod 755 {$configArray['user_config_dir']}");

    log_message("Configuration file created successfully");
}

// Setup cron job for update_server_load.php
function setup_cron_job() {
    log_message("Setting up cron job for update_server_load.php...");

    // Create cron job entry
    $cronEntry = "* * * * * php /var/www/shrakvpn/api/update_server_load.php >> /var/www/shrakvpn/api/cron.log 2>&1\n";

    // Create temporary file
    $tempFile = tempnam('/tmp', 'cron');
    file_put_contents($tempFile, $cronEntry);

    // Add to crontab
    run_command("crontab -l > /tmp/current_cron 2>/dev/null || true");

    // Check if the entry already exists
    $existingCron = file_get_contents('/tmp/current_cron');
    if (strpos($existingCron, 'update_server_load.php') === false) {
        file_put_contents('/tmp/current_cron', $existingCron . $cronEntry);
        run_command("crontab /tmp/current_cron");
        log_message("Cron job added successfully");
    } else {
        log_message("Cron job already exists, skipping");
    }

    // Clean up temporary files
    unlink($tempFile);
    unlink('/tmp/current_cron');
}

// Verify installation
function verify_installation($serverInfo) {
    log_message("Verifying installation...");

    $checks = [
        'VPN Service' => $serverInfo['vpn_type'] === 'wireguard'
            ? "systemctl is-active wg-quick@wg0"
            : "systemctl is-active openvpn@server",
        'Apache Service' => "systemctl is-active apache2",
        'Config File' => "test -f /var/www/shrakvpn/api/config.php && echo 'exists'",
        'API Scripts' => "test -f /var/www/shrakvpn/api/server_api.php && echo 'exists'"
    ];

    $allPassed = true;
    foreach ($checks as $name => $command) {
        $result = trim(run_command($command));
        $passed = $result === 'active' || $result === 'exists';

        log_message("Checking $name: " . ($passed ? "PASSED" : "FAILED"), $passed ? 'INFO' : 'ERROR');

        if (!$passed) {
            $allPassed = false;
        }
    }

    if ($allPassed) {
        log_message("All installation checks passed!", 'SUCCESS');
    } else {
        log_message("Some installation checks failed. Please review the logs and fix any issues.", 'WARNING');
    }
}

// Run the setup
try {
    setup_vpn_server();
} catch (Exception $e) {
    log_message("Error: " . $e->getMessage(), 'ERROR');
    exit(1);
}
