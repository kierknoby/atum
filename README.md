# Atum GUI

**NOT SUITABLE FOR PRODUCTION.**

Atum v0.1 is an early development preview. Do not install it on a production Kamailio host or rely on it as a production security boundary. Remote mode is solely for deliberately restricted access to a disposable test system or non-production Kamailio installation.

## Overview

Atum is a modular web interface for an existing Kamailio installation. The current development release provides read-only discovery and visualisation of recognised Kamailio configuration together with Atum's own administration functions.

Atum maintains its own local state for administrator accounts, sessions, audit records and module lifecycle state.

Atum is an independent project and is not affiliated with or endorsed by the Kamailio project.

## Development Status

Atum v0.1 is a development preview and has no production-supported release or upgrade path.

The implemented limitations are listed under [Current Limitations](#current-limitations).

## Compatibility

Atum v0.1 is intended for development and integration testing against an existing Kamailio installation on a separate, non-production host.

- PHP 8.2 or later is required.
- `pdo_sqlite`, `session` and `openssl` PHP extensions are required.
- Full system installation requires Linux-style `useradd` and `groupadd` commands.
- Automatic dependency installation is implemented for APT and DNF/YUM package families.
- Remote development installation requires a systemd-managed Linux host, Nginx or Apache, and PHP-FPM matching the PHP CLI version.
- Ubuntu is exercised by CI for PHP, shell, HTTP and isolated installation lifecycle tests.
- macOS is exercised by CI for PHP portability only. A real Kamailio installation on macOS has not been validated and the system installer is not claimed to work there.
- BSD, openSUSE, Alpine, Arch and other Unix-like systems may be recognised during pre-flight but are not currently validated for full installation.
- Windows is not a target for the local installation model.

Kamailio supporting an operating system does not by itself mean Atum has been tested or is supported on that operating system.

## Requirements

Atum requires:

- an existing Kamailio installation and readable root configuration;
- PHP 8.2 or later;
- PDO SQLite, session and OpenSSL PHP extensions;
- `flock`, `groupadd` and `useradd` for system installation;
- root access for system installation and removal;
- a separate test or non-production host.

Installing from GitHub also requires Git. Check for it with:

```sh
git --version
```

On an APT host, install it with `sudo apt install git`. On a DNF host, use `sudo dnf install git`; older YUM hosts use `sudo yum install git`. These commands do not extend Atum's compatibility claims.

Remote development access also requires:

- systemd;
- an existing Nginx or Apache installation;
- PHP-FPM with the same major and minor version as the PHP CLI;
- the `openssl` command;
- deliberate host and cloud firewall rules for the selected administration source address or network.

Atum does not install Kamailio or an unrelated web-server stack. The local built-in development server does not require Nginx, Apache or PHP-FPM and remains restricted to loopback.

## Installing

**Do not run the v0.1 installer on a production Kamailio system.**

### Option 1: Install from GitHub

```sh
cd ~
git clone https://github.com/kierknoby/atum.git
cd atum
sudo ./install --check --remote
sudo ./install --development --remote
```

`sudo ./install --check --remote` validates the baseline and remote Linux, systemd, web-server, PHP-FPM, OpenSSL and listen-address requirements. It does not require `--development` and makes no changes. `sudo ./install --development --remote` performs the installation and configures remote HTTPS access.

The checkout is only the installation source. The installer does not modify it and copies only files listed in `install-files.txt`. Git metadata, tests, untracked files and other checkout content are not installed.

### Option 2: Install from a local copy

From the root of an existing Atum source copy, run:

```sh
sudo ./install --check
sudo ./install --development
```

The second command installs Atum for loopback-only development access. Add `--remote` to configure remote development access as described below.

Pre-flight reports the detected operating system, package manager, service manager, web server, PHP version and extensions, Kamailio binary/version/configuration, and database URL schemes found within the statically recognisable literal include/import scope.

### Remote development installation

Remote installation is for an existing supported Kamailio test host and requires explicit use of `--development`:

```sh
sudo ./install --check --remote
sudo ./install --development --remote
```

It remains **NOT SUITABLE FOR PRODUCTION.** The installer uses an existing supported Nginx or Apache installation, creates a dedicated `atum` PHP-FPM pool, and exposes only Atum's `public/` directory over HTTPS. When Atum owns the TLS setup, it generates a self-signed development certificate. The default listen port is 8443.

After installation, open:

```text
https://PUBLIC_IP:8443/
```

The browser will warn because the generated certificate is self-signed.

Atum does not change firewall rules and does not alter Kamailio merely by being installed. On a DigitalOcean Droplet, allow the selected Atum port in the DigitalOcean Cloud Firewall or host firewall only from the administrator's trusted public IP or CIDR. DigitalOcean is not required, and Atum does not use its API.

If both supported web servers are installed, select one explicitly:

```sh
sudo ./install --check --remote --web-server=nginx
sudo ./install --development --remote --web-server=nginx
```

Remote mode listens on `0.0.0.0:8443` by default. Use `--listen-address` and `--listen-port` to select another literal address or port.

Available installer options:

```text
--check                     pre-flight only; make no changes
--no-deps                   do not install missing operating-system packages
--yes, -y                   answer yes to dependency package prompts
--prefix=/path              override the application path
--kamailio-config=/path     use an explicit Kamailio configuration
--allow-no-kamailio         development install without Kamailio
--development               acknowledge that v0.1 is not suitable for production
--remote                    check or configure remote development HTTPS access
--listen-address=address    listen IP for remote mode (default 0.0.0.0)
--listen-port=port          HTTPS port for remote mode (default 8443)
--web-server=name           select nginx or apache when both are installed
```

The installer does not install, replace or reconfigure Kamailio. It does not modify or remove the Git checkout.

During installation it:

- follows statically recognisable literal Kamailio includes and imports recursively from the selected root configuration;
- records SHA-256 hashes of statically recognised literal Kamailio configuration files before installation;
- records the partial scope and confidence of that snapshot and verifies the same recognised set before commit;
- installs only missing baseline Atum dependencies when package installation is permitted;
- creates a dedicated local `atum` account for the current Linux system-install path;
- creates the first Atum administrator interactively;
- stores Atum application, configuration and state separately from Kamailio;
- records Atum-owned host changes in a root-owned installation ledger used for rollback and removal;
- creates a root-owned provisional transaction journal before host mutation so an interrupted install can be recovered without guessing;
- treats `install-files.txt` as the authoritative installed application file set;
- excludes `.git`, arbitrary tracked or untracked checkout files, tests and development artefacts unless they are explicitly listed in that manifest;
- refuses to overwrite an existing Atum application, configuration or state tree.

Database schemes discovered in Kamailio configuration are reported for capability detection. Database-specific PHP drivers are not installed merely because Kamailio uses that database; an Atum module must declare a driver when it actually requires one.

## Updating Atum

Atum v0.1 has no supported in-place update path. `git pull` updates only the source checkout. It does not update the installed application under `/usr/share/atum`.

Do not use `git pull` in `/usr/share/atum`, copy a new checkout over it, or replace installed files manually. Atum v0.1 does not provide state migration or restore tooling, and uninstall removes Atum-owned state. There is therefore no documented update workaround that preserves an existing installation.

## Uninstalling

Preview removal:

```sh
sudo atum-uninstall --check
```

Remove Atum:

```sh
sudo atum-uninstall
```

The uninstaller uses the root-owned installation ledger and matching installation markers. It does not infer ownership from conventional paths.

Removal includes Atum application files, configuration, local users, sessions, audit data, module state, CLI links, the Atum account/group when Atum created them, and exact remote-development integrations recorded by the installer. Dependency removal is conservative. Use `sudo atum-uninstall --keep-dependencies` to retain every operating-system dependency.

The uninstaller does not remove the Git checkout, `.git`, untracked checkout files, shared Nginx/Apache/PHP-FPM installations, or Kamailio configuration. It does not revert legitimate Kamailio configuration changes. DNF/YUM dependencies introduced for Atum are retained because safe reverse-dependency proof is not implemented for that path.

## Repository-local Development

Atum can also be run directly from a repository checkout without a system installation:

```sh
export ATUM_STATE_DIR="$PWD/var"
export KAMAILIO_CONFIG="$PWD/examples/kamailio.cfg"
./bin/atum module:bootstrap
./bin/atum user:create admin
./bin/atum serve
```

The built-in server listens on loopback only:

```text
127.0.0.1:8090
```

Open:

```text
http://127.0.0.1:8090
```

For remote access through the loopback development server, use an SSH tunnel:

```sh
ssh -L 8090:127.0.0.1:8090 user@kamailio-host
```

`atum serve` continues to reject every non-loopback bind. Remote installation uses the host web server and PHP-FPM; it never publishes PHP's built-in server.

## Current Modules

Atum currently includes:

- **Framework**: core services, authentication, state, module loading, permissions, AJAX dispatch and views.
- **Dashboard**: Atum and host overview.
- **Kamailio Discovery**: read-only discovery of existing Kamailio configuration.
- **Module Admin**: Atum module inventory and dependency-aware install, uninstall, enable and disable controls.
- **Administrators**: local Atum administrator accounts and roles.
- **Audit Log**: authentication and Atum management audit records.

These are Atum modules. They do not imply that equivalent Kamailio management capabilities are complete.

## Kamailio Discovery

The Discovery module currently identifies:

- configuration files and recursive `include_file` / `import_file` relationships;
- loaded Kamailio modules;
- module parameters;
- SIP listeners;
- preprocessor defines;
- request, named, failure, branch, reply, send and event routes;
- basic KEMI indicators;
- database URL schemes used for dependency and capability discovery;
- source file and line provenance for discovered objects.

Discovery fails closed for configuration values. Only a small positive classification of non-secret scalar tuning values is returned; other parameter and define values are redacted before reaching the GUI, AJAX response or CLI output.

Discovery is conservative. Results carry syntactic or conditional confidence. Unknown statements retain useful type and provenance information without exposing their raw content. The scanner does not prove the complete effective configuration or semantically understand every custom syntax.

## Existing Installation Authority

Kamailio remains authoritative for the adopted installation.

Atum is intended to work with the representation already in use. A future module may, for example, discover that `dispatcher` data comes from a database on one system and a list file on another. Structured editing should use the existing mechanism where it can be identified and changed safely.

A discovered object may eventually be treated as:

- **Managed**: Atum understands the object and backing mechanism sufficiently for structured changes.
- **Recognised**: Atum can present the object but cannot safely rewrite it.
- **Custom**: Atum can show source/provenance but does not claim to understand the logic.

The v0.1 Discovery module does not yet provide complete semantic classification or Kamailio write operations.

## Module Structure

Atum is written in PHP and JavaScript and uses a modular structure deliberately familiar to FreePBX module developers.

```text
atum/
├── admin/
│   ├── libraries/
│   ├── modules/
│   └── views/
├── public/
├── bin/
├── config/
├── docs/
├── examples/
├── utests/
├── install
└── uninstall
```

A normal module can contain:

```text
admin/modules/discovery/
├── Discovery.class.php
├── module.xml
├── install.php
├── uninstall.php
├── page.discovery.php
├── views/
└── assets/
```

`module.xml` declares module identity, version, navigation, permissions, Atum module dependencies, minimum PHP version and required PHP extensions.

Module access follows a BMO-style service pattern:

```php
$report = Atum::Discovery()->scan();
$modules = Atum::create()->Modules->getActiveModules();
```

Module PHP is trusted application code. v0.1 has no third-party module signature or trust mechanism.

See [Architecture](docs/ARCHITECTURE.md) for framework, module and privilege boundaries.

## Atum State

Atum state is separate from Kamailio state.

Atum owns data such as:

- local Atum users;
- sessions;
- audit records;
- module installation and enable/disable state;
- installation ownership metadata;
- future Atum-only discovery/cache state.

An installed Atum application must not make a working Kamailio installation depend on Atum remaining installed.

## Security Model

Atum v0.1 is not production-ready. The controls below describe the current development scaffold, not a security certification.

Current controls include:

- local Atum accounts separate from Kamailio subscriber credentials;
- password hashing through PHP `password_hash()`, using Argon2id where available;
- cookie-only sessions with idle and absolute expiry;
- session ID rotation after login;
- server-side authenticated-user revalidation;
- CSRF validation for state-changing browser requests;
- source/username and source-address login throttling;
- administrator and viewer roles;
- server-side page and AJAX permission checks;
- Content Security Policy and related response headers;
- generic browser/AJAX errors instead of raw exception details;
- an application-writable SQLite audit log for authentication and Atum management operations; it is diagnostic, not tamper-proof;
- a dedicated `public/` document root;
- authenticated, allowlisted module asset serving;
- loopback-only enforcement for the built-in server;
- rejection of insecure non-loopback HTTP requests;
- discovery-time secret redaction;
- root-owned installation/removal metadata that the web process cannot modify.

The Atum web process has no supported privileged Kamailio write path in v0.1.

Read [SECURITY.md](SECURITY.md) before installing or modifying authentication, AJAX, local command execution or host integration.

## Installation and Host Changes

Atum follows one installation rule:

> **No untracked host mutations.**

Application, configuration and state are kept in Atum-owned paths. The installer records the host artefacts it creates so failed installation and later removal use the same ownership information.

Future module lifecycle hooks must not independently create users, services, packages, web-server configuration, firewall rules or other host artefacts. Host changes require a lifecycle mechanism that can record, validate and reverse them.

See [Installation Lifecycle](docs/INSTALLATION-LIFECYCLE.md).

## CLI

```text
atum status
atum system:check
atum module:bootstrap
atum module:list
atum module:install <rawname>
atum module:uninstall <rawname>
atum module:enable <rawname>
atum module:disable <rawname>
atum user:create <username> [admin|viewer]
atum audit:list [count]
atum discovery:scan [config]
atum discovery:dependencies [config]
atum serve [host:port]
```

System installation and removal require root. Atum application commands should otherwise use the dedicated Atum account where appropriate, for example:

```sh
sudo -u atum atum status
```

## Validation

Run the repository checks before committing changes:

```sh
sh -n install
sh -n uninstall
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php utests/run.php
php utests/remote-deployment.php
sh utests/installer-preflight.sh
sh utests/http.sh
```

The root-required `utests/system-lifecycle.sh` suite creates and removes the system `atum` account and global CLI links. Run it only in an ephemeral or disposable Linux test environment:

```sh
sudo env "PATH=$PATH" sh utests/system-lifecycle.sh
```

CI runs Composer validation, PHP and shell syntax checks, JavaScript syntax checks, SQLite-backed security/framework tests, remote pre-flight tests, HTTP boundary tests and the disposable Linux installer/uninstaller test. Linux exercises PHP 8.2, 8.3 and 8.4; macOS exercises PHP 8.3 portability. CI success is not equivalent to Kamailio integration or production certification.

## Current Limitations

- Kamailio is read-only from Atum.
- Discovery is incomplete and line-oriented in important areas.
- KEMI and arbitrary routing logic may be detected without being understood.
- No supported production web deployment exists.
- No privileged Kamailio configuration/write helper exists.
- No third-party module signing/trust system exists.
- Module PHP executes as trusted Atum code.
- Full system installation has not been validated across all operating systems supported by Kamailio.
- macOS has CI portability coverage only.
- RPM-family dependency removal is conservative and retains packages introduced by Atum in v0.1.
- There is no stable upgrade guarantee for development-preview state or module schema.

## AI-Assisted Contributions and Disclosure

Generative AI assistance must be disclosed in every commit containing AI-assisted changes:

```text
Assisted-by: AGENT_NAME:MODEL_VERSION
```

For example:

```text
Assisted-by: ChatGPT:gpt-5.6-sol
```

The human contributor remains solely responsible for the contribution. AI tools must not be listed as co-authors.

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

Atum is licensed under the GNU General Public License v3.0 or later (`GPL-3.0-or-later`). See [LICENSE](LICENSE).
