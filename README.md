# WP Multisite Migration Tool

A WordPress plugin to help migrate content from WordPress unisite installations to an existing WordPress multisite network.

## Features

- Configure multiple unisite database connections
- Easy-to-use admin interface
- Secure database connection management

## Installation

1. Download the plugin files
2. Upload the plugin folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to the 'WP Multisite Migration' menu in the WordPress admin panel
5. Configure your unisite database connection details

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher

## Usage

1. Navigate to the WP Multisite Migration settings page in your WordPress admin panel
2. Enter the following information for each unisite you want to migrate:
   - Site Name: A descriptive name for the unisite
   - Database Name: The name of the unisite's database
   - Table Prefix: The database table prefix (default: wp_)

## Security

- All database credentials are stored securely in the WordPress options table
- Access to the plugin settings is restricted to administrators only
- All form inputs are properly sanitized and validated

## Support

For support, please contact:
- Author: Brede Basualdo Serraino
- Email: hola@brede.cl
- Website: https://www.brede.cl

## License

This plugin is licensed under the GPL v2 or later.
