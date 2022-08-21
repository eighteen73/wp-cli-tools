<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$wpcli_core_autoloader = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $wpcli_core_autoloader ) ) {
	require_once $wpcli_core_autoloader;
}
WP_CLI::add_command( 'eighteen73', 'Eighteen73\WP_CLI\Commands\CreateSite' );

// require_once 'includes/Commands/Sync.php';
// require_once 'includes/Commands/CreateSite.php';

// WP_CLI::add_command('eighteen73 create-site', '\Eighteen73\WP_CLI\Commands\CreateSite');
// WP_CLI::add_command('eighteen73 sync', \Eighteen73\WP_CLI\Commands\Sync::class);
// // WP_CLI::add_command('eighteen73 create-plugin', CreateSite::class);
// // WP_CLI::add_command('eighteen73 create-block', CreateSite::class);
// // WP_CLI::add_command('eighteen73 create-pattern', CreateSite::class);
// WP_CLI::add_command('eighteen73 import-block', \Eighteen73\WP_CLI\Commands\ImportBlock::class);
// // WP_CLI::add_command('eighteen73 import-pattern', CreateSite::class);
