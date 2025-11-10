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

1. Download or clone this repository
2. Upload the `aqm-formidable-zip-whitelist` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to **Location Whitelist** in the WordPress admin menu

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

