sudo apt update<br>
sudo apt upgrade<br>
sudo apt install php <br>
sudo git clone coreScripts<br>
cd coreSripts<br>
setup_vpn_server.php<br>
sudo nano /etc/wireguard/wg0.conf  (Warining entfernen)<br>
sudo systemctl restart wg-quick@wg0<br>
sudo systemctl status wg-quick@wg0<br>
sudo ufw allow 51820/udp<br>
sudo ufw allow 80/tcp<br>
sudo ufw allow OpenSSH<br>
sudo ufw disable<br>
sudo ufw enable<br>
