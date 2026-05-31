# OJSDef Plugin — Design Specification
**Tanggal:** 2026-05-30
**Versi:** 1.0
**Status:** Draft for Review
**Referensi:** PRD OJSDef v1.2, SRS OJSDef v1.2, Backend MVP Design 2026-05-23

---

## 1. Overview

OJSDef Plugin adalah komponen PHP yang diinstall di server OJS target (Generic Plugin). Plugin menjadi jembatan antara instalasi OJS klien dengan OJSDef backend platform, memungkinkan **internal security audit** dari dalam sistem OJS.

Plugin bertanggung jawab untuk:
1. Mengirim **heartbeat** setiap 5 menit ke OJSDef backend (status koneksi)
2. Menerima **trigger scan** dari backend (Direct Mode) atau via respons heartbeat (Heartbeat Mode)
3. Menjalankan **6 scanner module** yang mengumpulkan data audit internal OJS
4. Mengirim hasil audit via **HMAC-SHA256 signed callback** ke backend

Plugin **tidak** melakukan: analisis CVSS, PDF generation, CVE matching, atau notifikasi — semua itu dilakukan oleh backend.

---

## 2. Kompatibilitas

| OJS Version | PHP Version | Status |
|-------------|-------------|--------|
| 3.3.x | 7.4, 8.0, 8.1 | Primary supported |
| 3.4.x | 8.0, 8.1, 8.2 | Primary supported |
| 3.5.x | 8.1, 8.2, 8.3 | Best-effort (perlu test matrix) |

**Keputusan implementasi:**
- Tidak menggunakan fitur PHP 8.1+ only — menjaga kompatibilitas ke 7.4
- HTTP client: cURL native PHP (tidak butuh Composer/Guzzle)
- Autoloading: manual `require_once` (tidak asumsi Composer tersedia di semua instalasi OJS)

---

## 3. Struktur Direktori & File

```
OJSDEF-Plugin/
└── ojsdef/
    ├── OjsdefPlugin.php                     <- Main plugin class (GenericPlugin)
    ├── OjsdefHandler.php                    <- HTTP handler: /ojsdef/trigger, /ojsdef/probe
    ├── version.xml                          <- Metadata plugin OJS
    ├── locale/
    │   └── en_US/
    │       └── locale.po                    <- Locale strings
    ├── classes/
    │   ├── ScanOrchestrator.php             <- Koordinasi semua scanner, build payload
    │   ├── HmacSigner.php                   <- Sign & verify HMAC-SHA256
    │   ├── ApiClient.php                    <- HTTP client (cURL) ke OJSDef backend
    │   └── scanners/
    │       ├── FingerprintScanner.php       <- OJS version, plugins list
    │       ├── ConfigScanner.php            <- config.inc.php audit
    │       ├── PluginAuditor.php            <- Plugin versions + enabled status
    │       ├── RbacAuditor.php              <- Users + roles audit
    │       ├── FileIntegrityChecker.php     <- SHA-256 hash comparison
    │       └── ContentInjectionDetector.php <- DB regex scan (judi/malware)
    └── templates/
        └── settingsForm.tpl                 <- UI settings di OJS Admin Panel
```

### version.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE version SYSTEM "../../../lib/pkp/dtd/pluginVersion.dtd">
<version>
    <application>ojsdef</application>
    <type>plugins.generic</type>
    <release>1.0.0.0</release>
    <date>2026-05-30</date>
    <lazy-load>1</lazy-load>
    <installedLocales>
        <locale name="en_US"/>
    </installedLocales>
</version>
```

---

## 4. OJS Integration

### 4.1 Plugin Class (OjsdefPlugin.php)

Plugin dikonfigurasi sebagai **site-wide plugin** (`isSitePlugin() = true`) — satu plugin per OJS instance, berlaku untuk semua jurnal dalam satu instalasi.

```php
class OjsdefPlugin extends GenericPlugin {

    public function register($category, $path, $mainContextId = NULL) {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled()) {
            // Routing: /index.php/index/ojsdef/* -> OjsdefHandler
            Hook::add('LoadHandler', [$this, 'callbackLoadHandler']);
            // Heartbeat: kirim setiap 5 menit pada request OJS apapun
            Hook::add('Core::loadBaseData', [$this, 'maybeSendHeartbeat']);
        }
        return $success;
    }

    public function callbackLoadHandler($hookName, $args) {
        if ($args[0] === 'ojsdef') {
            define('HANDLER_CLASS', 'OjsdefHandler');
            define('HANDLER_FILE', $this->getPluginPath() . '/OjsdefHandler.php');
            return true;
        }
        return false;
    }

    public function maybeSendHeartbeat($hookName, $args) {
        $lastHeartbeat = $this->getSetting(0, 'last_heartbeat_at');
        if (!$lastHeartbeat || (time() - (int)$lastHeartbeat) >= 300) {
            $this->updateSetting(0, 'last_heartbeat_at', time());
            ignore_user_abort(true);
            (new ApiClient($this))->sendHeartbeat();
        }
    }

    public function isSitePlugin()   { return true; }
    public function getDisplayName() { return __('plugins.generic.ojsdef.displayName'); }
    public function getDescription() { return __('plugins.generic.ojsdef.description'); }
    public function manage($args, $request) { /* render settingsForm */ }
    public function getActions($request, $actionArgs) { /* Settings + Test Connection */ }
}
```

### 4.2 Hook Strategy

| Hook | Tujuan | Dipicu Saat |
|------|--------|-------------|
| `LoadHandler` | Route URL `/ojsdef/*` ke OjsdefHandler | Setiap HTTP request OJS |
| `Core::loadBaseData` | Cek & kirim heartbeat jika sudah >= 5 menit | Setiap HTTP request OJS |

Plugin tidak memodifikasi behavior OJS — hanya menambah endpoint baru dan background heartbeat task.

### 4.3 HTTP Handler (OjsdefHandler.php)

URL pattern (site-wide plugin):
```
https://jurnal.target.ac.id/index.php/index/ojsdef/{operation}
```

| Operation | Method | Dipanggil Oleh | Fungsi |
|-----------|--------|----------------|--------|
| `trigger` | POST | OJSDef Backend | Memulai internal scan |
| `probe` | POST | OJSDef Backend | Test reachability (satu kali setelah first heartbeat) |

### 4.4 Plugin Settings (disimpan di OJS plugin_settings table)

| Key | Tipe | Keterangan |
|-----|------|------------|
| `backend_url` | string | URL OJSDef backend, default: `https://api.ojsdef.id` |
| `api_key` | string | API key dari OJSDef dashboard |
| `target_id` | string (UUID) | Target ID dari OJSDef dashboard |
| `last_heartbeat_at` | int (unix timestamp) | Waktu heartbeat terakhir berhasil |
| `connection_mode` | `unknown` / `direct` / `heartbeat` | Diupdate setelah probe selesai |
| `checksums_{version}` | JSON string | Cache checksums resmi OJS (TTL 7 hari) |
| `checksums_{version}_at` | int (unix timestamp) | Waktu checksums terakhir di-fetch |
| `pending_callback` | JSON string | Payload callback yang gagal; retry di heartbeat berikutnya |

---

## 5. Communication Protocol

### 5.1 HMAC-SHA256 Signing (HmacSigner.php)

Semua komunikasi dua arah (plugin->backend dan backend->plugin) ditandatangani dengan `api_key` sebagai shared secret.

**Formula:**
```
message   = unix_timestamp + "." + json_body
signature = HMAC-SHA256(api_key, message)
header    = "sha256=" + hex(signature)
```

**Anti-replay:** timestamp di header harus dalam rentang +/- 5 menit dari waktu server penerima.

```php
class HmacSigner {
    public function __construct(private string $apiKey) {}

    public function sign(string $body, int $timestamp): string {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->apiKey);
    }

    public function verify(string $signature, string $body, int $timestamp): bool {
        if (abs(time() - $timestamp) > 300) return false;
        return hash_equals($this->sign($body, $timestamp), $signature);
    }
}
```

### 5.2 Protokol 1 — Heartbeat (Plugin -> Backend)

Dikirim setiap 5 menit via `Core::loadBaseData` hook. Non-blocking menggunakan `ignore_user_abort(true)`.

```
POST {backend_url}/plugin/v1/heartbeat
Headers:
  X-OJSDef-Signature : sha256=<hmac_hex>
  X-OJSDef-Timestamp : 1748563200
  X-OJSDef-Target-ID : <target_uuid>
  Content-Type       : application/json
```

**Request body (first heartbeat — sertakan reachability_challenge):**
```json
{
  "target_id": "uuid-target",
  "plugin_version": "1.0.0",
  "ojs_version": "3.4.0.3",
  "php_version": "8.1.27",
  "trigger_endpoint": "https://jurnal.ac.id/index.php/index/ojsdef/trigger",
  "probe_endpoint":   "https://jurnal.ac.id/index.php/index/ojsdef/probe",
  "connection_mode": "unknown",
  "reachability_challenge": "rand-abc123"
}
```

**Response 200 — normal:**
```json
{ "status": "ok", "scan_requested": false, "job_id": null }
```

**Response 200 — Heartbeat Mode (backend minta plugin mulai scan):**
```json
{
  "status": "ok",
  "scan_requested": true,
  "job_id": "uuid-job",
  "scan_modules": ["fingerprint","config","plugins","rbac","file_integrity","content"]
}
```

Plugin langsung jalankan scan jika `scan_requested: true`.

### 5.3 Protokol 2 — Probe (Backend -> Plugin, satu kali)

Backend coba hit `probe_endpoint` segera setelah menerima first heartbeat untuk mendeteksi Direct Mode vs Heartbeat Mode.

```
POST https://jurnal.ac.id/index.php/index/ojsdef/probe
Headers: X-OJSDef-Signature, X-OJSDef-Timestamp

Body:  { "challenge": "rand-abc123" }
Response 200: { "challenge": "rand-abc123", "plugin_version": "1.0.0" }
```

| Hasil Probe | Backend Action | Ditampilkan di Dashboard |
|-------------|---------------|--------------------------|
| Response 200 dalam 10 detik | `trigger_mode = "direct"` | Direct Mode |
| Timeout / connection error | `trigger_mode = "heartbeat"` | Heartbeat Mode |

### 5.4 Protokol 3 — Trigger (Backend -> Plugin, Direct Mode saja)

```
POST https://jurnal.ac.id/index.php/index/ojsdef/trigger
Headers: X-OJSDef-Signature, X-OJSDef-Timestamp

Body:
{
  "job_id": "uuid-job",
  "scan_modules": ["fingerprint","config","plugins","rbac","file_integrity","content"]
}

Response 202 (langsung, sebelum scan selesai):
{ "status": "accepted", "job_id": "uuid-job" }
```

Plugin menggunakan teknik **respond-then-continue** agar backend mendapat 202 segera:
```php
http_response_code(202);
echo json_encode(['status' => 'accepted', 'job_id' => $jobId]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();  // PHP-FPM: tutup koneksi, PHP lanjut jalan
} else {
    ignore_user_abort(true);
    ob_flush(); flush();
}
// Scan dijalankan setelah koneksi ke backend tertutup
$payload = (new ScanOrchestrator($this->plugin))->runAll($jobId, $modules);
(new ApiClient($this->plugin))->sendCallback($jobId, $payload);
```

### 5.5 Protokol 4 — Callback (Plugin -> Backend)

Dikirim setelah semua scanner selesai (atau timeout 4 menit dengan partial results).

```
POST {backend_url}/plugin/v1/callback
Headers: X-OJSDef-Signature, X-OJSDef-Timestamp, X-OJSDef-Target-ID

Body:
{
  "event":            "audit_data",
  "target_id":        "uuid-target",
  "job_id":           "uuid-job",
  "plugin_version":   "1.0.0",
  "timestamp":        "2026-05-30T10:00:00Z",
  "duration_seconds": 47,
  "status":           "completed",
  "data":             { /* output ScanOrchestrator — lihat Section 6.8 */ }
}

Response 202: { "status": "received", "queued": true }
```

### 5.6 Error Handling & Retry

| Skenario | Penanganan |
|----------|-----------|
| HMAC invalid pada trigger/probe | Return 401, scan tidak dijalankan |
| Heartbeat gagal (network error) | Retry 3x dengan backoff 5s/10s/20s, lalu skip |
| Callback gagal (backend down) | Retry 3x, simpan payload di `pending_callback`; kirim ulang di heartbeat berikutnya |
| Scan berjalan lebih dari 4 menit | POST partial results dengan `"status": "partial"`, sertakan error per modul |
| PHP version < 7.4 | Log warning di OJS, heartbeat tetap jalan tapi scan di-skip |

---

## 6. Scanner Modules (MVP — 6 Modul)

### 6.1 ScanOrchestrator

```php
class ScanOrchestrator {
    private array $scannerMap = [
        'fingerprint'    => FingerprintScanner::class,
        'config'         => ConfigScanner::class,
        'plugins'        => PluginAuditor::class,
        'rbac'           => RbacAuditor::class,
        'file_integrity' => FileIntegrityChecker::class,
        'content'        => ContentInjectionDetector::class,
    ];

    public function runAll(string $jobId, array $modules): array {
        $results = []; $errors = [];
        foreach ($modules as $module) {
            if (!isset($this->scannerMap[$module])) continue;
            try {
                $results[$module] = (new $this->scannerMap[$module]($this->plugin))->scan();
            } catch (Throwable $e) {
                $errors[$module] = $e->getMessage();
            }
        }
        return [
            'modules_completed' => array_keys($results),
            'modules_failed'    => $errors,
            'results'           => $results,
        ];
    }
}
```

Modul yang gagal tidak menghentikan modul lain — scan tetap lanjut dan menghasilkan partial results.

### 6.2 FingerprintScanner

**Sumber data:** OJS `VersionDAO`, `PluginRegistry`

**Output:**
```json
{
  "ojs_version":  "3.4.0.3",
  "php_version":  "8.1.27",
  "server_os":    "Linux 5.15.0",
  "plugin_count": 12,
  "plugins": [
    { "name": "tinymce", "category": "generic", "version": "3.3.0.1", "enabled": true }
  ]
}
```

### 6.3 ConfigScanner

**Sumber data:** OJS `Config` class (abstraksi atas config.inc.php)

**Output:**
```json
{
  "debug_mode":             false,
  "show_errors":            false,
  "api_key_secret_length":  16,
  "force_ssl":              true,
  "allowed_hosts_set":      false,
  "installed":              true,
  "smtp_auth_enabled":      true,
  "smtp_password_set":      true,
  "db_driver":              "mysql",
  "db_host":                "localhost",
  "db_password_empty":      false
}
```

Tidak mengirim nilai password/secret — hanya flag boolean (ada/kosong).

### 6.4 PluginAuditor

**Sumber data:** `PluginRegistry::loadAllPlugins()`

**Output:**
```json
{
  "total_installed": 12,
  "total_enabled":   9,
  "disabled_but_installed": [
    { "name": "tinymce", "category": "generic", "version": "3.3.0.1", "enabled": false }
  ],
  "plugins": [ ]
}
```

### 6.5 RbacAuditor

**Sumber data:** `UserGroupDAO`, `UserDAO`

Tidak mengirim PII sensitif — hanya user_id dan metadata role (bukan email atau nama).

**Output:**
```json
{
  "total_users":              48,
  "superadmin_count":         2,
  "multiple_superadmin":      true,
  "inactive_high_priv_count": 1,
  "inactive_high_priv_users": [
    { "user_id": 5, "last_login": "2024-01-10", "role_count": 2 }
  ]
}
```

Threshold "inactive": tidak login lebih dari 1 tahun dengan role manager/admin.

### 6.6 FileIntegrityChecker

**Pendekatan:** Fetch checksums resmi dari OJSDef backend (`GET /plugin/v1/checksums?version=3.4.0.3`), compare lokal dengan `hash_file('sha256', ...)`, kirim hanya discrepancy. Checksums di-cache 7 hari di `plugin_settings`.

**Direktori yang dicek:** `classes/`, `controllers/`, `pages/`, `lib/pkp/classes/`, `lib/pkp/lib/`
**Direktori yang di-skip:** `cache/`, `files/`, `public/` (konten dinamis, bukan core)

**Output:**
```json
{
  "status":        "completed",
  "total_checked": 1247,
  "modified":      2,
  "missing":       0,
  "findings": [
    {
      "path":       "lib/pkp/classes/security/authorization/PolicySet.php",
      "status":     "modified",
      "local_hash": "abc123def456..."
    }
  ]
}
```

Jika checksums tidak tersedia: `{ "status": "skipped", "reason": "checksums_unavailable" }`

### 6.7 ContentInjectionDetector

**Sumber data:** Query OJS DB — tabel `submissions`/`articles`, field: `abstract`, `title`, `coverage`

**Regex patterns yang dideteksi:**

| Pattern Key | Target |
|-------------|--------|
| `gambling_keyword` | `bet365`, `sbobet`, `togel`, `slot gacor`, `judi online`, `pragmatic`, `maxwin`, dll |
| `hidden_iframe` | `<iframe>` dengan `display:none` / `visibility:hidden` / `width=0` |
| `js_redirect` | `window.location=`, `document.location=` |
| `base64_eval` | `eval(base64_decode(...))`, `eval(unescape(...))` |
| `phishing_tld` | Link ke `.xyz`, `.top`, `.click`, `.loan`, `.gq`, `.ml`, `.cf`, `.ga` |

**Output:**
```json
{
  "total_scanned":  156,
  "affected_count": 3,
  "detections": [
    {
      "submission_id": 42,
      "field":         "abstract",
      "pattern":       "gambling_keyword",
      "excerpt":       "slot gacor maxwin..."
    }
  ]
}
```

Mengirim maksimal 100 karakter excerpt sebagai bukti — tidak mengirim konten artikel lengkap.

### 6.8 Struktur Lengkap Callback Payload (field `data`)

```json
{
  "modules_completed": ["fingerprint","config","plugins","rbac","file_integrity","content"],
  "modules_failed":    {},
  "results": {
    "fingerprint":    { },
    "config":         { },
    "plugins":        { },
    "rbac":           { },
    "file_integrity": { },
    "content":        { }
  }
}
```

---

## 7. Settings UI & Admin Panel

### 7.1 Plugin Actions di OJS Plugin Gallery

Di **OJS -> Website Settings -> Plugins -> Generic Plugins -> OJSDef Security Scanner**:

| Action | Fungsi |
|--------|--------|
| **Settings** | Buka modal form untuk input api_key, backend_url, target_id |
| **Test Connection** | Kirim heartbeat segera + tampilkan status koneksi terbaru |

### 7.2 Form Fields

| Field | Label | Required | Keterangan |
|-------|-------|----------|------------|
| `backend_url` | URL Backend OJSDef | Ya | Default: `https://api.ojsdef.id` |
| `api_key` | API Key | Ya | Password field dengan show/hide toggle |
| `target_id` | Target ID | Ya | UUID dari OJSDef dashboard |

### 7.3 Status Display (read-only di bawah form)

```
STATE 1 — Belum dikonfigurasi / Disconnected
  Status: Disconnected
  Mode:   —
  Last Heartbeat: —

STATE 2 — Connected, Direct Mode
  Status: Connected
  Mode:   Direct — Scan mulai < 10 detik setelah "Run Scan"
  Last Heartbeat: 2026-05-30 10:05:23

STATE 3 — Connected, Heartbeat Mode
  Status: Connected
  Mode:   Heartbeat — Scan mulai dalam maksimal 5 menit
  Last Heartbeat: 2026-05-30 10:05:23

  [BANNER] Plugin berada di balik firewall. Backend tidak dapat
  menjangkau plugin secara langsung. Scan tetap berjalan via heartbeat.
  [Pelajari cara membuka akses langsung]
```

### 7.4 Alur Onboarding Admin OJS

```
1. OJSDef Dashboard -> Add Target -> input URL OJS
2. OJSDef Dashboard menampilkan:
     - Link download plugin ZIP
     - API Key (unik per target)
     - Target ID (UUID)
     - Backend URL
3. Admin OJS -> OJS Admin Panel -> Upload plugin ZIP -> Enable plugin
4. Admin OJS -> Plugin Settings -> isi 3 field -> Save
5. Plugin langsung kirim first heartbeat ke backend
6. Backend menerima heartbeat -> coba probe plugin
7. OJSDef Dashboard -> status berubah "Connected"
8. Admin siap klik "Run Scan" di dashboard
```

---

## 8. Full Data Flow

### 8.1 Connection Mode Auto-Detection (satu kali setelah first heartbeat)

```
Plugin (first heartbeat) -> POST /plugin/v1/heartbeat
  {trigger_endpoint, probe_endpoint, reachability_challenge}

Backend segera coba: POST probe_endpoint {challenge}

  Jika plugin respond 200 dalam 10 detik:
    -> trigger_mode = "direct"
    -> Dashboard: "Direct Mode"

  Jika timeout / connection error:
    -> trigger_mode = "heartbeat"
    -> Dashboard: "Heartbeat Mode" + firewall warning banner
```

### 8.2 Direct Mode Scan (Hybrid A — Primary Path)

```
User klik "Run Scan" di dashboard
  -> POST /api/v1/scans {target_id, scan_type: "full"}
  -> Backend: create scan_job (status=queued)
  -> Backend: enqueue internal_scan_task + external_scan_task ke Redis

[PARALLEL]

Internal Worker:
  -> POST /ojsdef/trigger ke plugin (HMAC-signed)
  -> Plugin respond 202 (tutup koneksi, lanjut proses)
  -> Plugin: ScanOrchestrator.runAll() ~45 detik
  -> Plugin: POST /plugin/v1/callback dengan hasil scan
  -> Backend: validate HMAC, save internal findings ke DB
  -> set job.internal_done = true
  -> check: kedua done? -> enqueue scoring_task

External Worker:
  -> Run 6 external scanner modules (~5-15 menit, rate limit 10 req/s)
  -> save external findings ke DB
  -> set job.external_done = true
  -> check: kedua done? -> enqueue scoring_task

Scoring Worker (setelah keduanya done):
  -> CVSS calc per finding
  -> overall_score (0-100) calculation
  -> PDF generation (WeasyPrint)
  -> UPDATE scan_job status=completed
  -> Jika ada Critical findings -> enqueue notification_task

Frontend polling (GET /api/v1/scans/:id setiap 5 detik):
  -> Deteksi status=completed -> render dashboard hasil scan
```

### 8.3 Heartbeat Mode Scan (Hybrid C — Fallback)

```
User klik "Run Scan" di dashboard
  -> Backend: create scan_job, set awaiting_plugin=true
  -> Backend: enqueue external_scan_task saja (tidak call plugin langsung)
  -> Frontend: tampilkan "Scan dijadwalkan, plugin akan mengambil task (maks 5 menit)"

Kurang dari 5 menit kemudian:
  Plugin heartbeat -> Backend respond {scan_requested: true, job_id, scan_modules}
  -> Plugin: ScanOrchestrator.runAll()
  -> Plugin: POST /plugin/v1/callback
  -> Proses selanjutnya sama dengan Direct Mode
```

### 8.4 Scoring Gate (mencegah double-trigger)

```python
def check_and_trigger_scoring(job_id: str):
    job = db.get_scan_job(job_id)
    if job.internal_done and job.external_done:
        lock_key = f"scoring_lock:{job_id}"
        if redis.set(lock_key, "1", nx=True, ex=300):  # atomic set, hanya sekali
            scoring_task.delay(job_id)
```

**Edge case — plugin tidak pernah callback dalam 10 menit:**
Celery Beat job cek job yang stuck setiap 5 menit. Jika internal timeout, scoring tetap jalan dengan external-only findings dan `error_message = "Plugin callback timeout"`.

### 8.5 Timing End-to-End

| Phase | Direct Mode | Heartbeat Mode |
|-------|-------------|----------------|
| Klik Run Scan -> scan dimulai | < 10 detik | Maks 5 menit |
| Internal scan (plugin, 6 modul) | ~45 detik | ~45 detik |
| External scan (bot, 6 modul) | ~5–15 menit | ~5–15 menit |
| Scoring + PDF generation | ~30 detik | ~30 detik |
| **Total** | **~6–16 menit** | **~11–21 menit** |

---

## 9. Keputusan Desain

| Keputusan | Pilihan yang Diambil | Alasan |
|-----------|---------------------|--------|
| Plugin type | GenericPlugin, site-wide | Akses ke semua OJS internals; satu plugin per instalasi OJS |
| Trigger mechanism | Hybrid A+C | Direct Mode cepat (<10s); Heartbeat Mode fallback untuk server di balik firewall |
| Firewall detection | Auto-probe saat first heartbeat | UX lebih baik — sistem auto-detect, admin tidak perlu manual test |
| Settings storage | OJS `plugin_settings` DB | Admin bisa input API key dari UI tanpa akses SSH ke server |
| HTTP client | cURL native PHP | Tidak butuh Composer; kompatibel semua instalasi OJS |
| Scanner scope MVP | 6 modul (P1 per SRS) | Termasuk FileIntegrity + ContentInjection sebagai value proposition terkuat OJSDef |
| Checksums source | Fetch dari OJSDef backend (cache 7 hari) | Backend kelola checksums DB; plugin tidak perlu reach GitHub langsung |
| PII dalam RBAC scan | Hanya user_id + metadata | Tidak expose email/nama — minimize transfer data sensitif |
| Content evidence | Max 100 char excerpt | Cukup sebagai bukti; tidak kirim konten artikel lengkap |
| Async PHP | fastcgi_finish_request() + ignore_user_abort | Respond 202 cepat ke backend, scan jalan di background |

---

## 10. Batasan MVP & Roadmap

### Tidak termasuk Plugin v1 (MVP):
- Scanner P2: DatabaseSecurityChecker (FR-INT-07), WeakCredentialsDetector (FR-INT-08)
- Antarmuka OJS untuk melihat hasil scan langsung — semua hasil di OJSDef dashboard
- Auto-update plugin dari dashboard
- Support OJS multi-context per instalasi — MVP support single context

### Phase 2 (Plugin v2):
- DatabaseSecurityChecker — DB user privileges, backup .sql exposure
- WeakCredentialsDetector — password hash vs common passwords list
- OJS 3.5.x full compatibility verified dan documented
- Plugin update mechanism dari OJSDef dashboard

---

## 11. Referensi

- PRD OJSDef v1.2 — `workspace/OJSDef_PRD_v1.2.md`
- SRS OJSDef v1.2 — `workspace/OJSDEF-BackEnd/docs/SRS_OJSDEF.md`
- Backend MVP Design — `workspace/OJSDEF-BackEnd/docs/superpowers/specs/2026-05-23-backend-mvp-design.md`
- OJS Plugin Guide — https://docs.pkp.sfu.ca/dev/plugin-guide/en/
- Reference Plugin (File Integrity Scanner) — https://github.com/ashvisualtheme/file-integrity-scanner
- SRS API Spec — Section 8.6 Plugin Callback Endpoint
- SRS Sequence Diagram — Section 9.3 Full Scan Execution Flow, 9.4 Plugin Heartbeat & Status Flow
