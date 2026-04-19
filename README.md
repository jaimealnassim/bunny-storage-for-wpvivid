# Bunny Storage for WPvivid

A WordPress plugin that adds **Bunny.net Storage** as a remote backup destination for [WPvivid Backup & Restore](https://wordpress.org/plugins/wpvivid-backuprestore/). Store your backups in Bunny's global edge storage network using FTP, REST API, or S3-compatible connections.

---

## Features

- **Three connection methods** — FTP, REST API (recommended), and S3-compatible (preview)
- **Nine storage regions** — DE, NY, LA, SG, SY, UK, SE, BR, JHB
- **Subdirectory support** — Organise backups into folders within your Storage Zone
- **Streaming uploads** — Large backup files are streamed directly from disk; nothing loaded into memory
- **Resumable transfers** — Uploads and downloads resume from where they left off if interrupted
- **No external dependencies** — No Composer, no AWS SDK. S3 Signature V4 signing is implemented natively in PHP
- **Full WPvivid integration** — Works with scheduled backups, manual backups, the Prepare to Download workflow, and retention cleanup

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.6+ |
| PHP | 7.4+ |
| WPvivid Backup & Restore | Any (free or pro) |
| PHP `curl` extension | For REST API and S3 methods |
| PHP `ftp` extension | For FTP method only |

---

## Installation

1. Download the latest release zip from the [Releases](../../releases) page.
2. In WordPress admin go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate **Bunny Storage for WPvivid**.
4. Go to **WPvivid Backup → Remote Storage** and click the **Bunny Storage** tab.
5. Enter your credentials and click **Test and Add**.

> **Important:** Do not rename the plugin directory. The directory name `bunny-storage-for-wpvivid` must sort alphabetically before `wpvivid-backuprestore` so this plugin loads first and can register with WPvivid's remote storage system before WPvivid initialises.

---

## Configuration

### Finding Your Bunny Credentials

1. Log in to [Bunny.net](https://bunny.net) and go to **Storage**.
2. Select your Storage Zone (or create one).
3. Note the **Storage Zone Name** — used as the FTP username and S3 bucket name.
4. Click the **FTP & API Access** tab to find your **Storage Zone Password** — used for both FTP and REST API connections.
5. For S3 credentials, look in your Storage Zone's S3-compatible settings section.

### Connection Methods

#### REST API *(Recommended)*
Uses Bunny's HTTP Storage API. Requires only cURL (available on virtually all hosts). Supports streaming uploads and ranged downloads.

- Credentials: Storage Zone Name + Storage Zone Password (used as the `AccessKey` header)

#### FTP
Standard FTP over port 21 with passive mode. Requires PHP's `ftp` extension.

- Credentials: Storage Zone Name (username) + Storage Zone Password

#### S3-Compatible *(Preview)*
Uses Bunny's S3-compatible API with AWS Signature V4. No external SDK needed.

- Credentials: S3 Access Key ID + S3 Secret Key + S3 Endpoint URL
- Default endpoint: `https://s3.bunnycdn.com`

---

## How It Works

### Plugin Load Order
WPvivid calls `run_wpvivid()` directly when its plugin file is loaded — before `plugins_loaded` fires. WordPress loads active plugins in alphabetical order, so this plugin (`bunny-*`) is loaded before WPvivid (`wpvivid-*`), ensuring our `wpvivid_remote_register` filter is in place when WPvivid builds its remote storage collection.

A Reflection-based safety net in `plugins_loaded` also injects the class into the already-built `WPvivid_Remote_collection` in case load order is ever reversed.

### FTP Path Handling
Bunny Storage FTP chroots each session to the zone root. All FTP operations use relative paths (no leading slash). For subdirectory uploads, `ftp_mkpath` walks segment-by-segment using `ftp_chdir` + `ftp_mkdir`, then subsequent uploads/downloads use just the bare filename from the correct working directory.

### Streaming Uploads
API and S3 uploads use `CURLOPT_PUT` with `CURLOPT_INFILE` to stream files directly from disk without loading them into PHP memory — essential for large multi-part backup archives.

---

## Security

- Credentials are stored base64-encoded in WordPress options (matching WPvivid's own pattern)
- Passwords are stored via `wp_unslash()` only — `sanitize_text_field()` is deliberately avoided to prevent silent corruption of credentials containing special characters
- All cURL calls enforce `CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST`
- S3 endpoints are validated to `https://` only
- Path traversal sequences (`../`, `./`) are stripped from user-supplied subdirectory paths
- Error messages expose only `basename()` of file paths, never absolute server paths

---

## Changelog

### 1.3.2
- Fix: Bunny icon now displays in the WPvivid backup history list (`WPVIVID_PLUGIN_URL` relative path traversal)

### 1.3.1
- Fix: "Prepare to Download" / Retrieve now works correctly with subdirectory paths — FTP download and cleanup use chdir-then-filename (consistent with upload)

### 1.3.0
- Fix: Bunny icon now appears in "Send Backup to Remote Storage" and schedule rows
- Added gray/dimmed icon for unselected state
- Fix: FTP upload subdirectory double-path bug (files now land in the correct location)
- Fix: Duplicate "Bunny Storage" tab removed (static constructor guard)

### 1.2.0
- Security hardening: passwords, SSL verification, path traversal, error message leakage
- Performance: static region map cache, deduplicated `filesize()` calls
- Fix: Plugin renamed for correct alphabetical load order
- Fix: Reflection safety net for edge-case load order reversal
- Fix: JS toggle scoped to `.nahnu-bunny-form` to prevent outer table matching

### 1.1.0
- Added REST API connection method (streaming cURL)
- Added S3-compatible connection method (native AWS Signature V4)
- Fix: Storage saves correctly when subdirectory path is entered

### 1.0.0
- Initial release — FTP connection method, nine regions, passive mode, retry logic

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for details.

---

## Author

**Nahnu Plugins** — [nahnuplugins.com](https://nahnuplugins.com)
