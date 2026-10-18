# WP-CLI Commands

A collection of WP-CLI helpers for our team's development workflow.

**Important:** These are extremely opinionated to match our development preferences and may not be useful to others. Functionality may also change unexpectedly as our workflows evolve.

## Installation

Prerequisites:

- PHP 8.1 or later, with the JSON and Mbstring extensions
- Composer 2.2 or later
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

Available commands are listed below. Generally they are designed for use on our [Nebula](https://github.com/eighteen73/nebula) websites unless stated otherwise. For full usage instructions please refer to the [package docs](https://docs.eighteen73.co.uk/wordpress/wp-cli/).

* `create-site` - Create new website
* `sync` - Import the remote website's database
* `first-sync` - Initialise a local website using a remote database
* `style-guide` - Add predetermined pages of content for visual checks and sign-off

### Interactive site creation

`create-site` uses Laravel Prompts for setup questions, installation messages and the final website summary. `--yes` skips the installation confirmation, but not missing setup details or the multisite question.

For repeat installations, supply the values whose questions you want to skip:

```shell
wp eighteen73 create-site foobar \
  --hostname=foobar.test \
  --db-name=foobar \
  --db-user=root \
  --db-password='secret' \
  --admin-user=admin \
  --admin-email=admin@example.com \
  --yes
```

Supplied hostnames default to HTTPS; add `--no-https` to use HTTP. Missing values still prompt in an interactive terminal. An explicitly empty `--db-password=''` is supported. Database names accept letters, numbers, underscores and hyphens, up to 64 characters. Supplied credentials are validated without re-prompting on failure. Missing databases are created automatically when Nebula setup values are supplied; existing tables always stop installation and are never deleted, including with `--yes`.

The complete command above runs single-site setup without questions, including when standard input is not a terminal. Multisite network type selection remains interactive. Passwords supplied on the command line can appear in shell history and process listings; Nebula receives its setup values through a child-only environment variable rather than another command-line argument.

Interactive prompts support macOS, Linux and Windows with WSL. Piped input, native Windows and terminals without interactive support use plain questions instead. Missing input stops setup rather than waiting indefinitely.

Composer and npm operations show a scrolling task with up to six lines of live output, followed by a completion summary. This requires PCNTL, POSIX and an interactive terminal with coloured output enabled; otherwise logs are printed normally. Failed processes stop setup and display their full output.

Nebula is initially installed with Composer scripts disabled. Its `post-create-project-cmd` wizard then runs separately with terminal input/output attached. Supplied hostname/database values require the coordinated `nebula-tools` release with `SetupOptions` support; older releases are rejected rather than ignoring these values. Without complete setup values, the Nebula wizard requires an interactive Composer session.

### Generated project defaults

New sites target PHP 8.5 and require Composer 2.10+. Creation sets `require.php` to `~8.5.0`, Composer's platform PHP to `8.5`, adds the WP Sec Adv repository and configures non-blocking native advisory resolution. Advisory reporting remains enabled.

Imsanity is configured on the initial site with a 2560 × 2560 maximum for all upload sources, preserving aspect ratio, JPEG quality 82, BMP-to-JPEG conversion enabled and PNG-to-JPEG conversion disabled. These remain editable plugin settings. Setup does not run bulk resizing; oversized new uploads replace the originals, so retain source photography separately. Multisite subsites added later use their own/network defaults.

The default Deployer stage also generates `.gitlab-ci.yml`, `renovate.json`, `autoupdate.json`, and `SETUP.md`, with a link from the project's README. These use the agency's shared GitLab components and Renovate preset. Automatic patch/minor merging and live deployments target `main`; the initial local branch remains `develop`.

Replace the repository URL, Kinsta SSH details and live health-check URL before enabling automation. Shared `sync`/`first-sync` SSH placeholders are appended to `config/environments/development.php`, with a remote project path ending in `/public/current`. `SETUP.md` documents the remaining hosting/GitLab setup, local onboarding and daily commands. Optional sync plugin overrides are supplied as comments.

`--skip-deployer` omits the deployment, automation, sync and onboarding scaffolding. PHP and Composer defaults still apply. Existing template destination files cause setup to stop rather than being overwritten.

## Development

To test unreleased `nebula-tools` changes, add this to `create-site`:

```shell
--nebula-tools-path=/path/to/nebula-tools
```

The checkout is validated before creating the site. After Nebula is downloaded, Composer installs a mirrored copy of the local package before starting its wizard. Uncommitted source changes are included; the checkout is not modified or symlinked. Relative paths are resolved from the directory where you invoke the command.

This adds a development-only path repository to the generated site's `composer.json` and lockfile, using version `2.0.x-dev` to satisfy Nebula's existing 2.x dependency constraint. Do not deploy that configuration. Use a fresh site directory for each test; this option does not resume or remove a partially created site, or bypass the non-empty database safeguard.

For development, you'll probably want to run a local clone of this package.

```shell
wp package uninstall eighteen73/wp-cli-tools
wp package install /local/path/to/wp-cli-tools
```
