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
- Remote development installation requires a systemd-managed Linux host and PHP 8.2 or newer. Atum can offer missing native PHP-FPM and OpenSSL dependencies on supported APT and DNF/YUM systems without adding third-party repositories. Automatic installation of a new Nginx/Apache web server is currently limited to APT-family systems.
- Ubuntu is exercised by CI for PHP, shell, HTTP and isolated installation lifecycle tests.
- The current development-preview lifecycle has also been exercised end-to-end on Debian 13 with an existing Kamailio 6.0.1 host. That test covered bootstrap acquisition, native PHP/Nginx provisioning, HTTPS application access, uninstall preview, removal of Atum-added APT dependencies, and successful Atum removal while Kamailio remained operational. This is integration evidence, not production certification or a claim of broad Debian support.
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

The recommended GitHub command uses `curl` to download the small bootstrap file.
The bootstrap then installs Git from the host's supported native package manager
when Git is missing, after confirmation. To manage Git yourself, check for it
with:

```sh
git --version
```

On an APT host, install it with `sudo apt install git`. On a DNF host, use `sudo dnf install git`; older YUM hosts use `sudo yum install git`. These commands do not extend Atum's compatibility claims.

Remote development access also requires:

- systemd;
- Nginx or Apache (an existing server is reused; Nginx is selected when neither exists);
- PHP-FPM with the same major and minor version as the PHP CLI;
- the `openssl` command;
- deliberate host and cloud firewall rules for the selected administration source address or network.

Atum does not install Kamailio or add third-party package repositories. In remote development mode it can install the selected native web server and matching PHP-FPM when permitted on APT-family systems; it never installs a second supported server when one already exists. DNF/YUM systems may reuse an existing supported Nginx/Apache installation, but require the administrator to install one before remote mode is configured. The local built-in development server does not require Nginx, Apache or PHP-FPM and remains restricted to loopback.

## Installing

**Do not run the v0.1 installer on a production Kamailio system.**

### Quick start

1. **Install Atum.**

   ```sh
   curl -fsSL https://raw.githubusercontent.com/kierknoby/atum/main/bootstrap | sudo sh
   ```

2. **Permit TCP/8443 from the current administrator address.** On a host using UFW that you administer over SSH:

   ```sh
   ADMIN_IP="${SSH_CLIENT%% *}"
   sudo ufw allow from "$ADMIN_IP" to any port 8443 proto tcp
   ```

   `ADMIN_IP` is the source address of the current SSH connection. This UFW plus SSH example is not universal: Atum never modifies firewall rules automatically, and administrators using another host firewall or a cloud/provider firewall must apply an equivalent policy for their trusted administration IP or CIDR.

3. **Open Atum.**

   ```text
   https://SERVER_IP:8443/
   ```

   Prefer the exact URL printed by the installer. The self-signed development certificate encrypts traffic, but it does not establish browser trust in the server's identity, so the browser will display a certificate warning.

4. **Sign in** with the administrator account created during installation.

### How the bootstrap works

The bootstrap downloads a clean temporary checkout, prints the exact Git commit
being installed, and invokes the existing installer once with
`--development --remote`. It removes the checkout on success, failure or an
interrupt. Pass additional installer arguments through `sh -s --`; for example:

```sh
curl -fsSL https://raw.githubusercontent.com/kierknoby/atum/main/bootstrap | sudo sh -s -- --verbose
```

`--yes`, `--verbose`, `--check`, `--no-deps` and other installer arguments are
forwarded unchanged. `--check` reaches the normal installer pre-flight and makes
no Atum changes.

If Git is missing, the bootstrap can install it through APT, DNF or YUM. It asks
first unless `--yes` was supplied, and refuses when `--no-deps` was supplied.
This acquisition happens before Atum's installation ledger exists, so Git is a
retained administrator prerequisite and is not removed by `atum-uninstall`.
The bootstrap never adds repositories, changes firewall rules or duplicates any
installer lifecycle operation.

All side-effectful bootstrap logic is inside one shell function, with its sole
invocation on the final line. A stream truncated before that invocation can at
most define part or all of the function; it cannot install a package, create a
checkout or invoke Atum. To audit the complete file before running anything as
root, use this more conservative alternative:

```sh
curl -fsSL https://raw.githubusercontent.com/kierknoby/atum/main/bootstrap -o atum-bootstrap
less atum-bootstrap
sudo sh ./atum-bootstrap --check
rm -f ./atum-bootstrap
```

HTTPS protects the download in transit, but the GitHub `main` URL is mutable. The
bootstrap is not signed or cryptographically pinned, and this is not a signed
release mechanism. It makes the selected source revision visible by printing its
full commit identifier before execution.

The detailed engineering view reports the operating system, package/service
managers, runtime, web server, Kamailio configuration, paths, bind address and
missing requirements. A normal installation uses a shorter operator-facing
summary and staged progress while performing the installation.

Use `--yes` for deterministic non-interactive package approval. Add `--verbose` when diagnosing an install to show the detailed Atum pre-flight view and additional validation/service diagnostics:

```sh
curl -fsSL https://raw.githubusercontent.com/kierknoby/atum/main/bootstrap | sudo sh -s -- --yes
curl -fsSL https://raw.githubusercontent.com/kierknoby/atum/main/bootstrap | sudo sh -s -- --verbose
```

A normal remote run is deliberately concise and follows completed installer boundaries rather than percentages or animation:

```text
Atum 0.1 Development Preview
NOT SUITABLE FOR PRODUCTION

Kamailio 6.0.1 detected
Configuration: /etc/kamailio/kamailio.cfg
Web server: Nginx (will be installed)
HTTPS bind: 0.0.0.0:8443

Preparing remote Atum installation

[1/6] Checking host
      done
[2/6] Installing system dependencies
[native package-manager stdout/stderr appears here]
      done
[6/6] Creating initial administrator

Username [admin]:
Password:
Confirm password:
```

Detected versions and selected/reused components reflect the actual host. Package decisions are described as capabilities, and native package-manager output is streamed directly in normal and verbose modes. No cursor control or colour support is required.

Normal package installation shows the package manager's own progress, warnings and errors between Atum's stage boundaries. `--verbose` is not required to see that output; it adds detailed Atum pre-flight and validation/service diagnostics. The final output uses a clearly delimited `ATUM INSTALLATION COMPLETE` block containing the administrator name, prominent access URL in remote mode, verified integration summary, warnings and removal-preview command.

The checkout is only the installation source. The installer does not modify it and copies only files listed in `install-files.txt`. Git metadata, tests, untracked files and other checkout content are not installed.

### Manual installation

#### Git checkout

Keep a source checkout when developing or when you want to choose and inspect a
revision with Git yourself:

```sh
cd ~
git clone https://github.com/kierknoby/atum.git
cd atum
sudo ./install --check --remote
sudo ./install --development --remote
```

The checkout remains in place and is not managed by Atum.

#### Local copy

From the root of an existing Atum source copy, run:

```sh
sudo ./install --check
sudo ./install --development
```

The second command installs Atum for loopback-only development access. Add `--remote` to configure remote development access as described below.

Pre-flight reports the detected operating system, package manager, service manager, web server, PHP version and extensions, Kamailio binary/version/configuration, and database URL schemes found within the statically recognisable literal include/import scope.

### Remote deployment details

Remote installation is for an existing supported Kamailio test host and requires explicit use of `--development`:

```sh
sudo ./install --check --remote
sudo ./install --development --remote
```

It remains **NOT SUITABLE FOR PRODUCTION.** The installer reuses an existing supported Nginx or Apache installation; when neither exists, it selects Nginx by default and can offer to install it from the host's normal package repositories. It creates a dedicated `atum` PHP-FPM pool and exposes only Atum's `public/` directory over HTTPS. It validates the generated PHP-FPM and web-server configuration before activating the endpoint, and activates it only after the initial administrator exists and Atum state permissions are normalized. When Atum owns the TLS setup, it generates a self-signed development certificate. The default listen port is 8443. It does not modify firewall rules.

Normal installation output reports the verified bind and renders the safest useful URL available. For an explicit address it uses that address. For a wildcard bind it prefers the server address from the current SSH connection, otherwise it uses a single unambiguous non-loopback host address. If no address is clearly preferable it prints a neutral placeholder:

```text
https://<server-address>:8443/
```

IPv6 literals are bracketed correctly. The installer does not use an external address-discovery service and does not claim that a host address is publicly reachable. The browser will warn because the generated development certificate is self-signed.

Atum does not alter Kamailio merely by being installed.

If both supported web servers are installed, select one explicitly:

```sh
sudo ./install --check --remote --web-server=nginx
sudo ./install --development --remote --web-server=nginx
```

Remote mode listens on `0.0.0.0:8443` by default. Use `--listen-address` and `--listen-port` to select another literal address or port.

### Installer options

Available installer options:

```text
--check                     pre-flight only; make no changes
--no-deps                   do not install missing operating-system packages
--yes, -y                   answer yes to dependency package prompts
--verbose                   show detailed Atum pre-flight/validation diagnostics
--prefix=/path              override the application path
--kamailio-config=/path     use an explicit Kamailio configuration
--allow-no-kamailio         development install without Kamailio
--development               acknowledge that v0.1 is not suitable for production
--remote                    check or configure remote development HTTPS access
--listen-address=address    listen IP for remote mode (default 0.0.0.0)
--listen-port=port          HTTPS port for remote mode (default 8443)
--web-server=name           select nginx or apache when both are installed
```

### Installation behavior and ownership

The installer does not install, replace or reconfigure Kamailio. It does not modify or remove the Git checkout.

During installation it:

- follows statically recognisable literal Kamailio includes and imports recursively from the selected root configuration;
- records SHA-256 hashes of statically recognised literal Kamailio configuration files before installation;
- records the partial scope and confidence of that snapshot and verifies the same recognised set before commit;
- installs only missing baseline dependencies from supported native APT or DNF/YUM repositories when package installation is permitted; APT can also install the selected remote-development web server, while DNF/YUM requires an existing one; `--no-deps` prevents this and `--yes` permits non-interactive confirmation;
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

### Normal removal

1. **Preview removal.**

   ```sh
   sudo atum-uninstall --check
   ```

2. **Remove Atum.**

   ```sh
   sudo atum-uninstall
   ```

3. **Remove the documented UFW rule, if you created it.**

   ```sh
   ADMIN_IP="${SSH_CLIENT%% *}"
   sudo ufw delete allow from "$ADMIN_IP" to any port 8443 proto tcp
   ```

   This deletes the administrator-created example rule. Atum does not delete it because Atum never created or owned it.

### Removal details

The preview validates the installation ledger and shows the application, configuration and state trees; CLI links; whether the Atum account and group were created by Atum; recorded remote web-server, PHP-FPM and TLS integration; and `Packages added`. Review this plan before removal.

Normal interactive removal asks for confirmation. The uninstaller uses the root-owned installation ledger and matching installation markers; it does not infer ownership from conventional paths.

Removal includes Atum application files, configuration, local users, sessions, audit data, module state, CLI links, the Atum account/group when Atum created them, and exact remote-development integrations recorded by the installer.

APT dependency removal is conservative. Atum records packages that were absent before installation and introduced by Atum. During uninstall it first simulates purging that recorded set. It purges those packages only when APT shows that no package outside the recorded set would be removed; otherwise it retains the dependencies rather than risk unrelated software. Consequently, an Nginx/PHP/PHP-FPM stack introduced solely for Atum may be removed. A pre-existing or shared Nginx, Apache or PHP-FPM installation is not treated as Atum-owned merely because Atum used it.

On DNF/YUM systems, v0.1 retains Atum-added dependencies because safe reverse-dependency proof is not implemented. To remove Atum while retaining all operating-system dependencies on any supported package family, use:

```sh
sudo atum-uninstall --keep-dependencies
```

Git is retained too. If the bootstrap installed Git because it was not already present, Git remains after Atum is uninstalled because it is an acquisition prerequisite installed before the lifecycle ledger exists, not an Atum runtime dependency owned by that ledger. The uninstaller also does not remove a Git checkout, `.git`, or untracked checkout files.

### Expected post-uninstall state

After a successful normal uninstall:

- `/usr/share/atum`, `/etc/atum` and `/var/lib/atum` are removed;
- the Atum CLI links are removed;
- the Atum account and group are removed if Atum created them;
- recorded Atum web-server, PHP-FPM and TLS integration is removed;
- safely removable APT dependencies introduced solely for Atum are removed unless `--keep-dependencies` was used;
- Git may remain;
- Kamailio remains installed and configured; Atum does not remove or restore Kamailio configuration; and
- package-manager logs, journal entries and shell history may naturally remain.


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

Discovery also presents a conservative system interpretation above the detailed inventory. It correlates positively discovered listeners, recognised modules, named routes and include provenance to describe available signalling and routing support. A module load alone is presented as available support, not proof of active use; stronger wording requires corroborating route evidence. Custom routes are grouped by configuration component but their internals are not interpreted.

For a growing, statically recognised subset of common routing constructs, Discovery presents existing Kamailio installations through operator-oriented views: an overview, call flow, connectivity, routing, media, and access/registration. Technical configuration evidence remains available for verification. It shows route entry points, recognised method/in-dialog/IP conditions, static route calls, reply/failure/branch wiring, high-value SIP actions and relay or local-reply termination points. Arguments and custom statements remain redacted. This is static, conservative configuration interpretation, not a compiler or observation of live SIP traffic; arbitrary logic, dynamic route targets and runtime-dependent behavior may remain custom or unresolved. The adopted Kamailio configuration remains authoritative.

Discovery is conservative. Results carry syntactic or conditional confidence. Unknown statements retain useful type and provenance information without exposing their raw content. The scanner does not prove the complete effective configuration or semantically understand every custom syntax, so absence statements mean only that no recognised evidence was found in the scanned configuration.

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
sh utests/installer-presentation.sh
sh utests/bootstrap.sh
sudo env "PATH=$PATH" sh utests/web-server-provisioning.sh
sh utests/installer-credentials.sh
sh utests/http.sh
```

The root-required `utests/system-lifecycle.sh` suite creates and removes the system `atum` account and global CLI links. Run it only in an ephemeral or disposable Linux test environment:

```sh
sudo env "PATH=$PATH" sh utests/system-lifecycle.sh
```

The credential suite uses an isolated application-level fixture. It exercises the real terminal input path without creating a system account or global CLI links.

CI runs Composer validation, PHP and shell syntax checks, JavaScript syntax checks, SQLite-backed security/framework tests, remote pre-flight and web-provisioning tests, isolated credential terminal tests, HTTP boundary tests and the disposable Linux installer/uninstaller test. Linux exercises PHP 8.2, 8.3 and 8.4; macOS exercises PHP 8.3 portability. CI success is not equivalent to Kamailio integration or production certification.

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
