# Security Policy

## Supported versions

This plugin is pre-1.0 (currently shipping `1.0.0-betaN` pre-releases). Only
the latest released version is supported — please make sure you can reproduce
an issue there before reporting it.

## Reporting a vulnerability

Please **do not** open a public GitHub issue for a security vulnerability.

Instead, use GitHub's private reporting form for this repository:

**[Report a vulnerability](https://github.com/IT-Gouvernance/bitwardensend/security/advisories/new)**

If that form isn't available to you, you can instead contact
[IT Gouvernance](https://www.it-gouvernance.fr/) directly.

Please include:

- The plugin version and GLPI version.
- Which Send driver is involved (CLI/`bw serve`, or native), if relevant.
- Steps to reproduce, and the impact you believe it has.

You'll get an acknowledgment as soon as possible, and we'll keep you updated
as the issue is investigated and fixed. Please give us a reasonable amount of
time to address the issue before any public disclosure.

## Scope

Some things worth knowing that are by design, not vulnerabilities to report:

- A Bitwarden Send link is self-contained: the decryption key sits in the URL
  fragment. Posted as a **public** followup, it is readable by anyone who can
  read the ticket — that's the point when handing a secret to the requester,
  but it is the admin/technician's responsibility to choose public vs. private
  deliberately (see [docs/README_TECHNICAL.md](docs/README_TECHNICAL.md)).
- Configuration secrets (master password, native driver's API client secret
  and master password) are encrypted with GLPI's own key, not with an
  additional plugin-specific secret.
- "File" Sends are not supported in this version (text only), so there's no
  file-upload attack surface to consider here.
