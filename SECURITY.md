# Security Policy

**NOT SUITABLE FOR PRODUCTION.**

Atum v0.1 is an early development preview. Do not install it on a production Kamailio host or rely on Atum authentication as a production security boundary. Remote development mode binds to the configured address, `0.0.0.0:8443` by default, and may already be reachable wherever existing host or cloud firewall policy permits it. Restrict access to a trusted administration IP or CIDR. Remote mode remains unsuitable for production.

## Supported Versions

No Atum version is currently designated production-supported.

Security fixes during the development-preview phase apply to the current development tree. There is no guarantee of backported fixes, stable database migrations or upgrade compatibility until the project declares a supported release.

## Safe Testing

Use Atum v0.1 only on a disposable test system or non-production Kamailio installation.

Before installation:

```sh
sudo ./install --check
```

A system installation requires explicit development acknowledgement:

```sh
sudo ./install --development
```

For browser access:

- use the built-in server on loopback only;
- use an SSH tunnel, or the explicit `sudo ./install --development --remote` Linux test-host mode;
- do not bind the development server directly to a LAN or WAN interface;
- restrict remote mode's HTTPS port at both host and cloud firewalls to a trusted administration IP/CIDR;
- keep an independent backup of the test Kamailio host.

## Trust Boundaries

Atum separates four types of state.

### Kamailio state

Kamailio configuration, runtime state and operational databases remain part of the adopted Kamailio installation.

The v0.1 Atum web application has no supported privileged path for changing them.

### Atum application source

Application and module source are outside the public web document root.

Only the `public/` tree is intended to be web-addressable. Enabled module browser assets are served through an authenticated and allowlisted asset endpoint; module PHP source is not exposed as a document root.

### Atum local state

Atum users, sessions, audit records and module lifecycle state are stored separately from Kamailio.

### Installation ownership

System-install ownership information is recorded in a root-owned installation ledger. The Atum web process cannot modify the authoritative ledger used for rollback and uninstall.

## Authentication and Sessions

The current framework provides:

- local Atum accounts separate from Kamailio subscriber credentials;
- password hashing through PHP `password_hash()`, preferring Argon2id where available;
- a server-enforced password length limit;
- cookie-only PHP sessions;
- idle and absolute session expiry;
- session ID regeneration after successful login;
- server-side account and session revalidation;
- invalidation after relevant account/password changes;
- login throttling by source address and username, plus a broader source-address limit;
- administrator and viewer roles.

Atum authentication has not undergone an independent security assessment and must not be treated as a production perimeter.

## Browser and AJAX Controls

The current framework provides:

- POST-only state-changing browser actions;
- CSRF validation for state-changing requests;
- server-side permission checks for pages and AJAX operations;
- fixed/validated module and command dispatch;
- Content Security Policy and related security headers;
- generic client-facing error responses rather than raw exception details;
- audit logging for authentication and Atum management operations;
- rejection of insecure non-loopback HTTP requests;
- loopback-only enforcement for the built-in PHP development server.

Remote mode terminates TLS directly in its dedicated Nginx/Apache vhost, passes PHP only to a dedicated `atum` PHP-FPM pool, and sets `HTTPS` at that local boundary. The application does not trust Internet-supplied `Forwarded` or `X-Forwarded-*` headers. Session cookies are Secure, HttpOnly and SameSite=Strict over that path. Plain HTTP is rejected before a session starts.

## Secret Handling

Discovery fails closed for parameter and define values. A small positive classification permits non-secret scalar tuning values; other values are redacted before information reaches the GUI, AJAX response or CLI output.

Unknown statements retain useful type and source provenance without exposing their raw content. This conservative handling does not prove that every custom syntax is semantically understood. Do not use development-preview discovery output as a substitute for normal protection of Kamailio configuration files.

The default audit destination is the same application-writable SQLite database as other Atum state. It has no tamper-evidence, external retention or append-only guarantee. The destination interface permits a future protected forwarder without presenting the current log as a production security record.

Secrets must not be deliberately written to Atum logs, audit events, discovery output or browser responses.

## Host and Kamailio Privileges

The v0.1 web process must not have broad write access to Kamailio configuration or arbitrary root command execution.

When Kamailio write support is introduced, host-level operations must use a narrowly defined privileged boundary with:

- explicit operation allowlisting;
- validated structured input;
- provenance of the target being changed;
- configuration validation before commit where available;
- audit recording;
- rollback or safe failure behaviour.

Browser input must never be converted into arbitrary shell command text.

## Module Trust

Atum module PHP is trusted application code and executes with Atum process privileges.

v0.1 has no module signing, repository trust or third-party module verification system. Do not install unreviewed third-party modules on a host containing valuable Kamailio configuration or credentials.

Module lifecycle hooks in v0.1 may change Atum-owned application state only. They must not independently create system users, services, packages, vhosts, firewall rules or other host-level artefacts.

## Installation and Removal Security

Installation/removal follows the lifecycle rules in `docs/INSTALLATION-LIFECYCLE.md`.

Important controls include:

- no install-time Kamailio configuration changes;
- pre/post installation hashing of files in the statically recognisable literal include/import scope;
- one shared exclusive lock covering install, interrupted-install recovery and uninstall;
- a root-owned installation ledger;
- per-install ownership markers on Atum-owned directory trees;
- an explicit `install-files.txt` allowlist that excludes Git metadata and unrelated checkout content from the installed application;
- refusal to guess ownership when the ledger or markers are missing or inconsistent;
- conservative operating-system dependency removal;
- no firewall rule added by default.

## Known Limitations

- The Kamailio configuration scanner is incomplete and line-oriented in important areas.
- Arbitrary Kamailio/KEMI logic is not semantically interpreted.
- The Nginx/Apache + PHP-FPM remote path is development-test coverage, not a supported production deployment.
- Its generated 30-day self-signed certificate encrypts traffic but does not establish trusted server identity.
- Atum does not configure a host or DigitalOcean firewall; an unrestricted public port materially increases development-preview risk.
- No privileged Kamailio write path exists.
- No third-party module signing/trust system exists.
- The project has not undergone an independent penetration test or source-code security audit.
- Platform CI is not equivalent to real Kamailio integration testing.
- Clean dependency rollback is conservative when the host changes after installation.
- Development-preview schemas and upgrade behaviour are not stable.

## Reporting a Vulnerability

Do not publish exploit details in a public GitHub issue.

Use GitHub private vulnerability reporting or a private security advisory for the repository where available. If private reporting is not enabled, contact the maintainers privately before publishing technical details.

Include where possible:

- affected commit/version;
- operating system and PHP version;
- whether authentication is required;
- minimum reproduction steps;
- expected impact;
- proposed mitigation, if known.

There is no bug-bounty programme.

## Contributor Security Requirements

New code must preserve the current security boundaries.

At minimum:

- do not use GET for state changes;
- require CSRF validation for state-changing browser requests;
- enforce permissions server-side;
- do not construct arbitrary shell commands from browser input;
- do not give the web process broad write access to Kamailio configuration;
- do not make untracked host changes;
- do not expose secrets in logs, discovery JSON or browser responses;
- do not bind new listeners externally by default;
- do not describe a development control as production-ready without explicit review and validation.
