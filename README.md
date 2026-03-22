<div align="center">

<img src="https://img.shields.io/badge/WordPress-5.6+-21759B?style=for-the-badge&logo=wordpress&logoColor=white" />
<img src="https://img.shields.io/badge/PHP-7.4+-777BB4?style=for-the-badge&logo=php&logoColor=white" />
<img src="https://img.shields.io/badge/Licence-GPL%20v2-2E7D32?style=for-the-badge" />
<img src="https://img.shields.io/badge/Version-1.0.0-1B3A6B?style=for-the-badge" />

<br /><br />

# WP Clone Master

### Clonez, migrez et sauvegardez votre site WordPress — en quelques clics.

Pas de compétences serveur requises. Pas de timeout. Pas de données perdues.

<br />

[Démarrer](#démarrer) · [Fonctionnalités](#fonctionnalités) · [Comment ça marche](#comment-ça-marche) · [Nextcloud](#nextcloud) · [FAQ](#faq)

<br />

</div>

---

## Le problème que tout le monde connaît

Migrer un site WordPress a toujours été une épreuve. On exporte une base de données depuis phpMyAdmin, on espère que l'hébergeur ne va pas couper la connexion au bout de 30 secondes, on oublie de remplacer l'ancienne URL quelque part dans la base, et le site de destination est cassé. On recommence.

WP Clone Master règle ce problème une bonne fois pour toutes. Depuis l'interface WordPress, sans jamais toucher un terminal.

---

## Fonctionnalités

**Clonage et migration complets**

WP Clone Master exporte l'intégralité de votre site — base de données, thèmes, plugins, médias, fichiers de configuration — dans une seule archive ZIP. Importez-la sur n'importe quel hébergement en quelques minutes. Les URLs de l'ancien domaine sont remplacées automatiquement partout dans la base, y compris dans les données sérialisées qu'Elementor, WooCommerce ou WPML enfouissent au plus profond de vos tables.

**Sauvegardes automatiques planifiées**

Configurez une sauvegarde quotidienne, hebdomadaire ou mensuelle et oubliez-y. En cas d'échec vous recevez un email. En cas de succès aussi, si vous le souhaitez. Les anciennes sauvegardes sont supprimées automatiquement selon la politique de rétention que vous définissez — par nombre ou par ancienneté.

**Stockage local ou Nextcloud**

Gardez vos sauvegardes sur le serveur, ou envoyez-les automatiquement vers votre instance Nextcloud après chaque backup. Les fichiers volumineux sont envoyés par morceaux, sans jamais saturer la mémoire ni dépasser les limites de votre hébergeur.

**Zéro configuration post-migration**

Wordfence, Elementor, WP Rocket, les plugins de cache, tous laissent des traces de l'ancien serveur après une migration. WP Clone Master s'en charge, les fichiers CSS d'Elementor sont régénérés, les règles WAF de Wordfence sont neutralisées, les caches obsolètes sont supprimés. Votre site est propre dès le premier chargement.

**Blocage des moteurs de recherche pendant la migration**

Quand vous importez sur un nouveau domaine, les moteurs de recherche ne doivent pas indexer le site avant que vous l'ayez validé. WP Clone Master possède une option noindex et le maintient actif jusqu'à ce que vous le désactiviez manuellement depuis les réglages WordPress. Pas de risque de contenu dupliqué indexé par erreur.


**L'export**

Depuis l'interface d'administration, lancez l'export. Le plugin découpe le travail en petites étapes — un thème, un plugin, un mois d'uploads à la fois — pour ne jamais dépasser les limites de votre hébergeur. Une barre de progression vous indique où en est l'opération. À la fin, un ZIP est prêt à télécharger.

**L'import**

Uploadez l'archive sur le site de destination. Le plugin extrait les données, prépare l'environnement, puis lance un processus indépendant de WordPress pour effectuer l'import. Ce processus est indépendant pour une raison simple : quand on remplace la base de données, WordPress lui-même ne peut plus être utilisé comme support — il faut un outil qui se tient en dehors.

Le remplacement des URLs se fait en plusieurs passes courtes plutôt qu'en une seule requête longue. Même sur un hébergement mutualisé avec des délais d'exécution très courts, la migration ira jusqu'au bout.

**La finalisation**

Une fois l'import terminé, le processus se supprime lui-même du serveur. Il ne laisse aucun fichier temporaire, aucune porte ouverte. Le site est opérationnel.

---

## Nextcloud

WP Clone Master s'intègre avec Nextcloud via le protocole d'authentification officiel Login Flow v2 — celui utilisé par l'application desktop Nextcloud. Vous n'avez pas à créer de token manuellement ni à saisir votre mot de passe principal dans l'interface WordPress. Un clic ouvre la page d'autorisation Nextcloud, vous approuvez, la connexion est établie.

Les sauvegardes sont envoyées vers le dossier de votre choix sur Nextcloud. Les fichiers de plusieurs gigaoctets sont découpés en morceaux de 10 Mo et envoyés séquentiellement — aucun proxy, aucun CDN, aucune limite de timeout ne peut bloquer le transfert.

---

## Diagnostic serveur intégré

Avant de lancer une migration, consultez le tableau de bord de diagnostic. Il affiche en un coup d'oeil tout ce qui peut influencer le bon déroulement d'une sauvegarde : version PHP, limites mémoire, espace disque disponible, extensions installées, droits d'écriture sur les répertoires. Si quelque chose risque de poser problème, vous le savez avant de commencer.

---

## Démarrer

**Installation**

Téléchargez le ZIP depuis la page [Releases](../../releases), puis dans WordPress :

```
Extensions > Ajouter > Téléverser une extension
```

Activez le plugin. Le menu **Clone Master** apparaît dans la barre latérale.

**Première sauvegarde**

Rendez-vous dans l'onglet **Planification**, activez les sauvegardes automatiques, choisissez la fréquence et enregistrez. La première sauvegarde se déclenchera à la prochaine heure ronde.

Pour une sauvegarde immédiate, cliquez sur **Sauvegarder maintenant** — le plugin ferme la connexion HTTP en moins d'une seconde et continue le travail en arrière-plan. Rafraîchissez l'historique pour suivre l'avancement.

**Première migration**

Depuis l'onglet **Export**, lancez l'export de votre site source. Une fois le ZIP téléchargé, rendez-vous sur le site de destination et utilisez l'onglet **Import** pour téléverser l'archive et démarrer la migration.

---

## FAQ

**Le plugin fonctionne-t-il sur les hébergements mutualisés ?**

Oui et c'est précisément le cas d'usage pour lequel il a été conçu. L'export et l'import sont découpés en petites opérations courtes qui respectent les limites d'exécution des hébergements partagés, même les plus restrictifs.

**Que se passe-t-il si l'import est interrompu ?**

Le processus d'import reprend là où il s'est arrêté. La position dans la base de données et l'avancement du remplacement d'URLs sont sauvegardés entre chaque appel. Une interruption réseau ne compromet pas la migration.

**Mon site utilise Elementor / WooCommerce / WPML. Est-ce supporté ?**

Le remplacement d'URLs gère les formats sérialisés, JSON imbriqué, les URLs encodées et les chemins système utilisés par ces plugins. Les fichiers CSS générés par Elementor sont supprimés après migration pour forcer leur régénération avec le nouveau domaine.

**Les sauvegardes manuelles sont-elles supprimées par la rétention ?**

La rétention automatique ne touche que les sauvegardes créées par le planificateur. Vos sauvegardes manuelles sont conservées indéfiniment jusqu'à suppression explicite.

**Le plugin supporte-t-il le multisite WordPress ?**

La version actuelle ne supporte pas le multisite. Un avertissement est affiché si un environnement multisite est détecté.

**Faut-il désactiver des plugins avant de migrer ?**

WP Clone Master gère automatiquement les plugins dont le rechargement immédiat est problématique après migration (pare-feux, plugins de cache, connexions liées au domaine source). Vous en êtes informé dans le résumé de migration et vous les réactivez manuellement après avoir vérifié que le site fonctionne correctement.

---

## Configuration requise

| Composant | Version minimale |
|---|---|
| WordPress | 5.6 |
| PHP | 7.4 |
| MySQL | 5.7 |
| Extensions PHP | zip, mysqli, json, openssl |

---

## Licence

WP Clone Master est distribué sous licence [GPL v2 ou ultérieure](LICENSE).

---
 
Si WP Clone Master vous a économisé du temps, vous pouvez soutenir le développement ici :
 
<a href="https://buymeacoffee.com/assistouest">
  <img src="https://img.shields.io/badge/Soutenir%20le%20projet-Buy%20me%20a%20coffee-FFDD00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black" />
</a>
 
</div>
 

