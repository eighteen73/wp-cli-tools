<?php

namespace Eighteen73\WP_CLI;

use WP_CLI;

class Helpers
{
	private static string $cwd;
	private static string $wp_dir;

	public static function paths()
	{
		self::$cwd = getcwd();
		self::$wp_dir = self::$cwd . '/web/wp';
	}

	public static function cli_command($command)
	{
		$command = self::prepare_command($command);
		// WP_CLI::line($command);
		exec($command, $output, $result);
		return $output;
	}

	public static function git_command($command, $working_dir = null)
	{
		self::paths();
		$full_command = 'git';
		if ($working_dir) {
			$full_command .= ' -C ' . escapeshellarg($working_dir);
		}
		$full_command .= ' ' . self::prepare_command($command);
		return self::cli_command($full_command);
	}

	public static function composer_command($command, $working_dir = null, $quiet = true)
	{
		self::paths();
		$command = 'composer ' . self::prepare_command($command);
		if ($working_dir) {
			$command .= ' --working-dir=' . escapeshellarg($working_dir);
		}
		if ($quiet) {
			$command .= ' --quiet';
		}
		return self::cli_command($command);
	}

	public static function wp_command($command, $working_dir = null, $return = true)
	{
		self::paths();
		$path = $working_dir ?? self::$wp_dir;
		$command = self::prepare_command($command) . ' --path=' . escapeshellarg($path);
		// WP_CLI::line($command);
		return WP_CLI::runcommand($command, [
			'exit_error' => false,
			'return' => $return,
		]);
	}

	public static function wp_add_option($key, $value, $autoload, $working_dir = null)
	{
		$command = 'option add ' . $key . ' ' . $value;
		if ($autoload) {
			$command .= ' --autoload=yes';
		}
		return self::wp_command($command, $working_dir);
	}

	public static function wp_update_option($key, $value, $working_dir = null)
	{
		return self::wp_command('option update ' . $key . ' ' . $value, $working_dir);
	}

	private static function prepare_command($command)
	{
		if (is_string($command)) {
			$command = [$command];
		}

		$cmd = '';
		foreach ($command as $part) {
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
		return trim($cmd);
	}
}
