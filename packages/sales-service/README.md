# Gestion des ventes

Paquet indépendant du service Sales : applications clientes, clés d’accès,
connecteurs, paniers, commandes, journal de paiements, boutique en ligne,
tableau de bord, statistiques, exports et confirmation de commande.

- Identifiant : `sales-service`
- Namespace : `NM\Services\SalesService`
- Version initiale : `1.0.0`
- PHP minimal : `8.3`
- Dépendance obligatoire : `inventory-service` (catalogue, prix, stock et finalisation)

Le paquet reprend le domaine, les modèles, les migrations, le fournisseur de
services, les contrôleurs, les routes, les vues et les scripts utilisés par
l’application. Il attend aussi le socle hôte partagé pour l’authentification,
les utilisateurs, les permissions, la navigation et le courrier (`App\Models\User`,
`App\Helpers\NavbarFactory`, `App\Services\Mail`).

Le runtime historique et son catalogue de services ne sont pas modifiés dans
cette étape. Les migrations sont incluses dans l’archive du paquet et seront
appliquées par le gestionnaire de services indépendants après activation.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\sales-service
```

Préparer l’archive signée sans publication GitHub :

```powershell
python tools\publish_service.py `
  --source service-publisher-sources\services\sales-service `
  --owner mon-organisation `
  --repository registre-services `
  --key-id sales-service-release
```
