<?php

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use WP_CLI;
use WP_CLI_Command;

/**
 * Handles a complete eighteen73 WordPress installation and configuration.
 *
 * ## EXAMPLES
 *
 *     # Install a new WordPress website using Nebula and it's install wizard
 *     $ wp eighteen73 create-site foobar
 *
 * @package eighteen73/wpi-cli-tools
 */
class CreateSite extends WP_CLI_Command
{
	private string $install_directory;
	private string $wp_directory;
	private string $site_name;
	private string $site_url;
	private string $site_username;
	private string $site_password;

	private array $options = [
		'woocommerce' => false,
	];

	/**
	 * Creates a new website.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The name (directory) for the website
	 *
	 * [--woocommerce]
	 * : Include WooCommerce
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eighteen73 create-site foobar
	 *
	 * @when before_wp_load
	 */
	public function __invoke(array $args, array $assoc_args)
	{
		// Check options
		$this->check_args($assoc_args);

		// Check PHP and node versions
		$this->check_path($args[0]);

		$this->status_message('Installing WordPress...');
		$this->download_nebula();
		$this->create_repo();
		$this->install_wordpress();

		$this->status_message('Installing theme...');
		$this->download_pulsar();

		$this->status_message('Installing default plugins...');
		$this->install_plugins();

		if ($this->options['woocommerce']) {
			$this->status_message('Installing WooCommerce...');
			$this->install_woocommerce();
		}

		WP_CLI::line();
		WP_CLI::line();
		WP_CLI::success('Your website is ready.');
		WP_CLI::line();
		WP_CLI::line('URL:      ' . $this->site_url);
		WP_CLI::line('Admin:    ' . $this->site_url . '/wp/wp-admin');
		WP_CLI::line();
		WP_CLI::line('Username: ' . $this->site_username);
		WP_CLI::line('Password: ' . $this->site_password);
	}

	private function status_message(string $message)
	{
		WP_CLI::line();
		WP_CLI::line(str_pad('', strlen($message) + 3, '*'));
		WP_CLI::line("* {$message}");
		WP_CLI::line(str_pad('', strlen($message) + 3, '*'));
		WP_CLI::line();
	}

	private function check_args(array $assoc_args)
	{
		if (isset($assoc_args['woocommerce']) && $assoc_args['woocommerce'] === true) {
			$this->options['woocommerce'] = true;
		} elseif (isset($assoc_args['woocommerce'])) {
			WP_CLI::error('Option `--woocommerce` must not have a value');
		}
	}

	private function check_path(string $site_name)
	{
		if (preg_match('/^\//', $site_name)) {
			$this->install_directory = rtrim($site_name, '/');
			$this->site_name = basename($site_name);
		} else {
			$this->install_directory = rtrim(getcwd(), '/') . '/' . rtrim($site_name, '/');
			$this->site_name = $site_name;
		}
		$this->wp_directory = $this->install_directory . '/web/wp';

		// Get confirmation
		WP_CLI::confirm("Installing \"{$this->site_name}\" to \"{$this->install_directory}\". Is this OK?");

		// Is it writable?
		if (!is_dir($this->install_directory)) {
			if (!is_writable(dirname($this->install_directory))) {
				WP_CLI::error("Insufficient permission to create directory '{$this->install_directory}'.");
			}

			WP_CLI::log("Creating directory '{$this->install_directory}'.");
			if (!@mkdir($this->install_directory, 0777, true /*recursive*/)) {
				$error = error_get_last();
				WP_CLI::error("Failed to create directory '{$this->install_directory}': {$error['message']}.");
			}
		}
		if (!is_writable($this->install_directory)) {
			WP_CLI::error("'{$this->install_directory}' is not writable by current user.");
		}

	}

	private function download_nebula()
	{
		Helpers::composer_command('create-project eighteen73/nebula '.escapeshellarg($this->install_directory).' --stability=dev', null, false);
		Helpers::composer_command('update', $this->install_directory);
		WP_CLI::line('   ... done');
	}

	private function create_repo()
	{
		Helpers::git_command('init', $this->install_directory);
		$this->commit_repo('Initial commit');
	}

	private function commit_repo(string $message)
	{
		Helpers::git_command('add .', $this->install_directory);
		Helpers::git_command('commit -m '.escapeshellarg($message), $this->install_directory);
	}

	private function download_pulsar()
	{
		Helpers::composer_command('create-project eighteen73/pulsar '.escapeshellarg($this->install_directory . '/web/app/themes/pulsar').' --stability=dev');
		Helpers::cli_command('npm install --prefix ' . escapeshellarg($this->install_directory . '/web/app/themes/pulsar'));
		Helpers::wp_command('theme activate pulsar', $this->wp_directory);
		$this->commit_repo('Add Pulsar theme');
		WP_CLI::line('   ... done');
	}

	private function install_wordpress()
	{
		$fp = @fopen($this->install_directory . '/.env', 'r');
		while (($buffer = fgets($fp, 4096)) !== false) {
			if (preg_match('/^WP_HOME="(.+)"$/', $buffer, $matches)) {
				$this->site_url = $matches[1];
				break;
			}
		}
		fclose($fp);

		WP_CLI::line();
		WP_CLI::line('Enter your admin username');
		WP_CLI::out('> ');
		$this->site_username = strtolower(trim(fgets(STDIN)));

		WP_CLI::line();
		WP_CLI::line('Enter your admin email address');
		WP_CLI::out('> ');
		$email = strtolower(trim(fgets(STDIN)));

		// Install
		$output = Helpers::wp_command([
			'core install',
			[
				'skip-email' => null,
				'url' => escapeshellarg($this->site_url . '/web'),
				'title' => escapeshellarg($this->site_name),
				'admin_user' => escapeshellarg($this->site_username),
				'admin_email' => escapeshellarg($email),
			],
		], $this->wp_directory);

		// Set language
		Helpers::wp_command('language core install en_GB', $this->wp_directory);
		Helpers::wp_command('site switch-language en_GB', $this->wp_directory);

		// Options
		Helpers::wp_update_option('blogdescription', '""', $this->wp_directory);
		Helpers::wp_update_option('date_format', '"d/m/Y"', $this->wp_directory);
		Helpers::wp_update_option('timezone_string', '"Europe/London"', $this->wp_directory);
		Helpers::wp_update_option('default_ping_status', '""', $this->wp_directory);
		Helpers::wp_update_option('default_pingback_flag', '""', $this->wp_directory);
		Helpers::wp_update_option('comments_notify', '""', $this->wp_directory);
		Helpers::wp_update_option('default_comment_status', '""', $this->wp_directory);
		Helpers::wp_update_option('comment_moderation', '1', $this->wp_directory);
		Helpers::wp_update_option('comment_registration', '1', $this->wp_directory);
		Helpers::wp_update_option('moderation_notify', '""', $this->wp_directory);
		Helpers::wp_update_option('page_comments', '""', $this->wp_directory);
		Helpers::wp_update_option('comment_previously_approved', '1', $this->wp_directory);
		Helpers::wp_update_option('show_avatars', '""', $this->wp_directory);
		Helpers::wp_update_option('permalink_structure', '"/%postname%/"', $this->wp_directory);

		$this->site_password = '';
		if (preg_match('/^Admin password: (.+)\s/', $output, $matches)) {
			$this->site_password = trim($matches[1]);
		}
		WP_CLI::line('   ... done');
	}

	private function install_plugins()
	{
		$plugins = [
			'always' => [
				'wp-media/wp-rocket',
				'wpackagist-plugin/duracelltomi-google-tag-manager',
				'wpackagist-plugin/limit-login-attempts-reloaded',
				'wpackagist-plugin/mailgun',
				'wpackagist-plugin/redirection',
				'wpackagist-plugin/webp-express',
				'wpackagist-plugin/wordpress-seo',
			],
			'dev' => [
				'wpackagist-plugin/spatie-ray',
			],
		];

		Helpers::composer_command('require ' . implode(' ', $plugins['always']), $this->install_directory);
		Helpers::composer_command('require --dev ' . implode(' ', $plugins['dev']), $this->install_directory);

		$all_plugins = array_map(
			fn(string $value) => substr($value, strpos($value, '/') + 1),
			array_merge($plugins['always'], $plugins['dev'])
		);

		Helpers::wp_command('plugin activate ' . implode(' ', $all_plugins), $this->wp_directory);
		Helpers::wp_add_option('limit_login_lockout_notify', '""', true, $this->wp_directory);
		Helpers::wp_add_option('limit_login_show_warning_badge', '0', true, $this->wp_directory);
		Helpers::wp_add_option('limit_login_hide_dashboard_widget', '1', true, $this->wp_directory);
		Helpers::wp_add_option('limit_login_show_top_level_menu_item', '0', true, $this->wp_directory);
		Helpers::wp_command('transient delete llar_welcome_redirect', $this->wp_directory);

		Helpers::wp_command([
			'option add mailgun',
			escapeshellarg(json_encode([
				'region' => 'eu',
				'useAPI' => '1',
				'domain' => 'site-email.com',
				'apiKey' => '',
				'username' => '',
				'password' => '',
				'secure' => '1',
				'sectype' => 'ssl',
				'track-clicks' => 'no',
				'track-opens' => '1',
				'from-address' => '',
				'from-name' => '',
				'override-from' => '0',
				'campaign-id' => '',
			])),
			[
				'format' => 'json',
				'autoload' => 'yes',
			],
		]);

		$this->commit_repo('Add house plugins');

		WP_CLI::line('   ... done');
	}

	private function install_woocommerce()
	{
		Helpers::composer_command('require wpackagist-plugin/woocommerce', $this->install_directory);
		Helpers::wp_command('plugin activate woocommerce', $this->wp_directory);
		WP_CLI::line('   ... done');
	}
}
