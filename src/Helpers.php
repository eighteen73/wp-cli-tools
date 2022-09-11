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
		exec(self::prepare_command($command), $output, $result);
		return $output;
	}

	public static function wp_command($command, $return = true)
	{
		self::paths();
		return WP_CLI::runcommand( self::prepare_command($command) . ' --path=' . escapeshellarg(self::$wp_dir), [
			'exit_error' => false,
			'return' => $return,
		]);
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
		// WP_CLI::line($cmd);
		return trim($cmd);
	}
}
