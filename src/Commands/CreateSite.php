<?php

namespace Eighteen73\WP_CLI\Commands;

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
	private string $site_name;
	private string $site_url;
	private string $site_username;
	private string $site_password;

	/**
	 * Creates a new website.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The name (directory) for the website.
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

	private function check_path(string $site_name)
	{
		if (preg_match('/^\//', $site_name)) {
			$this->install_directory = rtrim($site_name, '/');
			$this->site_name = basename($site_name);
		} else {
			$this->install_directory = rtrim(getcwd(), '/') . '/' . rtrim($site_name, '/');
			$this->site_name = $site_name;
		}

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
		$this->run_command([
			'composer',
			'create-project',
			[
				'stability' => 'dev',
			],
			'eighteen73/nebula',
			escapeshellarg($this->install_directory),
		]);
		$this->run_command([
			'composer',
			'update',
			[
				'quiet' => null,
				'working-dir' => escapeshellarg($this->install_directory),
			],
		]);
		WP_CLI::line('   ... done');
	}

	private function create_repo()
	{
		$this->run_command([
			'git',
			'-C',
			escapeshellarg($this->install_directory),
			'init',
		]);
		$this->commit_repo('Initial commit');
	}

	private function commit_repo(string $message)
	{
		$this->run_command([
			'git',
			'-C',
			escapeshellarg($this->install_directory),
			'add',
			'.',
		]);
		$this->run_command([
			'git',
			'-C',
			escapeshellarg($this->install_directory),
			'commit',
			'-m',
			escapeshellarg($message),
		]);
	}

	private function download_pulsar()
	{
		$this->run_command([
			'composer',
			'create-project',
			[
				'quiet' => null,
				'stability' => 'dev',
			],
			'eighteen73/pulsar',
			escapeshellarg($this->install_directory . '/web/app/themes/pulsar'),
		]);
		$this->run_command([
			'npm',
			'install',
			'--prefix',
			escapeshellarg($this->install_directory . '/web/app/themes/pulsar'),
		]);
		$this->run_wp([
			'theme',
			'activate',
			'pulsar',
		]);
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

		$output = $this->run_wp([
			'core',
			'install',
			[
				'skip-email' => null,
				'url' => escapeshellarg($this->site_url . '/web'),
				'title' => escapeshellarg($this->site_name),
				'admin_user' => escapeshellarg($this->site_username),
				'admin_email' => escapeshellarg($email),
			],
		]);

		$this->run_wp([
			'language',
			'core',
			'install',
			'en_GB',
		]);

		$this->run_wp([
			'site',
			'switch-language',
			'en_GB',
		]);

		$this->run_wp([
			'option',
			'update',
			'blogdescription',
			'""',
		]);

		$this->run_wp([
			'option',
			'update',
			'date_format',
			'"d/m/Y"',
		]);

		$this->run_wp([
			'option',
			'update',
			'timezone_string',
			'"Europe/London"',
		]);

		$this->run_wp([
			'option',
			'update',
			'default_ping_status',
			'""',
		]);

		$this->run_wp([
			'option',
			'update',
			'default_pingback_flag',
			'""',
		]);

		$this->run_wp([
			'option',
			'update',
			'comments_notify',
			'""',
		]);

		$this->run_wp([
			'option',
			'update',
			'default_comment_status',
			'""',
		]);

		$this->run_wp([
			'option',
			'update',
			'comment_moderation',
			'1',
		]);

		$this->run_wp([
			'option',
			'update',
			'comment_registration',
			'1',
		]);

		$this->run_wp([
			'option',
			'update',
			'moderation_notify',
			'""',
		]);

		$this->run_wp([
			'option',
			'update',
			'page_comments',
			'""',
		]);

		$this->run_wp([
			'option',
			'update',
			'comment_previously_approved',
			'1',
		]);

		$this->run_wp([
			'option',
			'update',
			'show_avatars',
			'""',
		]);

		$this->run_wp([
			'option',
			'update',
			'permalink_structure',
			'"/%postname%/"',
		]);

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

		$this->run_command([
			'composer',
			'require',
			[
				'quiet' => null,
				'working-dir' => escapeshellarg($this->install_directory),
			],
			implode(' ', $plugins['always']),
		]);

		$this->run_command([
			'composer',
			'require',
			[
				'quiet' => null,
				'dev' => null,
				'working-dir' => escapeshellarg($this->install_directory),
			],
			implode(' ', $plugins['dev']),
		]);

		$all_plugins = array_map(
			fn(string $value) => substr($value, strpos($value, '/') + 1),
			array_merge($plugins['always'], $plugins['dev'])
		);
		$this->run_wp([
			'plugin',
			'activate',
			implode(' ', $all_plugins),
		]);

		$this->run_wp([
			'option',
			'add',
			'limit_login_lockout_notify',
			'""',
			[
				'autoload' => 'yes',
			],
		]);
		$this->run_wp([
			'option',
			'add',
			'limit_login_show_warning_badge',
			'0',
			[
				'autoload' => 'yes',
			],
		]);
		$this->run_wp([
			'option',
			'add',
			'limit_login_hide_dashboard_widget',
			'1',
			[
				'autoload' => 'yes',
			],
		]);
		$this->run_wp([
			'option',
			'add',
			'limit_login_show_top_level_menu_item',
			'0',
			[
				'autoload' => 'yes',
			],
		]);
		$this->run_wp([
			'transient',
			'delete',
			'llar_welcome_redirect',
		]);
		$this->run_wp([
			'option',
			'add',
			'mailgun',
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

	private function run_command(array $command_parts)
	{
		$cmd = '';
		foreach ($command_parts as $part) {
			if (is_string($part)) {
				$cmd .= " {$part}";
				continue;
			}
			foreach ($part as $param => $param_value) {
				if ($param_value === null) {
					$cmd .= " --{$param}";
				} else {
					$cmd .= " --{$param}={$param_value}";
				}
			}
		}
		$cmd = trim($cmd);
		exec($cmd, $output, $result);
		return $output;
	}

	private function run_wp(array $command_parts)
	{
		$cmd = '';
		foreach ($command_parts as $part) {
			if (is_string($part)) {
				$cmd .= " {$part}";
				continue;
			}
			foreach ($part as $param => $param_value) {
				if ($param_value === null) {
					$cmd .= " --{$param}";
				} else {
					$cmd .= " --{$param}={$param_value}";
				}
			}
		}
		$cmd = trim($cmd);
		$cmd .= ' --path=' . escapeshellarg($this->install_directory . '/web/wp');
		return WP_CLI::runcommand($cmd, [
			'return' => true,
		]);
	}
}
