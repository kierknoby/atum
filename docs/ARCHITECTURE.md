# Atum Architecture

**NOT SUITABLE FOR PRODUCTION.**

Atum v0.1 is a development preview. Kamailio configuration, runtime and operational database state are read-only from Atum.

## State Authority

Atum separates Kamailio state from Atum state.

### Kamailio

Kamailio remains authoritative for:

- configuration files and included configuration;
- Kamailio module configuration;
- runtime/RPC state;
- Kamailio operational databases;
- deliberate operator changes committed to Kamailio by future Atum management modules.

Installing Atum does not convert these objects into Atum-owned state.

### Atum

Atum owns:

- local Atum users;
- sessions;
- audit records;
- module installation and enable/disable state;
- installation ownership metadata;
- Atum-only cache/discovery state where introduced.

Removing Atum must not make an otherwise functioning Kamailio installation depend on Atum remaining installed.

## Framework Services

The framework container exposes shared services including:

```php
$Atum->Config;
$Atum->State;
$Atum->Auth;
$Atum->Audit;
$Atum->Modules;
$Atum->Ajax;
$Atum->View;
$Atum->System;
```

Modules can also be loaded through the module service accessor:

```php
Atum::Discovery();
```

## SIP System Model

Kamailio discovery is an evidence pipeline rather than the operator UI contract:

```text
configuration facts → structural semantics → domain interpretation
                    → shared Atum System Model → operator modules
```

`AtumKamailioSystemModel` is a read-only boundary above the scanner and semantic control-flow model. It exposes structured server roles, SIP interfaces, journeys, routing stages, destinations, media, access handling, custom components, outcomes, evidence references and system-level gaps. System Map and Call Handling consume this shared model. Discovery retains the detailed processing trace and provenance used to verify its conclusions.

The model records whether evidence is static configuration, backing data or runtime data. This release populates static evidence and defines a read-only provider interface for future backing/runtime sources; it does not make database connectivity or runtime RPC a requirement.

## Module Layout

Each module lives under:

```text
admin/modules/<rawname>/
```

A module may contain:

- a class extending `AtumModule`;
- `module.xml` metadata;
- install/uninstall lifecycle hooks;
- pages and views;
- browser assets;
- AJAX commands;
- navigation metadata;
- Atum module dependencies;
- minimum PHP and extension requirements.

Module PHP is trusted application code. v0.1 has no module signing or third-party module trust infrastructure.

## Module Lifecycle

Module installed/enabled state is stored outside module source in Atum's local state database.

Module Admin provides dependency-aware install, uninstall, enable and disable operations for Atum modules.

Module manifests may declare namespaced permissions such as `discovery.view`. Definitions are registered with module state and roles receive explicit grants. Administrators retain all permissions; other roles do not implicitly receive management permissions.

In v0.1, lifecycle hooks may modify Atum-owned application state only. They must not independently install packages, create services/users, modify shared host configuration or make other host-level changes.

Host-level module mutations require a prepare, declare, validate, journal, apply, verify, commit and rollback operation API before they can be supported safely. Current hooks run only as trusted bundled application migrations and are not represented as transactional host operations.

## Kamailio Capability Modules

A Kamailio-facing Atum module is expected to own the complete management behaviour for its Kamailio concept rather than only a page.

Depending on the capability, that may include:

```text
discover
interpret
visualise
read runtime state
validate
create
update
delete
apply
rollback
report provenance
```

Not every module or discovered object supports every operation.

A future management module should distinguish between these conditions:

- **Managed**: Atum understands the object and backing mechanism sufficiently for structured changes.
- **Recognised**: Atum can identify and present the object but cannot safely rewrite it.
- **Custom**: Atum can show source/provenance but does not claim to understand the logic.

The v0.1 Discovery module does not yet implement complete semantic classification.

## Binding to Existing Configuration

Atum should manage the representation already in use where it can do so safely.

For example, a future Dispatcher module may find dispatcher destinations in a Kamailio database on one installation and in a dispatcher list file on another. The module should bind its controls to that existing backing mechanism rather than silently converting the installation to another representation.

If a backing mechanism or route cannot be interpreted safely, it remains visible but not structurally editable.

## Provenance

Recognised Kamailio objects should retain enough source information to answer where Atum obtained them.

For file-backed configuration this includes source filename and line information where available. Database/runtime-backed modules should retain equivalent source identifiers such as table/module/group/RPC origin where available.

Provenance must survive presentation so an administrator can distinguish Atum interpretation from the underlying Kamailio source.

## Web Boundary

Only `public/` is intended as a web document root.

Framework source, module source, configuration and Atum state stay outside the public tree.

Enabled modules expose supported browser assets through `public/module-asset.php`. The asset endpoint validates the module and requested asset type/path; module source itself is not exposed through the document root.

State-changing browser actions require authentication, permission checks, POST and CSRF validation.

The built-in PHP server is for local development only and is restricted to loopback. Explicit Linux remote-development installation uses a dedicated Nginx/Apache TLS vhost whose document root is `public/` and a dedicated PHP-FPM pool running as `atum`. It does not use or expose the built-in server, and it does not trust public proxy headers for scheme or client identity.

## Authentication and Roles

Atum uses local accounts rather than Kamailio subscriber credentials.

The v0.1 framework provides administrator and viewer roles. Page and AJAX permission checks are enforced server-side.

Authentication/session details and known limitations are documented in `SECURITY.md`.

## Privilege Boundary

v0.1 has no privileged Kamailio write path.

The web process must not be granted broad write permission to `/etc/kamailio` or arbitrary root command execution.

When host/Kamailio writes are introduced, the privileged side must accept narrowly defined structured operations rather than shell text from the browser. Operations must support validation, audit and safe failure/rollback appropriate to the target being changed.

## Installation Ownership

Installation/removal follows `docs/INSTALLATION-LIFECYCLE.md`.

The central rule is:

> **No untracked host mutations.**

Host artefacts created for Atum must be recorded by the installation lifecycle mechanism so failed installation and later removal use the same ownership record.

The source Git checkout is outside that ownership set. Installation copies only the application files declared by `install-files.txt`; repository metadata, tests and unrelated checkout content are not part of the installed web application and are not removal targets.

## Current Architectural Limitations

- Discovery is not a complete Kamailio parser.
- Arbitrary KEMI/routing semantics are not understood.
- No privileged write helper exists.
- Remote web deployment is development-test-only; no production web deployment is defined.
- No module signing/trust model exists.
- No supported host-mutation API exists for individual Atum modules.
