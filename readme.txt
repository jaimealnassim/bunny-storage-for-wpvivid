=== Bunny Storage for WPvivid ===
Contributors: nahnuplugins
Tags: wpvivid, bunny, bunnycdn, backup, remote storage, ftp, s3
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds Bunny.net Storage as a remote backup destination for WPvivid Backup & Restore. Supports FTP, REST API, and S3-compatible connections.

== Description ==

**Bunny Storage for WPvivid** integrates Bunny.net Storage Zones directly into WPvivid Backup & Restore as a remote backup destination. Store your WordPress backups in Bunny's global edge storage network with your choice of three connection methods.

= Connection Methods =

* **FTP** — Uses standard FTP with passive mode. Works on any server with PHP's FTP extension. Bunny Storage supports FTP on port 21 across all regions.
* **REST API** *(Recommended)* — Uses Bunny's HTTP Storage API with streaming cURL uploads and downloads. No extra PHP extensions required beyond cURL. Fastest and most reliable option.
* **S3-Compatible** *(Preview)* — Uses Bunny's in-preview S3-compatible API with AWS Signature V4 signing. No external SDK required — signing is handled natively in PHP.

= Features =

* Nine Bunny Storage regions: Falkenstein (DE), New York (NY), Los Angeles (LA), Singapore (SG), Sydney (SY), London (UK), Stockholm (SE), São Paulo (BR), Johannesburg (JHB)
* Optional subdirectory path inside your Storage Zone
* Resume support on FTP and API uploads (picks up where it left off if interrupted)
* Resumable downloads (Range header support)
* Bunny icon shown in the WPvivid backup list, schedule page, and remote storage selector
* Compatible with WPvivid's scheduled backups, manual backups, and Prepare to Download workflow
* No dependencies — no Composer, no AWS SDK, no external libraries

= Requirements =

* WordPress 5.6 or higher
* PHP 7.4 or higher
* WPvivid Backup & Restore (free) must be installed and active
* PHP `ftp` extension (for FTP method only)
* PHP `curl` extension (for REST API and S3 methods)

= Important: Plugin Load Order =

This plugin's directory name starts with `bunny-` which sorts before `wpvivid-` alphabetically. WordPress loads active plugins in alphabetical order, so this plugin loads before WPvivid — which is required for correct registration with WPvivid's remote storage system. Do not rename the plugin directory.

== Installation ==

1. Ensure WPvivid Backup & Restore is installed and active.
2. Upload the `bunny-storage-for-wpvivid` folder to `/wp-content/plugins/`.
3. Activate the plugin through the **Plugins** screen in WordPress.
4. Go to **WPvivid Backup → Remote Storage** and click the **Bunny Storage** tab.
5. Fill in your Storage Zone credentials and click **Test and Add**.

= Finding Your Bunny Credentials =

1. Log in to your Bunny.net dashboard.
2. Go to **Storage** and select your Storage Zone (or create one).
3. Note the **Storage Zone Name** — this is your FTP username and S3 bucket name.
4. Click the **FTP & API Access** tab to find your **Storage Zone Password** — this is used for both FTP and REST API connections.
5. For S3-compatible credentials, look for the S3 section within your Storage Zone settings.

== Frequently Asked Questions ==

= Which connection method should I use? =

**REST API** is recommended for most sites. It requires no special PHP extensions beyond cURL (which is available on virtually all hosts), is the fastest method, and supports streaming uploads so large backup files never cause memory issues.

Use **FTP** if your host has restrictions on outbound HTTPS connections but allows FTP, or if you prefer a proven protocol.

Use **S3** only if you specifically need S3-compatible access — note that Bunny's S3 interface is still in preview.

= What is the Storage Zone Password? =

It is the password shown on the **FTP & API Access** tab inside your Bunny Storage Zone settings. It is NOT your main Bunny.net account password. The same password is used as the `AccessKey` header for the REST API.

= Can I use a subdirectory inside my Storage Zone? =

Yes. Enter the subdirectory path in the **Subdirectory** field when adding storage, e.g. `/backups`. It will be created automatically on the first backup if it does not exist.

= Why does it say "bunny-*" must stay first alphabetically? =

WPvivid builds its remote storage registry when it first loads — before WordPress fires the `plugins_loaded` hook. Because WordPress loads plugins alphabetically, this plugin's name must sort before `wpvivid-backuprestore`. A Reflection-based fallback handles edge cases, but the alphabetical ordering is the primary mechanism.

= The S3 option shows as "preview" — is it stable? =

The S3-compatible interface is provided by Bunny.net as a preview feature. The implementation in this plugin is complete and correct, but Bunny may change their S3 endpoint details as the feature matures. The S3 endpoint field can be overridden if Bunny publishes region-specific endpoints.

== Changelog ==

= 1.3.2 =
* Fix: Bunny icon now displays correctly in the WPvivid backup history list (resolved WPVIVID_PLUGIN_URL path issue using relative traversal)

= 1.3.1 =
* Fix: FTP download (Prepare to Download / Retrieve) now correctly navigates to the subdirectory before retrieving files — matches the chdir-then-filename pattern used by upload
* Fix: FTP cleanup (retention/deletion) uses the same chdir approach for consistency

= 1.3.0 =
* Fix: Bunny icon now appears in "Send Backup to Remote Storage" row and schedule page (remote_pic filter was previously a no-op; added wpvivid_get_wpvivid_pro_url filter)
* Added gray/dimmed icon variant for unselected state
* Fix: FTP upload no longer double-paths the subdirectory (ftp_mkpath chdir'd into subdir, then ftp_rel_path added the subdir again — now uses bare filename after chdir)
* Fix: Double "Bunny Storage" tab (load_hooks + plugins_loaded both instantiated the class; added static guard)

= 1.2.0 =
* Security: Replaced sanitize_text_field() on passwords with wp_unslash() to prevent silent credential corruption
* Security: Added CURLOPT_SSL_VERIFYHOST to all cURL calls
* Security: S3 endpoint now enforced to https:// only
* Security: Full server paths stripped from error messages (basename() only)
* Security: Path traversal sequences stripped from user-supplied subdirectory path
* Security: curl_init() return value checked before use
* Security: Reflection catch block updated to catch Throwable (PHP 8.1+)
* Performance: region_map() now uses a static cache
* Performance: filesize() de-duplicated in upload path (reads from job_data)
* Fix: Plugin renamed to bunny-storage-for-wpvivid to guarantee correct alphabetical load order
* Fix: Reflection safety net added to inject class into WPvivid remote_collection if load order is reversed
* Fix: JS toggle rewritten to scope to .nahnu-bunny-form class, preventing outer table matching

= 1.1.0 =
* Added REST API connection method (streaming cURL PUT/GET/DELETE)
* Added S3-compatible connection method (AWS Signature V4, no SDK)
* Added connection method selector with dynamic field show/hide
* Fix: FTP test_connect no longer blocks storage save when subdirectory doesn't exist yet
* Fix: api_test_connect tests zone root only (not subpath) to avoid false failures

= 1.0.0 =
* Initial release — FTP connection method
* Nine Bunny Storage regions
* Passive mode, resumable uploads, retry logic
* WPvivid task progress integration

== Upgrade Notice ==

= 1.3.2 =
Fixes the Bunny logo display in the WPvivid backup list. Cosmetic fix only — no functional changes.

= 1.3.1 =
Fixes "Prepare to Download" / Retrieve from Bunny Storage. If you use a subdirectory path, update to this version before attempting downloads.
