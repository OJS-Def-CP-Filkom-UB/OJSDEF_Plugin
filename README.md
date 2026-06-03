# OJSDef Plugin

> **OJSDef Security Scanner Plugin** — Plugin Generic OJS yang menjembatani platform SaaS [OJSDef](https://ojsdef.zentaza.online) dengan instalasi Open Journal Systems (OJS) di server klien.

Plugin ini melakukan **audit keamanan dari dalam server OJS** (internal scan) dan mengirimkan hasilnya ke backend OJSDef melalui komunikasi HMAC-signed yang terenkripsi.

---

## Daftar Isi

- [Persyaratan](#persyaratan)
- [Instalasi](#instalasi)
- [Konfigurasi](#konfigurasi)
- [Cara Kerja](#cara-kerja)
- [Modul Scanner](#modul-scanner)
- [Protokol Komunikasi](#protokol-komunikasi)
- [Development](#development)
- [Lisensi](#lisensi)

---

## Persyaratan

| Komponen | Versi |
|----------|-------|
| OJS | 3.3.x, 3.4.x (utama), 3.5.x (best-effort) |
| PHP | 7.4 atau lebih baru |
| Akun OJSDef | Diperlukan untuk mendapatkan API Key dan Target ID |

---

## Instalasi

### 1. Download Plugin

Download file ZIP dari [GitHub Releases](https://github.com/OJS-Def-CP-Filkom-UB/OJSDEF_Plugin/releases/latest):

```
ojsdef-plugin-x.x.x.zip
```

### 2. Upload ke OJS

1. Login ke **OJS Admin Panel**
2. Buka **Website Settings → Plugins → Upload New Plugin**
3. Upload file `ojsdef-plugin-x.x.x.zip`
4. Klik **Save** — plugin akan muncul di daftar **Generic Plugins**

### 3. Aktifkan Plugin

Di halaman **Generic Plugins**, temukan **OJSDef Security Scanner** dan klik tombol **Enable**.

### 4. Konfigurasi Kredensial

Buka **Settings → OJSDef Security Scanner** dan isi:

| Field | Keterangan | Sumber |
|-------|-----------|--------|
| **Backend URL** | URL API OJSDef | Contoh: `https://api-ojsdef.zentaza.online` |
| **API Key** | Kunci autentikasi unik per target | Dashboard OJSDef → Target → Plugin Guide |
| **Target ID** | UUID identifikasi instalasi OJS ini | Dashboard OJSDef → Target → Plugin Guide |

Setelah disimpan, plugin akan mengirim heartbeat pertama dalam **≤ 5 menit**. Pantau status koneksi di **Dashboard OJSDef → Target Detail → Status Plugin**.

---

## Cara Kerja

Plugin berjalan sebagai **Generic Plugin OJS** dan beroperasi melalui dua mekanisme:

### Mode Direct (Rekomendasi)
Backend OJSDef dapat menjangkau server OJS secara langsung.

```
Backend OJSDef
    │
    ├─ POST /index.php/index/ojsdef/trigger  →  Plugin jalankan scan
    │
    └─ Plugin POST /plugin/v1/callback  →  Kirim hasil ke backend
```

### Mode Heartbeat (Fallback)
Server OJS berada di balik firewall dan tidak dapat dijangkau langsung.

```
Plugin (setiap 5 menit)
    │
    ├─ POST /plugin/v1/heartbeat  →  Backend (response: scan_requested=true)
    │
    └─ Plugin jalankan scan → POST /plugin/v1/callback
```

**Auto-detect:** Pada heartbeat pertama, plugin mengirim `reachability_challenge`. Backend mencoba probe ke endpoint plugin. Jika berhasil → `direct mode`; jika timeout → `heartbeat mode`.

---

## Modul Scanner

Plugin menjalankan **6 modul scanner** saat scan internal dieksekusi:

| Modul | Data yang Dikumpulkan |
|-------|-----------------------|
| **Fingerprint** | Versi OJS, versi PHP, OS server, daftar plugin aktif |
| **Config** | 11 config flag keamanan (debug mode, SSL, SMTP, DB) — tanpa nilai password |
| **Plugin Auditor** | Jumlah plugin: total, aktif, terinstall tapi nonaktif |
| **RBAC Auditor** | Jumlah superadmin, akun hak tinggi yang tidak aktif |
| **File Integrity** | Perbandingan SHA-256 checksum file OJS vs checksum resmi dari backend |
| **Content Injection Detector** | Deteksi 5 pola injeksi: gambling, hidden iframe, JS redirect, `eval(base64)`, phishing domain |

> **Privasi:** Plugin hanya mengumpulkan data teknis konfigurasi dan keamanan. Tidak ada konten artikel, data pengguna (nama/email), atau password yang dikirimkan.

---

## Protokol Komunikasi

Semua komunikasi antara plugin dan backend diautentikasi dengan **HMAC-SHA256** dua arah.

### Format Signing

```php
// PHP (HmacSigner.php)
$message = $timestamp . '.' . $body;
$signature = 'sha256=' . hash_hmac('sha256', $message, $apiKey);
```

### Headers Wajib

```
Content-Type: application/json
X-OJSDef-Signature: sha256=<64-char-hex>
X-OJSDef-Timestamp: <unix_timestamp>
X-OJSDef-Target-ID: <target_uuid>
```

### Anti-Replay

Request ditolak jika selisih `|now - timestamp| > 300` detik di kedua sisi.

### Endpoint Plugin (dipanggil backend)

| Method | URL | Fungsi |
|--------|-----|--------|
| `POST` | `/index.php/index/ojsdef/probe` | Test reachability, echo challenge |
| `POST` | `/index.php/index/ojsdef/trigger` | Trigger internal scan (respond 202 async) |

### Endpoint Backend (dipanggil plugin)

| Method | URL | Fungsi |
|--------|-----|--------|
| `POST` | `<backend_url>/plugin/v1/heartbeat` | Heartbeat tiap 5 menit |
| `POST` | `<backend_url>/plugin/v1/callback` | Kirim hasil scan |
| `GET`  | `<backend_url>/plugin/v1/checksums?version=<ver>` | Ambil checksum resmi per versi OJS |

---

## Development

### Struktur Direktori

```
OJSDEF-Plugin/
├── ojsdef.php                          — Entry point OJS
├── version.xml                         — Metadata versi plugin
├── composer.json                       — Dev dependencies (PHPUnit)
├── phpunit.xml                         — Konfigurasi PHPUnit
├── ojsdef/
│   ├── OjsdefPlugin.php                — Main plugin class (GenericPlugin)
│   ├── OjsdefHandler.php               — Handler HTTP /probe dan /trigger
│   ├── version.xml                     — Versi untuk OJS 3.4.x lazy-load
│   ├── locale/en_US/locale.po          — Lokalisasi string
│   ├── templates/settingsForm.tpl      — Template form settings
│   └── classes/
│       ├── HmacSigner.php              — Sign & verify HMAC-SHA256
│       ├── ApiClient.php               — HTTP client (heartbeat, callback, checksums)
│       ├── OjsdefSettingsForm.php      — Form settings OJS
│       ├── ScanOrchestrator.php        — Orkestrasi semua scanner
│       └── scanners/
│           ├── FingerprintScanner.php
│           ├── ConfigScanner.php
│           ├── PluginAuditor.php
│           ├── RbacAuditor.php
│           ├── FileIntegrityChecker.php
│           └── ContentInjectionDetector.php
└── tests/
    ├── bootstrap.php
    ├── HmacSignerTest.php              — 8 test cases
    ├── ApiClientSigningTest.php        — 2 test cases
    └── ContentInjectionDetectorTest.php — 9 test cases
```

### Setup Development

```bash
# Install PHPUnit (dev only — tidak masuk distribusi ZIP)
composer install --ignore-platform-req=ext-mbstring --ignore-platform-req=ext-curl

# Jalankan semua unit test
php vendor/bin/phpunit
# Expected: 19/19 PASSED
```

### Build Distribusi ZIP

> **Penting:** Gunakan .NET ZipFile API (bukan `Compress-Archive`) agar path menggunakan forward slash yang dikenali PHP/Linux.

```powershell
# Jalankan dari direktori OJSDEF-Plugin/
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$src = "ojsdef"
$out = "ojsdef-plugin-1.0.1.zip"
if (Test-Path $out) { Remove-Item $out -Force }

$zip = [System.IO.Compression.ZipFile]::Open($out, 'Create')
Get-ChildItem -Path $src -Recurse -File | ForEach-Object {
    $rel = $_.FullName.Substring((Resolve-Path $src).Path.Length).TrimStart('\').Replace('\','/')
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, "ojsdef/$rel")
}
$zip.Dispose()

Write-Host "Build selesai: $out"
```

### Kompatibilitas PHP 7.4

Plugin ditulis untuk PHP 7.4+ — hindari fitur PHP 8.0+:

| Jangan Gunakan | Alternatif |
|---------------|------------|
| `: mixed` return type | Hapus type hint, gunakan `@return` docblock |
| Constructor property promotion | Deklarasi property + assignment di `__construct` |
| `str_starts_with()` | `strpos($s, $prefix) === 0` |
| `str_contains()` | `strpos($s, $needle) !== false` |
| Named arguments `func(key: val)` | Positional arguments |

---

## Dokumentasi Tambahan

- [`docs/panduan-vps-ojs-docker-ssl-direct.md`](docs/panduan-vps-ojs-docker-ssl-direct.md) — Setup OJS testing di VPS dengan Docker + SSL
- [`docs/panduan-test-skenario-ojsdef.md`](docs/panduan-test-skenario-ojsdef.md) — Skenario pengujian integrasi plugin dengan backend OJSDef

---

## Lisensi

Plugin ini dikembangkan sebagai bagian dari proyek Capstone **OJSDef** oleh tim mahasiswa Filkom Universitas Brawijaya.

---

<div align="center">
  <sub>OJSDef Plugin v1.0.1 — Bagian dari platform <strong>OJSDef Security Scanner</strong></sub>
</div>
