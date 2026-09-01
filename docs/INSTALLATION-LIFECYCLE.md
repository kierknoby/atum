# Atum Installation Lifecycle

**NOT SUITABLE FOR PRODUCTION.**

Atum v0.1 is a development preview. The lifecycle rules below apply to development/testing and define the behaviour required before a future supported release.

## Installation Rule

Atum adopts an existing Kamailio installation. Installing or removing the Atum GUI must not alter Kamailio merely to make the GUI exist.

The core rule is:

> **No untracked host mutations.**

Every host artefact Atum creates or modifies must have enough ownership information recorded to support installation rollback and later removal.

## Install-Time Kamailio Protection

The installer does not write Kamailio configuration.

Before committing an installation, it:

1. discovers statically recognisable literal includes starting from the selected root configuration;
2. records SHA-256 hashes of discovered configuration files;
3. performs Atum installation work;
4. discovers/hashes the same statically recognisable scope again;
5. aborts if that recognised configuration changed during the transaction.

The ledger records the snapshot scope as partial. Macro-generated/non-literal includes, conditional preprocessing and external KEMI/custom configuration are not proven by this check. It is not a general Kamailio integrity-monitoring system.

## Atum-Owned Paths

The default system paths are:

```text
/usr/share/atum        application
/etc/atum              configuration and root-owned install ledger
/var/lib/atum          Atum application state
/usr/local/sbin/atum   CLI link
/usr/local/sbin/atum-uninstall
```

Remote mode additionally owns an individual Nginx/Apache vhost or drop-in, an individual PHP-FPM pool, an optional Apache enablement symlink, and generated certificate/key files under `/etc/atum/tls`. Each is provisionally journalled before installation commits and recorded with its hash or symlink target in the final ledger.

Where possible, Atum should own complete directory trees rather than place individual files inside unrelated application directories.

## Installation Identifier

A system installation receives a unique installation ID.

Atum-owned directory trees contain:

```text
.atum-install-id
```

The marker must match the root-owned installation ledger before the uninstaller permits recursive deletion of that tree.

A missing or mismatched marker is a removal error. The uninstaller must not infer ownership from the directory name alone.

## Installation Ledger

The authoritative ledger is stored at:

```text
/etc/atum/install-ledger.json
```

It is root-owned and must not be writable by the Atum web process.

The ledger records information such as:

- installation ID;
- application/configuration/state paths;
- Atum system user/group and whether the installer created them;
- operating-system packages that were absent before installation and added by the Atum dependency transaction;
- selected Kamailio root configuration;
- install-time hashes of recursively discovered Kamailio configuration files;
- Atum-created host integrations where supported;
- shared host-file backups/hashes where a future integration cannot use a dedicated drop-in;
- service state required to restore an existing service after removing an Atum-specific integration.

The ledger describes Atum ownership. It is not Kamailio configuration state.

## Installer Rollback

A failed installation uses the same ownership model as uninstall.

Before the transaction is committed, failure should remove safely reversible changes made by that transaction, including:

- staged Atum application/configuration/state;
- Atum account/group created by the transaction;
- CLI links created by the transaction;
- safely removable dependency packages added by the transaction.

Installation must not leave a partially committed Atum tree and then rely on a later manual cleanup.

Before the first host mutation, the installer creates a root-owned provisional transaction journal. Intended paths are recorded before creation and receive the same random provisional installation ID. Remote host files are recorded with hashes or symlink targets as they are created. A later installer removes only matching interrupted-install artefacts and refuses to overwrite administrator changes. Package deltas are reconciled after both successful and failed package-manager calls. A later installer carries retained dependency ownership into the next committed ledger. Packages are retained when safe reverse-dependency removal cannot be proved.

## Existing Shared Host Files

Dedicated files/drop-ins are preferred to edits of shared host configuration.

If a future integration must modify an existing shared file, the ownership record must include:

- original backup;
- original SHA-256 hash;
- SHA-256 hash of the version written by Atum.

Removal may restore the original only when the current file still matches the version Atum wrote.

If the file changed after Atum wrote it, uninstall must stop and report the conflict rather than overwrite later administrator/application work.

## Operating-System Dependencies

The installer snapshots package state before installing missing Atum dependencies.

Only packages that were absent before Atum and added by the dependency transaction are candidates for removal.

Dependency removal is conservative:

- on APT systems, removal must not proceed where package-manager simulation indicates unrelated installed software would also be removed;
- on DNF/YUM systems, v0.1 retains packages added for Atum because equivalent safe reverse-dependency proof is not yet implemented;
- `atum-uninstall --keep-dependencies` retains all operating-system dependencies deliberately.

Clean Atum removal is more important than removing a shared runtime package unsafely.

## Firewall and Network Exposure

Atum does not open firewall ports automatically.

The built-in server remains loopback-only. Explicit remote mode uses HTTPS on the selected host address/port through an existing Nginx or Apache and dedicated PHP-FPM pool. Atum never changes host or DigitalOcean firewall rules. The operator must permit the port manually and should restrict it to a trusted administration source IP or CIDR.

## Module Host Mutations

v0.1 module lifecycle hooks may change Atum-owned application state only.

They must not independently create or modify host-level artefacts such as:

- system accounts;
- packages;
- services;
- PHP-FPM pools;
- web-server configuration;
- firewall rules;
- certificates;
- shared host configuration files.

A module host-mutation API must record ownership/rollback data before those operations are supported.

## Uninstall Preview

Always preview removal first:

```sh
sudo atum-uninstall --check
```

The preview must show the Atum-owned paths and other recorded removal candidates without making changes.

If the install ledger or ownership markers are missing/inconsistent, removal stops rather than guessing.

## Uninstalling

Normal removal:

```sh
sudo atum-uninstall
```

Retain operating-system dependencies:

```sh
sudo atum-uninstall --keep-dependencies
```

The uninstaller is designed to be retryable after an interrupted removal. The authoritative ledger is retained until the final cleanup stage so a partially completed removal still has the ownership information required to continue safely.

## Kamailio Changes Made Through Atum

A future administrator action that deliberately changes Kamailio through an Atum management module is different from an Atum installation artefact.

For example, if an administrator adds a dispatcher destination through Atum and the change is committed to the Kamailio installation, that destination belongs to Kamailio.

Uninstalling Atum must not silently revert deliberate operational Kamailio changes.

## Clean Removal Result

After successful clean removal there should be no Atum-owned:

- process or service;
- socket;
- application tree;
- configuration tree;
- local state database;
- sessions or audit state;
- system account/group created by Atum;
- CLI link;
- PHP-FPM pool;
- web-server integration;
- generated certificate;
- other host artefact recorded as owned by the Atum installation.

This is an operational restoration goal, not forensic erasure. Package-manager logs, system journal entries, security logs and shell history may record that Atum was previously installed.

## Current Limitations

- Remote Nginx/Apache and PHP-FPM integration is systemd/Linux-only development-test functionality and is not production-supported.
- RPM-family dependency removal is intentionally conservative and retains Atum-added packages in v0.1.
- Module-owned host mutation registration is not implemented, so modules must not perform such changes.
- Clean removal cannot safely undo untracked manual changes made outside the Atum lifecycle mechanism.
