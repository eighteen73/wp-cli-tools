<?php

namespace Eighteen73\WP_CLI\Commands;

use WP_CLI;
use WP_CLI_Command;

/**
 * Managed an eighteen73 WordPress installation.
 *
 * ## EXAMPLES
 *
 *     # Install a new WordPress website using Nebula and it's install wizard
 *     $ wp eighteen73 create foobar
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
	 *     wp eighteen73 create foobar
	 *
	 * @when before_wp_load
	 */
	public function create(array $args, array $assoc_args)
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
			[
				'path' => escapeshellarg($this->install_directory . '/web/wp'),
			],
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
				'path' => escapeshellarg($this->install_directory . '/web/wp'),
				'skip-email' => null,
				'url' => escapeshellarg($this->site_url . '/web'),
				'title' => escapeshellarg($this->site_name),
				'admin_user' => escapeshellarg($this->site_username),
				'admin_email' => escapeshellarg($email),
			],
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
			[
				'path' => escapeshellarg($this->install_directory . '/web/wp'),
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
		return WP_CLI::runcommand($cmd, [
			'return' => true,
		]);
	}
}
