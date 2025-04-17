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
system('');  // This trick enables ANSI color codes in Windows 10+

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
