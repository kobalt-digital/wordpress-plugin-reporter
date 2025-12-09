# Plugin Reporter

A WordPress plugin that automatically sends plugin information to a remote endpoint once per day and provides a secure REST endpoint for on-demand reporting.

## Features

- **Automated Daily Reporting**: Automatically collects and sends plugin information once per day
- **Secure REST Endpoint**: Provides a secure REST API endpoint (`/wp-json/plugin-reporter/v1/send`) for on-demand plugin information
- **Customizable Configuration**: Configure custom endpoint URL and secret key via settings page
- **Test Connection**: Built-in test functionality to verify endpoint connectivity
- **Plugin Information Collected**:
  - Plugin slug
  - Plugin name
  - Version
  - Active/inactive status
  - Available updates

## Installation

1. Upload the plugin files to `/wp-content/plugins/plugin-reporter/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Settings → Plugin Reporter to configure

## Configuration

Access the settings page via:
- **Settings → Plugin Reporter**, or
- **Plugins → Plugin Reporter → Settings** (from plugins overview page)

### Settings

- **Endpoint URL**: The API endpoint where plugin information will be sent (default: `https://plugin-reporter.kobaltdigital.nl/api/data`)
- **Secret Key**: The secret key used for Bearer token authentication (required)

## Usage

### Automated Reporting

Once activated, the plugin automatically sends plugin information daily via WordPress cron. The next scheduled run is displayed on the settings page.

### Manual Testing

Use the "Test Connection" button on the settings page to manually send plugin information and verify connectivity.

### REST API Endpoint

Send a POST request to `/wp-json/plugin-reporter/v1/send` with the following header:
```
X-Reporter-Key: your-secret-key
```

The endpoint returns plugin information in JSON format:
```json
{
  "status": "ok",
  "sent_at": "2024-01-01 12:00:00",
  "plugin_count": 25
}
```

## Data Format

The plugin sends the following data structure:

```json
{
  "site_url": "https://example.com",
  "plugins": [
    {
      "slug": "plugin-name",
      "title": "Plugin Name",
      "version": "1.0.0",
      "status": "active",
      "update": false
    }
  ]
}
```

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher

## Author

Arne van Hoorn

## Version

1.0.2

