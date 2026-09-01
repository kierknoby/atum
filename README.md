# Atum GUI

**NOT SUITABLE FOR PRODUCTION.**

Atum v0.1 is an early development preview. Do not install it on a production Kamailio host or rely on it as a production security boundary. Remote mode is solely for deliberately restricted access to a disposable test system or non-production Kamailio installation.

## Overview

Atum is a modular web interface for discovering and managing an existing Kamailio installation.

The current development release is deliberately read-only towards Kamailio. It discovers existing configuration and presents recognised parts of the installation without changing Kamailio configuration, databases, services or runtime state.

Atum maintains its own local state for administrator accounts, sessions, audit records and module lifecycle state.

Atum is an independent project and is not affiliated with or endorsed by the Kamailio project.

## Development Status

Atum v0.1 is a development preview and has no production-supported release or upgrade path.

Current limitations include:

- Kamailio management is read-only.
- The configuration scanner recognises a limited subset of Kamailio configuration and is not a complete parser.
- Arbitrary Kamailio or KEMI routing logic is not semantically interpreted.
- Remote development deployment supports an existing Linux Nginx or Apache installation and existing PHP-FPM; no production deployment is provided.
- No privileged Kamailio write path exists.
- Third-party Atum modules have no signing or trust system.
- The project has not undergone an independent security audit or penetration test.
- CI portability checks do not establish support for a real Kamailio installation on that operating system.

Unknown or unsupported Kamailio statements are retained as redacted, provenance-bearing unknown records. Atum does not infer meaning merely to make an object editable.

## Compatibility

Atum v0.1 is intended for development and integration testing against an existing Kamailio installation on a Unix-like host.

The current system installer has these practical limits:

- PHP 8.2 or later is required.
- `pdo_sqlite`, `session` and `openssl` PHP extensions are required.
- Full system installation currently requires Linux-style `useradd` and `groupadd` commands.
- Automatic dependency installation is implemented for APT and DNF/YUM package families.
- Ubuntu is exercised by CI for PHP and shell tests.
- macOS is exercised by CI for PHP portability only. A real Kamailio installation on macOS has not been validated and the system installer is not claimed to work there.
- BSD, openSUSE, Alpine, Arch and other Unix-like systems may be recognised during pre-flight but are not currently validated for full installation.
- Windows is not a target for the local installation model.

Kamailio supporting an operating system does not by itself mean Atum has been tested or is supported on that operating system.

## Requirements

For a normal development installation:

- an existing Kamailio installation and readable root configuration;
- PHP 8.2 or later;
- PDO SQLite;
- PHP session support;
- PHP OpenSSL support;
- root access for system installation and removal;
- a separate test or non-production host.

The built-in development web server requires no separate Apache or Nginx configuration.

Remote development testing additionally requires a systemd-managed Linux host, an existing Nginx or Apache installation, matching PHP-FPM, and the `openssl` command. Atum does not install an unrelated web stack automatically.

## Installing

**Do not run the v0.1 installer on a production Kamailio system.**

Run the non-destructive pre-flight first:

```sh
sudo ./install --check
```

Pre-flight reports the detected operating system, package manager, service manager, web server, PHP version and extensions, Kamailio binary/version/configuration, and database URL schemes found through recursive Kamailio configuration discovery.

A system installation requires explicit acknowledgement that this is a development release:

```sh
sudo ./install --development
```

Available installer options:

```text
--check                     pre-flight only; make no changes
--no-deps                   do not install missing operating-system packages
--yes, -y                   answer yes to dependency package prompts
--prefix=/path              override the application path
--kamailio-config=/path     use an explicit Kamailio configuration
--allow-no-kamailio         development install without Kamailio
--development               acknowledge that v0.1 is not suitable for production
--remote                    configure explicit remote development HTTPS access
--listen-address=address    listen IP for remote mode (default 0.0.0.0)
--listen-port=port          HTTPS port for remote mode (default 8443)
--web-server=name           select nginx or apache when both are installed
```

The installer does not install, replace or reconfigure Kamailio.

During installation it:

- discovers the existing Kamailio configuration tree recursively;
- records SHA-256 hashes of statically recognised literal Kamailio configuration files before installation;
- records the partial scope and confidence of that snapshot and verifies the same recognised set before commit;
- installs only missing baseline Atum dependencies when package installation is permitted;
- creates a dedicated local `atum` account for the current Linux system-install path;
- creates the first Atum administrator interactively;
- stores Atum application, configuration and state separately from Kamailio;
- records Atum-owned host changes in a root-owned installation ledger used for rollback and removal;
- creates a root-owned provisional transaction journal before host mutation so an interrupted install can be recovered without guessing;
- copies only files named by `install-files.txt`, not arbitrary checkout contents;
- refuses to overwrite an existing Atum application, configuration or state tree.

Database schemes discovered in Kamailio configuration are reported for capability detection. Database-specific PHP drivers are not installed merely because Kamailio uses that database; an Atum module must declare a driver when it actually requires one.

## Remote Development Test Installation

**NOT SUITABLE FOR PRODUCTION.**

On a non-production Linux host that already has Kamailio, Nginx or Apache, and matching PHP-FPM:

```sh
sudo ./install --check
sudo ./install --development --remote
```

Remote mode creates an Atum-specific vhost/drop-in, a dedicated PHP-FPM pool running as `atum`, and a 30-day self-signed development certificate. It exposes only `/usr/share/atum/public`, requires HTTPS, and defaults to `0.0.0.0:8443`. Override the literal listen IP or port with `--listen-address=...` and `--listen-port=...`.

Open `https://PUBLIC_IP:8443/`. The browser will warn because the generated certificate is self-signed; encryption is provided, but trusted server identity is not. Atum does not discover the public IP and does not change UFW, firewalld, iptables, nftables or a DigitalOcean Cloud Firewall. Permit the selected TCP port manually only from the trusted public source IP or CIDR used for administration.

Preview and remove the complete Atum-owned deployment with:

```sh
sudo atum-uninstall --check
sudo atum-uninstall
```

Removal deletes only ledger-recorded Atum files whose ownership/content still matches, reloads the existing shared services, and leaves Kamailio and pre-existing web/PHP installations in place.

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

Discovery is conservative. Results carry syntactic or conditional confidence, and unsupported/non-literal configuration is retained as redacted unknown data. The scanner does not prove the complete effective configuration.

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

## Uninstalling

Preview removal before making changes:

```sh
sudo atum-uninstall --check
```

Remove Atum:

```sh
sudo atum-uninstall
```

The uninstaller uses the root-owned installation ledger. It does not infer ownership from conventional filenames or paths.

Atum-owned directory trees contain a unique installation marker. The marker must match the installation ledger before recursive deletion is allowed.

Normal removal is intended to remove:

- Atum application files;
- Atum configuration;
- Atum state, including local users, sessions and audit data;
- Atum-owned CLI links;
- the local Atum system account/group if Atum created them;
- exact Atum-owned host integrations recorded by the installer;
- operating-system dependencies added only for Atum where the package manager can prove that their removal will not affect unrelated installed software.

Atum does not remove or restore Kamailio configuration merely because the GUI is removed. A deliberate operational change committed to Kamailio through a future Atum management module belongs to Kamailio and must survive removal of Atum.

In v0.1, dependencies installed through DNF/YUM are retained during uninstall because safe reverse-dependency proof is not yet implemented for that path.

To retain all operating-system dependencies:

```sh
sudo atum-uninstall --keep-dependencies
```

Clean removal is an operational restoration goal, not forensic erasure. Package-manager logs, system journal entries and shell history may record that Atum was previously installed.

## Validation

Run the repository checks before committing changes:

```sh
sh -n install
sh -n uninstall
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php utests/run.php
sh utests/http.sh
sudo sh utests/system-lifecycle.sh
```

CI runs Composer validation, PHP and shell syntax checks, JavaScript syntax checks, SQLite-backed security/framework tests, HTTP boundary tests and an isolated Linux installer/uninstaller test. Linux exercises PHP 8.2, 8.3 and 8.4; macOS exercises PHP 8.3 portability. CI success is not equivalent to Kamailio integration or production certification.

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
