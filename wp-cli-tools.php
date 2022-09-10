<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$wpcli_core_autoloader = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $wpcli_core_autoloader ) ) {
	require_once $wpcli_core_autoloader;
}

WP_CLI::add_command( 'eighteen73 create-site', 'Eighteen73\WP_CLI\Commands\CreateSite' );
WP_CLI::add_command( 'eighteen73 first-sync', 'Eighteen73\WP_CLI\Commands\FirstSync' );
WP_CLI::add_command( 'eighteen73 sync', 'Eighteen73\WP_CLI\Commands\Sync' );

// WP_CLI::add_command('eighteen73 create-plugin', ToDo::class);
// WP_CLI::add_command('eighteen73 create-block', ToDo::class);
// WP_CLI::add_command('eighteen73 create-pattern', ToDo::class);
// WP_CLI::add_command('eighteen73 import-block', ToDo::class);
// WP_CLI::add_command('eighteen73 import-pattern', ToDo::class);
