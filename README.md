  ```bash
## Fi
sudo apt update
sudo apt upgrade
sudo apt install php
sudo git clone serverVPN
cd serverVPN
setup.php
---

Hier eine **komplette Schritt‑für‑Schritt‑Anleitung**, um auf einem frisch aufgesetzten Ubuntu 24.04 LTS‑Server **wg‑easy** in Docker laufen zu lassen – inkl. WireGuard, Web‑UI und UFW‑Firewall.

---

## 1. SSH‑Zugang & erste Checks  
1. Melde dich als root (oder ein sudo‑fähiger User) auf deinem neuen Server an:  
   ```bash
   ssh root@DEINE_SERVER_IP
   ```  
2. Prüfe, dass du wirklich Ubuntu 24.04 nutzt:  
   ```bash
   lsb_release -a
   ```

## 2. System aktualisieren  
```bash
apt update && apt upgrade -y
```

## 3. Docker CE & Docker Compose installieren  
```bash
# Notwendige Pakete
apt install -y apt-transport-https ca-certificates curl gnupg lsb-release

# Docker GPG‑Key
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg

# Docker‑Repository einrichten
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] \
  https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
  | tee /etc/apt/sources.list.d/docker.list > /dev/null

# Docker installieren
apt update
apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin


## 4. Verzeichnis für wg‑easy anlegen  
```bash
mkdir -p ~/wg-easy
cd ~/wg-easy
```

## 5. `docker-compose.yml` aus production‑Branch holen  
```bash
curl -fsSL \
  https://raw.githubusercontent.com/wg-easy/wg-easy/production/docker-compose.yml \
  -o docker-compose.yml
```

## 6. Admin‑Passwort hashen  
Ersetze im Folgenden `MeinSicheresPasswort123!` durch dein Wunsch‑Passwort:
```bash
docker run --rm ghcr.io/wg-easy/wg-easy wgpw weilisso001
```
Du bekommst einen Hash zurück, z. B.  
```
$2b$12$AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefghi  
```
## Wichtig jedes $ mit einem $$ ergänzen 
$$2b$$12$$AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefghi

→ Diesen kopieren, wir setzen ihn gleich als Umgebungsvariable.

## 7. `docker-compose.yml` anpassen  
Öffne `docker-compose.yml` im Editor und unter `environment:` folgende Werte setzen:

```yaml
    environment:
      - LANG=de
      - WG_HOST=167.172.32.251                  # deine Server‑IP oder DNS
      - PASSWORD_HASH=$$2b$$12$$AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefghi
      - PORT=8123                                # Web‑UI‑Port intern
      - WG_PORT=51820                            # WireGuard‑Port intern
```

Falls `environment:` dort noch nicht existiert, füge es unter dem Service `wg-easy:` ein.

Beispiel‑Ausschnitt:
```yaml
version: '3.6'
services:
  wg-easy:
    image: ghcr.io/wg-easy/wg-easy:latest
    container_name: wg-easy
    restart: unless-stopped
    cap_add:
      - NET_ADMIN
      - SYS_MODULE
    sysctls:
      - net.ipv4.ip_forward=1
      - net.ipv4.conf.all.src_valid_mark=1
    volumes:
      - etc_wireguard:/etc/wireguard
    ports:
      - "51820:51820/udp"    # VPN‑Port
      - "51821:51821/tcp"     # Web‑UI extern:intern
    environment:
      - LANG=de
      - WG_HOST=167.172.32.251
      - PASSWORD_HASH=$2b$12$AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefghi
      - PORT=8123
      - WG_PORT=51820

volumes:
  etc_wireguard:
```

> **Wichtig:** Der Port `8123:51821/tcp` mappt extern 8123 auf den internen UI‑Port 51821.

## 8. UFW‑Firewall konfigurieren  
```bash
apt install -y ufw
ufw allow OpenSSH
ufw allow 51821/tcp    # Web‑UI
ufw allow 51820/udp   # WireGuard
ufw --force enable
```

## 9. Container starten  
```bash
docker compose up -d
```

## 10. Überprüfung & Login  
1. **Container‑Status**  
   ```bash
   docker ps | grep wg-easy
   ```
   → STATUS sollte `Up` sein.

2. **Port‑Prüfung**  
   ```bash
   ss -tuln | grep -E "51821|51820"
   ```
   → Du solltest `LISTEN` auf `0.0.0.0:51821` und `0.0.0.0:51820` sehen.

3. **Web‑UI aufrufen**  
   Im Browser:  
   ```
   http://167.172.32.251:51821
   ```  
   Login mit Benutzer **admin** und deinem Klartext‑Passwort (z. B. `MeinSicheresPasswort123!`).

---

Für dein WG‑Subnetz `10.8.0.0/24` musst du auf dem **Server** zwei Dinge sicherstellen:

1. **IP‑Forwarding aktivieren**  
2. **NAT/Masquerade** für das WG‑Netz definieren

Angenommen dein externes Interface heißt `eth0` und das WireGuard‑Interface auf dem Server `wg0`, führst du Folgendes aus:

---

### 1. IP‑Forwarding einschalten

# Dauerhaft eintragen
sudo sed -i 's/^#*\s*net.ipv4.ip_forward=.*/net.ipv4.ip_forward=1/' /etc/sysctl.conf
sudo sysctl -p
```

### 2. NAT/Masquerade-Regel setzen


#### b) Oder in UFW (falls du UFW nutzt)

1. **Forwarding erlauben**  
   In `/etc/default/ufw` die Zeile  
   ```diff
   - DEFAULT_FORWARD_POLICY="DROP"
   + DEFAULT_FORWARD_POLICY="ACCEPT"
   ```
2. **Masquerade in die UFW‑Before‑Rules**  
   Öffne `/etc/ufw/before.rules` und ganz **oberhalb** von `*filter` füge ein:
   ```text
   *nat
   :POSTROUTING ACCEPT [0:0]
   -A POSTROUTING -s 10.8.0.0/24 -o eth0 -j MASQUERADE
   COMMIT
   ```
3. **UFW neu laden**  
   ```bash
   sudo ufw reload
   ```

---

Nach diesen Schritten solltest du:

- Mit `sysctl net.ipv4.ip_forward` den Wert `1` sehen.  
- Mit `iptables -t nat -L POSTROUTING` deine Masquerade‑Regel gelistet haben.  
- Deine WG‑Clients können dann mit AllowedIPs `0.0.0.0/0` über den Server ins Internet routen.

Das war’s! Dein neuer Ubuntu 24.04‑Server läuft nun als WireGuard‑VPN mit **wg‑easy** Web‑Interface, abgesichert durch UFW. Viel Spaß beim Ausprobieren!
