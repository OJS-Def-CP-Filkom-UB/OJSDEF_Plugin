# Panduan Setup OJS di VPS dengan Docker + Nginx + Cloudflare

**Tujuan:** Menjalankan OJS di VPS dengan Docker, Nginx sebagai reverse proxy, SSL dikelola Cloudflare, lalu menginstall plugin OJSDef Security Scanner.

> **WAJIB — Sebelum memulai:** Tentukan subdomain kamu terlebih dahulu, lalu **ganti semua kemunculan `ojs.contoh.ac.id`** di dokumen ini dan di setiap file konfigurasi yang dibuat. Contoh subdomain aktual: `ojs-test.zentaza.online`.

**Versi OJS:** 3.4.x  
**Estimasi waktu:** 45–90 menit

---

## Daftar Isi

1. [Spesifikasi VPS](#1-spesifikasi-vps)
2. [Install Docker dan Docker Compose](#2-install-docker-dan-docker-compose)
3. [Buat File Konfigurasi](#3-buat-file-konfigurasi)
4. [Konfigurasi DNS di Cloudflare](#4-konfigurasi-dns-di-cloudflare)
5. [Jalankan dan Install OJS](#5-jalankan-dan-install-ojs)
6. [Fix Konfigurasi OJS untuk HTTPS](#6-fix-konfigurasi-ojs-untuk-https)
7. [Install Plugin OJSDef](#7-install-plugin-ojsdef)
8. [Konfigurasi Plugin OJSDef](#8-konfigurasi-plugin-ojsdef)
9. [Verifikasi Koneksi](#9-verifikasi-koneksi)
10. [Troubleshooting](#10-troubleshooting)

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

---

## 3. Buat File Konfigurasi

### 3.1 Buat direktori project

```bash
mkdir -p ~/ojs-docker && cd ~/ojs-docker
```

### 3.2 Buat `nginx.conf`

> **Ganti `ojs.contoh.ac.id` dengan subdomain kamu di SELURUH blok ini (ada 2 kemunculan).**

```bash
nano nginx.conf
```

```nginx
server {
    listen 80;
    server_name ojs.contoh.ac.id;

    # Cloudflare real IP — agar log menggunakan IP asli pengunjung
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

    client_max_body_size 32M;

    location / {
        proxy_pass         http://ojs:80;
        proxy_http_version 1.1;

        proxy_set_header Host               $host;
        proxy_set_header X-Real-IP          $remote_addr;
        proxy_set_header X-Forwarded-For    $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host   $host;
        proxy_set_header X-Forwarded-Proto  https;

        proxy_read_timeout    120s;
        proxy_connect_timeout 10s;

        # Tulis ulang URL http:// -> https:// di seluruh response (safety net mixed content)
        # GANTI ojs.contoh.ac.id di baris sub_filter berikut dengan subdomain kamu
        proxy_set_header    Accept-Encoding  "";
        sub_filter          'http://ojs.contoh.ac.id' 'https://ojs.contoh.ac.id';
        sub_filter_once     off;
        sub_filter_types    text/html application/json application/javascript;
    }
}
```

### 3.3 Buat `docker-compose.yml`

```bash
nano docker-compose.yml
```

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
    environment:
      MYSQL_HOST: db
      MYSQL_DB: ojs
      MYSQL_USER: ojs
      MYSQL_PASSWORD: ojs_secret
      FORCE_SSL: "off"
    volumes:
      - ojs_files:/var/www/files
      - ojs_public:/var/www/html/public
      # Baris berikut diaktifkan di Seksi 6 setelah OJS terinstall:
      # - ./ojs-config.inc.php:/var/www/html/config.inc.php
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

> **Catatan versi:** Tag `3_4_0-7` = OJS 3.4.0. Untuk OJS 3.3.x gunakan `3_3_0-15`. Cek di [Docker Hub pkpinc/ojs](https://hub.docker.com/r/pkpinc/ojs/tags).

### 3.4 Buka firewall VPS

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status
```

---

## 4. Konfigurasi DNS di Cloudflare

### 4.1 Tambah A Record

1. Login ke [Cloudflare Dashboard](https://dash.cloudflare.com) → pilih domain
2. **DNS** → **Records** → **Add record**

| Field | Nilai |
|-------|-------|
| Type | `A` |
| Name | `ojs` (nama subdomain) |
| IPv4 address | IP publik VPS |
| Proxy status | **Proxied** (awan oranye — WAJIB) |
| TTL | `Auto` |

Klik **Save**.

### 4.2 Mode SSL/TLS

1. **SSL/TLS** → **Overview** → set ke **Flexible**

   ```
   Browser ──HTTPS──► Cloudflare ──HTTP──► Nginx ──HTTP──► OJS
   ```

2. **Edge Certificates** → aktifkan:
   - **Always Use HTTPS** → On
   - **Automatic HTTPS Rewrites** → On

---

## 5. Jalankan dan Install OJS

### 5.1 Jalankan semua container

```bash
cd ~/ojs-docker
docker compose up -d
```

Tunggu ~30 detik lalu cek:

```bash
docker compose ps
```

Semua harus berstatus `Up`:

```
NAME         IMAGE                  STATUS          PORTS
ojs_app      pkpinc/ojs:3_4_0-7    Up              80/tcp
ojs_db       mysql:8.0              Up (healthy)    3306/tcp
ojs_nginx    nginx:1.25-alpine      Up              0.0.0.0:80->80/tcp
```

### 5.2 Wizard instalasi OJS

Buka browser: `https://ojs.contoh.ac.id`

Ikuti wizard:

| Langkah | Isian |
|---------|-------|
| Bahasa | English atau Bahasa Indonesia |
| File Paths | Biarkan default |
| DB Driver | `mysqli` |
| DB Host | `db` |
| DB User | `ojs` |
| DB Password | `ojs_secret` |
| DB Name | `ojs` |
| Admin Username | `admin` |
| Admin Password | Password kuat min 8 karakter |
| Admin Email | email kamu |

Klik **Install Open Journal Systems** — tunggu 1–2 menit sampai selesai.

### 5.3 Buat jurnal percobaan

1. Login: `https://ojs.contoh.ac.id/index.php/index/login`
2. **Administration** → **Hosted Journals** → **Create Journal**
3. Isi Name dan Path → **Save**

---

## 6. Fix Konfigurasi OJS untuk HTTPS

> **Langkah ini WAJIB.** Tanpa ini: halaman admin stuck "Loading", error Mixed Content di console, dan kemungkinan 404 setelah login.

OJS perlu dikonfigurasi agar menghasilkan URL HTTPS dan mengizinkan request dari subdomain kamu. Kita copy `config.inc.php` keluar container, edit tiga setting sekaligus, lalu mount sebagai volume.

### 6.1 Salin config dari container ke host

```bash
cd ~/ojs-docker
docker cp ojs_app:/var/www/html/config.inc.php ./ojs-config.inc.php
```

### 6.2 Edit tiga setting sekaligus

```bash
nano ojs-config.inc.php
```

Gunakan `Ctrl+W` untuk search. Cari dan ubah/tambahkan **tiga setting berikut** — ganti `ojs.contoh.ac.id` dengan subdomain kamu:

**Setting 1 — `base_url`**

Cari baris `base_url["index"]` dan ubah:
```ini
base_url["index"] = "https://ojs.contoh.ac.id"
```

**Setting 2 — `allowed_hosts`** (kritis di OJS 3.4+)

Cari `allowed_hosts`. Jika baris dikomentari (diawali `;`), hapus `;`-nya dan ubah nilainya:
```ini
allowed_hosts = "[\"ojs.contoh.ac.id\"]"
```

> Tanpa setting ini, OJS 3.4+ memblokir semua request dengan redirect yang menghasilkan error 404 di halaman admin.

**Setting 3 — `trust_x_forwarded_for`**

Cari `trust_x_forwarded_for`. Jika dikomentari, hapus `;` dan set `On`:
```ini
trust_x_forwarded_for = On
```

Simpan: `Ctrl+X` → `Y` → `Enter`.

### 6.3 Aktifkan volume mount di docker-compose.yml

```bash
nano docker-compose.yml
```

Cari baris yang dikomentari dan **hapus tanda `#`**:

```yaml
    volumes:
      - ojs_files:/var/www/files
      - ojs_public:/var/www/html/public
      - ./ojs-config.inc.php:/var/www/html/config.inc.php
```

### 6.4 Clear cache dan recreate OJS

```bash
# Clear Smarty template cache
docker exec ojs_app bash -c "rm -rf /var/www/html/cache/t_compile/* /var/www/html/cache/fc-*"

# Recreate container OJS dengan config baru ter-mount
docker compose up -d --force-recreate ojs

# Reload Nginx
docker compose exec nginx nginx -s reload
```

### 6.5 Verifikasi berhasil

Buka: `https://ojs.contoh.ac.id/index.php/index/login`

Login → **Administration** → **Hosted Journals** — halaman harus muncul penuh tanpa spinner "Loading".

Buka DevTools (F12) → Console — tidak boleh ada error "Mixed Content".

---

## 7. Install Plugin OJSDef

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
docker exec ojs_app chown -R www-data:www-data /var/www/html/plugins/generic/ojsdef
docker exec ojs_app chmod -R 755 /var/www/html/plugins/generic/ojsdef
```

Aktifkan di OJS: **Website Settings** → **Plugins** → **Generic Plugins**.

---

## 8. Konfigurasi Plugin OJSDef

### 8.1 Dapatkan kredensial dari OJSDef Dashboard

1. Login ke OJSDef Dashboard → **Add Target**
2. Masukkan URL: `https://ojs.contoh.ac.id`
3. Catat tiga nilai yang ditampilkan:

| Nilai | Keterangan |
|-------|-----------|
| **Backend URL** | URL API OJSDef |
| **API Key** | Kunci autentikasi unik per target |
| **Target ID** | UUID target |

### 8.2 Isi Settings plugin OJS

1. **Website Settings** → **Plugins** → **Generic Plugins** → **OJSDef Security Scanner** → **Settings**
2. Isi ketiga field → **Save** → **Test Connection**

---

## 9. Verifikasi Koneksi

| Connection Status | Artinya |
|------------------|---------|
| **Connected — Direct Mode** | Backend dapat reach plugin langsung. Scan mulai <10 detik. |
| **Connected — Heartbeat Mode** | Plugin di balik firewall. Scan mulai <5 menit via heartbeat. |
| **Disconnected** | Cek API Key, Backend URL, Target ID. |

Jalankan scan dari OJSDef Dashboard: pilih target → **Run Scan** → pilih tipe.

---

## 10. Troubleshooting

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

### Halaman admin stuck "Loading" atau Mixed Content error

Jalankan Seksi 6 jika belum. Jika sudah, verifikasi tiga setting:

```bash
docker exec ojs_app grep -E 'base_url\[|allowed_hosts|trust_x_forwarded' \
    /var/www/html/config.inc.php
```

Output yang benar (ganti dengan subdomain kamu):
```
base_url["index"] = "https://ojs.contoh.ac.id"
allowed_hosts = "[\"ojs.contoh.ac.id\"]"
trust_x_forwarded_for = On
```

Jika ada yang salah, edit `~/ojs-docker/ojs-config.inc.php` di host lalu:

```bash
docker exec ojs_app bash -c "rm -rf /var/www/html/cache/t_compile/* /var/www/html/cache/fc-*"
docker compose up -d --force-recreate ojs
docker compose exec nginx nginx -s reload
```

### 404 setelah perubahan config

Penyebab: `allowed_hosts` belum diset (OJS 3.4+). Tambahkan ke `ojs-config.inc.php`:
```ini
allowed_hosts = "[\"ojs.contoh.ac.id\"]"
```
Lalu jalankan perintah recreate di atas.

### `ojs-config.inc.php` ter-mount sebagai direktori (bug Docker)

Terjadi jika file belum ada saat Docker pertama kali mount. Cek:

```bash
docker exec ojs_app file /var/www/html/config.inc.php
# Harus: ASCII text — bukan: directory
```

Jika `directory`:

```bash
docker compose rm -sf ojs
rm -rf ~/ojs-docker/ojs-config.inc.php

# Komentari kembali baris volume config di docker-compose.yml
nano ~/ojs-docker/docker-compose.yml
# Tambahkan # di depan: - ./ojs-config.inc.php:/var/www/html/config.inc.php

docker compose up -d ojs
sleep 20
docker cp ojs_app:/var/www/html/config.inc.php ~/ojs-docker/ojs-config.inc.php
# Lanjutkan dari Seksi 6.2
```

### Plugin tidak muncul setelah upload

```bash
docker exec ojs_app bash -c \
  "find /var/www/html/plugins/generic/ojsdef -type f -exec chmod 644 {} \; && \
   find /var/www/html/plugins/generic/ojsdef -type d -exec chmod 755 {} \; && \
   chown -R www-data:www-data /var/www/html/plugins/generic/ojsdef"
```

### Plugin status tetap Disconnected

```bash
# Test outbound dari container OJS ke backend
docker exec ojs_app curl -s -o /dev/null -w "%{http_code}" \
    https://api.ojsdef.id/plugin/v1/heartbeat
# Harus 401 (server reachable, auth gagal — itu normal tanpa key)
# Jika timeout: izinkan outbound
sudo ufw allow out 443/tcp
sudo ufw allow out 80/tcp
```

### Reset total — mulai dari awal

> ⚠️ Menghapus semua data OJS!

```bash
cd ~/ojs-docker
docker compose down -v
rm -f ojs-config.inc.php
docker compose up -d
```

---

## Referensi Perintah Docker

```bash
docker compose ps                                        # status semua container
docker compose logs -f nginx                             # log Nginx real-time
docker compose logs -f ojs                               # log OJS real-time
docker exec -it ojs_app bash                            # masuk ke container OJS
docker exec -it ojs_db mysql -u ojs -pojs_secret ojs   # masuk MySQL
docker compose restart nginx                             # restart Nginx saja
docker compose restart ojs                              # restart OJS saja
docker compose stop                                      # stop semua (data aman)
docker compose down                                      # hapus container (data aman)
docker compose down -v                                   # hapus container + data volume
```

---

## Struktur File di VPS

```
~/ojs-docker/
├── docker-compose.yml        <- Orchestrasi semua service (db + ojs + nginx)
├── nginx.conf                <- Reverse proxy + sub_filter HTTPS
├── ojs-config.inc.php        <- config.inc.php OJS (dibuat di Seksi 6, di-mount ke container)
└── ojsdef/                   <- Plugin (hanya jika pakai Metode B upload manual)
```

---

## Referensi

- [OJS Docker Hub — pkpinc/ojs](https://hub.docker.com/r/pkpinc/ojs)
- [PKP Docker OJS GitHub](https://github.com/pkp/docker-ojs)
- [Cloudflare IP Ranges](https://www.cloudflare.com/ips/)
- [OJSDef Plugin Design Spec](superpowers/specs/2026-05-30-ojsdef-plugin-design.md)
- [OJSDef Plugin Implementation Plan](superpowers/plans/2026-05-30-ojsdef-plugin-implementation.md)

---

*Capstone Project Kelompok 3 — Topik G2, Fakultas Ilmu Komputer, Universitas Brawijaya, 2026.*
