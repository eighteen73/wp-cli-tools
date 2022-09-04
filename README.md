# WP-CLI Commands

A collection of WP-CLI helpers for our team's development workflow.

**Important:** These are extremely opinionated for our development preferences and may not be useful to others.

## Installation

_// Coming soon_

## New website setup

Our install command will install a fresh copy of WordPress (using our Nebula framework) with our Pulsar theme and other plugins that are part of our standard installation.

It will interactively ask for installation details (e.g. database credentials) as needed.

```shell
# Install under "foobar" in current directory
wp eighteen73 create foobar

# Install under "foobar", specifying full path
wp eighteen73 create /home/joebloggs/foobar
```
