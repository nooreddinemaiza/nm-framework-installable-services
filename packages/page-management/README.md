# Page Management

Gestion de pages publiques, templates, sections, blocs, menus, SEO et révisions.

## Dépendances

Aucun autre service installable n’est requis. Le framework hôte fournit les
utilisateurs, permissions, sessions, cache, événements, vues et base de données.
La page de permissions de l’hôte doit définir les capacités `pages.*` utilisées
par le tableau de bord.

Le tableau de bord est disponible sur `/dashboard/pages`, les pages publiques
sur `/p/{slug}`. Le routage de la page d’accueil `/` reste la responsabilité du
shell du framework hôte.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\page-management
```
