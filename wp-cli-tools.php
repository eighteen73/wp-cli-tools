<?php
/**
 * The main package script
 *
 * @package eighteen73/wpi-cli-tools
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$wpcli_core_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $wpcli_core_autoloader ) ) {
	require_once $wpcli_core_autoloader;
}

WP_CLI::add_command( 'eighteen73 version', 'Eighteen73\WP_CLI\Commands\Version' );
WP_CLI::add_command( 'eighteen73 create-site', 'Eighteen73\WP_CLI\Commands\CreateSite' );
WP_CLI::add_command( 'eighteen73 first-sync', 'Eighteen73\WP_CLI\Commands\FirstSync' );
WP_CLI::add_command( 'eighteen73 sync', 'Eighteen73\WP_CLI\Commands\Sync' );
WP_CLI::add_command( 'eighteen73 just-launched', 'Eighteen73\WP_CLI\Commands\JustLaunched' );
WP_CLI::add_command( 'eighteen73 style-guide', 'Eighteen73\WP_CLI\Commands\StyleGuide' );

// WP_CLI::add_command('eighteen73 create-plugin', ToDo::class);
// WP_CLI::add_command('eighteen73 create-block', ToDo::class);
// WP_CLI::add_command('eighteen73 create-pattern', ToDo::class);
// WP_CLI::add_command('eighteen73 import-block', ToDo::class);
// WP_CLI::add_command('eighteen73 import-pattern', ToDo::class);
