# WP-CLI Commands

A collection of WP-CLI helpers for our team's development workflow.

**Important:** These are extremely opinionated to match our development preferences and may not be useful to others. Functionality may also change unexpectedly as our workflows evolve.

## Installation

Prerequisites:

- A global copy of [WP-CLI](https://make.wordpress.org/cli/handbook/guides/installing/)

To install the package run the following command:

```shell
wp package install eighteen73/wp-cli-tools
```

## Updates

You should ensure that you are running the latest copy of this package, especially before setting up new websites.

To update the package run the following command:

```shell
wp package update
```

## Usage

Available commands are listed below. Generally they are designed for use on our [Nebula](https://github.com/eighteen73/nebula) websites unless stated otherwise. For full usage instructions please refer to the [package docs](https://docs.eighteen73.co.uk/wordpress/build-tools/wp-cli/).

* `create-site` - Create new website
* `sync` - Import the remote website's database
* `first-sync` - Initialise a local website using a remote database
* `style-guide` - Add predetermined pages of content for visual checks and sign-off

There is also a special command that may be run on a live website. It does require this package to be available in the remote environment though.

* `just-launched` - Runs useful post-launch operations on a website to prevent some common gotchas

## Development

For development, you'll probably want to run a local clone of this package.

```shell
wp package uninstall eighteen73/wp-cli-tools
wp package install /local/path/to/wp-cli-tools
```
