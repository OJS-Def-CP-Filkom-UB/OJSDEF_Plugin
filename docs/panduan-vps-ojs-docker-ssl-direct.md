# Panduan Setup OJS di VPS dengan Docker + Nginx + SSL Let's Encrypt (Tanpa Cloudflare Proxy)

**Tujuan:** Menjalankan OJS di VPS dengan Docker, Nginx sebagai reverse proxy, SSL dari Let's Encrypt langsung di VPS, DNS dikelola di Cloudflare (mode DNS Only), lalu menginstall plugin OJSDef Security Scanner.

> **WAJIB — Sebelum memulai:** Tentukan subdomain kamu terlebih dahulu, lalu **ganti semua kemunculan `ojs.contoh.ac.id`** di dokumen ini dan di setiap file konfigurasi yang dibuat. Contoh subdomain aktual: `ojs-test.zentaza.online`.

**Perbedaan utama dari panduan Cloudflare Proxy:**
- SSL dikelola **Certbot + Let's Encrypt** langsung di VPS — bukan Cloudflare
- Cloudflare hanya sebagai DNS resolver (awan **abu-abu / DNS Only**, bukan awan oranye)
- Nginx mendengarkan port **80 dan 443**
- Traffic: `Browser ──HTTPS──► Nginx (VPS) ──HTTP──► OJS (Apache, port 80 internal)`

**Versi OJS:** 3.4.x (`pkpofficial/ojs:stable-3_4_0`)
**Estimasi waktu:** 60–90 menit

---

## Daftar Isi

1. [Spesifikasi VPS](#1-spesifikasi-vps)
2. [Install Docker dan Docker Compose](#2-install-docker-dan-docker-compose)
3. [Konfigurasi DNS di Cloudflare](#3-konfigurasi-dns-di-cloudflare)
4. [Install Certbot dan Dapatkan Sertifikat SSL](#4-install-certbot-dan-dapatkan-sertifikat-ssl)
5. [Buat File Konfigurasi](#5-buat-file-konfigurasi)
6. [Jalankan Container](#6-jalankan-container)
7. [Konfigurasi OJS untuk HTTPS](#7-konfigurasi-ojs-untuk-https) ← **WAJIB sebelum wizard**
8. [Jalankan Wizard Instalasi OJS](#8-jalankan-wizard-instalasi-ojs)
9. [Setup Auto-Renewal SSL](#9-setup-auto-renewal-ssl)
10. [Install Plugin OJSDef](#10-install-plugin-ojsdef)
11. [Konfigurasi Plugin OJSDef](#11-konfigurasi-plugin-ojsdef)
12. [Verifikasi Domain OJS di Dashboard](#12-verifikasi-domain-ojs-di-dashboard)
13. [Manajemen Container (Restart yang Aman)](#13-manajemen-container-restart-yang-aman)
14. [Troubleshooting](#14-troubleshooting)

---

## 1. Spesifikasi VPS

| Komponen | Minimum | Rekomendasi |
|----------|---------|-------------|
| OS | Ubuntu 22.04 LTS | Ubuntu 22.04 LTS |
| CPU | 2 vCPU | 4 vCPU |
| RAM | 2 GB | 4 GB |
| Storage | 20 GB SSD | 40 GB SSD |
| Port terbuka | **80, 443, 22** | 80, 443, 22 |

> Semua perintah dijalankan sebagai user dengan akses `sudo`.

---

## 2. Install Docker dan Docker Compose

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y ca-certificates curl gnupg lsb-release unzip

sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
  sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# (Opsional) agar tidak perlu sudo di setiap perintah docker
sudo usermod -aG docker $USER && newgrp docker
```

Verifikasi:

```bash
docker --version
docker compose version
```

Buka firewall VPS:

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status
```

---

## 3. Konfigurasi DNS di Cloudflare

> **PENTING:** Gunakan mode **DNS Only** (awan abu-abu) — bukan Proxied (awan oranye). Traffic harus langsung ke VPS agar Certbot dapat memverifikasi domain dan SSL berjalan di VPS.

### 3.1 Tambah A Record

1. Login ke [Cloudflare Dashboard](https://dash.cloudflare.com) → pilih domain
2. **DNS** → **Records** → **Add record**

| Field | Nilai |
|-------|-------|
| Type | `A` |
| Name | `ojs` (nama subdomain) |
| IPv4 address | IP publik VPS |
| Proxy status | **DNS only** (awan abu-abu — WAJIB) |
| TTL | `Auto` (atau `3600`) |

Klik **Save**.

### 3.2 Verifikasi propagasi DNS

Tunggu beberapa menit lalu verifikasi dari terminal:

```bash
nslookup ojs.contoh.ac.id
# atau
dig ojs.contoh.ac.id +short
```

Output harus menampilkan **IP publik VPS** — bukan IP Cloudflare (104.x.x.x / 172.x.x.x).

> Jika output adalah IP Cloudflare, proxy masih aktif. Pastikan Proxy status di Cloudflare sudah **DNS Only** (abu-abu) sebelum lanjut.

---

## 4. Install Certbot dan Dapatkan Sertifikat SSL

> **Certbot diinstall langsung di host VPS** — bukan di dalam container. Sertifikat yang dihasilkan akan di-mount ke container Nginx.

### 4.1 Install Certbot

```bash
sudo apt install -y certbot
```

### 4.2 Pastikan port 80 kosong

```bash
sudo ss -tlnp | grep ':80'
# Jika ada proses lain, hentikan dahulu
```

### 4.3 Dapatkan sertifikat SSL

> **Ganti `ojs.contoh.ac.id`** dengan subdomain kamu.
> **Ganti `email@kamu.com`** dengan email aktif.

```bash
sudo certbot certonly --standalone \
    -d ojs.contoh.ac.id \
    --email email@kamu.com \
    --agree-tos \
    --no-eff-email
```

Jika berhasil, sertifikat tersimpan di:

```
/etc/letsencrypt/live/ojs.contoh.ac.id/fullchain.pem
/etc/letsencrypt/live/ojs.contoh.ac.id/privkey.pem
```

Verifikasi:

```bash
sudo ls -la /etc/letsencrypt/live/ojs.contoh.ac.id/
```

---

## 5. Buat File Konfigurasi

### 5.1 Buat direktori project

```bash
mkdir -p ~/ojs-docker/certbot-webroot && cd ~/ojs-docker
```

### 5.2 Buat `nginx.conf`

> **Ganti semua `ojs.contoh.ac.id`** dengan subdomain kamu.

```bash
nano nginx.conf
```

```nginx
# Redirect HTTP ke HTTPS + webroot certbot renewal
server {
    listen 80;
    server_name ojs.contoh.ac.id;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl;
    server_name ojs.contoh.ac.id;

    ssl_certificate     /etc/letsencrypt/live/ojs.contoh.ac.id/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ojs.contoh.ac.id/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    add_header Strict-Transport-Security "max-age=63072000" always;
    client_max_body_size 32M;

    # Rewrite /api/v1/* -> /index/api/v1/* agar melewati OJS router
    # (tanpa ini Apache eksekusi file PHP langsung dan namespace OJS tidak ter-load)
    location ~ ^/api/v1(/.*)?$ {
        rewrite ^/api/v1(/.*)?$ /index/api/v1$1 break;
        proxy_pass         http://ojs:80;
        proxy_http_version 1.1;
        proxy_set_header Host               $host;
        proxy_set_header X-Real-IP          $remote_addr;
        proxy_set_header X-Forwarded-For    $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto  https;
        proxy_set_header Accept-Encoding    "";
        proxy_redirect http://ojs.contoh.ac.id https://ojs.contoh.ac.id;
        sub_filter 'http://ojs.contoh.ac.id' 'https://ojs.contoh.ac.id';
        sub_filter 'http:\/\/ojs.contoh.ac.id' 'https:\/\/ojs.contoh.ac.id';
        sub_filter_once off;
        sub_filter_types application/json text/html text/css application/javascript;
        proxy_read_timeout 120s;
        proxy_connect_timeout 10s;
    }

    # Rewrite /install/* -> /index/install/* (OJS restful URL menghapus konteks "index")
    location ~ ^/install(/.*)?$ {
        rewrite ^/install(/.*)?$ /index/install$1 break;
        proxy_pass         http://ojs:80;
        proxy_http_version 1.1;
        proxy_set_header Host               $host;
        proxy_set_header X-Real-IP          $remote_addr;
        proxy_set_header X-Forwarded-For    $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto  https;
        proxy_set_header Accept-Encoding    "";
        proxy_redirect http://ojs.contoh.ac.id https://ojs.contoh.ac.id;
        sub_filter 'http://ojs.contoh.ac.id' 'https://ojs.contoh.ac.id';
        sub_filter 'http:\/\/ojs.contoh.ac.id' 'https:\/\/ojs.contoh.ac.id';
        sub_filter_once off;
        sub_filter_types text/html text/css application/javascript application/json;
        proxy_read_timeout 120s;
        proxy_connect_timeout 10s;
    }

    location / {
        proxy_pass         http://ojs:80;
        proxy_http_version 1.1;
        proxy_set_header Host               $host;
        proxy_set_header X-Real-IP          $remote_addr;
        proxy_set_header X-Forwarded-For    $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto  https;
        # Nonaktifkan kompresi agar sub_filter bisa bekerja
        proxy_set_header Accept-Encoding    "";
        # Rewrite Location header pada redirect OJS (http -> https)
        proxy_redirect http://ojs.contoh.ac.id https://ojs.contoh.ac.id;
        # Ganti http:// -> https:// di body (plain URL — untuk HTML/JS/CSS)
        sub_filter 'http://ojs.contoh.ac.id' 'https://ojs.contoh.ac.id';
        # Ganti http:\/\/ -> https:\/\/ di body (JSON-escaped URL — untuk AJAX response)
        sub_filter 'http:\/\/ojs.contoh.ac.id' 'https:\/\/ojs.contoh.ac.id';
        sub_filter_once off;
        sub_filter_types text/css application/javascript application/json;
        proxy_read_timeout 120s;
        proxy_connect_timeout 10s;
    }
}
```

**Penjelasan konfigurasi nginx khusus OJS:**

| Direktif | Alasan |
|----------|--------|
| `rewrite /api/v1 -> /index/api/v1` | OJS 3.4 restful URL menghilangkan konteks "index". Tanpa rewrite, Apache langsung eksekusi `api/v1/contexts/index.php` → OJS namespace tidak ter-load → Error 500 |
| `rewrite /install -> /index/install` | Sama seperti di atas untuk halaman install wizard |
| `proxy_redirect http:// https://` | Rewrite header `Location` dari OJS saat redirect, agar tidak kembali ke `http://` |
| `sub_filter 'http://...'` | Ganti URL plain di HTML/JS/CSS response body |
| `sub_filter 'http:\/\/...'` | Ganti URL JSON-escaped di AJAX response (format berbeda dari HTML) |
| `Accept-Encoding ""` | Nonaktifkan gzip compression backend agar sub_filter dapat memodifikasi response |

### 5.3 Buat `docker-compose.yml`

```bash
nano docker-compose.yml
```

```yaml
services:
  db:
    image: mysql:8.0
    container_name: ojs_db
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ojsroot_secret
      MYSQL_DATABASE: ojs
      MYSQL_USER: ojs
      MYSQL_PASSWORD: ojs_secret
    volumes:
      - db_data:/var/lib/mysql
    networks:
      - ojs_network
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

  ojs:
    image: pkpofficial/ojs:stable-3_4_0
    container_name: ojs_app
    restart: unless-stopped
    environment:
      MYSQL_HOST: db
      MYSQL_DB: ojs
      MYSQL_USER: ojs
      MYSQL_PASSWORD: ojs_secret
      FORCE_SSL: "off"
    volumes:
      - ojs_files:/var/www/files
      - ojs_public:/var/www/html/public
      # PENTING: config.inc.php TIDAK di-bind-mount.
      # Entrypoint image menggunakan sed -i yang gagal pada bind-mounted file
      # (error "Resource busy"). Konfigurasi dilakukan via docker exec di Seksi 7.
    depends_on:
      db:
        condition: service_healthy
    networks:
      - ojs_network

  nginx:
    image: nginx:1.25-alpine
    container_name: ojs_nginx
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
      - /etc/letsencrypt:/etc/letsencrypt:ro
      - ./certbot-webroot:/var/www/certbot:ro
    depends_on:
      - ojs
    networks:
      - ojs_network

volumes:
  db_data:
  ojs_files:
  ojs_public:

networks:
  ojs_network:
    driver: bridge
```

> **Catatan image:** `pkpofficial/ojs:stable-3_4_0` adalah image resmi OJS 3.4.0. Cek tag lain di [Docker Hub](https://hub.docker.com/r/pkpofficial/ojs).

---

## 6. Jalankan Container

```bash
cd ~/ojs-docker
docker compose up -d
```

Tunggu ~30 detik lalu cek status:

```bash
docker compose ps
```

Semua harus `Up`:

```
NAME        IMAGE                          STATUS                   PORTS
ojs_app     pkpofficial/ojs:stable-3_4_0   Up                       80/tcp, 443/tcp
ojs_db      mysql:8.0                      Up (healthy)             3306/tcp
ojs_nginx   nginx:1.25-alpine              Up              0.0.0.0:80->80/tcp, 0.0.0.0:443->443/tcp
```

Verifikasi SSL aktif:

```bash
curl -skI https://ojs.contoh.ac.id | head -5
# Harus: HTTP/1.1 302 atau 200 (bukan SSL error)
```

> **Jangan buka browser dulu.** Lanjutkan ke Seksi 7 untuk konfigurasi HTTPS wajib.

---

## 7. Konfigurasi OJS untuk HTTPS

> **WAJIB sebelum wizard.** Ada dua patch yang harus dilakukan:
> 1. **`config.inc.php`** — setting DB, files directory, dan HTTPS URL
> 2. **Apache `ojs.conf`** — agar Apache meneruskan deteksi HTTPS ke PHP/OJS
>
> Keduanya dilakukan via `docker exec` (bukan bind-mount) untuk menghindari konflik dengan entrypoint script image OJS yang menggunakan `sed -i`.

### 7.1 Patch `config.inc.php`

Jalankan script berikut **satu kali** setelah container pertama kali naik.

> **Ganti `ojs.contoh.ac.id`** di dalam script dengan subdomain kamu sebelum menjalankan.

```bash
docker exec ojs_app php -r "
\$f='/var/www/html/config.inc.php';
\$c=file_get_contents(\$f);

// Perbaiki DB settings (sesuai docker-compose environment)
\$ds=strpos(\$c,'[database]'); \$cs=strpos(\$c,'[cache]');
\$db=substr(\$c,\$ds,\$cs-\$ds);
\$db2=preg_replace('/^host = .*\$/m','host = db',\$db);
\$db2=preg_replace('/^username = .*\$/m','username = ojs',\$db2);
\$db2=preg_replace('/^password = .*\$/m','password = ojs_secret',\$db2);
\$db2=preg_replace('/^name = .*\$/m','name = ojs',\$db2);
\$c=str_replace(\$db,\$db2,\$c);

// Files directory (volume Docker di-mount di /var/www/files)
\$c=str_replace(\"files_dir = files\n\",\"files_dir = /var/www/files\n\",\$c);

// HTTPS: percayai header X-Forwarded-Proto dari Nginx
\$c=str_replace('trust_x_forwarded_for = Off','trust_x_forwarded_for = On',\$c);

// HTTPS: set base_url[index] (HANYA array index, bukan scalar base_url)
// Catatan: tidak boleh ada base_url scalar DAN base_url[index] bersamaan
// karena PHP parse_ini_file akan error/undefined behavior
if(strpos(\$c,'base_url[index]')===false){
  \$c=preg_replace('/^restful_urls = On\$/m',
    'restful_urls = On'.PHP_EOL.'base_url[index] = \"https://ojs.contoh.ac.id\"',\$c);
}

// Allowed hosts (keamanan OJS 3.4+, wajib ada)
\$c=str_replace(\"allowed_hosts = ''\",\"allowed_hosts = '[\\\"ojs.contoh.ac.id\\\"]'\",\$c);

file_put_contents(\$f,\$c);
echo 'DONE'.PHP_EOL;
echo 'host='.preg_match('/^host = db\$/m',\$c).PHP_EOL;
echo 'files_dir='.preg_match('/files_dir = \/var\/www\/files/',\$c).PHP_EOL;
echo 'trust_x='.preg_match('/trust_x_forwarded_for = On/',\$c).PHP_EOL;
echo 'base_url_index='.preg_match('/base_url\[index\]/',\$c).PHP_EOL;
echo 'allowed_hosts='.preg_match('/ojs\.contoh\.ac\.id/',\$c).PHP_EOL;
"
```

Output yang benar (semua harus `1`):

```
DONE
host=1
files_dir=1
trust_x=1
base_url_index=1
allowed_hosts=1
```

### 7.2 Patch Apache `ojs.conf` untuk deteksi HTTPS

Secara default, Apache di dalam container OJS tidak tahu koneksi aslinya adalah HTTPS (karena Nginx yang handle SSL). Akibatnya OJS menghasilkan semua URL dengan `http://` — termasuk di dalam AJAX JSON response yang tidak bisa dicapai sub_filter Nginx.

Fix: tambahkan `SetEnvIf` agar Apache mendeteksi HTTPS dari header `X-Forwarded-Proto` yang dikirim Nginx:

```bash
docker exec ojs_app sh -c "
grep -q 'SetEnvIf X-Forwarded-Proto' /etc/apache2/conf.d/ojs.conf && echo 'SUDAH ADA' && exit 0
sed -i 's|</VirtualHost>|\tSetEnvIf X-Forwarded-Proto https HTTPS=on\n\tSetEnvIf X-Forwarded-Proto https REQUEST_SCHEME=https\n</VirtualHost>|' /etc/apache2/conf.d/ojs.conf
echo 'Apache ojs.conf diupdate'
"

# Reload Apache (graceful — tanpa mematikan proses yang berjalan)
docker exec ojs_app httpd -k graceful
```

Verifikasi:

```bash
docker exec ojs_app grep 'SetEnvIf' /etc/apache2/conf.d/ojs.conf
# Harus muncul:
# SetEnvIf X-Forwarded-Proto https HTTPS=on
# SetEnvIf X-Forwarded-Proto https REQUEST_SCHEME=https
```

> **Catatan persistensi:** Perubahan `ojs.conf` di dalam container hilang jika container di-recreate (`docker compose up --force-recreate`). Jalankan kembali perintah di atas setelah setiap recreate. Lihat Seksi 13 untuk strategi restart yang aman.

### 7.3 Verifikasi keseluruhan

```bash
# 1. Cek config.inc.php
docker exec ojs_app grep -E '^host|files_dir|^trust_x|^base_url|^allowed_hosts' \
    /var/www/html/config.inc.php | grep -v '^;'
```

Output yang benar:

```
base_url[index] = "https://ojs.contoh.ac.id"
trust_x_forwarded_for = On
allowed_hosts = '["ojs.contoh.ac.id"]'
host = db
files_dir = /var/www/files
```

```bash
# 2. Cek Apache SetEnvIf ada
docker exec ojs_app grep 'SetEnvIf' /etc/apache2/conf.d/ojs.conf

# 3. Cek tidak ada Mixed Content (harus 0)
curl -sk https://ojs.contoh.ac.id/index/install | grep -c 'http://ojs.contoh.ac.id'
```

---

## 8. Jalankan Wizard Instalasi OJS

### 8.1 Buka halaman instalasi

Buka browser: `https://ojs.contoh.ac.id/index/install`

> **Gunakan path `/index/install`** (bukan `/install`). Halaman wizard harus tampil dengan CSS dan JavaScript berjalan normal.

### 8.2 Isi form instalasi

| Field | Nilai | Catatan |
|-------|-------|---------|
| **Language** | English | |
| **Files directory** | `/var/www/files` | **Wajib ganti dari default** |
| **Database driver** | `MySQLi` | |
| **Database host** | `db` | **Wajib `db`, bukan `localhost`** |
| **Database username** | `ojs` | |
| **Database password** | `ojs_secret` | |
| **Database name** | `ojs` | |
| **Create database** | Unchecked | DB sudah dibuat Docker |
| **Admin username** | bebas (misal: `admin`) | |
| **Admin password** | min 6 karakter | |
| **Admin email** | email aktif | |

Klik **Install Open Journal Systems** — tunggu 1–2 menit.

### 8.3 Verifikasi instalasi berhasil

```bash
# Cek installed = On
docker exec ojs_app grep '^installed' /var/www/html/config.inc.php
# Output: installed = On

# Cek tabel DB terbuat (~124 tabel)
docker exec ojs_db mysql -uojs -pojs_secret ojs \
    -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema="ojs";' 2>/dev/null | tail -1
# Output: 124

# Cek admin user terbuat
docker exec ojs_db mysql -uojs -pojs_secret ojs \
    -e 'SELECT username, email FROM users;' 2>/dev/null
```

### 8.4 Test login dan halaman admin

Login: `https://ojs.contoh.ac.id/index/login`

Navigasi ke: **Administration → Hosted Journals**

- Halaman harus muncul penuh (tidak ada spinner "Loading" permanen)
- Tombol **Create Journal** harus membuka form tanpa error "Failed Ajax request"
- Tidak ada Mixed Content error di browser console (F12)

---

## 9. Setup Auto-Renewal SSL

Sertifikat Let's Encrypt valid **90 hari**.

### 9.1 Test renewal (dry run)

```bash
sudo certbot renew --dry-run \
    --webroot -w ~/ojs-docker/certbot-webroot
# Output: Congratulations, all simulated renewals succeeded
```

### 9.2 Setup cron untuk renewal otomatis

```bash
sudo crontab -e
```

Tambahkan (renewal tiap hari pukul 03:00):

```cron
0 3 * * * certbot renew --webroot -w /home/YOUR_USER/ojs-docker/certbot-webroot --quiet && docker exec ojs_nginx nginx -s reload
```

> Ganti `YOUR_USER` dengan username Linux. Cek path: `echo ~/ojs-docker/certbot-webroot`.

Verifikasi:

```bash
sudo crontab -l
sudo certbot certificates
```

---

## 10. Install Plugin OJSDef

### Metode A — Upload via OJS Admin Panel (Direkomendasikan)

1. Login ke OJS → **Website Settings** → **Plugins** → **Plugin Gallery**
2. **Upload a New Plugin** → pilih `ojsdef-plugin-1.0.1.zip` → **Save**
3. Di tab **Generic Plugins** → cari **OJSDef Security Scanner** → toggle aktifkan

### Metode B — Copy via SCP (jika upload browser gagal)

```bash
# Dari komputer lokal
scp ojsdef-plugin-1.0.1.zip user@<IP_VPS>:~/ojs-docker/

# Di VPS
cd ~/ojs-docker
unzip ojsdef-plugin-1.0.1.zip
docker cp ojsdef/ ojs_app:/var/www/html/plugins/generic/ojsdef
docker exec ojs_app chown -R apache:apache /var/www/html/plugins/generic/ojsdef
docker exec ojs_app chmod -R 755 /var/www/html/plugins/generic/ojsdef
```

Aktifkan di OJS: **Website Settings** → **Plugins** → **Generic Plugins**.

---

## 11. Konfigurasi Plugin OJSDef

### 11.1 Dapatkan kredensial dari OJSDef Dashboard

1. Login ke OJSDef Dashboard → **Add Target**
2. Masukkan URL: `https://ojs.contoh.ac.id`
3. Catat tiga nilai:

| Nilai | Keterangan |
|-------|-----------|
| **Backend URL** | URL API OJSDef |
| **API Key** | Kunci autentikasi unik per target |
| **Target ID** | UUID target |

### 11.2 Isi Settings plugin OJS

1. **Website Settings** → **Plugins** → **Generic Plugins** → **OJSDef Security Scanner** → **Settings**
2. Isi ketiga field:
   - **Backend URL**: URL backend OJSDef production (contoh: `https://api.ojsdef.example.com`)
   - **API Key**: Salin dari dashboard OJSDef → pilih target → Plugin Guide
   - **Target ID**: Salin dari dashboard OJSDef → pilih target → Plugin Guide
3. Klik **Save** → **Test Connection**

Jika test berhasil, connection status akan menampilkan **"Connected — Direct Mode"** atau **"Connected — Heartbeat Mode"** (tergantung reachability plugin dari backend).

### 11.3 Troubleshoot koneksi gagal

Jika status tetap **"Disconnected"** setelah 10 menit:

1. **Pastikan OJS dapat akses backend:**
   ```bash
   docker exec ojs_app curl -vI https://api.ojsdef.example.com/plugin/v1/heartbeat
   # Harus dapat reach (jangan timeout/connection refused)
   ```

2. **Cek log plugin untuk debug:**
   - Di halaman **Settings** OJSDef → tampilkan debug log (jika tersedia)
   - Atau cek container log: `docker logs ojs_app | grep ojsdef`

3. **Re-enter API Key** (mungkin salah copy):
   - Pastikan tidak ada leading/trailing whitespace
   - Copy ulang dari dashboard OJSDef

---

## 12. Verifikasi Domain OJS di Dashboard

Sebelum scan bisa dijalankan, domain OJS harus diverifikasi di dashboard OJSDef:

### 12.1 Buka halaman verifikasi

1. Dashboard OJSDef → **Targets** → pilih target OJS → **Verify Domain**

### 12.2 Pilih metode verifikasi

**Metode 1: File Verification** (Direkomendasikan)
1. Dashboard akan generate token verification, misal: `abc123def456`
2. Buat file di OJS: `/.well-known/ojsdef-verify-abc123def456.txt`
3. Isi file dengan: `ojsdef-verification=abc123def456`
4. Akses dari browser: `https://ojs.contoh.ac.id/.well-known/ojsdef-verify-abc123def456.txt` → harus muncul isi file
5. Di dashboard → klik **Verify** → sistem akan check file → jika cocok, status berubah **Verified**

Implementasi di container OJS:
```bash
docker exec ojs_app sh -c \
    "mkdir -p /var/www/html/.well-known && \
     echo 'ojsdef-verification=abc123def456' > \
     /var/www/html/.well-known/ojsdef-verify-abc123def456.txt"
```

**Metode 2: DNS Verification** (Alternatif)
1. Dashboard akan generate token, misal: `xyz789`
2. Tambahkan DNS TXT record di provider (Cloudflare, dll):
   - Name: `_ojsdef-verify.ojs.contoh.ac.id`
   - Value: `ojsdef-verification=xyz789`
3. Tunggu propagasi DNS (5-30 menit)
4. Di dashboard → klik **Verify** → sistem akan query DNS → jika ada, status **Verified**

### 12.3 Setelah verifikasi berhasil

Dashboard otomatis redirect ke **Plugin Installation Guide** dengan instruksi lengkap dan copy-button untuk API Key dan Target ID.

---

## 13. Manajemen Container (Restart yang Aman)

> **Penting:** Patch yang dilakukan via `docker exec` di Seksi 7 (config.inc.php dan Apache ojs.conf) **tidak persisten** — hilang jika container di-recreate. Gunakan metode restart yang tepat.

### Restart normal (konfigurasi tetap ada):

```bash
docker compose stop && docker compose start    # stop + start semua
docker compose restart ojs                     # restart OJS saja
docker compose restart nginx                   # restart Nginx saja
```

### Jika terpaksa harus recreate container:

```bash
docker compose up -d --force-recreate ojs
# Setelah container naik, jalankan ulang:
# - Patch config.inc.php (Seksi 7.1)
# - Patch Apache ojs.conf (Seksi 7.2)
```

### Update versi OJS:

```bash
docker compose pull ojs
docker compose up -d --force-recreate ojs
# Lalu jalankan ulang patch Seksi 7.1 dan 7.2
```

---

## 14. Troubleshooting

### Plugin OJSDef di bagian sebelumnya sudah tercakup di Seksi 11.3

### Container tidak naik

```bash
docker compose ps
docker compose logs ojs --tail=50
docker compose logs nginx --tail=20
```

### 502 Bad Gateway

```bash
docker compose restart ojs
docker compose logs ojs --tail=30
```

### Mixed Content — URL masih `http://` di browser

```bash
# Cek apakah SetEnvIf ada di Apache
docker exec ojs_app grep 'SetEnvIf' /etc/apache2/conf.d/ojs.conf
# Jika tidak ada: jalankan ulang Seksi 7.2

# Cek baseUrl yang dihasilkan OJS (harus https://)
curl -sk https://ojs.contoh.ac.id/index/login | grep -o '"baseUrl":"[^"]*"'
# Output benar: "baseUrl":"https:\/\/ojs.contoh.ac.id"
```

### Halaman admin stuck "Loading"

Penyebab: AJAX API call gagal karena URL masih `http://` (Mixed Content diblokir browser).

```bash
# Test API endpoint
curl -sk https://ojs.contoh.ac.id/api/v1/contexts \
    -H 'Accept: application/json' -w '\nHTTP:%{http_code}'
# Harus: HTTP:403 (unauthorized) — bukan HTTP:500

# Jika HTTP:500: cek rewrite /api/v1 di nginx.conf
grep 'rewrite.*api' ~/ojs-docker/nginx.conf
```

### Error "Failed Ajax request or invalid JSON" saat Create Journal

Penyebab: URL AJAX di JSON response masih `http://`.

```bash
# Test AJAX endpoint
curl -sk 'https://ojs.contoh.ac.id/index/%24%24%24call%24%24%24/grid/admin/context/context-grid/fetch-grid' \
    -H 'X-Requested-With: XMLHttpRequest' | grep -o '"fetchGridUrl":"[^"]*"' | head -1
# Harus: "https:\/\/ojs.contoh.ac.id\/..."
# Jika http://: Apache SetEnvIf belum ada (jalankan ulang Seksi 7.2)
```

### Error "Mailer driver isn't specified"

Penyebab: `config.inc.php` kosong (biasanya akibat operasi salah yang membaca dan menulis file yang sama).

```bash
# Cek ukuran file (harus > 0)
docker exec ojs_app wc -c /var/www/html/config.inc.php

# Jika 0: ambil template dari image (BUKAN dari container yang running)
cid=$(docker create pkpofficial/ojs:stable-3_4_0)
docker cp $cid:/var/www/html/config.inc.php ~/ojs-docker/ojs-config-backup.inc.php
docker rm $cid
docker cp ~/ojs-docker/ojs-config-backup.inc.php ojs_app:/var/www/html/config.inc.php

# Lalu jalankan ulang Seksi 7.1 dan 7.2
```

> **Peringatan:** Jangan jalankan `docker exec ojs_app cat /var/www/html/config.inc.php > file.txt` jika `file.txt` adalah file yang sama yang di-bind-mount — ini akan mengosongkan file!

### `installed = Off` setelah container restart

```bash
# Cek apakah DB masih ada datanya
docker exec ojs_db mysql -uojs -pojs_secret ojs \
    -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema="ojs";' 2>/dev/null | tail -1
# Jika > 0: OJS sudah pernah terinstall, jalankan ulang patch Seksi 7.1 saja
# Jika 0: perlu install ulang dari Seksi 8
```

### SSL / HTTPS tidak berjalan

```bash
sudo ls -la /etc/letsencrypt/live/ojs.contoh.ac.id/
docker exec ojs_nginx nginx -t
curl -vI https://ojs.contoh.ac.id 2>&1 | head -20
```

### Certbot gagal mendapatkan sertifikat

```bash
dig ojs.contoh.ac.id +short        # harus IP VPS, bukan Cloudflare
sudo ss -tlnp | grep ':80'          # harus kosong sebelum certbot
sudo certbot certonly --standalone -d ojs.contoh.ac.id --email email@kamu.com --agree-tos -v
```

### Reset total — mulai dari awal

> **Peringatan:** Menghapus semua data OJS!

```bash
cd ~/ojs-docker
docker compose down -v    # hapus container + volume
docker compose up -d      # start ulang
# Lalu jalankan Seksi 7 dan 8 dari awal
```

### Plugin OJSDef tidak muncul setelah upload

```bash
docker exec ojs_app find /var/www/html/plugins/generic/ojsdef -type f -exec chmod 644 {} \;
docker exec ojs_app find /var/www/html/plugins/generic/ojsdef -type d -exec chmod 755 {} \;
docker exec ojs_app chown -R apache:apache /var/www/html/plugins/generic/ojsdef
```

### Plugin status tetap Disconnected

```bash
docker exec ojs_app curl -s -o /dev/null -w "%{http_code}" \
    https://api.ojsdef.id/plugin/v1/heartbeat
# Harus 401 (server reachable) — jika timeout: buka port outbound
sudo ufw allow out 443/tcp
sudo ufw allow out 80/tcp
```

---

## Referensi Perintah Docker

```bash
docker compose ps                                           # status semua container
docker compose logs -f nginx                                # log Nginx real-time
docker compose logs -f ojs                                  # log OJS real-time
docker exec -it ojs_app sh                                  # masuk ke container OJS
docker exec -it ojs_db mysql -u ojs -pojs_secret ojs       # masuk MySQL
docker compose stop && docker compose start                 # restart aman (config tetap)
docker compose restart ojs                                  # restart OJS saja
docker compose restart nginx                                # restart Nginx saja
docker compose down                                         # hapus container (volume aman)
docker compose down -v                                      # hapus container + semua volume
```

---

## Struktur File di VPS

```
~/ojs-docker/
├── docker-compose.yml        ← Orchestrasi service (db + ojs + nginx)
├── nginx.conf                ← Reverse proxy + SSL + rewrite + sub_filter
├── certbot-webroot/          ← Webroot untuk certbot renewal
└── ojsdef/                   ← Plugin (hanya jika pakai Metode B upload manual)

/etc/letsencrypt/             ← Sertifikat Let's Encrypt (di-mount ke nginx, read-only)
└── live/ojs.contoh.ac.id/
    ├── fullchain.pem
    └── privkey.pem

# Di dalam container ojs_app (tidak ada config bind-mount):
/var/www/html/config.inc.php    ← Config OJS (dipatch via docker exec Seksi 7.1)
/etc/apache2/conf.d/ojs.conf    ← Apache VirtualHost (dipatch via docker exec Seksi 7.2)
/var/www/files/                 ← Upload files (volume ojs_files, persisten)
/var/www/html/public/           ← Public files (volume ojs_public, persisten)
```

---

## Catatan Teknis: Mengapa Tidak Pakai Config Bind-Mount?

Pendekatan ini tidak melakukan bind-mount `config.inc.php` ke dalam container karena:

1. **Entrypoint script** image `pkpofficial/ojs` menggunakan `sed -i` untuk mengkonfigurasi `config.inc.php` dari environment variable saat container start
2. `sed -i` bekerja dengan membuat file temporary lalu rename — operasi rename **gagal** dengan error `"Resource busy"` pada file yang di-bind-mount dari host
3. Akibatnya entrypoint tidak bisa mengatur `host = db`, `files_dir`, dll. — config tetap berisi nilai template (`host = localhost`, `files_dir = files`)
4. Selain itu, Apache (UID 100 di dalam container) tidak bisa menulis ke bind-mounted file yang dimiliki user host (UID berbeda) dengan permission 644 — sehingga installer OJS tidak bisa menyimpan `installed = On`

Solusinya: config disimpan **di dalam container** (writable oleh Apache) dan dikonfigurasi via `docker exec` setelah container naik.

---

## Referensi

- [OJS Docker Hub — pkpofficial/ojs](https://hub.docker.com/r/pkpofficial/ojs)
- [PKP Docker OJS GitHub](https://github.com/pkp/docker-ojs)
- [Certbot — Let's Encrypt](https://certbot.eff.org/)
- [Mozilla SSL Configuration Generator](https://ssl-config.mozilla.org/)
- [OJSDef Plugin Design Spec](superpowers/specs/2026-05-30-ojsdef-plugin-design.md)
- [OJSDef Plugin Implementation Plan](superpowers/plans/2026-05-30-ojsdef-plugin-implementation.md)
- [Panduan Cloudflare Proxy (alternatif)](panduan-vps-ojs-docker.md)

---

*Capstone Project Kelompok 3 — Topik G2, Fakultas Ilmu Komputer, Universitas Brawijaya, 2026.*
