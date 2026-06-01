# Panduan Skenario Pengujian OJSDef Security Scanner

**Tujuan:** Menyiapkan berbagai kondisi keamanan di instalasi OJS untuk memverifikasi deteksi OJSDef — baik scanner internal (plugin PHP) maupun eksternal (Python bot).

**Prasyarat:**
- OJS sudah berjalan di VPS (ikuti panduan `panduan-vps-ojs-docker-ssl-direct.md`)
- Plugin OJSDef sudah terpasang dan terhubung ke backend
- Akses SSH ke VPS dan akses admin OJS

**Konvensi dokumen:** Ganti `ojs.contoh.ac.id` dengan domain OJS aktual kamu.

---

## Daftar Isi

- [Arsitektur Scanner OJSDef](#arsitektur-scanner-ojsdef)
- [Level Risiko dan CVSS](#level-risiko-dan-cvss)
- [Skenario KRITIS 9.0–10.0](#skenario-kritis-90100)
  - [K-1 Debug Mode Aktif](#k-1-debug-mode-aktif--internal)
  - [K-2 Content Injection — Gambling](#k-2-content-injection--gambling--internal)
  - [K-3 Injeksi eval base64](#k-3-injeksi-evalbase64--internal)
  - [K-4 File Integrity Violation Core File](#k-4-file-integrity-violation--core-file--internal)
- [Skenario BERBAHAYA 7.0–8.9](#skenario-berbahaya-7089)
  - [B-1 Force SSL Dimatikan](#b-1-force-ssl-dimatikan--internal--eksternal)
  - [B-2 Hidden iFrame Injection](#b-2-hidden-iframe-injection--internal)
  - [B-3 Phishing TLD di Konten](#b-3-phishing-tld-di-konten--internal)
  - [B-4 Multiple Superadmin Account](#b-4-multiple-superadmin-account--internal)
  - [B-5 Missing Security Headers](#b-5-missing-security-headers--eksternal)
  - [B-6 File Integrity Violation Plugin File](#b-6-file-integrity-violation--plugin-file--internal)
- [Skenario PERHATIAN 4.0–6.9](#skenario-perhatian-4069)
  - [P-1 JavaScript Redirect Tersembunyi](#p-1-javascript-redirect-tersembunyi--internal)
  - [P-2 SMTP Tanpa Autentikasi](#p-2-smtp-tanpa-autentikasi--internal)
  - [P-3 Akun Superadmin Tidak Aktif](#p-3-akun-superadmin-tidak-aktif--internal)
  - [P-4 Plugin Terinstall Tapi Dinonaktifkan](#p-4-plugin-terinstall-tapi-dinonaktifkan--internal)
  - [P-5 Endpoint Admin Terbuka dari Luar](#p-5-endpoint-admin-terbuka-dari-luar--eksternal)
  - [P-6 Versi OJS Terekspos di Header](#p-6-versi-ojs-terekspos-di-header--eksternal)
- [Skenario AMAN/RENDAH 0.1–3.9](#skenario-amanrendah-0139)
  - [A-1 Informasi Versi di Meta Tag](#a-1-informasi-versi-di-meta-tag--eksternal)
  - [A-2 Cookie Tanpa Secure Flag](#a-2-cookie-tanpa-secure-flag--eksternal)
  - [A-3 OAI Endpoint Terbuka](#a-3-oai-endpoint-terbuka--eksternal)
  - [A-4 Banyak Plugin Aktif](#a-4-banyak-plugin-aktif--internal)
- [Skenario 4: Verifikasi Domain (File + DNS Method)](#skenario-4-verifikasi-domain-file--dns-method)
  - [Test Case 4.1 — File Method](#test-case-41--file-method)
  - [Test Case 4.2 — DNS Method](#test-case-42--dns-method)
  - [Test Case 4.3 — Token Mismatch](#test-case-43--token-mismatch)
- [Skenario 5: Plugin Status Display](#skenario-5-plugin-status-display)
  - [Test Case 5.1 — Status Terhubung](#test-case-51--status-terhubung)
  - [Test Case 5.2 — Status Tidak Terhubung](#test-case-52--status-tidak-terhubung)
  - [Test Case 5.3 — Status Belum Terhubung](#test-case-53--status-belum-terhubung)
- [Skenario 6: Audit Log (saas_admin only)](#skenario-6-audit-log-saas_admin-only)
  - [Test Case 6.1 — Login tercatat](#test-case-61--login-tercatat)
  - [Test Case 6.2 — Filter aksi](#test-case-62--filter-aksi)
  - [Test Case 6.3 — RBAC guard](#test-case-63--rbac-guard)
- [Skenario 7: False Positive di Laporan](#skenario-7-false-positive-di-laporan)
  - [Test Case 7.1 — Mark sebagai false positive](#test-case-71--mark-sebagai-false-positive)
  - [Test Case 7.2 — Export laporan dengan FP](#test-case-72--export-laporan-dengan-fp)
- [Skenario Baseline — Konfigurasi Bersih](#skenario-baseline--konfigurasi-bersih)
- [Cara Menjalankan Scan OJSDef](#cara-menjalankan-scan-ojsdef)
- [Checklist Verifikasi Hasil](#checklist-verifikasi-hasil)
- [Urutan Pengujian yang Disarankan](#urutan-pengujian-yang-disarankan)

---

## Arsitektur Scanner OJSDef

```
SCANNER INTERNAL (Plugin PHP)          SCANNER EKSTERNAL (Python Bot)
--------------------------------       --------------------------------
FingerprintScanner                     SSL/TLS Checker
  OJS version, PHP, OS, plugins          Cert validity, cipher suites

ConfigScanner                          HTTP Header Analyzer
  11 config flags (debug, smtp, ssl)     Security headers presence/values

PluginAuditor                          Endpoint Discovery
  enabled/disabled count                 Admin, API, OAI, robots.txt

RbacAuditor                            CVE Database Check
  superadmin count, inactive accts       Known OJS vulnerabilities

FileIntegrityChecker
  SHA-256 vs checksums database

ContentInjectionDetector
  gambling, iframe, js-redirect,
  eval(base64), phishing TLD
```

---

## Level Risiko dan CVSS

| Level | Label UI OJSDef | CVSS Score | Warna Dashboard |
|-------|-----------------|------------|-----------------|
| Critical | **Kritis** | 9.0 – 10.0 | Merah |
| High | **Berbahaya** | 7.0 – 8.9 | Oranye |
| Medium | **Perhatian** | 4.0 – 6.9 | Kuning |
| Low | **Aman** | 0.1 – 3.9 | Hijau |

---

## Skenario KRITIS (9.0–10.0)

---

### K-1 Debug Mode Aktif — [INTERNAL]

**CVSS:** 9.8 | **Modul:** ConfigScanner

**Deskripsi:**
Konfigurasi debug aktif mengekspos stack trace PHP, path internal server, query SQL, dan informasi sistem kepada semua pengunjung saat terjadi error. Memberikan peta lengkap arsitektur internal kepada penyerang.

**Langkah setup kondisi vulnerable:**

```bash
docker exec ojs_app php -r "
\$f='/var/www/html/config.inc.php';
\$c=file_get_contents(\$f);
\$c=str_replace('display_errors = Off','display_errors = On',\$c);
\$c=str_replace('show_stacktrace = Off','show_stacktrace = On',\$c);
file_put_contents(\$f,\$c);
echo 'Debug mode: ON';
"
```

**Verifikasi kondisi vulnerable:**

```bash
curl -sk https://ojs.contoh.ac.id/index/HALAMAN_TIDAK_ADA 2>&1 | \
    grep -i 'stack\|trace\|exception\|warning'
# Jika ada output → stack trace terekspos
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "config",
  "flags": {
    "display_errors": "On",
    "show_stacktrace": "On"
  },
  "severity": "critical",
  "cvss": 9.8
}
```

**Remediasi:**

```bash
docker exec ojs_app php -r "
\$f='/var/www/html/config.inc.php';
\$c=file_get_contents(\$f);
\$c=str_replace('display_errors = On','display_errors = Off',\$c);
\$c=str_replace('show_stacktrace = On','show_stacktrace = Off',\$c);
file_put_contents(\$f,\$c);
echo 'Debug mode: OFF';
"
```

---

### K-2 Content Injection — Gambling — [INTERNAL]

**CVSS:** 9.5 | **Modul:** ContentInjectionDetector

**Deskripsi:**
Penyerang menyisipkan konten promosi judi online ke halaman jurnal ilmiah. Pola yang dideteksi mencakup: `slot`, `togel`, `casino`, `poker`, `gacor`, `maxwin`, `jackpot`, `scatter`.

**Langkah setup:**

1. Login OJS sebagai admin
2. Pilih jurnal → **Settings** → **Journal** → **Masthead**
3. Di field **About the Journal**, sisipkan:

```html
<p>Jurnal penelitian multidisiplin yang terindeks internasional.</p>

<!-- test gambling injection -->
<p style="display:none">
SLOT GACOR maxwin hari ini! TOGEL online jackpot terbesar.
Daftar CASINO online bonus 100%. POKER uang asli terpercaya.
</p>
```

4. Klik **Save**

**Verifikasi:**

```bash
curl -sk "https://ojs.contoh.ac.id/index/JOURNAL_PATH/about" | \
    grep -i 'slot\|togel\|casino\|jackpot\|gacor'
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "content",
  "gambling_keywords_found": true,
  "matched_patterns": ["slot gacor", "togel", "casino", "jackpot"],
  "location": "journal.about",
  "severity": "critical"
}
```

**Remediasi:** Hapus konten injection dari field About the Journal, simpan ulang.

---

### K-3 Injeksi eval(base64) — [INTERNAL]

**CVSS:** 9.8 | **Modul:** ContentInjectionDetector

**Deskripsi:**
Pola `eval(base64_decode(...))` dalam konten mengindikasikan upaya eksekusi kode PHP tersembunyi — metode standar malware untuk backdoor dan webshell. Ini adalah indikator kompromi (IoC) paling kuat.

**Langkah setup** (simulasi — kode tidak dieksekusi karena ada di konten HTML):

1. Login OJS → jurnal → **Settings** → **Website** → **Appearance** → **Setup**
2. Di field **Footer**, tambahkan:

```html
<p>© 2026 Test Journal — All Rights Reserved</p>
<!-- malware simulation test marker -->
<span style="display:none">
eval(base64_decode('dGVzdCBzaW11bGFzaSBtYWx3YXJl'))
</span>
```

3. Simpan

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "content",
  "eval_base64_found": true,
  "location": "site.footer",
  "severity": "critical",
  "note": "Pattern consistent with PHP backdoor/webshell injection"
}
```

**Remediasi:** Hapus tag `<span>` berisi pattern dari Footer settings.

---

### K-4 File Integrity Violation — Core File — [INTERNAL]

**CVSS:** 9.1 | **Modul:** FileIntegrityChecker

**Deskripsi:**
File inti OJS yang termodifikasi mengindikasikan kemungkinan kompromi sistem. Scanner membandingkan SHA-256 hash setiap file dengan database checksums resmi PKP. Perubahan apapun — termasuk tambahan karakter tunggal — akan terdeteksi.

**Langkah setup:**

```bash
# Tambahkan komentar tidak berbahaya ke entrypoint OJS
docker exec ojs_app sh -c \
    "echo '// integrity_test_marker' >> /var/www/html/index.php"

# Verifikasi file berubah
docker exec ojs_app tail -2 /var/www/html/index.php
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "file_integrity",
  "modified_files": ["index.php"],
  "missing_files": [],
  "severity": "critical",
  "cvss": 9.1
}
```

**Remediasi:**

```bash
# Hapus baris yang ditambahkan
docker exec ojs_app sh -c \
    "head -n -1 /var/www/html/index.php > /tmp/idx.php && \
     cp /tmp/idx.php /var/www/html/index.php && \
     rm /tmp/idx.php"
```

---

## Skenario BERBAHAYA (7.0–8.9)

---

### B-1 Force SSL Dimatikan — [INTERNAL + EKSTERNAL]

**CVSS:** 8.1 | **Modul:** ConfigScanner + External SSL Checker

**Deskripsi:**
`force_ssl = Off` memungkinkan akses melalui HTTP plaintext. Kredensial login, token sesi, dan data submission dapat dicuri via man-in-the-middle (MITM) pada jaringan tidak aman (WiFi publik, ISP yang tidak terpercaya).

**Langkah verifikasi kondisi** (kondisi default OJS sudah Off — tidak perlu setup tambahan):

```bash
# Cek nilai di config
docker exec ojs_app grep 'force_ssl\|force_login_ssl' /var/www/html/config.inc.php

# Cek apakah HTTP redirect ke HTTPS (Nginx harus handle ini)
curl -skI http://ojs.contoh.ac.id | head -3
# Harus: HTTP/1.1 301 Moved Permanently
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "config",
  "force_ssl": "Off",
  "force_login_ssl": "Off",
  "severity": "high",
  "note": "OJS tidak memaksa SSL secara native. Pastikan redirect HTTPS dikonfigurasi di Nginx."
}
```

**Remediasi:** Pastikan redirect HTTP → HTTPS ada di `nginx.conf` (sudah ada di panduan setup). Jika ingin OJS juga enforce SSL natively:

```ini
; Di config.inc.php
force_login_ssl = On
```

---

### B-2 Hidden iFrame Injection — [INTERNAL]

**CVSS:** 8.3 | **Modul:** ContentInjectionDetector

**Deskripsi:**
iFrame tersembunyi digunakan untuk clickjacking, drive-by download, atau memuat payload phishing. Pola yang dideteksi: tag `<iframe>` dengan atribut `hidden`, `display:none`, atau dimensi sangat kecil (width/height 0-2px).

**Langkah setup:**

1. Login OJS → jurnal → **Settings** → **Journal** → **Masthead**
2. Di **About the Journal**, tambahkan:

```html
<p>Jurnal ini menerima artikel dari semua disiplin ilmu.</p>

<!-- hidden iframe injection test -->
<iframe src="https://attacker-simulation.example.com/track"
        width="1" height="1"
        style="display:none; visibility:hidden"
        frameborder="0">
</iframe>
```

3. Simpan

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "content",
  "hidden_iframe_found": true,
  "iframe_src": "https://attacker-simulation.example.com/track",
  "location": "journal.about",
  "severity": "high"
}
```

**Remediasi:** Hapus tag `<iframe>` dari konten, simpan ulang.

---

### B-3 Phishing TLD di Konten — [INTERNAL]

**CVSS:** 7.5 | **Modul:** ContentInjectionDetector

**Deskripsi:**
Link menuju domain dengan TLD berisiko tinggi yang sering digunakan phishing (`.ru`, `.cn`, `.tk`, `.ml`, `.ga`, `.cf`, `.gq`) tertanam di konten jurnal. Pengunjung yang mengklik link tersebut berisiko diarahkan ke situs phishing.

**Langkah setup:**

1. Login OJS → **Settings** → **Website** → **Information** → **For Authors**
2. Tambahkan:

```html
<p>Panduan penulisan tersedia di <a href="https://author-guide.tk/panduan">sini</a>.</p>
<p>Kunjungi juga mitra kami di <a href="http://partner.ml/ojs">partner.ml</a>.</p>
```

3. Simpan

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "content",
  "phishing_tld_found": true,
  "matched_domains": ["author-guide.tk", "partner.ml"],
  "location": "site.for_authors",
  "severity": "high"
}
```

**Remediasi:** Hapus atau ganti link dengan domain legitimate (.com, .ac.id, .edu, dll).

---

### B-4 Multiple Superadmin Account — [INTERNAL]

**CVSS:** 7.2 | **Modul:** RbacAuditor

**Deskripsi:**
Setiap akun Site Administrator yang tidak diperlukan memperluas attack surface. Kompromi satu akun superadmin berarti kendali penuh atas seluruh platform. OJSDef memflag jika `superadmin_count > 1`.

**Langkah setup:**

1. Login OJS → **Administration** → **Users & Roles** → **Users** → **Add User**
2. Buat akun:

| Field | Nilai |
|-------|-------|
| Username | `admin_test2` |
| Email | `admin2@test.contoh.ac.id` |
| Password | `TestAdmin456!` |
| Role | **Site Administrator** |

3. Simpan. Ulangi untuk `admin_test3`.

**Verifikasi di DB:**

```bash
docker exec ojs_db mysql -uojs -pojs_secret ojs -e \
    "SELECT u.username, u.email 
     FROM users u 
     JOIN user_groups ug ON u.user_id=ug.user_id
     WHERE ug.group_id=1;" 2>/dev/null
# group_id=1 biasanya Site Administrator
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "rbac",
  "superadmin_count": 3,
  "severity": "high",
  "note": "Lebih dari 1 superadmin. Prinsip least privilege dilanggar."
}
```

**Remediasi:** Hapus akun superadmin yang tidak diperlukan (Users & Roles → hapus akun test).

---

### B-5 Missing Security Headers — [EKSTERNAL]

**CVSS:** 7.4 | **Modul:** External HTTP Header Analyzer

**Deskripsi:**
HTTP security headers melindungi pengguna dari XSS, clickjacking, MIME sniffing, dan information leakage di sisi browser. Ketiadaannya membuat pengguna rentan meskipun server tidak terkompromi.

**Langkah setup simulasi kondisi lemah:**

```bash
# Edit nginx.conf — hapus atau komentari baris add_header HSTS
nano ~/ojs-docker/nginx.conf
# Tambahkan # di depan:
# add_header Strict-Transport-Security "max-age=63072000" always;

docker exec ojs_nginx nginx -s reload
```

**Verifikasi kondisi vulnerable:**

```bash
curl -skI https://ojs.contoh.ac.id | grep -i 'strict-transport\|x-frame\|x-content'
# Jika tidak ada output → header hilang
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "headers",
  "missing": ["Strict-Transport-Security", "X-Frame-Options", "X-Content-Type-Options"],
  "severity": "high"
}
```

**Remediasi — tambahkan security headers ke `nginx.conf`:**

```nginx
add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
```

```bash
docker exec ojs_nginx nginx -s reload
```

---

### B-6 File Integrity Violation — Plugin File — [INTERNAL]

**CVSS:** 7.8 | **Modul:** FileIntegrityChecker

**Deskripsi:**
Modifikasi pada file plugin resmi OJS yang tidak terdaftar dalam checksums database mengindikasikan potensi code injection atau trojanized plugin. Lebih sulit dideteksi manual karena plugin jumlahnya banyak.

**Langkah setup:**

```bash
# Modifikasi file plugin default theme
docker exec ojs_app sh -c \
    "echo '/* integrity_test */' >> \
     /var/www/html/plugins/themes/default/styles/variables.less"

# Verifikasi
docker exec ojs_app tail -2 \
    /var/www/html/plugins/themes/default/styles/variables.less
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "file_integrity",
  "modified_files": ["plugins/themes/default/styles/variables.less"],
  "severity": "high"
}
```

**Remediasi:**

```bash
docker exec ojs_app sh -c \
    "head -n -1 /var/www/html/plugins/themes/default/styles/variables.less > \
     /tmp/var.less && cp /tmp/var.less \
     /var/www/html/plugins/themes/default/styles/variables.less"
```

---

## Skenario PERHATIAN (4.0–6.9)

---

### P-1 JavaScript Redirect Tersembunyi — [INTERNAL]

**CVSS:** 6.1 | **Modul:** ContentInjectionDetector

**Deskripsi:**
Script JavaScript yang melakukan redirect (`window.location`, `document.location`) tersembunyi di konten jurnal. Digunakan untuk mengarahkan pengunjung ke situs berbahaya tanpa sepengetahuan mereka.

**Langkah setup:**

1. Login OJS → jurnal → **Settings** → **Website** → **Appearance** → **Setup**
2. Di field **Footer**, tambahkan:

```html
<p>© 2026 Test Journal</p>
<script>
// js redirect test simulation
if(document.referrer.indexOf('google') > -1) {
    window.location.href = 'https://redirect-test.example.com';
}
</script>
```

3. Simpan

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "content",
  "js_redirect_found": true,
  "pattern": "window.location",
  "location": "site.footer",
  "severity": "medium"
}
```

**Remediasi:** Hapus tag `<script>` dari Footer settings.

---

### P-2 SMTP Tanpa Autentikasi — [INTERNAL]

**CVSS:** 5.3 | **Modul:** ConfigScanner

**Deskripsi:**
Server email dikonfigurasi tanpa autentikasi, membuka risiko relay spam atau intercept email sistem (password reset, notifikasi review). Server SMTP terbuka dapat disalahgunakan untuk mengirim email phishing atas nama jurnal.

**Langkah setup:**

```bash
docker exec ojs_app php -r "
\$f='/var/www/html/config.inc.php';
\$c=file_get_contents(\$f);
\$c=str_replace('; smtp = On','smtp = On',\$c);
\$c=str_replace('; smtp_server = mail.example.com','smtp_server = smtp-test.contoh.ac.id',\$c);
\$c=str_replace('; smtp_port = 25','smtp_port = 25',\$c);
// Sengaja tidak mengisi smtp_auth
file_put_contents(\$f,\$c);
echo 'SMTP no-auth: ON';
"
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "config",
  "smtp_enabled": true,
  "smtp_auth": null,
  "severity": "medium",
  "note": "SMTP aktif tanpa autentikasi — risiko relay spam"
}
```

**Remediasi:**

```bash
docker exec ojs_app php -r "
\$f='/var/www/html/config.inc.php';
\$c=file_get_contents(\$f);
\$c=str_replace('smtp = On','; smtp = On',\$c);
\$c=str_replace('smtp_server = smtp-test.contoh.ac.id','; smtp_server = mail.example.com',\$c);
\$c=str_replace('smtp_port = 25','; smtp_port = 25',\$c);
file_put_contents(\$f,\$c);
echo 'SMTP: reverted';
"
```

---

### P-3 Akun Superadmin Tidak Aktif — [INTERNAL]

**CVSS:** 5.0 | **Modul:** RbacAuditor

**Deskripsi:**
Akun dengan hak tinggi yang sudah lama tidak login (>90 hari) tetap aktif — berpotensi menjadi target credential stuffing atau brute force tanpa diketahui pemiliknya karena tidak ada monitoring aktif.

**Langkah setup:**

1. Buat akun Journal Manager baru:
   - Login OJS → Users & Roles → Add User
   - Username: `manager_lama`, Email: `manager@test.contoh.ac.id`
   - Role: **Journal Manager**

2. Set last login menjadi 6 bulan lalu:

```bash
docker exec ojs_db mysql -uojs -pojs_secret ojs -e \
    "UPDATE users 
     SET date_last_login = DATE_SUB(NOW(), INTERVAL 180 DAY)
     WHERE username = 'manager_lama';" 2>/dev/null
echo "Last login: updated ke 180 hari lalu"
```

**Verifikasi:**

```bash
docker exec ojs_db mysql -uojs -pojs_secret ojs -e \
    "SELECT username, 
            DATEDIFF(NOW(), date_last_login) as days_inactive
     FROM users 
     WHERE date_last_login < DATE_SUB(NOW(), INTERVAL 90 DAY);" 2>/dev/null
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "rbac",
  "inactive_high_privilege": [
    {
      "user_id": 3,
      "last_login": "2025-12-01",
      "days_inactive": 180
    }
  ],
  "severity": "medium"
}
```

**Remediasi:** Nonaktifkan atau hapus akun yang tidak digunakan lebih dari 90 hari.

---

### P-4 Plugin Terinstall Tapi Dinonaktifkan — [INTERNAL]

**CVSS:** 4.3 | **Modul:** PluginAuditor

**Deskripsi:**
Plugin yang terinstall tetapi dinonaktifkan tetap ada di filesystem server dan bisa mengandung kerentanan yang dapat dieksploitasi. Attack surface berkurang hanya jika plugin benar-benar dihapus (uninstall), bukan sekadar dinonaktifkan.

**Langkah setup:**

1. Login OJS → **Website Settings** → **Plugins** → **Plugin Gallery**
2. Install tapi **jangan aktifkan** beberapa plugin:
   - `Citation Style Language` → Install → (jangan toggle aktifkan)
   - `Hypothes.is` → Install → aktifkan → nonaktifkan lagi

**Verifikasi:**

```bash
docker exec ojs_db mysql -uojs -pojs_secret ojs -e \
    "SELECT plugin_name, setting_value as enabled
     FROM plugin_settings 
     WHERE setting_name='enabled' AND setting_value='0'
     LIMIT 5;" 2>/dev/null
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "plugins",
  "total_installed": 18,
  "total_enabled": 15,
  "disabled_but_installed": 3,
  "severity": "medium",
  "recommendation": "Uninstall plugin yang tidak digunakan"
}
```

**Remediasi:** Plugin Gallery → cari plugin disabled → Uninstall.

---

### P-5 Endpoint Admin Terbuka dari Luar — [EKSTERNAL]

**CVSS:** 5.8 | **Modul:** External Endpoint Discovery

**Deskripsi:**
Halaman administrasi OJS yang dapat diakses dari internet tanpa pembatasan IP meningkatkan risiko brute force login. Kondisi ini adalah default OJS — semua instalasi baru akan memiliki kondisi ini.

**Verifikasi kondisi** (tidak perlu setup khusus — ini kondisi default):

```bash
# Endpoint login terbuka
curl -sk https://ojs.contoh.ac.id/index/login \
    -w '\nHTTP:%{http_code}' -o /dev/null
# Output: HTTP:200 → terbuka

# Endpoint admin terbuka
curl -sk https://ojs.contoh.ac.id/index/admin \
    -w '\nHTTP:%{http_code}' -o /dev/null
# Output: HTTP:200 atau HTTP:302 → terbuka
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "endpoints",
  "login_accessible": true,
  "admin_accessible": true,
  "ip_restriction": false,
  "severity": "medium",
  "recommendation": "Tambahkan rate limiting pada endpoint login"
}
```

**Remediasi partial — tambahkan rate limiting di nginx.conf:**

```nginx
# Di atas server block (level http):
limit_req_zone $binary_remote_addr zone=ojs_login:10m rate=5r/m;

# Di dalam location /index/login:
limit_req zone=ojs_login burst=10 nodelay;
limit_req_status 429;
```

---

### P-6 Versi OJS Terekspos di Header — [EKSTERNAL]

**CVSS:** 4.0 | **Modul:** External Fingerprinting

**Deskripsi:**
Versi OJS yang terekspos di HTTP response atau HTML meta tag memudahkan penyerang mengidentifikasi instalasi dengan CVE spesifik untuk versi tersebut, mempercepat reconnaissance serangan terarah.

**Verifikasi kondisi** (kondisi default OJS):

```bash
# Cek meta generator tag
curl -sk https://ojs.contoh.ac.id/index/index | grep -i 'generator'
# Output: <meta name="generator" content="Open Journal Systems 3.4.0.10" />

# Cek header server
curl -skI https://ojs.contoh.ac.id | grep -i 'server\|x-powered'
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "fingerprint",
  "ojs_version": "3.4.0.10",
  "version_source": "meta_generator",
  "severity": "medium",
  "cve_check": "No known CVEs for this version"
}
```

**Remediasi** — sembunyikan version info di nginx:

```nginx
server_tokens off;
proxy_hide_header X-Powered-By;
```

Untuk meta generator: buat custom theme yang override template header dan hapus baris `<meta name="generator">`.

---

## Skenario AMAN/RENDAH (0.1–3.9)

---

### A-1 Informasi Versi di Meta Tag — [EKSTERNAL]

**CVSS:** 3.1 | **Modul:** External Fingerprinting

**Deskripsi:**
Meta tag `generator` terekspos secara default di semua halaman OJS publik. Risiko sangat rendah secara individual, tetapi digunakan OJSDef untuk cross-reference CVE database dan fingerprinting instalasi.

**Verifikasi** (tidak perlu setup — kondisi default):

```bash
curl -sk https://ojs.contoh.ac.id/index/index | grep generator
# Output: <meta name="generator" content="Open Journal Systems 3.4.0.10" />
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "fingerprint",
  "ojs_version": "3.4.0.10",
  "php_version": "8.2.x",
  "server_os": "Alpine Linux",
  "severity": "low",
  "note": "Informasi versi digunakan untuk CVE correlation"
}
```

---

### A-2 Cookie Tanpa Secure Flag — [EKSTERNAL]

**CVSS:** 3.7 | **Modul:** External Cookie Analyzer

**Deskripsi:**
Cookie sesi tanpa atribut `Secure` dapat terkirim melalui HTTP jika pengguna mengakses sebelum redirect HTTPS bekerja. Risiko session hijacking pada jaringan tidak aman.

**Verifikasi:**

```bash
# Cek cookie dari login page
curl -skc /tmp/test_cookies.txt \
    https://ojs.contoh.ac.id/index/login -o /dev/null
cat /tmp/test_cookies.txt
# Periksa kolom terakhir (secure flag) dan ada/tidaknya HttpOnly
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "cookies",
  "session_cookie": {
    "name": "OJSSID",
    "secure": false,
    "httponly": true
  },
  "severity": "low",
  "note": "Cookie akan aman selama HTTPS redirect di Nginx aktif"
}
```

**Remediasi:** Pastikan redirect HTTP→HTTPS di Nginx aktif. Untuk cookie Secure flag eksplisit, aktifkan `force_ssl = On` di config OJS.

---

### A-3 OAI Endpoint Terbuka — [EKSTERNAL]

**CVSS:** 2.6 | **Modul:** External Endpoint Discovery

**Deskripsi:**
Endpoint OAI-PMH (`/index/oai`) terbuka secara publik — ini adalah fitur standar OJS untuk interoperabilitas metadata jurnal ilmiah. Sangat rendah risikonya, bersifat informatif. Disertakan sebagai konfirmasi bahwa jurnal berfungsi normal.

**Setup jurnal dengan artikel agar OAI punya data:**

1. Login OJS → buat jurnal (misal path: `jurnal-test`)
2. **Issues** → **Create Issue** → publish
3. **Submissions** → submit artikel percobaan → assign ke issue → publish

**Verifikasi:**

```bash
curl -sk "https://ojs.contoh.ac.id/index/jurnal-test/oai?verb=Identify"
# Output: XML dengan informasi repositori

curl -sk "https://ojs.contoh.ac.id/index/jurnal-test/oai?verb=ListRecords&metadataPrefix=oai_dc"
# Output: XML dengan daftar artikel
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "endpoints",
  "oai_accessible": true,
  "oai_records_count": 1,
  "severity": "low",
  "note": "Informasional — OAI-PMH adalah fitur standar jurnal open access"
}
```

---

### A-4 Banyak Plugin Aktif — [INTERNAL]

**CVSS:** 2.0 | **Modul:** PluginAuditor

**Deskripsi:**
Setiap plugin tambahan meningkatkan attack surface secara proporsional. Risiko residual rendah dan bersifat informatif. OJSDef melaporkan jumlah plugin untuk audit, bukan sebagai temuan serius.

**Langkah setup:**

1. Login OJS → **Website Settings** → **Plugins** → **Plugin Gallery**
2. Install dan aktifkan beberapa plugin sekaligus:
   - `Citation Style Language`
   - `Hypothes.is`
   - `Browse By Section`
   - `Funding`
   - `Usage Statistics`

**Verifikasi:**

```bash
docker exec ojs_db mysql -uojs -pojs_secret ojs -e \
    "SELECT COUNT(*) as enabled_plugins
     FROM plugin_settings
     WHERE setting_name='enabled' AND setting_value='1';" 2>/dev/null
```

**Output yang diharapkan dari OJSDef:**

```json
{
  "module": "plugins",
  "total_installed": 22,
  "total_enabled": 20,
  "disabled_installed": 2,
  "severity": "low",
  "recommendation": "Audit rutin plugin — hapus yang tidak digunakan"
}
```

---

## Skenario Baseline — Konfigurasi Bersih

Jalankan sebelum dan sesudah pengujian untuk memvalidasi kondisi "semua aman":

```bash
# 1. Reset config flags
docker exec ojs_app php -r "
\$f='/var/www/html/config.inc.php';
\$c=file_get_contents(\$f);
\$c=str_replace('display_errors = On','display_errors = Off',\$c);
\$c=str_replace('show_stacktrace = On','show_stacktrace = Off',\$c);
\$c=str_replace('smtp = On','; smtp = On',\$c);
file_put_contents(\$f,\$c);
echo 'Config: clean';
"

# 2. Kembalikan file yang dimodifikasi
docker exec ojs_app sh -c \
    "head -n -1 /var/www/html/index.php > /tmp/idx.php && \
     cp /tmp/idx.php /var/www/html/index.php"

docker exec ojs_app sh -c \
    "head -n -1 /var/www/html/plugins/themes/default/styles/variables.less > \
     /tmp/v.less && cp /tmp/v.less \
     /var/www/html/plugins/themes/default/styles/variables.less"

# 3. Kembalikan nginx security headers (jika dihapus di B-5)
# Edit ~/ojs-docker/nginx.conf dan uncomment baris add_header
docker exec ojs_nginx nginx -s reload

# 4. Verifikasi kondisi bersih
echo "=== Security Headers ==="
curl -skI https://ojs.contoh.ac.id | grep -i 'strict-transport\|x-frame\|x-content'

echo "=== Config flags ==="
docker exec ojs_app grep -E 'display_errors|show_stacktrace' \
    /var/www/html/config.inc.php | grep -v '^;'
```

**Kondisi baseline yang benar:**

| Parameter | Nilai yang Diharapkan |
|-----------|----------------------|
| `display_errors` | Off |
| `show_stacktrace` | Off |
| `force_ssl` | Off (HTTPS via Nginx) |
| HSTS header | Ada |
| X-Frame-Options | Ada |
| Superadmin count | 1 |
| Inactive high-priv | 0 |
| File integrity | Semua match |
| Content injection | Tidak ada |

---

## Skenario 4: Verifikasi Domain (File + DNS Method)

### Test Case 4.1 — File Method

**Pre-condition:** Target ditambah di dashboard, belum diverifikasi

**Steps:**
1. Login ke OJSDef Dashboard → Target detail → **Verify Domain**
2. Copy token verification yang digenerate, misal: `abc123def456`
3. Di VPS, buat file verification:
   ```bash
   docker exec ojs_app sh -c \
       "mkdir -p /var/www/html/.well-known && \
        echo 'ojsdef-verification=abc123def456' > \
        /var/www/html/.well-known/ojsdef-verify-abc123def456.txt"
   ```
4. Verifikasi file dapat diakses:
   ```bash
   curl -sk https://ojs.contoh.ac.id/.well-known/ojsdef-verify-abc123def456.txt
   # Output: ojsdef-verification=abc123def456
   ```
5. Di dashboard → klik tombol **Verify**

**Expected Result:**
- Dashboard menampilkan instruksi lengkap dengan copy button untuk kedua nilai
- Setelah klik Verify → request succeed dengan response 200
- Dashboard redirect otomatis ke **Plugin Installation Guide**
- Status target berubah menjadi **Verified**

---

### Test Case 4.2 — DNS Method

**Pre-condition:** Target ditambah, belum diverifikasi

**Steps:**
1. Login ke OJSDef Dashboard → Target detail → **Verify Domain**
2. Pilih metode **DNS Verification**
3. Copy token, misal: `xyz789`
4. Login ke Cloudflare DNS console:
   - Add TXT record
   - Name: `_ojsdef-verify.ojs.contoh.ac.id`
   - Value: `ojsdef-verification=xyz789`
5. Tunggu propagasi DNS (5-30 menit):
   ```bash
   dig _ojsdef-verify.ojs.contoh.ac.id TXT +short
   # Harus muncul: "ojsdef-verification=xyz789"
   ```
6. Di dashboard → klik **Verify**

**Expected Result:**
- Sistem query DNS TXT record
- Record found dengan value cocok
- Verifikasi succeed → redirect ke Plugin Guide
- Status target → **Verified**

---

### Test Case 4.3 — Token Mismatch

**Pre-condition:** Verification dialog terbuka, token siap

**Steps:**
1. Buat file verification tapi dengan konten salah:
   ```bash
   docker exec ojs_app sh -c \
       "echo 'ojsdef-verification=WRONG_TOKEN' > \
        /var/www/html/.well-known/ojsdef-verify-abc123def456.txt"
   ```
2. Di dashboard → klik **Verify**

**Expected Result:**
- Request dikirim, file ditemukan tapi content tidak match
- Error message tampil: "Verification failed: token mismatch"
- Tetap di halaman verifikasi (tidak redirect)
- User bisa retry atau pilih metode lain

---

## Skenario 5: Plugin Status Display

### Test Case 5.1 — Status Terhubung

**Pre-condition:**
- Plugin terinstall dan terkonfigurasi dengan API Key valid
- Heartbeat sudah berjalan setidaknya 1 kali

**Steps:**
1. Login ke OJSDef Dashboard → Target detail
2. Scroll ke **Plugin Status** card

**Expected Result:**
- Badge hijau dengan label **"Terhubung"**
- Mode yang terdeteksi: **"Direct Mode"** atau **"Heartbeat Mode"**
- Timestamp **"Terakhir aktif: HH:MM (just now)"**
- Info section menampilkan: OJS version, PHP version, plugin version

---

### Test Case 5.2 — Status Tidak Terhubung

**Pre-condition:**
- Plugin terinstall tapi API Key salah atau Backend URL tidak valid
- Sudah menunggu > 10 menit

**Steps:**
1. Login OJS → Plugins → OJSDef Settings → ubah API Key menjadi salah
2. Tunggu 10 menit
3. Dashboard → Target detail

**Expected Result:**
- Badge kuning dengan label **"Tidak Terhubung"**
- Collapsible section **"Troubleshoot"** muncul dengan:
  - Checklist: "API Key valid?", "Backend URL reachable?", "Firewall blocked?"
  - Tombol **"Perbaharui Konfigurasi"** → direct ke plugin settings
- Last heartbeat timestamp masih menampilkan, tapi dengan label "(belum terhubung)"

---

### Test Case 5.3 — Status Belum Terhubung

**Pre-condition:**
- Target ditambah, plugin belum diinstall

**Steps:**
1. Dashboard → Target detail yang baru ditambah
2. Scroll ke **Plugin Status** section

**Expected Result:**
- Badge abu-abu dengan label **"Belum Terhubung"**
- Tombol prominent **"Panduan Instalasi Plugin"** → link ke panduan setup
- Info: "Plugin belum terdeteksi. Ikuti panduan instalasi di bawah."
- Section **"Instalasi Plugin"** auto-expand dengan instruksi step-by-step

---

## Skenario 6: Audit Log (saas_admin only)

### Test Case 6.1 — Login tercatat

**Pre-condition:**
- Audit logging feature implemented
- Login sebagai `admin_ojs`

**Steps:**
1. Logout dari OJS
2. Login sebagai `admin_ojs` dengan password
3. Login ke OJSDef Dashboard sebagai `saas_admin`
4. Navigasi ke **Settings** → **Audit Logs**

**Expected Result:**
- Daftar audit records tampil
- Record terbaru untuk `user.login`: 
  - Email: `admin_ojs@...`
  - Aksi: `user.login`
  - Timestamp: kurang lebih sama dengan login OJS
  - IP Address: source IP koneksi

---

### Test Case 6.2 — Filter aksi

**Pre-condition:**
- Minimal 5 audit records dengan berbagai tipe aksi

**Steps:**
1. Dashboard → **Settings** → **Audit Logs**
2. Klik filter dropdown **"Aksi"**
3. Pilih **"Target Created"** (atau aksi apapun yang ada)
4. Tekan Apply

**Expected Result:**
- Hanya tampilkan records dengan aksi terpilih
- Count indicator berubah
- Record list refresh tanpa full page reload

---

### Test Case 6.3 — RBAC guard

**Pre-condition:**
- User dengan role `admin_ojs` atau `viewer` sudah login

**Steps:**
1. Login sebagai `admin_ojs`
2. Akses langsung URL: `https://ojsdef.example.com/audit-logs`

**Expected Result:**
- Akses ditolak (HTTP 403 atau redirect ke dashboard)
- Pesan error: "Akses Ditolak. Hanya platform admin yang dapat melihat audit log."
- Redirect ke `/dashboard` setelah 3 detik

---

## Skenario 7: False Positive di Laporan

### Test Case 7.1 — Mark sebagai false positive

**Pre-condition:**
- Scan selesai dengan minimal 1 finding
- Finding ditampilkan di report detail

**Steps:**
1. Dashboard → **Reports** → pilih report terbaru
2. Di findings list, cari finding apapun
3. Klik toggle **"Tandai sebagai False Positive"** (checkbox/toggle)
4. Confirm di dialog yang muncul

**Expected Result:**
- Toggle berubah status (checked/unchecked sesuai aksi)
- Badge **"Positif Palsu"** muncul di finding
- Opacity finding berkurang (visual indication bahwa ini FP)
- Risk score di header report **recalculate otomatis**:
  - `findings_summary.total_risk` berkurang
  - `findings_summary.critical_count` berkurang jika finding itu critical
- Tombol **"Undo"** muncul jika perlu revert

---

### Test Case 7.2 — Export laporan dengan FP

**Pre-condition:**
- Report sudah ada dengan minimal 1 finding marked as FP

**Steps:**
1. Dashboard → **Reports** → pilih report
2. Klik **"Export"** → **"JSON"**
3. Download file JSON

**Expected Result:**
- JSON response include field `false_positive_label` untuk setiap finding yang di-mark FP
- Contoh struktur:
  ```json
  {
    "findings": [
      {
        "id": "...",
        "severity": "high",
        "false_positive_label": "False Positive",
        "description": "..."
      }
    ],
    "findings_summary": {
      "total": 5,
      "false_positives": 1,
      "actual_findings": 4,
      "total_risk": 34.5
    }
  }
  ```
- File dapat di-open di text editor tanpa corruption

---

## Cara Menjalankan Scan OJSDef

### Via Dashboard OJSDef

1. Login OJSDef Dashboard → pilih target `ojs.contoh.ac.id`
2. Klik **Run Scan** → pilih tipe:
   - **Internal Scan** — menjalankan semua modul plugin internal
   - **External Scan** — menjalankan bot Python dari luar
   - **Full Scan** — keduanya sekaligus

### Verifikasi koneksi sebelum scan

```bash
# Cek heartbeat plugin ke backend
docker exec ojs_app curl -sk -o /dev/null -w "%{http_code}" \
    https://backend.ojsdef.id/plugin/v1/heartbeat
# Output: 401 = server reachable (normal tanpa API key valid dari context curl ini)
```

### Trigger manual dari plugin settings

1. Login OJS → **Website Settings** → **Plugins** → **Generic Plugins**
2. OJSDef Security Scanner → **Settings**
3. **Test Connection** → harus **Connected (Direct Mode)** atau **Connected (Heartbeat Mode)**
4. **Run Scan Now** (jika tersedia di versi plugin)

---

## Checklist Verifikasi Hasil

### Internal Scanner

| ID | Skenario | Modul | Expected Flag | Level |
|----|----------|-------|---------------|-------|
| K-1 | Debug mode aktif | ConfigScanner | `display_errors: On` | Kritis |
| K-2 | Gambling injection | ContentInjection | `gambling_keywords_found: true` | Kritis |
| K-3 | eval(base64) | ContentInjection | `eval_base64_found: true` | Kritis |
| K-4 | Core file modified | FileIntegrity | `modified_files: ["index.php"]` | Kritis |
| B-1 | Force SSL off | ConfigScanner | `force_ssl: Off` | Berbahaya |
| B-2 | Hidden iframe | ContentInjection | `hidden_iframe_found: true` | Berbahaya |
| B-3 | Phishing TLD | ContentInjection | `phishing_tld_found: [...]` | Berbahaya |
| B-4 | Multiple superadmin | RbacAuditor | `superadmin_count: 3` | Berbahaya |
| B-6 | Plugin file modified | FileIntegrity | `modified_files: [...]` | Berbahaya |
| P-1 | JS redirect | ContentInjection | `js_redirect_found: true` | Perhatian |
| P-2 | SMTP no auth | ConfigScanner | `smtp_no_auth: true` | Perhatian |
| P-3 | Inactive admin | RbacAuditor | `inactive_high_privilege: [...]` | Perhatian |
| P-4 | Disabled plugins | PluginAuditor | `disabled_installed: N` | Perhatian |
| A-4 | Many plugins | PluginAuditor | `total_enabled: N` (info) | Aman |

### External Scanner

| ID | Skenario | Modul | Expected Flag | Level |
|----|----------|-------|---------------|-------|
| B-1 | HTTP no redirect | SSL Checker | `http_redirect: missing` | Berbahaya |
| B-5 | Missing headers | Header Analyzer | `hsts_missing`, `xframe_missing` | Berbahaya |
| P-5 | Admin endpoint open | Endpoint Discovery | `admin_accessible: true` | Perhatian |
| P-6 | Version in meta | Fingerprinting | `ojs_version: "3.4.0.10"` | Perhatian |
| A-1 | Generator meta tag | Fingerprinting | `generator_tag: found` | Aman |
| A-2 | Cookie no Secure | Cookie Analyzer | `session_cookie.secure: false` | Aman |
| A-3 | OAI endpoint | Endpoint Discovery | `oai_accessible: true` (info) | Aman |

---

## Urutan Pengujian yang Disarankan

```
1. Baseline Scan awal → catat semua temuan kondisi bersih
         ↓
2. Setup K-1 (debug mode) → scan → verifikasi Kritis → remediasi → scan ulang
         ↓
3. Setup K-2 + K-3 (content injection) → scan → verifikasi → remediasi
         ↓
4. Setup K-4 (file integrity core) → scan → verifikasi → remediasi
         ↓
5. Setup B-1 s/d B-6 → scan → verifikasi → remediasi masing-masing
         ↓
6. Setup P-1 s/d P-6 → scan → verifikasi → remediasi
         ↓
7. Setup A-1 s/d A-4 → scan → verifikasi (opsional remediasi)
         ↓
8. Baseline Scan akhir → konfirmasi kembali ke kondisi bersih
```

> **Tips:** Lakukan scan sebelum dan sesudah setiap skenario untuk membuktikan deteksi dan remediation bekerja. Screenshot hasil dashboard OJSDef untuk dokumentasi.

---

## Referensi

- [OJS Security Documentation](https://docs.pkp.sfu.ca/admin-guide/en/securing-your-system)
- [PKP Security Advisories](https://pkp.sfu.ca/security-advisories/)
- [OWASP Web Application Testing Guide](https://owasp.org/www-project-web-security-testing-guide/)
- [CVSS v3.1 Calculator](https://www.first.org/cvss/calculator/3.1)
- [OJSDef Plugin Design Spec](superpowers/specs/2026-05-30-ojsdef-plugin-design.md)
- [Panduan Setup OJS VPS](panduan-vps-ojs-docker-ssl-direct.md)

---

*Capstone Project Kelompok 3 — Topik G2, Fakultas Ilmu Komputer, Universitas Brawijaya, 2026.*
