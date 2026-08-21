# Plugin GLPI — Bitwarden Send

Ajoute un bouton **Bitwarden Send** dans la timeline ITIL, à côté de Répondre/Tâche/
Solution/Document/Validation. Un technicien saisit un secret, le plugin crée un lien
Bitwarden Send et publie ce lien en suivi sur le ticket.

Conçu pour GLPI 11.

*(English: see [README_TECHNICAL.md](README_TECHNICAL.md).)*

Pour un aperçu des fonctionnalités avec captures d'écran, voir le [README principal](../README.md) (en anglais pour le moment).

Historique des versions : voir [CHANGELOG.md](../CHANGELOG.md).

## Ce que fait le plugin

- Entrée dans la timeline (s'ouvre en ligne, comme Tâche/Solution/...), plus un onglet
  « Bitwarden Sends » sur Ticket, Change et Problem.
- Création de Send texte : expiration, nombre de vues, mot de passe, masquage de
  l'adresse e-mail de l'expéditeur.
- Message de suivi en texte enrichi (même éditeur qu'un suivi GLPI normal), avec un
  aperçu en direct de la date d'expiration et du nombre de vues au fil de la saisie.
- Publie le lien en suivi (public ou privé) à partir d'un modèle configurable, ou d'un
  gabarit de suivi GLPI choisi au moment de la création.
- Révoque un lien depuis l'onglet, et affiche un statut « Expiré » dès que la date
  d'expiration est dépassée, même si personne n'a révoqué le lien.
- Supprime automatiquement les anciennes entrées révoquées/expirées, selon une
  planification que vous contrôlez.
- Droit dédié `plugin_bitwardensend_send` (Administration > Profils).
- Secrets de configuration chiffrés avec la clé GLPI.

## Driver de Send

Le plugin crée les Sends via l'un de ces deux drivers, choisi sur la page de
configuration :

- **CLI** (par défaut) — pilote l'API locale (`bw serve`) du client officiel `bw`.
  Nécessite un accès système/shell sur le serveur GLPI pour installer et lancer ce
  client — voir « Prérequis serveur » ci-dessous.
- **Natif** — dialogue directement avec l'API Bitwarden en PHP, sans aucun binaire
  externe. La seule option sur les hébergements où l'accès shell du driver CLI n'est
  pas disponible, par exemple GLPI Cloud. Voir « Driver natif » ci-dessous pour sa
  configuration et une vraie limitation (comptes PBKDF2 uniquement).

## Prérequis serveur

Bitwarden n'expose pas la création de Send via son API publique d'organisation ; le
driver CLI pilote donc l'**API locale** (`bw serve`) du client officiel `bw`. Ignorez
toute cette section si vous utilisez le driver natif.

```bash
# 1. Installer le client
npm install -g @bitwarden/cli     # ou le binaire depuis bitwarden.com/download

# 2. Répertoire de données appartenant à l'utilisateur du serveur web
sudo mkdir -p /var/lib/bitwarden-cli
sudo chown www-data:www-data /var/lib/bitwarden-cli

# 3. Connexion une fois, avec une clé API dédiée à un compte de service
sudo -u www-data BITWARDENCLI_APPDATA_DIR=/var/lib/bitwarden-cli \
     bw config server https://vault.example.com     # auto-hébergé uniquement
sudo -u www-data BITWARDENCLI_APPDATA_DIR=/var/lib/bitwarden-cli \
     bw login --apikey
```

### Service `bw serve`

`/etc/systemd/system/bw-serve.service` :

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

Le port 8087 ne doit **jamais** être accessible depuis l'extérieur de la machine :
quiconque l'atteint contrôle le coffre sans aucune authentification.

## Driver natif

Aucune installation côté serveur : tout se saisit directement sur la page de
configuration du plugin.

1. Créez un compte de service Bitwarden **dédié** à ce plugin — jamais le vôtre. GLPI
   conserve les identifiants de ce compte (chiffrés avec la clé GLPI), il doit donc
   pouvoir être révoqué indépendamment de tout compte d'une vraie personne.
2. Configurez le KDF de ce compte en **PBKDF2** (pas Argon2id) à sa création. C'est une
   contrainte technique, pas une préférence : l'extension `sodium` de PHP ne peut pas
   reproduire la dérivation de clé Argon2id de Bitwarden (elle sale avec une valeur de 32
   octets ; la fonction Argon2id de PHP n'en accepte que 16), donc un compte en Argon2id
   ne peut simplement pas fonctionner avec ce driver. Aucun contournement possible en
   dehors du driver CLI.
3. Générez la clé API de ce compte (ID client + secret) depuis ses réglages Bitwarden.
4. Sur la page de configuration du plugin, réglez **Driver de Send** sur « Natif (PHP
   uniquement) » et renseignez l'ID/secret client de l'API, l'e-mail et le mot de passe
   maître du compte, ainsi que les URL d'identité/API/coffre web (pré-remplies pour le
   cloud Bitwarden — ajustez les trois pour un serveur auto-hébergé ou Vaultwarden).
5. **Tester la connexion**.

## Installation du plugin

Téléchargez la dernière archive de release (`glpi-bitwardensend-<version>.tar.bz2`,
depuis la page Releases du dépôt) et extrayez-la dans le répertoire des plugins de
GLPI :

```bash
cd /var/www/glpi/plugins
tar xjf glpi-bitwardensend-<version>.tar.bz2
chown -R www-data:www-data bitwardensend
```

Puis Configuration > Plugins > Installer, puis Activer. Si vous mettez à jour une
installation existante, utilisez **Mettre à jour** plutôt que de simplement remplacer
les fichiers.

## Configuration

Configuration > Général > onglet **Bitwarden Send** :

| Réglage | Rôle |
|---|---|
| Driver de Send | `cli` (par défaut) ou `native` — voir « Driver de Send » ci-dessus |
| URL de l'API locale | Driver CLI : `http://127.0.0.1:8087` |
| Mot de passe maître | Driver CLI, optionnel, chiffré ; permet le déverrouillage automatique du coffre |
| URL de base des liens Send | Driver CLI : repli quand l'API ne renvoie pas le lien d'accès |
| URL d'identité/API/coffre web | Driver natif : points d'accès Bitwarden — pré-remplis pour le cloud |
| ID/secret client de l'API, e-mail, mot de passe maître | Compte de service du driver natif — voir « Driver natif » ci-dessus |
| Valeurs par défaut des liens | expiration, vues max, comportement du suivi, modèle |
| Autoriser les gabarits de suivi GLPI | permet aux techniciens de choisir un gabarit de suivi GLPI à la place du modèle ci-dessus |

**Tester la connexion** : pour le driver CLI, indique le statut du coffre (`unlocked`,
`locked`, `unauthenticated`) et tente un déverrouillage si un mot de passe maître est
enregistré ; pour le driver natif, s'authentifie et déverrouille la clé du compte, en
signalant le succès ou l'échec précis (mauvais mot de passe maître, API inaccessible...).

Accordez ensuite le droit dans Administration > Profils > *votre profil* > l'onglet des
droits du plugin. À noter : « Voir l'onglet Bitwarden Sends » doit rester coché pour que
les autres droits (créer, révoquer, supprimer) fonctionnent réellement.

## Nettoyage automatique

Une fois un lien révoqué, ou une fois qu'il expire, son entrée dans l'onglet
« Bitwarden Sends » peut être nettoyée automatiquement. Ça se configure dans
**Configuration > Actions automatiques > Bitwarden Send**, comme n'importe quelle
autre action planifiée GLPI :

- Le paramètre numérique est la rétention en jours (30 par défaut). Mettez `0` pour
  désactiver le nettoyage automatique.
- La fréquence et le mode d'exécution se règlent aussi sur cet écran.

## Notes de sécurité

- Un lien Send est autonome : la clé de déchiffrement se trouve dans le fragment de
  l'URL. Publié en suivi **public**, il est lisible par quiconque peut lire le ticket.
  C'est généralement le but recherché pour transmettre un mot de passe au demandeur,
  mais choisissez entre public et privé en connaissance de cause.
- Avec « vues max = 1 », le premier lecteur consomme le lien. Si un client de messagerie
  prévisualise les liens dans la notification, ça peut consommer cette unique vue :
  autorisez 2 vues ou plus si vous observez ce cas.
- Désactivez « Conserver le lien dans la base GLPI » pour que GLPI ne garde que les
  métadonnées. Copier le lien depuis l'onglet n'est alors plus possible.
- Un mot de passe maître stocké dans GLPI donne accès au coffre du compte de service :
  utilisez un compte dédié ne contenant rien de plus que le nécessaire. Ça vaut pour les
  deux drivers — le compte de service du driver natif a besoin de la même isolation.
- Les Sends de type **fichier** ne sont pas pris en charge dans cette version (texte
  uniquement).
- Le driver natif ne prend en charge que les comptes de service en KDF PBKDF2 — voir
  « Driver natif » ci-dessus pour la raison.

## Suivi en texte enrichi

Le champ « Texte du suivi » du formulaire de création de Send utilise le véritable
éditeur de texte enrichi de GLPI, avec les mêmes outils de mise en forme qu'un suivi de
ticket normal. Le modèle par défaut sur la page de configuration reste un champ texte
simple.

Deux variables portent le lien, pour deux usages différents :

- `{url}` se transforme en un lien cliquable complet (`<a href="...">...</a>`). À
  utiliser seul dans le texte.
- `{url_raw}` se transforme en l'URL brute seule. À utiliser dans **votre propre**
  `href="..."` — par exemple un gabarit de suivi GLPI avec son propre texte de lien —
  car `{url}` injecterait une balise `<a>` entière dans cet attribut et casserait le
  balisage.

`{expiration}` et `{max_access}` restent disponibles dans les deux cas.

**Vous tapez `{url_raw}` directement dans un `href="..."` sur un champ en texte
enrichi ?** L'éditeur de GLPI réécrit tout `href` qu'il ne reconnaît pas comme une URL
déjà absolue, en le préfixant avec l'URL de base de GLPI — ce qui corrompt un
`{url_raw}` seul dès l'enregistrement du gabarit, avant même que ce plugin ne le voie.
Utilisez plutôt `https://bitwardensend.invalid/{url_raw}` : déjà absolue, donc
l'éditeur n'y touche pas, et ce plugin la résout tout de même vers le vrai lien. Ça ne
concerne que les champs en texte enrichi (un gabarit de suivi GLPI, ou le texte de
suivi du formulaire de création) — le modèle par défaut en texte simple de la page de
configuration n'est pas concerné.

### Utiliser les gabarits de suivi de GLPI

En plus du modèle configuré du plugin, le formulaire de création peut aussi proposer
n'importe quel gabarit de suivi GLPI (Configuration > Gabarits > Gabarits de suivi),
restreint à l'entité de l'élément. En choisir un remplace le texte du suivi par le
contenu de ce gabarit ; les variables `{url}`/`{url_raw}`, `{expiration}` et
`{max_access}` sont toujours substituées si le gabarit GLPI les utilise lui aussi.

Cette option est activée par défaut et peut être désactivée depuis **Configuration >
Général > onglet Bitwarden Send** (« Autoriser le choix d'un gabarit de suivi GLPI lors
de la création d'un Send ») si vous préférez que les techniciens ne voient jamais que le
modèle du plugin. Le sélecteur disparaît aussi automatiquement si l'utilisateur courant
n'a pas le droit de lecture sur les gabarits de suivi GLPI, ou si aucun n'existe pour
l'entité de l'élément.

## Dépannage

### `bw serve` indique « locked »

`bw login --apikey` connecte le compte mais laisse le coffre **verrouillé**. Deux
options :

1. Enregistrer le mot de passe maître dans la configuration du plugin — le plugin
   déverrouille alors le coffre automatiquement à chaque fois qu'il en a besoin, y
   compris après un redémarrage de `bw serve`.
2. Déverrouiller le service à la main, sans stocker le mot de passe dans GLPI :

   ```bash
   curl -s -X POST http://127.0.0.1:8087/unlock \
        -H 'Content-Type: application/json' \
        -d '{"password":"VOTRE_MOT_DE_PASSE_MAITRE"}'
   ```

   Le coffre se reverrouille au redémarrage du service, il faut donc répéter
   l'opération.

## Traductions

L'interface est disponible en anglais et en français (`fr`, `fr_FR`, `fr_BE`, `fr_CA`).
GLPI affiche automatiquement le catalogue correspondant à la langue d'interface de
chaque utilisateur — rien à configurer.

Le modèle de suivi par défaut est traduit uniquement à l'installation : il est
enregistré dans la langue utilisée pour installer le plugin. Pour l'avoir dans une
autre langue, éditez-le directement sur la page de configuration, ou réinstallez avec
une autre langue d'interface.