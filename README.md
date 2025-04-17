sudo apt update<br>
sudo apt upgrade<br>
sudo apt install php <br>
sudo git clone coreScripts<br>
cd coreSripts<br>
setup_vpn_server.php<br>
sudo nano /etc/wireguard/wg0.conf  (Warining entfernen)<br>
sudo systemctl restart wg-quick@wg0<br>
sudo systemctl status wg-quick@wg0<br>

sudo mkdir -p /var/www/shrakvpn/api/logs<br>
sudo chown -R www-data:www-data /var/www/shrakvpn/api/logs<br>
sudo chmod -R 777 /var/www/shrakvpn/api/logs<br>
sudo touch /var/www/shrakvpn/api/logs/user_sync.log<br>
sudo chmod 666 /var/www/shrakvpn/api/logs/user_sync.log<br>

sudo ufw allow 51820/udp<br>
sudo ufw allow 80/tcp<br>
sudo ufw allow OpenSSH<br>
sudo ufw disable<br>
sudo ufw enable<br>
