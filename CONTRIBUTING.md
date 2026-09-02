# Contributing to Atum

**NOT SUITABLE FOR PRODUCTION.**

Atum v0.1 is an early development project. Contributions should preserve the existing Kamailio installation, Atum's module boundaries and the security/installation rules described in this repository.

## Validation

Run before submitting a change:

```sh
sh -n bootstrap
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

Security/state tests fail rather than skip when PDO SQLite is unavailable. The HTTP test requires loopback listener access.

The system lifecycle test requires root and creates and removes the system `atum` account and global CLI links. Run it only in an ephemeral or disposable Linux test environment, never on a normal workstation or persistent server:

```sh
sudo env "PATH=$PATH" sh utests/system-lifecycle.sh
```

Application, state, configuration, remote integration and service effects are directed to temporary fixtures, but the account and global link checks intentionally exercise the real system namespaces.

A change that requires a new PHP extension must declare it in the relevant module's `module.xml` rather than assuming the extension exists.

## Module Design

- Keep functionality in the module that owns the Kamailio or Atum concept.
- Do not move Kamailio-specific behaviour into Framework merely for convenience.
- Keep related domain behaviour together where splitting it would create artificial classes or interfaces with no operational value.
- Retain source/provenance when interpreting existing Kamailio configuration.
- Prefer an explicit unsupported/custom state over guessed semantics.
- Do not convert an existing Kamailio representation to a different format merely to make it editable through Atum.
- Declare module dependencies, PHP requirements and PHP extensions in `module.xml`.
- Keep module browser assets under the module and expose only supported asset types through the module asset path.

## Existing Kamailio Authority

Kamailio remains authoritative for the adopted installation.

A future structured change must be made through the mechanism actually used by that installation where Atum can identify and update it safely.

Do not silently rewrite unknown Kamailio logic.

## Host Changes

Atum uses the installation lifecycle contract in `docs/INSTALLATION-LIFECYCLE.md`.

The rule is:

> **No untracked host mutations.**

In v0.1, module lifecycle hooks may change Atum-owned application state only. They must not independently create or modify:

- system users or groups;
- operating-system packages;
- systemd/OpenRC/launchd services;
- PHP-FPM pools;
- Apache/Nginx configuration;
- firewall rules;
- certificates;
- shared host configuration files;
- other host-level artefacts.

A host-level module mutation mechanism must record ownership and rollback information before those operations are permitted.

## Security Requirements

Read `SECURITY.md` before adding an authenticated page, AJAX command, local command execution or host-level operation.

At minimum:

- state-changing browser operations must use POST rather than GET;
- state-changing browser operations require CSRF validation;
- permissions must be declared and enforced server-side;
- browser input must not become arbitrary shell syntax;
- secrets must not be returned in discovery results or written to logs/audit records;
- the web process must not gain broad write permission to Kamailio configuration;
- new listeners must remain local by default unless a reviewed deployment model explicitly says otherwise;
- client-facing errors must not expose raw exception details or sensitive paths unnecessarily.

## Documentation

Documentation should describe implemented behaviour and limitations directly.

- Do not claim support for an operating system solely because Kamailio supports it.
- Do not describe planned functionality as implemented.
- State destructive behaviour, retained data and uninstall consequences explicitly.
- State uncertain or unavailable information as uncertain or unavailable rather than inferring it.
- Use British English in project documentation and user-facing text where the platform does not require a fixed technical term.

## AI-Assisted Contributions and Disclosure

Generative AI assistance must be disclosed in every commit containing AI-assisted changes:

```text
Assisted-by: AGENT_NAME:MODEL_VERSION
```

Example:

```text
Assisted-by: ChatGPT:gpt-5.6-sol
```

The human contributor remains solely responsible for the contribution, including review, testing and licence compliance. AI tools must not be listed as co-authors.

## Licence

New source files should carry:

```text
SPDX-License-Identifier: GPL-3.0-or-later
```

By contributing, you agree that your contribution is licensed under the project's GPL-3.0-or-later licence.
