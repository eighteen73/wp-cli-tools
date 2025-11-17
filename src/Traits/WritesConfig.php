<?php

namespace Eighteen73\WP_CLI\Traits;

trait WritesConfig
{
	// private function editConfigValue(string $config_name, mixed $new_value, string $file = 'application.php'): void
	// {
	// 	$config_filepath = "{$this->install_directory}/config/application.php";
	//
	// 	$fp = fopen( $config_filepath, 'r+' );
	// 	while ( ! feof( $fp ) ) {
	// 		$line = fgets( $fp );
	// 		if ( ! str_contains( $line, "'{$config_name}'" ) ) {
	// 			continue;
	// 		}
	//
	// 		if ( ! is_string($new_value) ) {
	// 			$new_value = (string) $new_value;
	// 		} else {
	// 			$new_value = "'".str_replace("'", "\\'", $new_value)."'";
	// 		}
	//
	// 		$new_line = "Config::define( '{$config_name}', ".$new_value." )";
	// 		fseek( $fp, -strlen( $line ), SEEK_CUR );
	// 		fwrite( $fp, $new_line );
	// 	}
	// 	fclose( $fp );
	// }

	private function addConfigFile(string $filename, string $content): void
	{
		if (! preg_match('/^[a-z0-9_\-]+\.php$/', $filename)) {
			return;
		}

		$config_filepath = "{$this->install_directory}/config/includes/{$filename}";

		$full_content = "<?php\n";
		$full_content .= "namespace Eighteen73\Nebula\n";
		$full_content .= "\n";
		$full_content .= "use Roots\WPConfig\Config\n";
		$full_content .= "\n";
		$full_content .= trim($content) . "\n";

		file_put_contents( $config_filepath, $full_content );
	}
}
