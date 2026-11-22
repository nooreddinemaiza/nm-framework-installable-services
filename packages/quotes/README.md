# Gestion des devis

Paquet indépendant de demandes publiques de devis, de consultation du tableau de
bord, de suivi des statuts et d’export PDF/CSV.

## Dépendances

- `inventory-service` fournit les produits et variantes consultés et validés
  lors de la recherche et de la création des lignes de devis.
- Le framework hôte fournit les utilisateurs, les paramètres, les permissions
  et les composants PDF partagés.

Les demandes publiques sont disponibles sur `POST /api/quotes/submit` et la
recherche de produits sur `GET /api/quotes/catalog-search`. Le tableau de bord
est exposé sur `/dashboard/quotes`.

Les valeurs de configuration sont stockées sous `quotes.*` dans les paramètres
du framework hôte; l’interface de configuration reste celle de la page Services
de l’hôte.

La conversion d’un devis accepté en commande n’est pas implémentée par le
service source actuel; elle doit être apportée par une intégration avec le
service des ventes.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\quotes
```
