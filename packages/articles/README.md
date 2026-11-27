# Articles

Gestion des articles et catégories, publication publique, statistiques de
consultation et interface d’administration.

## Dépendances

Le paquet ne dépend d’aucun autre service installable. Le framework hôte fournit
les utilisateurs, paramètres, permissions, rendu des vues et base de données.

Routes publiques : `/articles` et `/articles/{slug}`. Le tableau de bord est
disponible sur `/dashboard/articles`; la ressource d’administration est servie
par le paquet.

Le package fournit son propre dialogue de configuration, son endpoint
`/dashboard/articles/configuration` et le script associé. La page Services du
framework découvre automatiquement les dialogues des services activés via
`Views/partials/dashboard/service-configurations/<id>.view.php`.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\articles
```
