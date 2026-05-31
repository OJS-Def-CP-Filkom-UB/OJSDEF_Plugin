# Panduan Setup OJS Percobaan di VPS dengan Docker + Nginx + Cloudflare

**Tujuan:** Menjalankan instalasi Open Journal Systems (OJS) di VPS menggunakan Docker sebagai environment percobaan (testing/staging) dengan Nginx sebagai reverse proxy dan SSL dikelola Cloudflare, kemudian menginstall plugin OJSDef Security Scanner.

**Versi OJS:** 3.3.x atau 3.4.x  
**Estimasi waktu:** 45–90 menit

---

## Daftar Isi

1. [Spesifikasi VPS](#1-spesifikasi-vps)
2. [Install Docker dan Docker Compose](#2-install-docker-dan-docker-compose)
3. [Setup Nginx + OJS dengan Docker Compose](#3-setup-nginx--ojs-dengan-docker-compose)
4. [Konfigurasi DNS di Cloudflare](#4-konfigurasi-dns-di-cloudflare)
5. [Konfigurasi Awal OJS](#5-konfigurasi-awal-ojs)
6. [Install Plugin OJSDef](#6-install-plugin-ojsdef)
7. [Konfigurasi Plugin OJSDef](#7-konfigurasi-plugin-ojsdef)
8. [Verifikasi Koneksi](#8-verifikasi-koneksi)
9. [Troubleshooting](#9-troubleshooting)

---

## 1. Spesifikasi VPS

### Minimum (Testing)

| Komponen | Spesifikasi |
|----------|-------------|
| OS | Ubuntu 22.04 LTS (64-bit) |
| CPU | 2 vCPU |
| RAM | 2 GB |
| Storage | 20 GB SSD |
| Network | Port **80** dan **443** terbuka ke publik |

### Rekomendasi (Staging yang lebih stabil)

| Komponen | Spesifikasi |
|----------|-------------|
| OS | Ubuntu 22.04 LTS (64-bit) |
| CPU | 4 vCPU |
| RAM | 4 GB |
| Storage | 40 GB SSD |

> **Catatan:** Panduan ini menggunakan Ubuntu 22.04. Semua perintah dijalankan sebagai user dengan akses `sudo`.

---

## 2. Install Docker dan Docker Compose

SSH ke VPS, lalu jalankan langkah berikut.

### 2.1 Update sistem

```bash
sudo apt update && sudo apt upgrade -y
```

### 2.2 Install dependensi

```bash
sudo apt install -y ca-certificates curl gnupg lsb-release unzip
```

### 2.3 Tambah Docker GPG key dan repository

```bash
sudo install -m 0755 -d /etc/apt/keyrings

curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
  sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg

sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
```

### 2.4 Install Docker Engine

```bash
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

### 2.5 Verifikasi instalasi

```bash
docker --version
docker compose version
```

Output yang diharapkan:

```
Docker version 24.x.x, build ...
Docker Compose version v2.x.x
```

### 2.6 Tambah user ke grup docker (opsional)

```bash
sudo usermod -aG docker $USER
newgrp docker
```

---

## 3. Setup Nginx + OJS dengan Docker Compose

### 3.1 Buat direktori project

```bash
mkdir -p ~/ojs-docker && cd ~/ojs-docker
```

### 3.2 Buat file `nginx.conf`

```bash
nano nginx.conf
```

Paste konten berikut — ganti `ojs.contoh.ac.id` dengan subdomain kamu:

```nginx
server {
    listen 80;
    server_name ojs.contoh.ac.id;

    # -------------------------------------------------------
    # Cloudflare real IP — gunakan IP asli pengunjung, bukan
    # IP Cloudflare proxy, untuk log dan rate-limit yang akurat.
    # Daftar lengkap: https://www.cloudflare.com/ips/
    # -------------------------------------------------------
    set_real_ip_from 103.21.244.0/22;
    set_real_ip_from 103.22.200.0/22;
    set_real_ip_from 103.31.4.0/22;
    set_real_ip_from 104.16.0.0/13;
    set_real_ip_from 104.24.0.0/14;
    set_real_ip_from 108.162.192.0/18;
    set_real_ip_from 131.0.72.0/22;
    set_real_ip_from 141.101.64.0/18;
    set_real_ip_from 162.158.0.0/15;
    set_real_ip_from 172.64.0.0/13;
    set_real_ip_from 173.245.48.0/20;
    set_real_ip_from 188.114.96.0/20;
    set_real_ip_from 190.93.240.0/20;
    set_real_ip_from 197.234.240.0/22;
    set_real_ip_from 198.41.128.0/17;
    set_real_ip_from 2400:cb00::/32;
    set_real_ip_from 2606:4700::/32;
    set_real_ip_from 2803:f800::/32;
    set_real_ip_from 2405:b500::/32;
    set_real_ip_from 2405:8100::/32;
    set_real_ip_from 2a06:98c0::/29;
    set_real_ip_from 2c0f:f248::/32;
    real_ip_header CF-Connecting-IP;

    # Upload file besar (untuk upload plugin OJS via browser)
    client_max_body_size 32M;

    location / {
        proxy_pass         http://ojs:80;
        proxy_http_version 1.1;

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        # Beritahu OJS bahwa koneksi ke browser adalah HTTPS
        # (SSL diakhiri di Cloudflare, bukan di sini)
        proxy_set_header X-Forwarded-Proto https;

        proxy_read_timeout 120s;
        proxy_connect_timeout 10s;

        # Tulis ulang semua URL http:// → https:// di response sebelum dikirim ke browser.
        # Mencegah mixed content error meskipun OJS masih menghasilkan http:// URL.
        # Ganti 'ojs.contoh.ac.id' dengan subdomain kamu.
        proxy_set_header   Accept-Encoding  "";
        sub_filter         'http://ojs.contoh.ac.id' 'https://ojs.contoh.ac.id';
        sub_filter_once    off;
        sub_filter_types   text/html application/json application/javascript;
    }
}
```

### 3.3 Buat file `docker-compose.yml`

```bash
nano docker-compose.yml
```

Paste konten berikut:

```yaml
version: '3.8'

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
    image: pkpinc/ojs:3_4_0-7
    container_name: ojs_app
    restart: unless-stopped
    # Port tidak diekspos ke publik — hanya dapat diakses oleh nginx via internal network
    environment:
      MYSQL_HOST: db
      MYSQL_DB: ojs
      MYSQL_USER: ojs
      MYSQL_PASSWORD: ojs_secret
      # SSL dikelola Cloudflare — matikan FORCE_SSL di level OJS
      FORCE_SSL: "off"
    volumes:
      - ojs_files:/var/www/files
      - ojs_public:/var/www/html/public
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
    volumes:
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
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

> **Catatan versi OJS:** Tag `3_4_0-7` adalah OJS 3.4.0 build ke-7. Untuk OJS 3.3.x gunakan `3_3_0-15`. Cek tag terbaru di [Docker Hub pkpinc/ojs](https://hub.docker.com/r/pkpinc/ojs/tags).

### 3.4 Buka firewall VPS

```bash
sudo ufw allow 22/tcp     # SSH — jangan sampai terkunci
sudo ufw allow 80/tcp     # Cloudflare akan request ke port 80
sudo ufw allow 443/tcp    # Untuk akses langsung jika diperlukan
sudo ufw enable
sudo ufw status
```

### 3.5 Jalankan semua container

```bash
docker compose up -d
```

Verifikasi ketiga container berjalan:

```bash
docker compose ps
```

Output yang diharapkan:

```
NAME         IMAGE                   STATUS          PORTS
ojs_app      pkpinc/ojs:3_4_0-7     Up              80/tcp
ojs_db       mysql:8.0               Up (healthy)    3306/tcp
ojs_nginx    nginx:1.25-alpine       Up              0.0.0.0:80->80/tcp
```

---

## 4. Konfigurasi DNS di Cloudflare

### 4.1 Tambah A Record

1. Login ke [Cloudflare Dashboard](https://dash.cloudflare.com)
2. Pilih domain kamu
3. Buka tab **DNS** → **Records**
4. Klik **Add record** dan isi:

| Field | Nilai |
|-------|-------|
| Type | `A` |
| Name | `ojs` (atau subdomain lain, misal `jurnal`) |
| IPv4 address | IP publik VPS kamu |
| Proxy status | **Proxied** (ikon awan oranye — WAJIB aktif) |
| TTL | `Auto` |

Klik **Save**.

> **Kenapa Proxied wajib aktif?** Mode Proxied memungkinkan Cloudflare mengelola SSL secara otomatis dan menyembunyikan IP asli VPS. Jika diset ke DNS Only (awan abu-abu), SSL dari Cloudflare tidak aktif.

### 4.2 Konfigurasi SSL/TLS di Cloudflare

1. Di Cloudflare Dashboard, buka tab **SSL/TLS** → **Overview**
2. Set encryption mode ke **Flexible**

   ```
   Browser ──HTTPS──► Cloudflare ──HTTP──► Nginx (VPS) ──HTTP──► OJS
   ```

   > Mode Flexible cukup untuk testing. Cloudflare menangani SSL ke browser; traffic internal Cloudflare → VPS berjalan via HTTP di jaringan yang sudah diamankan.

3. Di tab **SSL/TLS** → **Edge Certificates**, aktifkan:
   - **Always Use HTTPS** → On
   - **Automatic HTTPS Rewrites** → On

### 4.3 Verifikasi DNS sudah propagasi

Tunggu 1–5 menit setelah menambah A record, lalu cek:

```bash
curl -I https://ojs.contoh.ac.id
```

Jika muncul `HTTP/2 200` atau redirect ke halaman OJS, DNS sudah propagasi dan Nginx berjalan dengan benar.

---

## 5. Konfigurasi Awal OJS

### 5.1 Akses OJS via browser

Buka browser dan akses subdomain yang sudah dikonfigurasi:

```
https://ojs.contoh.ac.id
```

OJS akan menampilkan halaman wizard instalasi.

### 5.2 Wizard instalasi OJS

**Langkah 1 — Bahasa:**
- Pilih `Bahasa Indonesia` atau `English`
- Klik **Continue**

**Langkah 2 — File Paths:**
- Biarkan semua nilai default
- Klik **Continue**

**Langkah 3 — Database Settings:**

| Field | Nilai |
|-------|-------|
| Driver | `mysqli` |
| Host | `db` |
| Username | `ojs` |
| Password | `ojs_secret` |
| Database name | `ojs` |

Klik **Continue**

**Langkah 4 — Administrator Account:**

| Field | Nilai |
|-------|-------|
| Username | `admin` |
| Password | Buat password kuat (minimal 8 karakter) |
| Email | email admin kamu |

> **Simpan username dan password ini.**

Klik **Install Open Journal Systems**

### 5.3 Konfigurasi base URL OJS (Volume Mount)

OJS perlu mengetahui URL publiknya agar menghasilkan link HTTPS yang benar. Pendekatan yang digunakan adalah **meng-copy `config.inc.php` ke luar container, mengeditnya, lalu me-mount-nya kembali sebagai volume** — sehingga perubahan tidak hilang saat container restart.

**Langkah 1 — Salin config dari container ke host:**

```bash
cd ~/ojs-docker
docker cp ojs_app:/var/www/html/config.inc.php ./ojs-config.inc.php
```

**Langkah 2 — Edit file di host:**

```bash
nano ojs-config.inc.php
```

Cari baris `base_url["index"]` dan ubah nilainya menjadi URL HTTPS subdomain kamu:

```ini
base_url["index"] = "https://ojs.contoh.ac.id"
```

Cari `trust_x_forwarded_for` (kemungkinan diawali `;` tanda komentar), hapus `;` dan set ke `On`:

```ini
trust_x_forwarded_for = On
```

Simpan file (`Ctrl+X` → `Y` → `Enter`).

**Langkah 3 — Tambahkan volume mount ke `docker-compose.yml`:**

```bash
nano docker-compose.yml
```

Di bagian `ojs:` → `volumes:`, tambahkan baris mount config:

```yaml
  ojs:
    volumes:
      - ojs_files:/var/www/files
      - ojs_public:/var/www/html/public
      - ./ojs-config.inc.php:/var/www/html/config.inc.php   # tambah baris ini
```

**Langkah 4 — Recreate container OJS dan clear cache:**

```bash
# Clear cache OJS sebelum recreate
docker exec ojs_app bash -c "rm -rf /var/www/html/cache/t_compile/* /var/www/html/cache/fc-*"

# Recreate container dengan volume mount baru
docker compose up -d --force-recreate ojs
```

> **Kenapa volume mount, bukan edit langsung di dalam container?** Beberapa versi Docker image pkpinc/ojs dapat me-reset `config.inc.php` saat container recreate. Dengan volume mount, file di host yang menjadi sumber kebenaran dan tidak bisa ditimpa oleh container.

### 5.4 Login ke OJS

```
https://ojs.contoh.ac.id/index.php/index/login
```

### 5.5 Buat jurnal percobaan

1. Di admin panel, klik **Administration** → **Hosted Journals**
2. Klik **Create Journal**
3. Isi field:

| Field | Contoh |
|-------|--------|
| Name | `Jurnal Percobaan OJSDef` |
| Path | `test` |

4. Klik **Save**
5. Buka jurnal: `https://ojs.contoh.ac.id/index.php/test`

---

## 6. Install Plugin OJSDef

Ada dua metode instalasi. Pilih salah satu.

---

### Metode A — Upload via OJS Admin Panel (Direkomendasikan)

#### A.1 Dapatkan file ZIP plugin

File `ojsdef-plugin-1.0.1.zip` tersedia dari dua sumber:

- **Dari OJSDef Dashboard:** Tombol **Download Plugin ZIP** saat menambahkan target OJS
- **Manual dari repository:** Direktori `OJSDEF-Plugin/ojsdef-plugin-1.0.1.zip`

#### A.2 Upload plugin via OJS

1. Login ke OJS sebagai admin
2. Buka **Website Settings** → **Plugins**
3. Klik **Plugin Gallery**
4. Klik tombol **Upload a New Plugin**
5. Klik **Choose File** → pilih `ojsdef-plugin-1.0.1.zip`
6. Klik **Save**

#### A.3 Aktifkan plugin

1. Di halaman Plugins, klik tab **Generic Plugins**
2. Cari **OJSDef Security Scanner**
3. Klik toggle switch untuk mengaktifkan

---

### Metode B — Copy Langsung ke Container via SCP

Gunakan metode ini jika upload via browser gagal.

#### B.1 Upload ZIP ke VPS

Dari komputer lokal:

```bash
scp ojsdef-plugin-1.0.1.zip user@<IP_VPS>:~/ojs-docker/
```

#### B.2 Ekstrak plugin ke dalam container

Di VPS:

```bash
cd ~/ojs-docker
unzip ojsdef-plugin-1.0.1.zip
docker cp ojsdef/ ojs_app:/var/www/html/plugins/generic/ojsdef
```

#### B.3 Set permission

```bash
docker exec ojs_app chown -R www-data:www-data /var/www/html/plugins/generic/ojsdef
docker exec ojs_app chmod -R 755 /var/www/html/plugins/generic/ojsdef
```

#### B.4 Aktifkan plugin via OJS

1. Login ke OJS admin panel
2. **Website Settings** → **Plugins** → **Generic Plugins**
3. Temukan **OJSDef Security Scanner** dan klik toggle untuk mengaktifkan

---

## 7. Konfigurasi Plugin OJSDef

### 7.1 Buka halaman Settings plugin

1. Di halaman Generic Plugins, temukan **OJSDef Security Scanner**
2. Klik **Settings**

### 7.2 Dapatkan kredensial dari OJSDef Dashboard

1. Login ke OJSDef Dashboard
2. Klik **Add Target** / **Tambah Target**
3. Masukkan URL OJS: `https://ojs.contoh.ac.id`
4. Dashboard menampilkan:

| Nilai | Deskripsi | Contoh |
|-------|-----------|--------|
| **Backend URL** | URL API OJSDef | `https://api.ojsdef.id` |
| **API Key** | Kunci autentikasi unik per target | `ojsdef_pk_live_abc123...` |
| **Target ID** | UUID target di sistem OJSDef | `550e8400-e29b-41d4-...` |

### 7.3 Isi form Settings plugin

| Field | Nilai yang diisi |
|-------|------------------|
| **OJSDef Backend URL** | Backend URL dari dashboard |
| **API Key** | API Key dari dashboard |
| **Target ID** | Target ID dari dashboard |

Klik **Save**.

### 7.4 Test Connection

Setelah menyimpan, klik tombol **Test Connection**. Plugin akan mengirim heartbeat ke backend OJSDef dan menampilkan hasilnya.

---

## 8. Verifikasi Koneksi

### 8.1 Cek status di Settings Plugin

Refresh halaman Settings plugin. Bagian **Connection Status** akan menampilkan salah satu dari tiga state:

#### State 1 — Belum terhubung
```
Status: Disconnected
Mode: Unknown
Last Heartbeat: —
```
→ Cek kembali API Key, Backend URL, dan Target ID.

#### State 2 — Terhubung (Direct Mode)
```
Status: Connected
Mode: Direct Mode — Scan starts in under 10 seconds.
Last Heartbeat: 2026-05-31 10:05:23
```
→ Backend dapat menjangkau plugin langsung melalui Cloudflare proxy.

#### State 3 — Terhubung (Heartbeat Mode)
```
Status: Connected
Mode: Heartbeat Mode — Scan starts within 5 minutes.
Last Heartbeat: 2026-05-31 10:05:23

⚠️ Plugin is behind a firewall. Backend cannot reach the plugin directly.
   Scans will still work via heartbeat.
```
→ Scan tetap berjalan via heartbeat, tapi butuh hingga 5 menit untuk dimulai.

### 8.2 Jalankan scan pertama

Di OJSDef Dashboard:
1. Pilih target OJS
2. Klik **Run Scan**
3. Pilih tipe scan: **Internal**, **External**, atau **Full Audit**
4. Pantau progress di dashboard

### 8.3 Cek log container (opsional)

```bash
# Log Nginx (request masuk)
docker compose logs -f nginx

# Log OJS (error PHP)
docker exec ojs_app tail -f /var/log/apache2/error.log
```

---

## 9. Troubleshooting

### OJS tidak bisa diakses via subdomain

```bash
# Cek semua container berjalan
docker compose ps

# Pastikan Nginx merespons di port 80
curl -I http://<IP_VPS>/

# Cek log Nginx
docker compose logs nginx --tail=50

# Pastikan firewall mengizinkan port 80
sudo ufw status
sudo ufw allow 80/tcp
```

> Jika `curl` ke IP langsung berjalan tapi subdomain tidak, masalah ada di DNS Cloudflare — pastikan A record sudah Proxied dan IP VPS sudah benar.

### Cloudflare menampilkan error 502 Bad Gateway

Nginx dapat diakses tapi OJS container tidak merespons.

```bash
# Cek status container
docker compose ps

# Cek log OJS
docker compose logs ojs --tail=50

# Restart OJS
docker compose restart ojs
```

### OJS menghasilkan URL `http://` bukan `https://` (Mixed Content Error)

Gejala: browser console menampilkan *"Mixed Content: ... insecure XMLHttpRequest endpoint 'http://...'"* dan halaman admin stuck di "Loading".

**Diagnosa — cek isi config saat ini:**

```bash
docker exec ojs_app grep -n 'base_url\|trust_x_forwarded_for' /var/www/html/config.inc.php | head -10
```

**Fix — ikuti prosedur volume mount di Seksi 5.3.** Ringkasannya:

```bash
cd ~/ojs-docker

# Salin config
docker cp ojs_app:/var/www/html/config.inc.php ./ojs-config.inc.php

# Edit: set base_url ke https:// dan aktifkan trust_x_forwarded_for
nano ojs-config.inc.php

# Tambahkan volume mount di docker-compose.yml (lihat Seksi 5.3 Langkah 3)
nano docker-compose.yml

# Clear cache dan recreate
docker exec ojs_app bash -c "rm -rf /var/www/html/cache/t_compile/* /var/www/html/cache/fc-*"
docker compose up -d --force-recreate ojs
```

**Verifikasi Nginx sub_filter aktif** — pastikan `nginx.conf` sudah punya baris `sub_filter` (lihat Seksi 3.2). Reload jika perlu:

```bash
docker compose exec nginx nginx -s reload
```

Dengan dua fix sekaligus (volume mount config + Nginx sub_filter), mixed content error tidak bisa muncul lagi dari manapun asalnya.

### Halaman OJS menampilkan error 500

```bash
docker exec -it ojs_app bash
tail -100 /var/log/apache2/error.log
```

### Plugin tidak muncul setelah upload

```bash
docker exec ojs_app bash -c \
  "find /var/www/html/plugins/generic/ojsdef -type f -exec chmod 644 {} \; && \
   find /var/www/html/plugins/generic/ojsdef -type d -exec chmod 755 {} \; && \
   chown -R www-data:www-data /var/www/html/plugins/generic/ojsdef"
```

Kemudian refresh halaman Plugins di OJS.

### Status plugin tetap Disconnected

Periksa secara berurutan:

1. **API Key salah** — Salin ulang dengan teliti (hindari spasi ekstra)

2. **Backend URL tidak bisa dijangkau dari VPS** — Test outbound dari container OJS:
   ```bash
   docker exec ojs_app curl -s -o /dev/null -w "%{http_code}" \
     https://api.ojsdef.id/plugin/v1/heartbeat
   ```
   Harus mengembalikan `401` (autentikasi gagal tapi server reachable). Jika timeout, OJS container tidak bisa menjangkau backend.

3. **Outbound firewall memblokir** — Izinkan outbound HTTPS dari VPS:
   ```bash
   sudo ufw allow out 443/tcp
   sudo ufw allow out 80/tcp
   ```

4. **Heartbeat belum terpicu** — Coba akses beberapa halaman OJS di browser untuk memicu heartbeat.

### Error database saat instalasi OJS

```bash
# Pastikan MySQL sudah siap
docker exec ojs_db mysqladmin ping -h localhost -u ojs -pojs_secret

# Jika tidak bisa connect, restart dan tunggu
docker compose restart db
sleep 30
docker compose restart ojs
```

### Lupa password admin OJS

```bash
docker exec ojs_db mysql -u ojs -pojs_secret ojs -e \
  "UPDATE users SET password=MD5('PasswordBaru123!') WHERE username='admin';"
```

### Reset instalasi OJS (mulai dari awal)

> ⚠️ **Peringatan:** Perintah ini akan menghapus **semua data** OJS!

```bash
cd ~/ojs-docker
docker compose down -v
docker compose up -d
```

---

## Referensi Perintah Docker

```bash
# Status semua container
docker compose ps

# Log real-time per service
docker compose logs -f nginx
docker compose logs -f ojs

# Masuk ke dalam container OJS
docker exec -it ojs_app bash

# Masuk ke MySQL
docker exec -it ojs_db mysql -u ojs -pojs_secret ojs

# Restart service tertentu
docker compose restart nginx
docker compose restart ojs

# Stop semua container (data tetap aman)
docker compose stop

# Start kembali
docker compose start

# Hapus container (data tetap aman di volume)
docker compose down

# Hapus container + semua data volume
docker compose down -v
```

---

## Struktur File Setup

```
~/ojs-docker/
├── docker-compose.yml              <- Konfigurasi Docker Compose (db + ojs + nginx)
├── nginx.conf                      <- Konfigurasi reverse proxy Nginx + sub_filter
├── ojs-config.inc.php              <- config.inc.php OJS (di-mount sebagai volume, dibuat di Seksi 5.3)
└── ojsdef/                         <- Plugin (jika menggunakan Metode B)
    ├── OjsdefPlugin.php
    ├── OjsdefHandler.php
    ├── version.xml
    ├── classes/
    │   ├── HmacSigner.php
    │   ├── ApiClient.php
    │   ├── ScanOrchestrator.php
    │   ├── OjsdefSettingsForm.php
    │   └── scanners/
    │       ├── FingerprintScanner.php
    │       ├── ConfigScanner.php
    │       ├── PluginAuditor.php
    │       ├── RbacAuditor.php
    │       ├── FileIntegrityChecker.php
    │       └── ContentInjectionDetector.php
    ├── locale/
    │   └── en_US/
    │       └── locale.po
    └── templates/
        └── settingsForm.tpl
```

Data OJS disimpan di Docker volumes dan tetap persisten meski container di-restart.

---

## Referensi

- [OJS Docker Hub — pkpinc/ojs](https://hub.docker.com/r/pkpinc/ojs)
- [PKP Docker OJS GitHub](https://github.com/pkp/docker-ojs)
- [Cloudflare IP Ranges](https://www.cloudflare.com/ips/)
- [OJS Plugin Development Guide](https://docs.pkp.sfu.ca/dev/plugin-guide/en/)
- [OJSDef Plugin Design Spec](superpowers/specs/2026-05-30-ojsdef-plugin-design.md)
- [OJSDef Plugin Implementation Plan](superpowers/plans/2026-05-30-ojsdef-plugin-implementation.md)

---

*Dokumen ini adalah bagian dari Capstone Project Kelompok 3 — Topik G2, Fakultas Ilmu Komputer, Universitas Brawijaya, 2026.*
