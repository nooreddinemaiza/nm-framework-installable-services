# Service Caisse

Gestion des caisses, sessions, mouvements, encaissements et remboursements via
`sales-service`.

## Dépendances

- `sales-service` fournit les commandes et les opérations financières que le
  service Caisse encaisse ou rembourse.
- Le framework hôte fournit les utilisateurs, les paramètres, les permissions,
  les activités et l’infrastructure de base de données.

Le tableau de bord est disponible sur `/dashboard/cash`. Les migrations sont
ordonnées pour créer les caisses et sessions avant les reçus.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\cash-register
```
