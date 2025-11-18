<?php

namespace Eighteen73\WP_CLI\Traits;

trait WritesConfig {

	/**
	 * Adds lines of configuration to an existing file
	 *
	 * @param string $config_filepath The name of the configuration file to be created.
	 * @param string $after_line_containing Insert after this line
	 * @param mixed  $new_lines New content to insert
	 * @return void
	 */
	private function add_config_lines( string $config_filepath, string $after_line_containing, mixed $new_lines ): void {
		$new_file = '';

		$fp = fopen( $config_filepath, 'r' );
		while ( ! feof( $fp ) ) {
			$line = fgets( $fp );
			$new_file .= $line;
			if ( ! str_contains( $line, $after_line_containing ) ) {
				continue;
			}
			$new_file .= $new_lines;
		}
		fclose( $fp );
		file_put_contents( $config_filepath, $new_file );
	}

	/**
	 * Adds a configuration file to the specified directory.
	 *
	 * @param string $filename The name of the configuration file to be created.
	 * @param string $content The content to include within the configuration file.
	 * @return void
	 */
	private function add_config_file( string $filename, string $content ): void {
		if ( ! preg_match( '/^[a-z0-9_\-]+\.php$/', $filename ) ) {
			return;
		}

		$config_filepath = "{$this->install_directory}/config/includes/{$filename}";

		$full_content = "<?php\n";
		$full_content .= "namespace Eighteen73\Nebula;\n";
		$full_content .= "\n";
		$full_content .= "use Roots\WPConfig\Config;\n";
		$full_content .= "\n";
		$full_content .= trim( $content );
		$full_content .= "\n";

		file_put_contents( $config_filepath, $full_content );
	}
}
