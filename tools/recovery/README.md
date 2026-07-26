# Kit de récupération WPCM

Ce kit fonctionne sans charger WordPress. Il permet d'inspecter, de vérifier et d'extraire une archive `.wpcm` lorsque le site, le thème ou une extension provoque une erreur fatale.

## Commandes

```bash
php wpcm-recovery.php info /chemin/sauvegarde.wpcm
php wpcm-recovery.php verify /chemin/sauvegarde.wpcm
php wpcm-recovery.php extract /chemin/sauvegarde.wpcm /chemin/dossier-vide
```

L'extraction est reprenable. Le dossier de destination contient ensuite :

- `database.sql`
- `wp-content/`

## Restauration manuelle

Lorsque le cœur WordPress et `wp-config.php` fonctionnent encore :

```bash
wp db import /chemin/dossier-vide/database.sql --skip-plugins --skip-themes
rsync -a /chemin/dossier-vide/wp-content/ /chemin/wordpress/wp-content/
```

Commencez toujours dans un dossier vide et conservez l'archive originale en lecture seule.
