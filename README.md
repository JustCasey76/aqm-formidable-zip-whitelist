# AQM Formidable ZIP & State Whitelist (Hardened)

Server-side ZIP/State allowlist for Formidable Forms. Auto-detects location fields, validates submissions, and blocks unauthorized locations with hardened security against Unicode/invisible character attacks.

## Features

- **Automatic Field Detection** - Automatically finds ZIP/postal and state/province fields in your forms
- **ZIP Code Validation** - Support for 5-digit and extended format ZIP codes
- **State Validation** - Accepts both state codes and full state names
- **Hardened Security** - Protection against Unicode/invisible characters and fullwidth character attacks
- **Double Enforcement** - Validation on both form submission and entry creation/update
- **User-Friendly Interface** - Step-by-step setup guide with clear instructions

## Requirements

- WordPress 5.0+
- Formidable Forms plugin (active)
- PHP 7.4+

## Installation

1. **From zip (recommended):** Download the release zip from [GitHub Releases](https://github.com/AQ-Marketing/aqm-formidable-zip-whitelist/releases). In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip, then **Install Now** and **Activate**.
2. **From folder:** Upload the `aqm-formidable-zip-whitelist` folder to `/wp-content/plugins/`, then activate via **Plugins** in WordPress.
3. Navigate to **Location Whitelist** in the WordPress admin menu.

### "Plugin file does not exist" after activating

This usually means WordPress has a stale or wrong plugin path (often from a duplicate folder or a bad zip). Fix it:

1. **Remove duplicate folders**  
   In `/wp-content/plugins/` delete any extra copies, e.g. `aqm-formidable-zip-whitelist-1` or `aqm-formidable-zip-whitelist (1)`. Keep only one folder: `aqm-formidable-zip-whitelist` containing `aqm-formidable-zip-whitelist.php`.

2. **Clean active plugins**  
   If the error persists, deactivate the plugin (if it appears in the list), then remove the broken entry from the database: in `wp_options` find `active_plugins` and remove the line for `aqm-formidable-zip-whitelist-1/aqm-formidable-zip-whitelist.php` (or any path that doesn’t match your single folder). Then activate again from **Plugins**.

3. **Reinstall from GitHub**  
   Use the zip from the [Releases](https://github.com/AQ-Marketing/aqm-formidable-zip-whitelist/releases) page (built with correct paths). Avoid zips built on Windows with tools that use backslashes inside the archive.

## Quick Setup

1. Select which forms should have location validation
2. Enable ZIP and/or State validation
3. Enter allowed ZIP codes (one per line) and/or states (comma-separated)
4. Customize error messages (optional)
5. Save settings

## License

GPL-2.0+ (Same as WordPress)

## Author

AQ Marketing (Justin Casey)

