<div align="center">

<br/>

<img src="https://img.shields.io/badge/WP%20Clone%20Master-1.0.0-0969da?style=for-the-badge" alt="WP Clone Master 1.0.0"/>

<br/><br/>

# WP Clone Master

**Solution de migration et sauvegarde haute performance pour WordPress.**

WP Clone Master est la solution de migration et de sauvegarde haute performance pour WordPress. Conçu pour les sites volumineux ou les environnements restreints, il utilise un moteur asynchrone par étapes pour contourner les timeouts serveurs et autres erreurs. Intégration Nextcloud native, remplacement d'URL intelligent et restauration sécurisée.

<br/>

[![WordPress 5.6+](https://img.shields.io/badge/WordPress-5.6%2B-21759B?style=flat-square&logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Licence GPL v2](https://img.shields.io/badge/Licence-GPL%20v2-22863a?style=flat-square)](LICENSE)
[![Zéro dépendance](https://img.shields.io/badge/D%C3%A9pendances-aucune-success?style=flat-square)]()
[![Taille](https://img.shields.io/badge/Poids-94%20Ko-f59e0b?style=flat-square)]()

<br/>

</div>

---

## En une phrase

> **WP Clone Master sauvegarde, clone et restaure votre site WordPress entier**

Il remplace le scénario habituel du plugin qui se fige à 67 %, erreur 524, `max_execution_time exceeded` par un moteur asynchrone qui découpe chaque opération en étapes de quelques secondes, reprend là où il s'est arrêté et vous envoie une notification email quand c'est terminé.

<br/>

---


| La plupart des plugins de backup | WP Clone Master |
|---|---|
| Une requête HTTP = tout le backup | Une requête = une étape (~5 s max) |
| Échoue sur les sites > 500 Mo | Testé sur des archives > 3 Go |
| Timeout Cloudflare 524 fatal | Réponse HTTP en < 1 s, backup en arrière-plan |
| Dates en timezone du serveur PHP | 100 % `wp_date()` — timezone WordPress partout |
| Mot de passe cloud en clair ou en dur | Nextcloud Login Flow v2 + AES-256-CBC |
| URL cassées après migration | Remplacement serialization-safe (PHP, JSON, HTTP↔HTTPS…) |

<br/>

---

## Fonctionnalités

### 🗜️ Export complet en 7 étapes

Le site entier dans un ZIP portable, sans jamais toucher la limite de temps PHP.

```
init → database → files_scan → files_archive × N → config → package → cleanup
 1s       5-30s       2s              ~5s chacune      2s       10s       1s
```

Chaque étape est indépendante et persistée — si une étape échoue, elle reprend sans recommencer depuis zéro.

**Contenu de l'archive :**
- Base de données complète (multi-row `INSERT`, 100 lignes/requête — identique à `mysqldump`)
- Thèmes, plugins, uploads, mu-plugins, langues, drop-ins
- `wp-config.php`, `.htaccess`, `robots.txt`, options clés

**Exclusions intelligentes :** caches, `node_modules`, `.git`, logs de debug, répertoires temporaires du plugin, fichiers > 256 Mo.

---

### 🔄 Migration sûre — remplacement d'URL serialization-safe

Déplacez votre site vers un nouveau domaine ou un nouvel hébergeur. Le moteur de remplacement gère **toutes les formes** que peut prendre une URL dans WordPress :

```
https://ancien.site          →  https://nouveau.site       plain text
http://ancien.site           →  https://nouveau.site       upgrade HTTP → HTTPS
//ancien.site                →  //nouveau.site             protocol-relative
https:\/\/ancien.site        →  https:\/\/nouveau.site     JSON échappé
a:2:{s:3:"url";s:22:"..."}  →  longueur recalculée        PHP sérialisé
/var/www/ancien/wp-content   →  /var/www/nouveau/…         chemins absolus
```

Aucune URL cassée. Aucun widget qui pointe vers l'ancienne adresse.

---

### ⏰ Sauvegardes automatiques planifiées

Configurez une fois, oubliez. WP-Cron gère l'exécution ; le plugin gère le reste.

**Fréquences :** toutes les heures · 2×/jour · quotidien · hebdomadaire · mensuel

**Rétention :**
- Par nombre — *"Garder les 7 dernières"*
- Par durée — *"Supprimer après 30 jours"*

> Les sauvegardes manuelles ne sont **jamais** supprimées automatiquement.

**Lancer maintenant sans anxiété** — le bouton répond en < 1 s, le backup tourne en arrière-plan. Un chronomètre en temps réel s'affiche dans l'interface. Compatible Cloudflare, nginx, Apache, LiteSpeed.

```
Vous cliquez         WordPress répond { queued }      Backup tourne
──────────►  < 1 s  ◄──────────────────────────────  3 min en arrière-plan
                     Polling /status toutes les 5 s ──► Résultat dans l'historique
```

---

### ☁️ Stockage cloud — Nextcloud natif

#### Connexion par autorisation (Login Flow v2)

Pas de mot de passe saisi dans WordPress. Pas de copier-coller de token d'application. Le même protocole d'autorisation que le client desktop officiel Nextcloud :

1. Vous entrez l'URL de votre Nextcloud
2. Un popup s'ouvre sur **votre** Nextcloud — vous vous connectez normalement
3. Vous cliquez "Autoriser" — le popup se ferme automatiquement
4. Le token est chiffré et stocké — la connexion est établie pour toutes les sauvegardes futures

> Votre mot de passe Nextcloud ne transite **jamais** par WordPress.

#### Upload par morceaux pour les gros fichiers

| Taille de l'archive | Méthode | Timeout possible ? |
|---|---|---|
| ≤ 50 Mo | PUT unique (cURL streaming) | Non |
| > 50 Mo | **Chunked upload WebDAV — 10 Mo/requête** | Non |
| 3 Go | 300 requêtes × ~5 s | **Non** |

Protocole officiel Nextcloud (MKCOL → PUT × N → MOVE), identique au client desktop. Si un morceau échoue, la session est nettoyée proprement.

---

### 📊 Journal d'historique

Chaque sauvegarde — automatique ou manuelle — est enregistrée :

| Date | Déclencheur | Statut | Durée | Taille | Stockage | Fichier |
|---|---|---|---|---|---|---|
| 2026-03-21 15:00 | Auto | ✅ Succès | 3 min 12 s | 2,8 Go | ✅ Nextcloud | auto_wpcm_…zip |
| 2026-03-20 15:00 | Auto | ❌ Échec | 47 s | — | — | Erreur PHP : … |

50 entrées max (FIFO). Notifications email configurables : toujours / échec uniquement / jamais.

<br/>

---

## 🚀 Installation

```bash
# Via WP-CLI
wp plugin install wp-clone-master.zip --activate

# Ou via l'interface WordPress
Plugins → Ajouter → Téléverser → wp-clone-master.zip → Activer
```

**C'est tout.** Zéro `composer install`. Zéro `npm build`. Opérationnel immédiatement.

**Prérequis :**

```
✅ Obligatoires          ○ Optionnel
WordPress 5.6+           curl — requis pour upload Nextcloud > 50 Mo
PHP 7.4+
Extensions : zip, mysqli, json, mbstring, zlib
wp-content/ accessible en écriture
```

**Pour les sites à faible trafic** (WP-Cron ne se déclenche pas assez souvent) :

```bash
# Cron système — toutes les 5 minutes
*/5 * * * * curl -s https://votre-site.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Le plugin affiche un avertissement dans l'interface si `DISABLE_WP_CRON` est détecté.

<br/>

---

## 🏗️ Architecture

```
wp-clone-master/
│
├── wp-clone-master.php           # Singleton principal — 16 actions AJAX
│
├── includes/
│   ├── class-exporter.php        # Pipeline export 7 étapes
│   ├── class-importer.php        # Upload chunked + installateur standalone auto-destructeur
│   ├── class-scheduler.php       # WP-Cron, rétention, notifications
│   ├── class-backup-settings.php # Options + historique (wp_options) + chiffrement
│   ├── class-storage-driver.php  # Abstraction stockage : Local + Nextcloud chunked
│   ├── class-url-replacer.php    # Remplacement serialization-safe
│   ├── class-server-detector.php # Détection environnement PHP/MySQL/serveur
│   └── class-date.php            # Toutes les dates via wp_date() — zéro date() natif
│
└── admin/
    ├── js/admin.js               # App React — onglets Export, Import, Sauvegardes, Serveur
    └── js/schedule-tab.js        # Onglet Planification injecté dynamiquement
```

### Flux de données

```
┌─────────────────────────────────────────────────────┐
│                  Interface React                     │
│  Export │ Import │ Sauvegardes │ Planification │ Serveur │
└─────────────────────────┬───────────────────────────┘
                          │ AJAX (nonce + manage_options)
┌─────────────────────────▼───────────────────────────┐
│                  WP_Clone_Master                    │
│              16 handlers AJAX sécurisés             │
└──┬──────────┬──────────┬──────────────┬─────────────┘
   │          │          │              │
   ▼          ▼          ▼              ▼
Exporter  Importer  Scheduler     StorageDriver
   │                   │          ┌────┴────┐
   │                   │        Local   Nextcloud
   └───────────────────┘         (WebDAV chunked)
          │
       WPCM_Date          ← wp_date() / wp_timezone() partout
       WPCM_URL_Replacer  ← serialized + JSON + protocol-relative
```

<br/>

---

## 🔒 Sécurité

Conçu pour un usage en production. Chaque surface exposée est protégée.

| Surface | Protection appliquée |
|---|---|
| Toutes les actions AJAX | `check_ajax_referer()` + `current_user_can('manage_options')` |
| Suppression de fichiers | Validation `realpath()` — path traversal impossible |
| Répertoires de sauvegarde | `.htaccess` `Deny from all` + `index.php` vide |
| Token Nextcloud | AES-256-CBC avec `AUTH_KEY + AUTH_SALT + 'wpcm_nc'` |
| Login Flow v2 | Token de poll en transient WordPress 10 min, jamais exposé au JS |
| Sessions d'export | IDs 32 caractères aléatoires (`wp_generate_password`) |
| Inputs POST | `sanitize_text_field`, `sanitize_file_name`, `esc_url_raw`, `sanitize_email` |
| Requêtes SQL | `mysqli_real_escape_string()` direct — préserve les tokens WP (`%postname%`…) |
| Noindex post-migration | `pre_option_blog_public` à `PHP_INT_MAX` — immunisé Redis/Memcached |

<br/>

---

## ❓ FAQ

<details>
<summary><strong>Mon hébergeur limite PHP à 30 secondes. Est-ce que ça va fonctionner ?</strong></summary>

Oui — c'est précisément le cas d'usage principal. Chaque étape dure quelques secondes au maximum. La limite `max_execution_time` n'a aucun impact sur le résultat.

</details>

<details>
<summary><strong>J'ai Cloudflare devant mon site. Les timeouts 524 vont-ils poser problème ?</strong></summary>

Non. Le backup fonctionne en mode *fire & forget* : WordPress répond au navigateur en moins d'une seconde, puis continue le backup sans connexion HTTP active. Cloudflare ne voit qu'une requête courte.

</details>

<details>
<summary><strong>Mon site fait 4 Go. Est-ce supporté ?</strong></summary>

Oui. Le plugin découpe l'export (1 répertoire par appel AJAX) et l'upload Nextcloud (10 Mo par requête). L'empreinte mémoire PHP reste constante quelle que soit la taille du site.

</details>

<details>
<summary><strong>Les URLs sont-elles bien remplacées après une migration HTTP → HTTPS ?</strong></summary>

Oui. Le moteur gère 6 formes d'URL : plain, HTTP↔HTTPS, protocol-relative, JSON-escaped, PHP sérialisé avec recalcul des longueurs, et chemins absolus serveur.

</details>

<details>
<summary><strong>Mes sauvegardes automatiques ne se déclenchent pas.</strong></summary>

WP-Cron nécessite du trafic pour se déclencher. Sur un site à faible trafic, ajoutez une tâche cron système qui appelle `/wp-cron.php` toutes les 5 minutes. Le plugin affiche un avertissement si `DISABLE_WP_CRON` est actif.

</details>

<details>
<summary><strong>Mon mot de passe Nextcloud est-il stocké quelque part ?</strong></summary>

Il n'est jamais saisi dans WordPress. Le Login Flow v2 génère un token d'application côté Nextcloud, que WordPress chiffre en AES-256-CBC avec vos clés `AUTH_KEY` + `AUTH_SALT`. Ce token chiffré n'est jamais transmis au navigateur.

</details>

<br/>

---

## 📜 Licence

GPL v2 ou ultérieure — voir [LICENSE](LICENSE).

<br/>

---

<div align="center">

*Fait pour les développeurs qui déploient des sites WordPress en production*
*et qui ont besoin d'un backup qui fonctionne vraiment.*

**[⬆ Retour en haut](#wp-clone-master)**

</div>
