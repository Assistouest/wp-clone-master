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

 
Si WP Clone Master vous a économisé du temps, vous pouvez soutenir le développement ici :
 
<a href="https://buymeacoffee.com/assistouest">
  <img src="https://img.shields.io/badge/Soutenir%20le%20projet-Buy%20me%20a%20coffee-FFDD00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black" />
</a>
 
</div>
 

