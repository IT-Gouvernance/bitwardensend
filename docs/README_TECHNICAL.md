# GLPI plugin — Bitwarden Send

Adds a **Bitwarden Send** button to the ITIL timeline, alongside Answer/Task/Solution/
Document/Validation. A technician types a secret, the plugin creates a Bitwarden Send
link and posts that link as a followup on the ticket.

Built for GLPI 11.

*(Français : voir [README_TECHNIQUE.md](README_TECHNIQUE.md).)*

For a feature overview with screenshots, see the [main README](../README.md).

Release history: see [CHANGELOG.md](../CHANGELOG.md).

## What it does

- Timeline entry (opens inline, like Task/Solution/...) plus a "Bitwarden Sends" tab
  on Ticket, Change and Problem.
- Text Send creation: expiration, view count, password, sender email hiding.
- Rich text followup message (same editor as a normal GLPI followup), with a live
  preview of the expiration date and view count as you fill in the form.
- Posts the link as a followup (public or private) from a configurable template, or
  from one of GLPI's own followup templates ("gabarits de suivi") picked at creation
  time.
- Revokes a link from the tab, and shows an "Expired" status once its expiration date
  has passed even if nobody revoked it.
- Deletes old revoked/expired entries automatically, on a schedule you control.
- Dedicated right `plugin_bitwardensend_send` (Administration > Profiles).
- Configuration secrets encrypted with the GLPI key.

## Send driver

The plugin creates Sends through one of two drivers, chosen on the configuration page:

- **CLI** (default) — drives the official `bw` client's local API (`bw serve`). Requires
  shell/system access on the GLPI server to install and run that client — see "Server
  prerequisites" below.
- **Native** — talks to the Bitwarden API directly in PHP, with no external binary at
  all. The only option on hosts where the CLI driver's shell access is not available,
  e.g. GLPI Cloud. See "Native driver" below for its own setup and one real limitation
  (PBKDF2-only accounts).

## Server prerequisites

Bitwarden does not expose Send creation through its public organization API, so the
CLI driver drives the official `bw` client's local API (`bw serve`). Skip this whole
section if you are using the native driver instead.

```bash
# 1. Install the client
npm install -g @bitwarden/cli     # or the binary from bitwarden.com/download

# 2. Data directory owned by the web server user
sudo mkdir -p /var/lib/bitwarden-cli
sudo chown www-data:www-data /var/lib/bitwarden-cli

# 3. Log in once, with a dedicated service account API key
sudo -u www-data BITWARDENCLI_APPDATA_DIR=/var/lib/bitwarden-cli \
     bw config server https://vault.example.com     # self-hosted only
sudo -u www-data BITWARDENCLI_APPDATA_DIR=/var/lib/bitwarden-cli \
     bw login --apikey
```

### `bw serve` service

`/etc/systemd/system/bw-serve.service`:

```ini
[Unit]
Description=Bitwarden CLI Vault Management API
After=network.target

[Service]
Type=simple
User=www-data
Environment=BITWARDENCLI_APPDATA_DIR=/var/lib/bitwarden-cli
ExecStart=/usr/local/bin/bw serve --hostname 127.0.0.1 --port 8087
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now bw-serve
```

Port 8087 must **never** be reachable from outside the host: anyone who reaches it
controls the vault with no authentication.

## Native driver

No server-side installation: everything below is entered directly on the plugin's
configuration page.

1. Create a **dedicated** Bitwarden service account for this plugin — never your own.
   GLPI stores this account's credentials (encrypted with the GLPI key), so it needs to
   be one you can revoke independently of any real person's account.
2. Set that account's KDF to **PBKDF2** (not Argon2id) when creating it. This is a hard
   requirement, not a preference: PHP's `sodium` extension cannot reproduce Bitwarden's
   Argon2id key derivation (it salts with a 32-byte value; PHP's Argon2id function
   accepts only 16), so an Argon2id account simply cannot work with this driver. There
   is no workaround short of the CLI driver.
3. Generate that account's API key (client ID + secret) from its Bitwarden settings.
4. On the plugin's configuration page, set **Send driver** to "Native (PHP only)" and
   fill in the API client ID/secret, the account's email and master password, and the
   Identity/API/Web vault URLs (pre-filled for Bitwarden's cloud — adjust all three for
   a self-hosted server or Vaultwarden).
5. **Test connection**.

## Plugin installation

Download the latest release archive (`glpi-bitwardensend-<version>.tar.bz2`, from the
repository's Releases page) and extract it under GLPI's plugin directory:

```bash
cd /var/www/glpi/plugins
tar xjf glpi-bitwardensend-<version>.tar.bz2
chown -R www-data:www-data bitwardensend
```

Then Setup > Plugins > Install, then Enable. If you are updating an existing
installation, use **Update** rather than just replacing the files.

## Configuration

Setup > General > **Bitwarden Send** tab:

| Setting | Purpose |
|---|---|
| Send driver | `cli` (default) or `native` — see "Send driver" above |
| Local API URL | CLI driver: `http://127.0.0.1:8087` |
| Master password | CLI driver, optional, encrypted; enables automatic vault unlocking |
| Send link base URL | CLI driver: fallback when the API does not return the access URL |
| Identity/API/Web vault URL | Native driver: Bitwarden endpoints — pre-filled for the cloud |
| API client ID/secret, account email, master password | Native driver's service account — see "Native driver" above |
| Link defaults | expiration, max views, followup behaviour, template |
| Allow GLPI followup templates | lets technicians pick one of GLPI's own followup templates instead of the template above |

**Test connection**: for the CLI driver, reports the vault status (`unlocked`,
`locked`, `unauthenticated`) and attempts an unlock when a master password is stored;
for the native driver, authenticates and unlocks the account key, reporting success or
the specific failure (e.g. wrong master password, unreachable API).

Then grant the right in Administration > Profiles > *your profile* > the plugin's
rights tab. Note that "See the Bitwarden Sends tab" needs to stay checked for the other
rights (create, revoke, delete) to actually work.

## Automatic cleanup

Once a link is revoked, or once it expires, its record on the "Bitwarden Sends" tab can
be cleaned up automatically. This is configured from **Setup > Automatic actions >
Bitwarden Send**, like any other GLPI scheduled action:

- The numeric parameter is the retention in days (30 by default). Set it to `0` to
  disable automatic cleanup.
- The frequency and execution mode are also set on that same screen.

## Security notes

- A Send link is self-contained: the decryption key sits in the URL fragment. Posted
  as a **public** followup, it is readable by anyone who can read the ticket. That is
  usually the point when handing a password to the requester, but choose between
  public and private deliberately.
- With "max views = 1" the first reader consumes the link. If an email notification
  client previews links, that can consume the single view: allow 2 or more if you
  notice this happening.
- Turn off "Keep the link in the GLPI database" so GLPI stores metadata only. Copying
  the link from the tab is then no longer possible.
- A master password stored in GLPI grants access to the service account vault: use a
  dedicated account holding nothing beyond what is needed. This applies to both
  drivers — the native driver's own service account needs the same isolation.
- **File** Sends are not supported in this version (text only).
- The native driver only supports service accounts using the PBKDF2 KDF — see "Native
  driver" above for why.

## Rich text followup

The "Followup text" field on the Send creation form uses GLPI's own rich text editor,
with the same formatting tools as a normal ticket followup. The default template on the
configuration page stays a plain text field.

Two variables carry the link, for two different uses:

- `{url}` expands to a whole clickable link (`<a href="...">...</a>`). Use it on its
  own in the text.
- `{url_raw}` expands to the bare URL only. Use it inside your **own** `href="..."` —
  for example a GLPI followup template with its own link wording — since `{url}` would
  inject a whole `<a>` tag inside that attribute and break the markup.

`{expiration}` and `{max_access}` are also available in both places.

**Typing `{url_raw}` directly into a `href="..."` on a rich text field?** GLPI's editor
rewrites any href it does not recognize as an already-absolute URL, prefixing it with
the GLPI base URL — which corrupts a bare `{url_raw}` the moment the template is saved,
before this plugin ever sees it. Use `https://bitwardensend.invalid/{url_raw}` instead:
already absolute, so the editor leaves it alone, and this plugin still resolves it to
the real link. This only matters on rich text fields (a GLPI followup template, or the
create form's own followup text) — the plain-text default template on the configuration
page is unaffected.

### Using GLPI's own followup templates

Besides the plugin's own configured template, the creation form can also offer any of
GLPI's own followup templates (Setup > Templates > Followup templates), restricted to
the item's entity. Picking one replaces the followup text with that template's content;
the `{url}`/`{url_raw}`, `{expiration}` and `{max_access}` variables still get
substituted if the GLPI template itself uses them.

This is on by default and can be turned off from **Setup > General > Bitwarden Send**
("Allow choosing a GLPI followup template when creating a Send") if you would rather
technicians only ever see the plugin's own template. It also disappears on its own if
the current user has no read right on GLPI followup templates, or none exist for the
item's entity.

## Troubleshooting

### `bw serve` reports "locked"

`bw login --apikey` logs the account in but leaves the vault **locked**. Two options:

1. Store the master password in the plugin configuration — the plugin then unlocks the
   vault automatically whenever it needs to, including after a `bw serve` restart.
2. Unlock the service by hand, without storing the password in GLPI:

   ```bash
   curl -s -X POST http://127.0.0.1:8087/unlock \
        -H 'Content-Type: application/json' \
        -d '{"password":"YOUR_MASTER_PASSWORD"}'
   ```

   The vault locks again when the service restarts, so this has to be repeated.

## Translations

The interface is available in English and French (`fr`, `fr_FR`, `fr_BE`, `fr_CA`).
GLPI automatically shows the catalog matching each user's interface language — nothing
to configure.

The default followup template is translated at install time only: it is saved in the
language used to install the plugin. To get it in another language, edit it directly
on the configuration page, or reinstall with a different interface language.