#!/usr/bin/env python3
#  -------------------------------------------------------------------------
#  Bitwarden Send plugin for GLPI
#  -------------------------------------------------------------------------
#
#  LICENSE
#
#  This file is part of Bitwarden Send.
#
#  Bitwarden Send is free software: you can redistribute it and/or modify
#  it under the terms of the GNU General Public License as published by
#  the Free Software Foundation, either version 3 of the License, or
#  (at your option) any later version.
#
#  Bitwarden Send is distributed in the hope that it will be useful,
#  but WITHOUT ANY WARRANTY; without even the implied warranty of
#  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#  GNU General Public License for more details.
#
#  You should have received a copy of the GNU General Public License
#  along with Bitwarden Send. If not, see <https://www.gnu.org/licenses/>.
#  -------------------------------------------------------------------------
#
#  @copyright Copyright (C) 2026 by IT Gouvernance.
#  @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
#  @link      https://github.com/IT-Gouvernance/bitwardensend/
#  -------------------------------------------------------------------------
"""Rebuild the plugin translation catalogs (locales/*.pot, *.po, *.mo).

Standalone on purpose: neither xgettext nor msgfmt is required, which matters on
servers where the gettext tools are not installed. The MO binary format is
written directly (little endian, empty hash table - gettext falls back to a
binary search over the sorted key table).

Usage:
    python3 tools/build-locales.py

Add a language by copying the FR dict below and appending it to LANGUAGES.
"""
import os
import re
import struct
import sys

DOMAIN = 'bitwardensend'


def read_literal(s, i):
    """Read a quoted literal starting at s[i]. Return (value, next_index)."""
    quote = s[i]
    assert quote in '\'"', (s[i - 20:i + 20], i)
    i += 1
    out = []
    while i < len(s):
        c = s[i]
        if c == '\\':
            nxt = s[i + 1]
            if quote == "'":
                out.append(nxt if nxt in "\\'" else '\\' + nxt)
            else:
                out.append({'n': '\n', 't': '\t', 'r': '\r',
                            '"': '"', '\\': '\\', '$': '$'}.get(nxt, '\\' + nxt))
            i += 2
            continue
        if c == quote:
            return ''.join(out), i + 1
        out.append(c)
        i += 1
    raise ValueError('unterminated literal')


def read_argument(s, i):
    """Read one argument (possibly a concatenation of literals). Returns (value, i)."""
    parts = []
    while i < len(s):
        while s[i] in ' \t\r\n':
            i += 1
        if s[i] in '\'"':
            value, i = read_literal(s, i)
            parts.append(value)
        else:
            # Non-literal argument ($nb, a function call...): skip to the next
            # top-level comma or closing paren so the remaining args stay readable.
            depth = 0
            while i < len(s):
                c = s[i]
                if c in '\'"':
                    _, i = read_literal(s, i)
                    continue
                if c in '([':
                    depth += 1
                elif c in ')]':
                    if depth == 0:
                        break
                    depth -= 1
                elif c == ',' and depth == 0:
                    break
                i += 1
            return None, i
        while i < len(s) and s[i] in ' \t\r\n':
            i += 1
        if i < len(s) and s[i] in '.~':      # PHP "." / Twig "~" concatenation
            i += 1
            continue
        break
    return ''.join(parts), i


def find_calls(text, func):
    """Yield (args, position) for each call to `func` with literal arguments."""
    needle = func + '('
    pos = 0
    while True:
        pos = text.find(needle, pos)
        if pos == -1:
            return
        before = text[pos - 1] if pos else ' '
        if before.isalnum() or before in '_$>:':      # e.g. sprintf__( or ->__(
            pos += len(needle)
            continue
        i = pos + len(needle)
        args = []
        ok = True
        while True:
            while i < len(text) and text[i] in ' \t\r\n':
                i += 1
            if i >= len(text):
                ok = False
                break
            if text[i] == ')':
                i += 1
                break
            value, i = read_argument(text, i)
            args.append(value)
            while i < len(text) and text[i] in ' \t\r\n':
                i += 1
            if i < len(text) and text[i] == ',':
                i += 1
                continue
            if i < len(text) and text[i] == ')':
                i += 1
                break
            ok = False
            break
        if ok and args:
            yield args, pos
        pos = i if ok else pos + len(needle)


TPL_EN = ("Hello,\n\n"
          "Here is a secure link to retrieve the confidential information for this request:\n\n"
          "{url}\n\n"
          "The link expires on {expiration} and can be opened {max_access} time(s).\n\n"
          "Kind regards,")

TPL_FR = ("Bonjour,\n\n"
          "Voici un lien sécurisé pour récupérer les informations confidentielles "
          "de cette demande :\n\n"
          "{url}\n\n"
          "Ce lien expire le {expiration} et peut être ouvert {max_access} fois.\n\n"
          "Cordialement,")

FR = {
    '(Setup > Automatic actions).':
        '(Configuration > Actions automatiques).',
    '0 means unlimited until expiration.':
        "0 signifie illimité jusqu'à l'expiration.",
    'API URL': "URL de l'API",
    'API client ID': "ID client de l'API",
    'API client secret': "Secret client de l'API",
    'Account email': 'E-mail du compte',
    'Active': 'Actif',
    'Advanced options': 'Options avancées',
    'Allow choosing a GLPI followup template when creating a Send':
        "Autoriser le choix d'un gabarit de suivi GLPI lors de la création d'un Send",
    'Automatic deletion of revoked or expired entries is configured on the automatic action':
        "La suppression automatique des entrées révoquées ou expirées se configure sur l'action automatique",
    'Available variables: {url} (ready-to-click link), {url_raw} (just the address, '
    'for your own link), {expiration}, {max_access}':
        'Variables disponibles : {url} (lien prêt à cliquer), {url_raw} (juste '
        "l'adresse, pour votre propre lien), {expiration}, {max_access}",
    'Bitwarden API URL is not configured':
        "L'URL de l'API Bitwarden n'est pas configurée",
    'Bitwarden API client credentials are not configured.':
        "Les identifiants client de l'API Bitwarden ne sont pas configurés.",
    'Bitwarden API error: %s': "Erreur de l'API Bitwarden : %s",
    'Bitwarden Send link created: %s': 'Lien Bitwarden Send créé : %s',
    'Bitwarden connection': 'Connexion à Bitwarden',
    'Bitwarden did not return KDF parameters.':
        "Bitwarden n'a pas renvoyé les paramètres KDF.",
    'Bitwarden did not return a Send id/accessId':
        "Bitwarden n'a pas renvoyé d'identifiant de Send (id/accessId)",
    'Bitwarden did not return an access token.':
        "Bitwarden n'a pas renvoyé de jeton d'accès.",
    'Bitwarden did not return the account user key.':
        "Bitwarden n'a pas renvoyé la clé utilisateur du compte.",
    'Bitwarden identity URL is not configured.':
        "L'URL d'identité Bitwarden n'est pas configurée.",
    'Cannot reach the Bitwarden API (%s)': "Impossible de joindre l'API Bitwarden (%s)",
    'CLI / bw serve — recommended': 'CLI / bw serve — recommandé',
    'Configuration not saved: required fields are missing: %s':
        "Configuration non enregistrée : champs obligatoires manquants : %s",
    'Configuration saved.': 'Configuration enregistrée.',
    'Connected, vault unlocked. The plugin is ready to use.':
        'Connexion établie, coffre déverrouillé. Le plugin est opérationnel.',
    'Content to share': 'Contenu à partager',
    'Copy link': 'Copier le lien',
    'Could not create the Send: %s': 'Échec de la création du Send : %s',
    'Could not decrypt the account user key: wrong master password?':
        'Impossible de déchiffrer la clé utilisateur du compte : mot de passe maître incorrect ?',
    'Could not revoke the link: %s': 'Échec de la révocation du lien : %s',
    'Could not save the configuration.': "Échec de l'enregistrement de la configuration.",
    'Create link': 'Créer le lien',
    'Created by': 'Créé par',
    'Decrypted user key has an unexpected length.':
        'La clé utilisateur déchiffrée a une longueur inattendue.',
    'Default expiration (days)': 'Expiration par défaut (jours)',
    'Default maximum views': "Nombre maximal d'ouvertures par défaut",
    'Delete revoked or expired Bitwarden Send entries past the configured retention':
        "Supprime les entrées Bitwarden Send révoquées ou expirées au-delà de la durée de rétention configurée",
    'Delete the stored password': 'Supprimer le mot de passe enregistré',
    'Delete the stored secret': 'Supprimer le secret enregistré',
    'Delete this entry?': 'Supprimer cette entrée ?',
    'Encrypted with the GLPI key. Used to unlock the vault automatically. '
    'Leave empty if you unlock the service yourself.':
        'Chiffré avec la clé GLPI. Sert à déverrouiller automatiquement le coffre. '
        'Laissez vide si vous déverrouillez le service vous-même.',
    'Encrypted with the GLPI key.': 'Chiffré avec la clé GLPI.',
    'Encrypted with the GLPI key. PBKDF2 accounts only — see the README.':
        'Chiffré avec la clé GLPI. Comptes PBKDF2 uniquement — voir le README.',
    'Expiration': 'Expiration',
    'Expired': 'Expiré',
    'Expires in': 'Expire dans',
    'Followup configuration': 'Configuration du suivi',
    'Followup template': 'Modèle de suivi',
    'Followup text': 'Texte du suivi',
    'For example http://127.0.0.1:8087 — never expose this port publicly.':
        "Par exemple http://127.0.0.1:8087 — n'exposez jamais ce port publiquement.",
    'GLPI followup templates': 'Gabarits de suivi GLPI',
    'GLPI will store this account\'s credentials (encrypted). Use a dedicated, '
    'revocable service account — never your own.':
        'GLPI conservera les identifiants de ce compte (chiffrés). Utilisez un '
        'compte de service dédié et révocable — jamais le vôtre.',
    'Generate a password': 'Générer un mot de passe',
    'Generate a random password': 'Générer un mot de passe aléatoire',
    TPL_EN: TPL_FR,
    'Hide my email address from the recipient':
        'Masquer mon adresse e-mail au destinataire',
    'Hide the sender email address by default':
        "Masquer par défaut l'adresse e-mail de l'expéditeur",
    'Hide the text by default when opened':
        "Masquer le texte par défaut à l'ouverture",
    'Identity URL': "URL d'identité",
    'Item not found or access denied.': 'Élément introuvable ou accès refusé.',
    'Keep the link in the GLPI database': 'Conserver le lien dans la base GLPI',
    'Leave empty to require none': "Laissez vide pour n'en exiger aucun",
    'Length': 'Longueur',
    'Link defaults': 'Valeurs par défaut des liens',
    'Link options': 'Options du lien',
    'Link password (optional)': 'Mot de passe du lien (facultatif)',
    'Link revoked.': 'Lien révoqué.',
    'Local API (bw serve) — recommended': 'API locale (bw serve) — recommandé',
    'Local API URL': "URL de l'API locale",
    'Lowercase': 'Minuscules',
    'Master password': 'Mot de passe maître',
    'Max views': 'Ouvertures max.',
    'Maximum number of views': "Nombre maximal d'ouvertures",
    'Native (PHP only)': 'Natif (PHP uniquement)',
    'Native works without shell access on the server (e.g. GLPI Cloud) but only '
    'supports service accounts using the PBKDF2 KDF — see the README.':
        "Le mode natif fonctionne sans accès shell au serveur (par exemple GLPI "
        "Cloud), mais ne prend en charge que les comptes de service en KDF "
        'PBKDF2 — voir le README.',
    'New Bitwarden Send': 'Nouveau Bitwarden Send',
    'No Bitwarden Send link has been created for this item yet.':
        "Aucun lien Bitwarden Send n'a encore été créé pour cet élément.",
    'No master password is configured for the native driver.':
        "Aucun mot de passe maître n'est configuré pour le driver natif.",
    'Not set': 'Non renseigné',
    'Numbers': 'Chiffres',
    'Only needed if the vault is locked. Not required if it is already unlocked, '
    'for example if you unlocked it yourself on the server.':
        "Nécessaire uniquement si le coffre est verrouillé. Inutile s'il est déjà "
        "déverrouillé, par exemple si vous l'avez déverrouillé vous-même sur le "
        "serveur.",
    'Only used when the API does not return the access URL. '
    'Cloud: https://send.bitwarden.com/# — self-hosted: https://vault.example.com/#/send/':
        "Utilisée uniquement si l'API ne renvoie pas le lien d'accès. "
        'Cloud : https://send.bitwarden.com/# — auto-hébergé : https://vault.example.com/#/send/',
    'Only visible in your Bitwarden vault.':
        'Visible uniquement dans votre coffre Bitwarden.',
    'Password generator options': 'Options du générateur de mot de passe',
    'Password protected': 'Protégé par mot de passe',
    'Plugin default template': 'Modèle par défaut du plugin',
    'Post the link as a followup': 'Publier le lien dans un suivi',
    'Post the link as a followup by default':
        'Publier par défaut le lien dans un suivi',
    'Private followup (hidden from the requester)':
        'Suivi privé (masqué pour le demandeur)',
    'Private followup by default': 'Suivi privé par défaut',
    'Replace the current text? Your changes will be lost.':
        'Remplacer le texte actuel ? Vos modifications seront perdues.',
    'Retention (days)': 'Rétention (jours)',
    'Revoke': 'Révoquer',
    'Rights updated.': 'Droits mis à jour.',
    'See the Bitwarden Sends tab': "Voir l'onglet Bitwarden Sends",
    'Create Send links': 'Créer des liens Send',
    'Revoke Send links': 'Révoquer des liens Send',
    'Delete stored Send entries': 'Supprimer les entrées Send enregistrées',
    'Could not update the rights.': 'Échec de la mise à jour des droits.',
    'No profile selected.': 'Aucun profil sélectionné.',
    'Revoke this link?': 'Révoquer ce lien ?',
    'The link will no longer be viewable. This cannot be undone.':
        'Le lien ne sera plus consultable. Cette action est irréversible.',
    'Revoked': 'Révoqué',
    'Self-hosted: adjust these three URLs. Bitwarden.com and bitwarden.eu are '
    'separate — an account on one does not work with the other\'s URLs.':
        "Auto-hébergé : ajustez ces trois URL. Bitwarden.com et bitwarden.eu sont "
        "deux plateformes distinctes — un compte de l'une ne fonctionne pas avec "
        "les URL de l'autre.",
    'Send driver': 'Driver de Send',
    'Send it over another channel, by phone or text message for instance.':
        'Transmettez-le par un autre canal, par téléphone ou SMS par exemple.',
    'Send link base URL': 'URL de base des liens Send',
    'Send name': 'Nom du Send',
    'Show a random password generator on the creation form':
        'Afficher un générateur de mot de passe aléatoire dans le formulaire de création',
    'Stored encrypted in Bitwarden. GLPI only keeps the link and its metadata.':
        'Stocké chiffré dans Bitwarden. GLPI ne conserve que le lien et ses métadonnées.',
    'Stored — type a new one to replace it':
        'Enregistré — saisissez-en un nouveau pour le remplacer',
    'Symbols': 'Symboles',
    'Test connection': 'Tester la connexion',
    'The rights below require this one: every action in the tab checks it first.':
        "Les droits ci-dessous nécessitent celui-ci : chaque action de l'onglet le vérifie en premier.",
    'The Bitwarden client is not logged in. Run "bw login" on the server.':
        "Le client Bitwarden n'est pas connecté. Exécutez « bw login » sur le serveur.",
    'The Bitwarden vault is locked and no master password is configured.':
        "Le coffre Bitwarden est verrouillé et aucun mot de passe maître n'est configuré.",
    'The Send was created but no access link was returned.':
        "Le Send a été créé mais aucun lien d'accès n'a été retourné.",
    'The Send was created but the followup could not be added.':
        "Le Send a été créé mais le suivi n'a pas pu être ajouté.",
    'The content to share is empty.': 'Le contenu à partager est vide.',
    'This is the default text proposed when creating a Send. If the option '
    'above is enabled, the technician can instead pick one of the GLPI '
    'followup templates (Setup > Templates > Followup templates) — the same '
    'variables also work in those.':
        "C'est le texte par défaut proposé à la création d'un Send. Si "
        "l'option ci-dessus est activée, le technicien peut à la place "
        'choisir un des gabarits de suivi GLPI (Configuration > Gabarits > '
        'Gabarits de suivi) — les mêmes variables y fonctionnent aussi.',
    'This account uses the Argon2id KDF, which the native driver cannot reproduce '
    'in PHP. Use a service account configured with PBKDF2, or switch this Send '
    'driver to "cli".':
        'Ce compte utilise le KDF Argon2id, que le driver natif ne peut pas '
        'reproduire en PHP. Utilisez un compte de service configuré en PBKDF2, ou '
        'basculez ce driver de Send sur « cli ».',
    'This only removes the record from GLPI. This cannot be undone.':
        "Cela supprime uniquement l'enregistrement dans GLPI. Cette action est irréversible.",
    'The service answers but no account is logged in. '
    'Run "bw login --apikey" on the server as the service user.':
        "Le service répond mais aucun compte n'est connecté. "
        'Exécutez « bw login --apikey » sur le serveur avec '
        "l'utilisateur du service.",
    'The service answers but the vault is locked. Set the master password below so the '
    'plugin can unlock it, or unlock the service manually on the server.':
        'Le service répond mais le coffre est verrouillé. Renseignez le mot de passe '
        'maître ci-dessous pour que le plugin puisse le déverrouiller, ou '
        'déverrouillez le service à la main sur le serveur.',
    'Timeout (seconds)': "Délai d'attente (secondes)",
    'Unable to initialize cURL': "Impossible d'initialiser cURL",
    'Uppercase': 'Majuscules',
    'Unexpected response from the Bitwarden API (HTTP %d)':
        "Réponse inattendue de l'API Bitwarden (HTTP %d)",
    'Unexpected vault status: %s': 'État du coffre inattendu : %s',
    'Unknown Bitwarden API error': "Erreur inconnue de l'API Bitwarden",
    'Unknown Send identifier': 'Identifiant de Send inconnu',
    'Unlimited': 'Illimité',
    'Unsupported item type.': "Type d'élément non pris en charge.",
    'Use a GLPI followup template': 'Utiliser un gabarit de suivi GLPI',
    'Variables: {url} (ready-to-click link), {url_raw} (just the address, for your '
    'own link), {expiration}, {max_access}':
        'Variables : {url} (lien prêt à cliquer), {url_raw} (juste l\'adresse, pour '
        "votre propre lien), {expiration}, {max_access}",
    'Web vault URL': 'URL du coffre web',
    'When disabled, GLPI only keeps metadata: '
    'the link then exists in the followup only.':
        'Désactivé, GLPI ne conserve que les métadonnées : '
        "le lien n'existe alors que dans le suivi.",
    'an unlimited number of': 'un nombre illimité de',
    'day': 'jour',
    'days': 'jours',
    'the link (created once you submit)': 'le lien (créé une fois validé)',
}

# Brand name: kept identical in both forms.
FR_PLURAL = {('Bitwarden Send', 'Bitwarden Sends'): ('Bitwarden Send', 'Bitwarden Sends')}

HEADER_PO = '''# French translation for the GLPI Bitwarden Send plugin.
# Copyright (C) 2026
# This file is distributed under the same license as the plugin.
#
msgid ""
msgstr ""
"Project-Id-Version: bitwardensend __VERSION__\\n"
"Report-Msgid-Bugs-To: \\n"
"POT-Creation-Date: 2026-07-31 00:00+0000\\n"
"PO-Revision-Date: 2026-07-31 00:00+0000\\n"
"Last-Translator: \\n"
"Language-Team: French\\n"
"Language: fr_FR\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n > 1);\\n"
'''

HEADER_MO = (
    'Project-Id-Version: bitwardensend __VERSION__\n'
    'MIME-Version: 1.0\n'
    'Content-Type: text/plain; charset=UTF-8\n'
    'Content-Transfer-Encoding: 8bit\n'
    'Language: fr_FR\n'
    'Plural-Forms: nplurals=2; plural=(n > 1);\n'
)


def po_escape(text):
    return (text.replace('\\', '\\\\')
                .replace('"', '\\"')
                .replace('\t', '\\t')
                .replace('\n', '\\n'))


def po_string(text):
    """Render a PO string, splitting on newlines for readability."""
    if '\n' not in text and len(text) < 74:
        return '"%s"' % po_escape(text)
    chunks = text.split('\n')
    lines = ['""']
    for idx, chunk in enumerate(chunks):
        suffix = '\\n' if idx < len(chunks) - 1 else ''
        if chunk == '' and suffix == '':
            continue
        lines.append('"%s%s"' % (po_escape(chunk), suffix))
    return '\n'.join(lines)


def plugin_version(root):
    """Read the version from setup.php so it is never duplicated here."""
    setup = open(os.path.join(root, 'setup.php'), encoding='utf-8').read()
    match = re.search(r"PLUGIN_BITWARDENSEND_VERSION',\s*'([^']+)'", setup)
    if not match:
        raise SystemExit('Could not read PLUGIN_BITWARDENSEND_VERSION from setup.php')
    return match.group(1)


VERSION = '0.0.0'


def write_po(path, singles, plurals, refs, translated):
    out = [HEADER_PO.replace('__VERSION__', VERSION)]
    for msgid in sorted(singles):
        out.append('\n#: %s' % ', '.join(refs['singles'][msgid]))
        out.append('msgid %s' % po_string(msgid))
        out.append('msgstr %s' % po_string(translated.get(msgid, '') if translated else ''))
    for (sing, plur) in sorted(plurals):
        key = '\u241f'.join((sing, plur))
        out.append('\n#: %s' % ', '.join(refs['plurals'][key]))
        out.append('msgid %s' % po_string(sing))
        out.append('msgid_plural %s' % po_string(plur))
        forms = FR_PLURAL.get((sing, plur), ('', '')) if translated else ('', '')
        out.append('msgstr[0] %s' % po_string(forms[0]))
        out.append('msgstr[1] %s' % po_string(forms[1]))
    open(path, 'w', encoding='utf-8').write('\n'.join(out) + '\n')


def write_mo(path, entries):
    """entries: list of (key_bytes, value_bytes), unsorted."""
    entries = sorted(entries, key=lambda kv: kv[0])
    n = len(entries)
    orig_table_off = 28
    trans_table_off = orig_table_off + 8 * n
    data_off = trans_table_off + 8 * n

    orig_table, trans_table, blob = [], [], bytearray()
    for key, value in entries:
        orig_table.append((len(key), data_off + len(blob)))
        blob += key + b'\x00'
    for key, value in entries:
        trans_table.append((len(value), data_off + len(blob)))
        blob += value + b'\x00'

    out = bytearray()
    out += struct.pack('<IIIIII', 0x950412de, 0, n, orig_table_off, trans_table_off, 0)
    out += struct.pack('<I', data_off + len(blob))   # hash table offset (size 0)
    out = out[:28]
    for length, offset in orig_table:
        out += struct.pack('<II', length, offset)
    for length, offset in trans_table:
        out += struct.pack('<II', length, offset)
    out += blob
    open(path, 'wb').write(bytes(out))




# Locales sharing the French catalog.
FRENCH_LOCALES = ['fr_FR', 'fr_BE', 'fr_CA', 'fr']


def extract(root):
    """Collect every domain-bound msgid with its source references."""
    files = []
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in ('locales', 'tools', '.git')]
        for name in sorted(filenames):
            if name.endswith(('.php', '.twig', '.js')):
                files.append(os.path.join(dirpath, name))

    singles, plurals = {}, {}
    for path in sorted(files):
        rel = os.path.relpath(path, root)
        text = open(path, encoding='utf-8').read()

        def line_of(pos):
            return text.count('\n', 0, pos) + 1

        for args, pos in find_calls(text, '__'):
            if len(args) < 2 or args[1] != DOMAIN or args[0] is None:
                continue
            singles.setdefault(args[0], []).append('%s:%d' % (rel, line_of(pos)))

        for args, pos in find_calls(text, '_n'):
            if len(args) < 4 or args[3] != DOMAIN:
                continue
            if args[0] is None or args[1] is None:
                continue
            plurals.setdefault((args[0], args[1]), []).append('%s:%d' % (rel, line_of(pos)))

    return singles, plurals


def main():
    global VERSION

    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    VERSION = plugin_version(root)
    locales = os.path.join(root, 'locales')
    os.makedirs(locales, exist_ok=True)

    singles, plurals = extract(root)
    refs = {
        'singles': singles,
        'plurals': {'\u241f'.join(k): v for k, v in plurals.items()},
    }
    single_ids = sorted(singles)
    plural_ids = sorted(plurals)

    write_po(os.path.join(locales, 'bitwardensend.pot'), single_ids, plural_ids, refs, None)

    missing = [m for m in single_ids if m not in FR]
    obsolete = [m for m in FR if m not in single_ids]
    if missing:
        print('Missing French translations (%d):' % len(missing))
        for m in missing:
            print('   ', repr(m))
    if obsolete:
        print('Obsolete French entries (%d):' % len(obsolete))
        for m in obsolete:
            print('   ', repr(m))
    if missing or obsolete:
        return 1

    body = []
    for msgid in single_ids:
        body.append((msgid.encode('utf-8'), FR[msgid].encode('utf-8')))
    for pair in plural_ids:
        forms = FR_PLURAL[pair]
        body.append((
            pair[0].encode('utf-8') + b'\x00' + pair[1].encode('utf-8'),
            forms[0].encode('utf-8') + b'\x00' + forms[1].encode('utf-8'),
        ))

    for locale in FRENCH_LOCALES:
        po = os.path.join(locales, locale + '.po')
        write_po(po, single_ids, plural_ids, refs, FR)
        if locale != 'fr_FR':
            content = open(po, encoding='utf-8').read()
            content = content.replace('"Language: fr_FR', '"Language: ' + locale)
            open(po, 'w', encoding='utf-8').write(content)
        header = (HEADER_MO.replace('Language: fr_FR', 'Language: ' + locale)
                           .replace('__VERSION__', VERSION))
        write_mo(os.path.join(locales, locale + '.mo'),
                 [(b'', header.encode('utf-8'))] + body)

    print('%d entries (%d singular + %d plural) written for %s at version %s'
          % (len(body) + 1, len(single_ids), len(plural_ids),
             ', '.join(FRENCH_LOCALES), VERSION))
    return 0


if __name__ == '__main__':
    sys.exit(main())
