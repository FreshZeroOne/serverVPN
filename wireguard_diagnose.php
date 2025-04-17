<?php
/**
 * ShrakVPN WireGuard Diagnose Tool
 * 
 * Dieses Skript führt Diagnoseprüfungen für die WireGuard-Konfiguration durch
 * und gibt detaillierte Informationen zu möglichen Problemen aus.
 */

// Header für die Ausgabe
echo "==========================================================\n";
echo "        SHRAKVPN WIREGUARD DIAGNOSE TOOL\n";
echo "==========================================================\n\n";

// Prüfe, ob wir Root-Rechte haben
$isRoot = (posix_getuid() === 0);
echo "Ausführung als Root: " . ($isRoot ? "JA" : "NEIN - Einige Aktionen könnten fehlschlagen") . "\n\n";

// 1. Prüfen, ob WireGuard installiert ist
echo "1. WireGuard Installation prüfen:\n";
$wgCommand = trim(shell_exec("which wg 2>/dev/null"));
if (empty($wgCommand)) {
    echo "   [FEHLER] WireGuard Kommandozeilen-Tool (wg) nicht gefunden!\n";
    echo "   Führen Sie 'apt-get install wireguard' aus, um WireGuard zu installieren.\n";
} else {
    echo "   [OK] WireGuard ist installiert: $wgCommand\n";
    
    // WireGuard Version
    $wgVersion = trim(shell_exec("$wgCommand --version 2>&1"));
    echo "   WireGuard Version: $wgVersion\n";
}

// 2. Prüfen, ob das WireGuard Interface existiert
echo "\n2. WireGuard Interface prüfen:\n";
$wgInterface = "wg0"; // Standard-Interface
$interfaceExists = strpos(shell_exec("ip link show $wgInterface 2>&1"), "does not exist") === false;
if (!$interfaceExists) {
    echo "   [FEHLER] WireGuard Interface '$wgInterface' existiert nicht!\n";
    echo "   Prüfen Sie, ob WireGuard korrekt gestartet wurde.\n";
} else {
    echo "   [OK] WireGuard Interface '$wgInterface' existiert.\n";
    
    // Interface Status prüfen
    $ifStatus = trim(shell_exec("ip link show $wgInterface 2>/dev/null | grep state"));
    echo "   Interface Status: $ifStatus\n";
}

// 3. WireGuard Konfigurationsdatei prüfen
echo "\n3. WireGuard Konfigurationsdatei prüfen:\n";
$configPath = "/etc/wireguard/$wgInterface.conf";
if (!file_exists($configPath)) {
    echo "   [FEHLER] Konfigurationsdatei '$configPath' existiert nicht!\n";
} else {
    echo "   [OK] Konfigurationsdatei '$configPath' existiert.\n";
    
    // Lese und analysiere Konfiguration
    $config = file_get_contents($configPath);
    echo "   Dateirechte: " . substr(sprintf('%o', fileperms($configPath)), -4) . "\n";
    echo "   Dateibesitzer: " . posix_getpwuid(fileowner($configPath))['name'] . "\n";
    echo "   Dateigröße: " . filesize($configPath) . " bytes\n";
    
    // Analysiere Inhalt
    $sections = preg_split('/\[(Interface|Peer)\]/', $config, -1, PREG_SPLIT_DELIM_CAPTURE);
    $interfaceCount = 0;
    $peerCount = 0;
    
    for ($i = 1; $i < count($sections); $i += 2) {
        $type = $sections[$i];
        $content = isset($sections[$i+1]) ? $sections[$i+1] : '';
        
        if ($type === 'Interface') {
            $interfaceCount++;
        } else if ($type === 'Peer') {
            $peerCount++;
        }
    }
    
    echo "   Interface-Abschnitte: $interfaceCount\n";
    echo "   Peer-Abschnitte: $peerCount\n";
}

// 4. WireGuard Service Status prüfen
echo "\n4. WireGuard Service Status prüfen:\n";
$serviceStatus = shell_exec("systemctl status wg-quick@$wgInterface 2>&1");
$isActive = strpos($serviceStatus, "Active: active") !== false;
echo "   Service wg-quick@$wgInterface Status: " . ($isActive ? "AKTIV" : "NICHT AKTIV") . "\n";
if (!$isActive) {
    echo "   [WARNUNG] Der WireGuard Service ist nicht aktiv!\n";
    echo "   Führen Sie 'systemctl start wg-quick@$wgInterface' aus, um ihn zu starten.\n";
}

// 5. Prüfe, ob wir die Konfigurationsdatei schreiben können
echo "\n5. Berechtigungen für Konfigurationsdatei prüfen:\n";
if (file_exists($configPath)) {
    if (is_writable($configPath)) {
        echo "   [OK] Konfigurationsdatei ist schreibbar.\n";
    } else {
        echo "   [FEHLER] Konfigurationsdatei ist NICHT schreibbar!\n";
        echo "   Führen Sie 'chmod 600 $configPath' und 'chown root:root $configPath' aus.\n";
    }
} else {
    echo "   [FEHLER] Konfigurationsdatei existiert nicht, kann Berechtigungen nicht prüfen.\n";
}

// 6. Prüfe, ob wir das WireGuard Interface konfigurieren können
echo "\n6. WireGuard Konfiguration testen:\n";
if ($interfaceExists) {
    // Führe einen Test-Befehl aus (keine Änderung)
    $testCmd = "wg show $wgInterface";
    $testResult = shell_exec("$testCmd 2>&1");
    
    if (strpos($testResult, "error") !== false || strpos($testResult, "Error") !== false) {
        echo "   [FEHLER] Konnte WireGuard Interface nicht abfragen!\n";
        echo "   Fehler: $testResult\n";
    } else {
        echo "   [OK] WireGuard Interface kann abgefragt werden.\n";
        echo "   Ausgabe:\n";
        echo "   " . str_replace("\n", "\n   ", $testResult) . "\n";
    }
} else {
    echo "   [ÜBERSPRINGEN] WireGuard Interface existiert nicht, kann Test nicht durchführen.\n";
}

// 7. Log-Verzeichnisse prüfen
echo "\n7. Log-Verzeichnisse prüfen:\n";
$logDirs = [
    '/var/www/shrakvpn/api/logs',
    '/logs',
    __DIR__ . '/logs'
];

foreach ($logDirs as $dir) {
    if (is_dir($dir)) {
        echo "   [GEFUNDEN] Log-Verzeichnis existiert: $dir\n";
        echo "   Verzeichnisrechte: " . substr(sprintf('%o', fileperms($dir)), -4) . "\n";
        echo "   Verzeichnisbesitzer: " . posix_getpwuid(fileowner($dir))['name'] . "\n";
        
        // Prüfe, ob wir in das Verzeichnis schreiben können
        $testFile = "$dir/test_write_" . time() . ".tmp";
        if (@file_put_contents($testFile, "Test") !== false) {
            echo "   [OK] Verzeichnis ist schreibbar.\n";
            unlink($testFile); // Testdatei löschen
        } else {
            echo "   [FEHLER] Verzeichnis ist NICHT schreibbar!\n";
        }
        
        // Zeige Logdateien im Verzeichnis
        $logFiles = glob("$dir/*.log");
        if (!empty($logFiles)) {
            echo "   Log-Dateien gefunden:\n";
            foreach ($logFiles as $logFile) {
                $size = filesize($logFile);
                $lastMod = date("Y-m-d H:i:s", filemtime($logFile));
                echo "      " . basename($logFile) . " ($size bytes, zuletzt geändert: $lastMod)\n";
            }
        } else {
            echo "   Keine Log-Dateien im Verzeichnis gefunden.\n";
        }
    } else {
        echo "   [NICHT GEFUNDEN] Log-Verzeichnis existiert nicht: $dir\n";
    }
}

// 8. Prüfe den PHP-Handler und ob PHP als Root ausgeführt werden kann
echo "\n8. PHP-Handler prüfen:\n";
echo "   PHP Version: " . PHP_VERSION . "\n";
echo "   PHP SAPI: " . PHP_SAPI . "\n";
echo "   Ausgeführt als Benutzer: " . posix_getpwuid(posix_getuid())['name'] . "\n";

// Zusammenfassung und Empfehlungen
echo "\n==========================================================\n";
echo "ZUSAMMENFASSUNG UND EMPFEHLUNGEN:\n";
echo "==========================================================\n\n";

if (!$interfaceExists || !$isActive) {
    echo "KRITISCHES PROBLEM: WireGuard ist nicht korrekt konfiguriert oder läuft nicht.\n";
    echo "Stellen Sie sicher, dass WireGuard installiert ist und der Service läuft.\n\n";
}

if (file_exists($configPath) && !is_writable($configPath)) {
    echo "KRITISCHES PROBLEM: Die Konfigurationsdatei ist nicht schreibbar.\n";
    echo "Ändern Sie die Berechtigungen mit 'chmod 600 $configPath'.\n\n";
}

// Zeige Beispiel für manuelles Hinzufügen eines Peers
echo "Um einen Peer manuell hinzuzufügen, können Sie folgendes tun:\n\n";
echo "1. Bearbeiten Sie die Konfigurationsdatei:\n";
echo "   nano $configPath\n\n";
echo "2. Fügen Sie am Ende folgendes hinzu:\n";
echo "   [Peer]\n";
echo "   PublicKey = IHR_ÖFFENTLICHER_SCHLÜSSEL\n";
echo "   AllowedIPs = 10.8.0.x/32\n";
echo "   PersistentKeepalive = 25\n\n";
echo "3. Wenden Sie die Konfiguration an:\n";
echo "   wg syncconf $wgInterface $configPath\n\n";

echo "Führen Sie dieses Diagnose-Tool erneut aus, um zu prüfen, ob die Probleme behoben wurden.\n";
echo "==========================================================\n";