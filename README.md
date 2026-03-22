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

Migrer un site WordPress est devenu un parcours du combattant. La majorité des outils leaders du marché reposent sur un modèle économique payant.
### WP Clone Master brise ce cycle

Nous pensons que la liberté de déplacer vos propres données doit être totale, immédiate et surtout gratuite. Vous ne devriez jamais avoir à sortir votre carte bleue pour simplement changer d'hébergeur ou restaurer une sauvegarde que vous avez vous-même générée. 

Le plugin a été conçu pour ignorer les barrières de taille. Que votre site pèse 100 Mo ou 10 Go, le processus d'importation reste accessible sans aucune restriction. Nous avons remplacé les barrières de paiement par de l'ingénierie solide. Là où les autres bloquent l'utilisateur, nous utilisons une architecture qui découpe les tâches lourdes en micro-opérations pour éviter les plantages serveur (timeouts), même sur les hébergements mutualisés les plus restrictifs.

### Une sécurité auditée et une liberté retrouvée

Parce qu'une migration touche au cœur de votre site, nous n'avons fait aucun compromis sur la fiabilité. WP Clone Master intègre des mécanismes de défense de niveau industriel, incluant un installeur qui s'autodétruit après usage pour ne laisser aucune porte ouverte sur votre nouveau serveur. Un audit technique indépendant a d'ailleurs attribué la note de 9.2/10 à notre architecture de sécurité.

En choisissant WP Clone Master, vous rejoignez une vision de l'Open Source où les fonctionnalités essentielles comme la sauvegarde vers Nextcloud ou l'importation illimitée ne sont pas des options payantes, mais des droits fondamentaux pour chaque administrateur WordPress.

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
 

