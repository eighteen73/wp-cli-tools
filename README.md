# WP-CLI Commands

A collection of WP-CLI helpers for our team's development workflow.

**Important:** These are extremely opinionated for our development preferences and may not be useful to others.

## Installation

Prerequisites:

- A global copy of [WP-CLI](https://make.wordpress.org/cli/handbook/guides/installing/)

To install the package run the following command:

```shell
wp package install eighteen73/wp-cli-tools
```

## Updates

You should ensure that you are running the latest copy of the this package, especially before setting up new websites.

To update the package run the following command:

```shell
wp package update
```

## Available Commands

### `create-site`: Create new website

Our install command will install a fresh copy of WordPress using our [Nebula](https://github.com/eighteen73/nebula) framework. It will also install our [Pulsar](https://github.com/eighteen73/pulsar) theme and other plugins that are part of our standard installation.

It will interactively ask for installation details (e.g. database credentials) as needed.

```shell
# Install under "foobar" in current directory
wp eighteen73 create-site foobar

# Install under "foobar", specifying full path
wp eighteen73 create-site /home/joebloggs/foobar
```
