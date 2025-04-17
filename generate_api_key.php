<?php

/**
 * ShrakVPN API Key Generator
 *
 * Dieses Skript generiert einen sicheren API-Key, der für die Kommunikation
 * zwischen dem Admin-Panel und den VPN-Servern verwendet werden kann.
 *
 * Führen Sie das Skript aus, um einen neuen Key zu generieren:
 * php generate_api_key.php
 */

// Funktion zum Generieren eines zufälligen, sicheren API-Keys
function generateSecureApiKey($length = 32) {
    if (function_exists('random_bytes')) {
        // Moderne, kryptografisch sichere Methode
        return 'shkvpn-' . bin2hex(random_bytes($length / 2));
    } elseif (function_exists('openssl_random_pseudo_bytes')) {
        // Alternative mit OpenSSL
        return 'shkvpn-' . bin2hex(openssl_random_pseudo_bytes($length / 2));
    } else {
        // Weniger sichere Fallback-Methode für ältere PHP-Versionen
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $key = 'shkvpn-';
        for ($i = 0; $i < $length; $i++) {
            $key .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $key;
    }
}

// Generiere den Key
$apiKey = generateSecureApiKey();

// Server-ID aus der Konfiguration (falls vorhanden)
$serverId = 'unknown';
if (file_exists(__DIR__ . '/config.php')) {
    $config = require __DIR__ . '/config.php';
    $serverId = $config['server_id'] ?? 'unknown';
}

// Ausgabe mit Hinweisen formatieren
echo "=======================================================\n";
echo "        ShrakVPN SERVER API KEY GENERATOR\n";
echo "=======================================================\n\n";
echo "Server ID: $serverId\n\n";
echo "Generierter API-Key:\n";
echo "$apiKey\n\n";
echo "Um diesen API-Key zu verwenden:\n\n";
echo "1. Fügen Sie diesen Key zur .env Datei im Admin-Panel hinzu:\n";
echo "   SERVER_API_KEY=$apiKey\n\n";
echo "2. Aktualisieren Sie die config.php auf diesem VPN-Server:\n";
echo "   'server_api_key' => '$apiKey',\n\n";
echo "WICHTIG: Bewahren Sie diesen Key sicher auf und teilen Sie ihn nur\n";
echo "mit autorisierten Personen. Der Key ermöglicht den Zugriff auf\n";
echo "sensible Serverfunktionen.\n";
echo "=======================================================\n";

// Optional: Biete an, den Key direkt in die Konfigurationsdatei zu schreiben
if (PHP_SAPI === 'cli' && file_exists(__DIR__ . '/config.php')) {
    echo "\nMöchten Sie den Key automatisch in die config.php eintragen? (j/n): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    if (strtolower($line) === 'j') {
        $configContent = file_get_contents(__DIR__ . '/config.php');
        $pattern = "/'server_api_key'\s*=>\s*['\"].*['\"]/";

        if (preg_match($pattern, $configContent)) {
            // Ersetze vorhandenen Key
            $configContent = preg_replace($pattern, "'server_api_key' => '$apiKey'", $configContent);
        } else {
            // Füge Key hinzu falls noch nicht vorhanden
            $insertPattern = "/(return\s*\[\s*)/";
            $replacement = "$1\n    // API Key für die Kommunikation mit dem Admin-Panel\n    'server_api_key' => '$apiKey',\n";
            $configContent = preg_replace($insertPattern, $replacement, $configContent);
        }

        file_put_contents(__DIR__ . '/config.php', $configContent);
        echo "API-Key wurde in die config.php eingetragen.\n";
    }
    fclose($handle);
}
