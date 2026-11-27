# Gestion des produits et des stocks

Paquet indépendant du catalogue produits et du domaine Inventory : écrans
d’administration, catalogue public, mouvements de stock, réservations,
inventaires et réductions. Il reprend les services, contrôleurs, routes,
vues, scripts et migrations actuellement fournis par l’application.

- Identifiant de paquet : `inventory-service`
- Namespace : `NM\Services\InventoryService`
- Version initiale : `1.0.0`
- PHP minimal : `8.3`

Le paquet fournit ses bindings, sa configuration par défaut et ses migrations.
Il fournit également le dialogue de configuration Inventory dans les paramètres
des services installés (`/dashboard/inventory/configuration`) et dans sa page
d’administration.
Les pages publiques des produits, catégories et marques n’affichent le widget
de demande de devis que lorsque le paquet optionnel `quotes` est actif. Ce
dernier dépend d’`inventory-service` pour rechercher et valider les produits.
Le code actuellement présent dans l’application n’est pas supprimé ni déplacé
dans cette étape : le paquet est une copie indépendante à vérifier avant toute
publication et tout basculement du runtime.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\inventory-service
```

Préparer l’archive signée sans publication GitHub :

```powershell
python tools\publish_service.py `
  --source service-publisher-sources\services\inventory-service `
  --owner mon-organisation `
  --repository registre-services `
  --key-id inventory-service-release
```
