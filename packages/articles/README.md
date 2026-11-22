# Articles

Gestion des articles et catégories, publication publique, statistiques de
consultation et interface d’administration.

## Dépendances

Le paquet ne dépend d’aucun autre service installable. Le framework hôte fournit
les utilisateurs, paramètres, permissions, rendu des vues et base de données.

Routes publiques : `/articles` et `/articles/{slug}`. Le tableau de bord est
disponible sur `/dashboard/articles`; la ressource d’administration est servie
par le paquet.

La page Services du framework hôte reste responsable de l’enregistrement des
paramètres de configuration.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\articles
```
