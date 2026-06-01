# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**OJSDef Plugin** — OJS Generic Plugin (PHP) yang di-install di server OJS klien sebagai jembatan internal scanner ke platform OJSDef SaaS. Plugin melakukan audit keamanan dari dalam server OJS dan mengirimkan hasilnya ke backend melalui HMAC-signed callback.

- **Versi**: 1.0.1
- **Kompatibilitas OJS**: 3.3.x, 3.4.x (primary), 3.5.x (best-effort)
- **PHP**: 7.4+ (PHP 8.1+ untuk development/testing)
- **Distribusi**: `ojsdef-plugin-1.0.1.zip`

## Commands

```bash
# Install dependensi PHPUnit (development only, tidak masuk ZIP distribusi)
composer install --ignore-platform-req=ext-mbstring --ignore-platform-req=ext-curl

# Jalankan semua unit tests
php vendor/bin/phpunit

# Build distribusi ZIP (tanpa vendor, tests, docs) — PowerShell:
Compress-Archive -Path @("ojsdef","ojsdef.php","version.xml") -DestinationPath "ojsdef-plugin-1.0.1.zip" -Force
```

## Deployment ke OJS Klien

### Build distribusi ZIP

```bash
# PowerShell
Compress-Archive -Path @("ojsdef","ojsdef.php","version.xml") -DestinationPath "ojsdef-plugin-1.0.1.zip" -Force
```

### Instalasi di OJS

1. Login ke OJS admin panel → **Website Settings** → **Plugins** → **Upload New Plugin**
2. Upload file `ojsdef-plugin-1.0.1.zip`
3. Aktifkan plugin di daftar **Generic Plugins**
4. Buka **Settings** → **OJSDef Security Scanner**:
   - **Backend URL**: URL backend OJSDef (contoh: `https://api.ojsdef.example.com`)
   - **API Key**: Salin dari dashboard OJSDef → Target → Plugin Guide
   - **Target ID**: UUID target dari dashboard OJSDef

### Verifikasi Instalasi

Setelah mengisi settings, plugin akan otomatis mengirim heartbeat dalam ≤5 menit.
Cek status di dashboard OJSDef → Target detail → **Status Plugin**.

### OJS Testing di VPS

Gunakan `docs/panduan-vps-ojs-docker-ssl-direct.md` untuk setup OJS testing di VPS dengan Docker.

## Directory Structure

```
OJSDEF-Plugin/
├── ojsdef.php                     — Entry point yang di-load OJS
├── version.xml                    — Metadata versi plugin untuk OJS plugin manager
├── composer.json                  — PHPUnit 10.5 sebagai dev dependency
├── phpunit.xml                    — Konfigurasi PHPUnit
├── ojsdef/
│   ├── OjsdefPlugin.php           — Main plugin class (extends GenericPlugin)
│   ├── OjsdefHandler.php          — HTTP handler untuk /probe dan /trigger endpoints
│   ├── version.xml                — Versi untuk OJS
│   ├── locale/en_US/locale.po     — String lokalisasi plugin
│   ├── templates/settingsForm.tpl — Smarty template form settings + status koneksi
│   └── classes/
│       ├── HmacSigner.php         — Sign dan verify HMAC-SHA256
│       ├── ApiClient.php          — HTTP client (heartbeat, callback, checksums GET)
│       ├── OjsdefSettingsForm.php — Form settings OJS (backend_url, api_key, target_id)
│       ├── ScanOrchestrator.php   — Orkestrasi semua scanner, isolasi error per modul
│       └── scanners/
│           ├── FingerprintScanner.php       — OJS version, PHP version, server OS, plugin list
│           ├── ConfigScanner.php            — 11 config flags (debug, ssl, smtp, db) tanpa password
│           ├── PluginAuditor.php            — Total/enabled/disabled plugins
│           ├── RbacAuditor.php              — Superadmin count, inactive high-privilege accounts
│           ├── FileIntegrityChecker.php     — SHA-256 checksum vs backend checksums (cache 7 hari)
│           └── ContentInjectionDetector.php — 5 regex patterns: gambling, iframe, redirect, eval, phishing
├── tests/
│   ├── bootstrap.php              — PHPUnit bootstrap: SPL autoloader + OJS stub classes
│   ├── HmacSignerTest.php         — 8 test cases HmacSigner
│   ├── ApiClientSigningTest.php   — 2 test cases header signing
│   └── ContentInjectionDetectorTest.php — 9 test cases regex detection
└── docs/
    ├── panduan-vps-ojs-docker.md  — Panduan setup OJS percobaan di VPS dengan Docker
    └── superpowers/
        ├── specs/2026-05-30-ojsdef-plugin-design.md    — Desain arsitektur lengkap
        └── plans/2026-05-30-ojsdef-plugin-implementation.md — Implementation plan 13 tasks
```

## HMAC Protocol

Semua komunikasi plugin ↔ backend diautentikasi HMAC-SHA256 dua arah.

### Signing (PHP `HmacSigner::sign()`)

```php
$message = $timestamp . '.' . $body;   // contoh: "1748563200.{...json...}"
return 'sha256=' . hash_hmac('sha256', $message, $this->apiKey);
```

### Headers yang dikirim (plugin → backend)

```
Content-Type: application/json
X-OJSDef-Signature: sha256=<64-char-hex>
X-OJSDef-Timestamp: <unix_timestamp>
X-OJSDef-Target-ID: <target_uuid>
```

### GET request (checksums endpoint)

Body dianggap string kosong `""` — pesan yang di-sign = `"<timestamp>."` (timestamp + titik saja).

### Anti-replay

Tolak jika `|now - timestamp| > 300` detik di kedua sisi.

## Connection Mode (Hybrid A+C)

| Mode | Kondisi | Cara kerja |
|------|---------|-----------|
| `direct` | Backend bisa reach plugin | Backend POST ke `/ojsdef/trigger` |
| `heartbeat` | Plugin di balik firewall | Backend set `scan_requested=true` di response heartbeat |
| `unknown` | Belum terdeteksi | Plugin kirim `reachability_challenge`, backend probe `/ojsdef/probe` |

**Auto-detect flow:**
1. Heartbeat pertama → plugin kirim `reachability_challenge` + `probe_endpoint`
2. Backend POST ke `probe_endpoint` (background task, timeout 10s)
3. Plugin echo challenge → backend set `connection_mode = direct`
4. Probe timeout/error → `connection_mode = heartbeat`

## Backend Endpoints yang Dipanggil Plugin

| Method | URL | Fungsi |
|--------|-----|--------|
| `POST` | `<backend_url>/plugin/v1/heartbeat` | Heartbeat tiap 5 menit |
| `POST` | `<backend_url>/plugin/v1/callback` | Kirim hasil scan (expect HTTP **202**) |
| `GET`  | `<backend_url>/plugin/v1/checksums?version=<ver>` | Fetch checksums (cache 7 hari) |

## Plugin HTTP Endpoints (dipanggil backend)

| Method | URL OJS | Fungsi |
|--------|---------|--------|
| `POST` | `/index.php/index/ojsdef/probe` | Test reachability + echo challenge |
| `POST` | `/index.php/index/ojsdef/trigger` | Trigger internal scan, respond 202 lalu jalankan scan |

## Scanner Modules

| Modul (`scan_modules` key) | Class | Data yang dikumpulkan |
|--------------------------|-------|----------------------|
| `fingerprint` | `FingerprintScanner` | ojs_version, php_version, server_os, plugins list |
| `config` | `ConfigScanner` | 11 config flags — tanpa nilai password/secret |
| `plugins` | `PluginAuditor` | total_installed, total_enabled, disabled_but_installed |
| `rbac` | `RbacAuditor` | superadmin_count, inactive high-privilege (user_id+last_login saja, bukan PII) |
| `file_integrity` | `FileIntegrityChecker` | modified/missing files vs official SHA-256 checksums |
| `content` | `ContentInjectionDetector` | Gambling, hidden iframe, JS redirect, eval(base64), phishing TLD |

## PHP 7.4 Compatibility

Plugin ditulis untuk PHP 7.4+ — hindari fitur PHP 8.0+:

| Jangan pakai | Alternatif |
|-------------|------------|
| `: mixed` return type | Hapus type hint, gunakan `@return` docblock |
| Constructor property promotion | Deklarasi property + assignment manual di `__construct` |
| `str_starts_with()` | `strpos($s, $prefix) === 0` |
| `str_contains()` | `strpos($s, $needle) !== false` |
| Named arguments `func(key: val)` | Positional arguments |

## Unit Tests

```
HmacSignerTest          (8 cases) — sign, determinism, verify, expired/future ts, wrong prefix
ApiClientSigningTest    (2 cases) — required headers, sha256 prefix
ContentInjectionDetectorTest (9 cases) — 7 detects + 2 clean texts
```

Jalankan: `php vendor/bin/phpunit` → **19/19 OK**.

## OJS Plugin Settings (disimpan di tabel `plugin_settings`)

| Setting Key | Keterangan |
|------------|-----------|
| `backend_url` | URL backend OJSDef (diisi admin) |
| `api_key` | API key dari dashboard OJSDef |
| `target_id` | UUID target dari dashboard OJSDef |
| `connection_mode` | `direct` / `heartbeat` / `unknown` |
| `last_heartbeat_at` | Unix timestamp heartbeat terakhir (throttle 5 menit) |
| `reachability_challenge` | Challenge sementara saat mode=unknown |
| `pending_callback` | JSON hasil scan yang gagal dikirim (retry di heartbeat berikutnya) |
| `checksums_<ver>` | Cache JSON checksums dari backend per versi OJS |
| `checksums_<ver>_at` | Unix timestamp cache disimpan (TTL 7 hari = 604800 detik) |
