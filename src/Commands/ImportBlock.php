<?php

namespace Eighteen73\WP_CLI\Commands;

use WP_CLI;

class ImportBlock
{
	private array $all_blocks = [];

	public function __construct()
	{
		$this->getAvailableBlocks();
	}

	public function add()
	{
		$blocks = $this->askUserForBlocks();

		// TODO Confirm a Pulsar theme is active
		// TODO Copy each block into the theme

		$dir = get_template_directory();
		WP_CLI::line();
		foreach ($blocks as $block) {
			WP_CLI::line("Copying {$block} to {$dir}");
		}
	}

	private function getAvailableBlocks()
	{
		// TODO Replace with real logic
		$this->all_blocks = [
			'carousel',
			'carousel-slide',
		];
	}

	private function askUserForBlocks(): array
	{
		WP_CLI::line('Available blocks:');
		foreach ($this->all_blocks as $block) {
			WP_CLI::line('  ' . $block);
		}
		do {
			if (isset($valid_blocks)) {
				WP_CLI::line();
				WP_CLI::line('Invalid block(s)');
			}
			WP_CLI::line();
			WP_CLI::line('Which blocks (comma separated) would you like to add?');
			WP_CLI::out('> ');
			$blocks = strtolower(trim(fgets(STDIN)));
			$blocks = (array) preg_split('/[\s,]/', $blocks);
			$blocks = array_filter(array_unique($blocks));
			sort($blocks);
			$valid_blocks = (array) array_intersect($blocks, $this->all_blocks);
		} while (!count($blocks) || $blocks !== $valid_blocks);
		return $blocks;
	}
}
