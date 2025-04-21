<?php

/**
 * ShrakVPN Server Load Update Configuration Script
 *
 * This script sets up the configuration needed for update_server_load.php
 * and creates a cron job to regularly update server load.
 *
 * Usage:
 * php setup.php
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define constants
define('SCRIPT_DIR', __DIR__);
define('CONFIG_FILE', SCRIPT_DIR . '/config.php');
define('LOG_DIR', SCRIPT_DIR . '/logs');

// Define console colors for Windows
define('RESET', "\033[0m");
define('RED', "\033[31m");
define('GREEN', "\033[32m");
define('YELLOW', "\033[33m");
define('BLUE', "\033[34m");
define('MAGENTA', "\033[35m");
define('CYAN', "\033[36m");
define('WHITE', "\033[37m");
define('BOLD', "\033[1m");

// Enable Windows console colors
#system('');  // This trick enables ANSI color codes in Windows 10+

// Create logs directory if it doesn't exist
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0777, true);
}

// Print a fancy banner
function print_banner() {
    echo CYAN . BOLD . "
╔═══════════════════════════════════════════════════╗
║                                                   ║
║   ███████╗██╗  ██╗██████╗  █████╗ ██╗  ██╗        ║
║   ██╔════╝██║  ██║██╔══██╗██╔══██╗██║ ██╔╝        ║
║   ███████╗███████║██████╔╝███████║█████╔╝         ║
║   ╚════██║██╔══██║██╔══██╗██╔══██║██╔═██╗         ║
║   ███████║██║  ██║██║  ██║██║  ██║██║  ██╗        ║
║   ╚══════╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝        ║
║                                                   ║
║   VPN Server Load Update Configuration Tool       ║
║                                                   ║
╚═══════════════════════════════════════════════════╝
" . RESET . PHP_EOL;
}

// Setup logging with colors
function log_message($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    
    // Use colors according to log level
    $color = RESET;
    $levelColor = RESET;
    switch ($level) {
        case 'SUCCESS':
            $color = GREEN;
            $levelColor = GREEN . BOLD;
            break;
        case 'INFO':
            $color = CYAN;
            $levelColor = CYAN . BOLD;
            break;
        case 'WARNING':
            $color = YELLOW;
            $levelColor = YELLOW . BOLD;
            break;
        case 'ERROR':
            $color = RED;
            $levelColor = RED . BOLD;
            break;
        case 'STEP':
            $color = MAGENTA;
            $levelColor = MAGENTA . BOLD;
            break;
        default:
            $color = RESET;
            $levelColor = BOLD;
    }
    
    // Format log message with colors
    echo $color . "[" . WHITE . $timestamp . $color . "] " . 
         $levelColor . "[" . $level . "]" . RESET . " " . 
         $color . $message . RESET . PHP_EOL;
    
    // Write to log file without color codes
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_DIR . '/setup.log', $logMessage, FILE_APPEND);
}

// Improved function to show a progress bar
function show_progress($text, $sleep = 1) {
    echo CYAN;
    echo $text . " ";
    $chars = ['⣾', '⣽', '⣻', '⢿', '⡿', '⣟', '⣯', '⣷'];
    for ($i = 0; $i < 3; $i++) {
        foreach ($chars as $char) {
            echo "\r$text $char";
            usleep(100000); // 0.1 seconds
        }
    }
    echo "\r$text " . GREEN . "✓" . RESET . PHP_EOL;
    if ($sleep > 0) {
        sleep($sleep);
    }
}

// Function to prompt for user input with colors
function prompt($question, $default = null, $options = null) {
    $defaultText = $default !== null ? YELLOW . " [" . $default . "]" . RESET : "";
    $optionsText = "";
    
    if ($options !== null) {
        $optionsText = CYAN . " (";
        foreach ($options as $index => $option) {
            if ($index > 0) {
                $optionsText .= "/";
            }
            if ($option === $default) {
                $optionsText .= BOLD . $option . RESET . CYAN;
            } else {
                $optionsText .= $option;
            }
        }
        $optionsText .= ")" . RESET;
    }

    echo BOLD . BLUE . $question . RESET . $optionsText . $defaultText . ": " . GREEN;
    $answer = trim(fgets(STDIN));
    echo RESET;

    if (empty($answer) && $default !== null) {
        return $default;
    }

    if ($options !== null) {
        while (!in_array($answer, $options) && !($answer === "" && in_array($default, $options))) {
            echo RED . "Bitte wählen Sie eine der folgenden Optionen: " . RESET . 
                 YELLOW . implode(", ", $options) . RESET . PHP_EOL;
            echo BOLD . BLUE . $question . RESET . $optionsText . $defaultText . ": " . GREEN;
            $answer = trim(fgets(STDIN));
            echo RESET;

            if (empty($answer) && $default !== null) {
                return $default;
            }
        }
    }

    return $answer;
}

// Get server information
function collect_server_info() {
    log_message("Konfiguration für ShrakVPN Server Load Update", 'STEP');
    show_progress("Prüfe bestehende Konfiguration");

    // Check if it's a fresh install or update
    $isUpdate = file_exists(CONFIG_FILE);
    if ($isUpdate) {
        log_message("Bestehende Konfiguration gefunden. Führe Update durch.");
    } else {
        log_message("Keine bestehende Konfiguration gefunden. Führe neue Installation durch.");
    }

    echo PHP_EOL . BOLD . MAGENTA . "╔═══ SERVER KONFIGURATION ═══╗" . RESET . PHP_EOL;
    
    $serverInfo = [];

    // Get server ID
    if ($isUpdate) {
        $existingConfig = require(CONFIG_FILE);
        $serverInfo['server_id'] = $existingConfig['server_id'];
        log_message("Verwende bestehende Server-ID: " . BOLD . $serverInfo['server_id'] . RESET);
    } else {
        $serverInfo['server_id'] = prompt("Geben Sie die Server-ID ein (z.B., de-01)");
    }

    // Choose VPN type
    if ($isUpdate) {
        $serverInfo['vpn_type'] = $existingConfig['vpn_type'];
        log_message("Verwende bestehenden VPN-Typ: " . BOLD . $serverInfo['vpn_type'] . RESET);
    } else {
        $serverInfo['vpn_type'] = prompt("VPN-Typ auswählen", "wireguard", ["wireguard", "openvpn"]);
    }
    
    // Configure Wireguard port
    if ($serverInfo['vpn_type'] === 'wireguard') {
        if ($isUpdate && isset($existingConfig['wireguard_port'])) {
            $serverInfo['wireguard_port'] = $existingConfig['wireguard_port'];
            log_message("Verwende bestehenden Wireguard-Port: " . BOLD . $serverInfo['wireguard_port'] . RESET);
        } else {
            $serverInfo['wireguard_port'] = prompt("Port für Wireguard eingeben", "443");
            log_message("Wireguard wird auf Port " . BOLD . $serverInfo['wireguard_port'] . RESET . " konfiguriert");
        }
    }

    // Configure proxy settings
    echo PHP_EOL . BOLD . MAGENTA . "╔═══ PROXY KONFIGURATION ═══╗" . RESET . PHP_EOL;
    
    if ($isUpdate && isset($existingConfig['use_proxy'])) {
        $serverInfo['use_proxy'] = $existingConfig['use_proxy'];
        log_message("Verwende bestehende Proxy-Einstellung: " . BOLD . ($serverInfo['use_proxy'] ? "Ja" : "Nein") . RESET);
    } else {
        $useProxy = prompt("Möchten Sie einen Proxy verwenden", "nein", ["ja", "nein"]);
        $serverInfo['use_proxy'] = ($useProxy === 'ja');
    }
    
    if ($serverInfo['use_proxy']) {
        if ($isUpdate && isset($existingConfig['proxy_host'])) {
            $serverInfo['proxy_host'] = $existingConfig['proxy_host'];
            $serverInfo['proxy_port'] = $existingConfig['proxy_port'];
            $serverInfo['proxy_type'] = $existingConfig['proxy_type'] ?? 'socks5';
            $serverInfo['proxy_username'] = $existingConfig['proxy_username'] ?? '';
            $serverInfo['proxy_password'] = $existingConfig['proxy_password'] ?? '';
            log_message("Verwende bestehenden Proxy: " . BOLD . $serverInfo['proxy_host'] . ":" . $serverInfo['proxy_port'] . RESET);
        } else {
            $serverInfo['proxy_type'] = prompt("Proxy-Typ eingeben", "socks5", ["socks5", "socks4", "http"]);
            $serverInfo['proxy_host'] = prompt("Proxy-Host eingeben");
            $serverInfo['proxy_port'] = prompt("Proxy-Port eingeben", "1080");
            
            $useAuth = prompt("Benötigt der Proxy Authentifizierung", "nein", ["ja", "nein"]);
            if ($useAuth === 'ja') {
                $serverInfo['proxy_username'] = prompt("Proxy-Benutzername eingeben");
                $serverInfo['proxy_password'] = prompt("Proxy-Passwort eingeben");
            } else {
                $serverInfo['proxy_username'] = '';
                $serverInfo['proxy_password'] = '';
            }
            
            log_message("Proxy wird konfiguriert: " . BOLD . $serverInfo['proxy_type'] . "://" . 
                        $serverInfo['proxy_host'] . ":" . $serverInfo['proxy_port'] . RESET);
        }
    }

    // Pi-hole installation
    echo PHP_EOL . BOLD . MAGENTA . "╔═══ PI-HOLE KONFIGURATION ═══╗" . RESET . PHP_EOL;
    
    if ($isUpdate && isset($existingConfig['install_pihole'])) {
        $serverInfo['install_pihole'] = $existingConfig['install_pihole'];
        log_message("Verwende bestehende Pi-hole-Einstellung: " . BOLD . ($serverInfo['install_pihole'] ? "Ja" : "Nein") . RESET);
    } else {
        $installPihole = prompt("Möchten Sie Pi-hole installieren (Network-wide Ad-Blocker)", "nein", ["ja", "nein"]);
        $serverInfo['install_pihole'] = ($installPihole === 'ja');
    }
    
    if ($serverInfo['install_pihole']) {
        if ($isUpdate && isset($existingConfig['pihole_password'])) {
            $serverInfo['pihole_password'] = $existingConfig['pihole_password'];
            log_message("Verwende bestehendes Pi-hole-Passwort");
        } else {
            $serverInfo['pihole_password'] = prompt("Pi-hole Admin-Passwort eingeben (leer lassen für zufälliges Passwort)");
            if (empty($serverInfo['pihole_password'])) {
                $serverInfo['pihole_password'] = substr(str_shuffle(MD5(microtime())), 0, 12);
                log_message("Zufälliges Pi-hole-Passwort generiert: " . BOLD . $serverInfo['pihole_password'] . RESET);
            }
        }
        
        if ($isUpdate && isset($existingConfig['pihole_interface'])) {
            $serverInfo['pihole_interface'] = $existingConfig['pihole_interface'];
        } else {
            $serverInfo['pihole_interface'] = prompt("Pi-hole Netzwerk-Interface", "eth0");
        }
        
        if ($isUpdate && isset($existingConfig['pihole_dns1'])) {
            $serverInfo['pihole_dns1'] = $existingConfig['pihole_dns1'];
            $serverInfo['pihole_dns2'] = $existingConfig['pihole_dns2'];
        } else {
            log_message("Bitte wählen Sie die DNS-Server für Pi-hole:");
            $serverInfo['pihole_dns1'] = prompt("Primärer DNS-Server", "9.9.9.9");
            $serverInfo['pihole_dns2'] = prompt("Sekundärer DNS-Server", "149.112.112.112");
        }
        
        log_message("Pi-hole wird während der Installation konfiguriert");
    }

    echo PHP_EOL . BOLD . MAGENTA . "╔═══ DATENBANK KONFIGURATION ═══╗" . RESET . PHP_EOL;
    
    // Database connection info
    $serverInfo['db_host'] = prompt("Datenbank-Host eingeben", "85.215.238.89");
    $serverInfo['db_name'] = prompt("Datenbank-Name eingeben", "api_db");
    $serverInfo['db_user'] = prompt("Datenbank-Benutzername eingeben", "api_user");
    $serverInfo['db_password'] = prompt("Datenbank-Passwort eingeben", "weilisso001");
    $serverInfo['db_port'] = prompt("Datenbank-Port eingeben", "3306");

    echo PHP_EOL;
    return $serverInfo;
}

// Create configuration file
function create_config_file($serverInfo) {
    log_message("Erstelle Konfigurationsdatei", 'STEP');

    // Create logs directory in the SCRIPT_DIR folder
    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0777, true);
    }
    
    show_progress("Bereite Konfigurationsdaten vor");
    
    // Prepare configuration array
    $configArray = [
        // Server identification
        'server_id' => $serverInfo['server_id'],

        // VPN type
        'vpn_type' => $serverInfo['vpn_type'],

        // Wireguard port
        'wireguard_port' => $serverInfo['wireguard_port'] ?? null,

        // Proxy settings
        'use_proxy' => $serverInfo['use_proxy'] ?? false,
        'proxy_type' => $serverInfo['proxy_type'] ?? null,
        'proxy_host' => $serverInfo['proxy_host'] ?? null,
        'proxy_port' => $serverInfo['proxy_port'] ?? null,
        'proxy_username' => $serverInfo['proxy_username'] ?? null,
        'proxy_password' => $serverInfo['proxy_password'] ?? null,

        // Pi-hole settings
        'install_pihole' => $serverInfo['install_pihole'] ?? false,
        'pihole_password' => $serverInfo['pihole_password'] ?? null,
        'pihole_interface' => $serverInfo['pihole_interface'] ?? null,
        'pihole_dns1' => $serverInfo['pihole_dns1'] ?? null,
        'pihole_dns2' => $serverInfo['pihole_dns2'] ?? null,

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

        // Logging settings
        'log_file' => LOG_DIR . '/server_load_updates.log',
        'log_enabled' => true,
    ];

    // Create the config file content
    show_progress("Erstelle Konfigurationsdatei");
    $configContent = "<?php\n\n/**\n * Configuration for the VPN Server Load Update\n *\n * Generated by setup.php on " . date('Y-m-d H:i:s') . "\n */\n\nreturn " . var_export($configArray, true) . ";\n";

    // Save to the script directory
    file_put_contents(CONFIG_FILE, $configContent);

    log_message("Konfigurationsdatei erfolgreich erstellt: " . BOLD . CONFIG_FILE . RESET, 'SUCCESS');
}

// Configure Wireguard and redsocks if needed
function configure_network_components($serverInfo) {
    if ($serverInfo['vpn_type'] === 'wireguard') {
        log_message("Konfiguriere Wireguard auf Port " . $serverInfo['wireguard_port'], 'STEP');
        
        // Check if wireguard is installed
        show_progress("Prüfe Wireguard Installation");
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            log_message("Windows-System erkannt. Bitte stellen Sie sicher, dass Wireguard installiert ist.", 'WARNING');
            log_message("Für Windows muss die Wireguard-Konfiguration manuell angepasst werden.", 'WARNING');
            
            // Create a helper script for Windows
            $wgConfigHelperPath = SCRIPT_DIR . '\\configure_wireguard_port.bat';
            $wgConfigContent = "@echo off\n";
            $wgConfigContent .= "echo Dieses Skript hilft bei der Konfiguration von Wireguard auf Port " . $serverInfo['wireguard_port'] . "\n";
            $wgConfigContent .= "echo 1. Öffnen Sie die Wireguard-Konfiguration\n";
            $wgConfigContent .= "echo 2. Ändern Sie den ListenPort auf " . $serverInfo['wireguard_port'] . "\n";
            $wgConfigContent .= "echo 3. Speichern Sie die Änderungen und starten Sie den Wireguard-Dienst neu\n";
            $wgConfigContent .= "echo.\n";
            $wgConfigContent .= "echo Drücken Sie eine Taste, um die Wireguard-Benutzeroberfläche zu öffnen...\n";
            $wgConfigContent .= "pause >nul\n";
            $wgConfigContent .= "start \"\" \"C:\\Program Files\\WireGuard\\wireguard.exe\"\n";
            
            file_put_contents($wgConfigHelperPath, $wgConfigContent);
            log_message("Hilfsskript erstellt: " . BOLD . $wgConfigHelperPath . RESET);
            log_message("Führen Sie dieses Skript aus, um die Wireguard-Konfiguration anzupassen.");
        } else {
            // Linux system
            $configPath = "/etc/wireguard/wg0.conf";
            
            if (!file_exists($configPath)) {
                log_message("Wireguard-Konfigurationsdatei nicht gefunden: $configPath", 'ERROR');
                log_message("Stellen Sie sicher, dass Wireguard installiert ist. Installation mit:", 'INFO');
                log_message(BOLD . "apt update && apt install -y wireguard" . RESET);
                return;
            }
            
            show_progress("Ändere Wireguard-Port auf " . $serverInfo['wireguard_port']);
            
            // Create a backup
            $backupPath = $configPath . ".bak";
            copy($configPath, $backupPath);
            
            // Read current config
            $wgConfig = file_get_contents($configPath);
            
            // Update ListenPort
            $wgConfig = preg_replace('/ListenPort\s*=\s*\d+/', "ListenPort = " . $serverInfo['wireguard_port'], $wgConfig);
            
            // If ListenPort doesn't exist, add it to [Interface] section
            if (!preg_match('/ListenPort\s*=/', $wgConfig)) {
                $wgConfig = preg_replace('/(\[Interface\][^\[]*)/s', "$1ListenPort = " . $serverInfo['wireguard_port'] . "\n", $wgConfig);
            }
            
            // Write updated config
            file_put_contents($configPath, $wgConfig);
            
            log_message("Wireguard-Konfiguration aktualisiert. Port auf " . BOLD . $serverInfo['wireguard_port'] . RESET . " gesetzt.", 'SUCCESS');
            log_message("Wireguard neu starten mit: " . BOLD . "systemctl restart wg-quick@wg0" . RESET);
            
            // Open firewall port
            show_progress("Konfiguriere Firewall für Port " . $serverInfo['wireguard_port']);
            
            log_message("Um die Firewall zu konfigurieren, führen Sie diese Befehle aus:", 'INFO');
            log_message(BOLD . "ufw allow " . $serverInfo['wireguard_port'] . "/udp" . RESET);
            log_message(BOLD . "ufw reload" . RESET);
        }
    }
    
    // Configure redsocks for proxy if needed
    if ($serverInfo['use_proxy']) {
        log_message("Konfiguriere Proxy mit redsocks", 'STEP');
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            log_message("Redsocks wird unter Windows nicht unterstützt.", 'WARNING');
            log_message("Windows-Benutzer müssen einen anderen Proxy-Client verwenden.", 'WARNING');
        } else {
            // Linux system
            show_progress("Prüfe redsocks Installation");
            
            // Check if redsocks is installed
            $redsocksInstalled = shell_exec("which redsocks 2>/dev/null");
            if (empty($redsocksInstalled)) {
                log_message("redsocks nicht gefunden. Installation mit:", 'INFO');
                log_message(BOLD . "apt update && apt install -y redsocks" . RESET);
            }
            
            // Create redsocks configuration
            show_progress("Erstelle redsocks Konfiguration");
            
            $redsocksConfigPath = "/etc/redsocks.conf";
            $redsocksConfig = "base {
    log_debug = off;
    log_info = on;
    log = \"file:/var/log/redsocks.log\";
    daemon = on;
    redirector = iptables;
}

redsocks {
    local_ip = 0.0.0.0;
    local_port = 12345;
    
    ip = " . $serverInfo['proxy_host'] . ";
    port = " . $serverInfo['proxy_port'] . ";
    
    type = " . $serverInfo['proxy_type'] . ";
";

            // Add authentication if needed
            if (!empty($serverInfo['proxy_username']) && !empty($serverInfo['proxy_password'])) {
                $redsocksConfig .= "    login = \"" . $serverInfo['proxy_username'] . "\";\n";
                $redsocksConfig .= "    password = \"" . $serverInfo['proxy_password'] . "\";\n";
            }
            
            $redsocksConfig .= "}\n";
            
            log_message("Um redsocks zu konfigurieren, führen Sie diese Befehle aus:", 'INFO');
            log_message(BOLD . "sudo bash -c 'cat > $redsocksConfigPath << \"EOF\"\n$redsocksConfig\nEOF'" . RESET);
            log_message(BOLD . "systemctl enable redsocks" . RESET);
            log_message(BOLD . "systemctl restart redsocks" . RESET);
            
            // Create iptables rules to redirect traffic through proxy
            show_progress("Erstelle iptables Regeln für Proxy-Umleitung");
            
            $iptablesScript = "#!/bin/bash

# Redsocks iptables script

# Create new chain
iptables -t nat -N REDSOCKS

# Ignore LANs and redsocks itself
iptables -t nat -A REDSOCKS -d 0.0.0.0/8 -j RETURN
iptables -t nat -A REDSOCKS -d 10.0.0.0/8 -j RETURN
iptables -t nat -A REDSOCKS -d 127.0.0.0/8 -j RETURN
iptables -t nat -A REDSOCKS -d 169.254.0.0/16 -j RETURN
iptables -t nat -A REDSOCKS -d 172.16.0.0/12 -j RETURN
iptables -t nat -A REDSOCKS -d 192.168.0.0/16 -j RETURN
iptables -t nat -A REDSOCKS -d 224.0.0.0/4 -j RETURN
iptables -t nat -A REDSOCKS -d 240.0.0.0/4 -j RETURN

# Redirect TCP traffic to redsocks
iptables -t nat -A REDSOCKS -p tcp -j REDIRECT --to-port 12345

# Apply REDSOCKS chain to VPN traffic
iptables -t nat -A PREROUTING -i wg0 -p tcp -j REDSOCKS
";
            $iptablesScriptPath = SCRIPT_DIR . '/redsocks_iptables.sh';
            file_put_contents($iptablesScriptPath, $iptablesScript);
            chmod($iptablesScriptPath, 0755);
            
            log_message("Iptables-Skript erstellt: " . BOLD . $iptablesScriptPath . RESET, 'SUCCESS');
            log_message("Führen Sie dieses Skript aus, um den Traffic durch den Proxy zu leiten:", 'INFO');
            log_message(BOLD . "sudo " . $iptablesScriptPath . RESET);
            log_message("Um die Regeln beim Systemstart zu laden, fügen Sie dies in /etc/rc.local ein.", 'INFO');
        }
    }
}

// Install Pi-hole if needed
function install_pihole($serverInfo) {
    if (!$serverInfo['install_pihole']) {
        return;
    }

    log_message("Beginne mit Pi-hole Installation", 'STEP');

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        log_message("Pi-hole wird unter Windows nicht unterstützt.", 'ERROR');
        log_message("Pi-hole kann nur auf Linux-Systemen installiert werden.", 'ERROR');
        return;
    }

    // Create an install script for Pi-hole with automated settings
    $setupVars = [
        'PIHOLE_INTERFACE' => $serverInfo['pihole_interface'],
        'IPV4_ADDRESS' => 'auto',
        'IPV6_ADDRESS' => 'auto',
        'PIHOLE_DNS_1' => $serverInfo['pihole_dns1'],
        'PIHOLE_DNS_2' => $serverInfo['pihole_dns2'],
        'QUERY_LOGGING' => 'true',
        'INSTALL_WEB_SERVER' => 'true',
        'INSTALL_WEB_INTERFACE' => 'true',
        'LIGHTTPD_ENABLED' => 'true',
        'BLOCKING_ENABLED' => 'true',
        'WEBPASSWORD' => hash('sha256', $serverInfo['pihole_password']),
        'DNSMASQ_LISTENING' => 'single',
        'PIHOLE_BLOCKING_ENABLED' => 'true'
    ];

    $setupVarsContent = "";
    foreach ($setupVars as $key => $value) {
        $setupVarsContent .= "$key=$value\n";
    }

    $installScriptContent = "#!/bin/bash
# Pi-hole automated installation script
echo 'Beginne Pi-hole Installation...'

# Create setupVars.conf
echo 'Erstelle setupVars.conf für automatische Installation...'
cat > /etc/pihole/setupVars.conf << 'EOF'
$setupVarsContent
EOF

# Run the installer in automated mode
echo 'Starte Pi-hole Installer...'
curl -sSL https://install.pi-hole.net | PIHOLE_SKIP_OS_CHECK=true bash /dev/stdin --unattended

# Set admin password
pihole -a -p '$serverInfo[pihole_password]'

echo 'Pi-hole Installation abgeschlossen!'
echo 'Pi-hole Web Interface: http://localhost/admin'
echo 'Pi-hole Admin Passwort: $serverInfo[pihole_password]'
";

    $installScriptPath = SCRIPT_DIR . '/install_pihole.sh';
    file_put_contents($installScriptPath, $installScriptContent);
    chmod($installScriptPath, 0755);

    log_message("Pi-hole Installationsskript erstellt: " . BOLD . $installScriptPath . RESET, 'SUCCESS');
    log_message("Um Pi-hole zu installieren, führen Sie dieses Skript aus:", 'INFO');
    log_message(BOLD . "sudo " . $installScriptPath . RESET);
    
    echo PHP_EOL . CYAN . BOLD . "╔═══ PI-HOLE INSTALLATION ═══╗" . RESET . PHP_EOL;
    log_message("Nach der Installation ist Pi-hole über das Web Interface erreichbar:");
    log_message("URL: " . BOLD . "http://SERVER_IP/admin" . RESET);
    log_message("Benutzername: " . BOLD . "admin" . RESET);
    log_message("Passwort: " . BOLD . $serverInfo['pihole_password'] . RESET);
    log_message("Pi-hole kann als DNS-Server (Server IP) in der VPN-Konfiguration verwendet werden");
    log_message("um Werbung und Tracking für alle VPN-Nutzer zu blockieren.");
    echo CYAN . BOLD . "╚════════════════════════════╝" . RESET . PHP_EOL;
}

// Setup cron job for update_server_load.php
function setup_cron_job() {
    log_message("Richte geplante Aufgabe für update_server_load.php ein", 'STEP');

    // Check if running on Windows or Linux
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        setup_windows_task();
    } else {
        setup_linux_cron();
    }
}

// Setup Windows Task Scheduler
function setup_windows_task() {
    $phpPath = prompt("Vollständigen Pfad zu php.exe eingeben", "C:\\php\\php.exe");
    $scriptPath = realpath(SCRIPT_DIR . '/update_server_load.php');
    
    show_progress("Erstelle Batch-Datei für geplante Aufgabe");
    
    // Create a batch file for the task
    $batchContent = "@echo off\n\"$phpPath\" \"$scriptPath\" >> \"" . LOG_DIR . "\\cron.log\" 2>&1";
    $batchFile = SCRIPT_DIR . '\\update_server_load.bat';
    file_put_contents($batchFile, $batchContent);
    
    // Create the scheduled task (runs every minute)
    $taskName = "ShrakVPNServerLoadUpdate";
    $command = "schtasks /Create /F /SC MINUTE /MO 1 /TN $taskName /TR \"$batchFile\"";
    
    echo PHP_EOL . YELLOW . BOLD . "╔═══ WINDOWS TASK SCHEDULER ═══╗" . RESET . PHP_EOL;
    log_message("Bitte führen Sie den folgenden Befehl als Administrator aus:");
    echo WHITE . BOLD . $command . RESET . PHP_EOL . PHP_EOL;
    log_message("Oder erstellen Sie manuell eine Aufgabe im Task Scheduler, die diese Batch-Datei jede Minute ausführt:");
    log_message("Batch-Datei-Speicherort: " . BOLD . $batchFile . RESET);
    echo YELLOW . BOLD . "╚═══════════════════════════════╝" . RESET . PHP_EOL;
}

// Setup Linux Cron Job
function setup_linux_cron() {
    // Create cron job entry
    $scriptPath = realpath(SCRIPT_DIR . '/update_server_load.php');
    $logPath = LOG_DIR . '/cron.log';
    $cronEntry = "* * * * * php $scriptPath >> $logPath 2>&1\n";

    // Create temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($tempFile, $cronEntry);

    echo PHP_EOL . YELLOW . BOLD . "╔═══ LINUX CRON JOB ═══╗" . RESET . PHP_EOL;
    log_message("Führen Sie die folgenden Befehle aus, um den Cron-Job einzurichten:");
    echo WHITE . BOLD . "crontab -l > /tmp/current_cron 2>/dev/null || true" . RESET . PHP_EOL;
    echo WHITE . BOLD . "echo \"$cronEntry\" >> /tmp/current_cron" . RESET . PHP_EOL;
    echo WHITE . BOLD . "crontab /tmp/current_cron" . RESET . PHP_EOL;
    echo WHITE . BOLD . "rm /tmp/current_cron" . RESET . PHP_EOL . PHP_EOL;
    log_message("Dies konfiguriert das Server-Load-Update-Skript so, dass es jede Minute ausgeführt wird.");
    echo YELLOW . BOLD . "╚═════════════════════╝" . RESET . PHP_EOL;
}

// Main script execution
try {
    print_banner();
    echo PHP_EOL;
    
    $serverInfo = collect_server_info();
    create_config_file($serverInfo);
    configure_network_components($serverInfo);
    install_pihole($serverInfo);
    setup_cron_job();

    echo PHP_EOL;
    log_message("Setup erfolgreich abgeschlossen!", 'SUCCESS');
    log_message("Das update_server_load.php Skript wird nun die generierte config.php Datei verwenden");
    log_message("und automatisch gemäß der geplanten Aufgabe/Cron-Job ausgeführt.");
    
    echo PHP_EOL . GREEN . BOLD . "╔═════════════════════════════════════════╗" . RESET . PHP_EOL;
    echo GREEN . BOLD . "║  ShrakVPN Server Load Update eingerichtet  ║" . RESET . PHP_EOL;
    echo GREEN . BOLD . "╚═════════════════════════════════════════╝" . RESET . PHP_EOL;
    
} catch (Exception $e) {
    echo PHP_EOL;
    log_message("Fehler: " . $e->getMessage(), 'ERROR');
    exit(1);
}
