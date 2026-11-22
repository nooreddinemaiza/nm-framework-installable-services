# Paiements en ligne

Paquet indépendant du noyau de paiements en ligne : configuration, stockage
chiffré des secrets, tentatives de paiement, notifications signées,
rapprochement, remboursements et tableau de bord.

- Identifiant : `online-payments`
- Namespace : `NM\Services\OnlinePayments`
- Version initiale : `1.0.0`
- PHP minimal : `8.3`
- Dépendance obligatoire : `sales-service`
- Extensions : `openssl`, `json`

Le paquet reprend les contrôleurs, routes, vues, modèles et migration du
service installable existant. Il s’intègre au socle hôte partagé pour
l’authentification, les comptes et les permissions (`App\Models\User`,
`App\Helpers\NavbarFactory`). L’application doit fournir `APP_URL` en HTTPS
(HTTP local est accepté) et `ONLINE_PAYMENTS_ENCRYPTION_KEY`, une clé de
32 octets encodée en hexadécimal ou Base64.

Aucun adaptateur de passerelle n’est inclus : tant qu’un adaptateur réel n’est
pas enregistré et configuré, le paquet n’initie aucun paiement. Les retours
navigateur ne confirment jamais un paiement; seules les notifications
fournisseur vérifiées alimentent le rapprochement.

Routes fournies : `/dashboard/online-payments`, `/online-payment/return` et
`POST /online-payment/webhook/{providerCode}`.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\online-payments
```

Préparer l’archive signée sans publication GitHub :

```powershell
python tools\publish_service.py `
  --source service-publisher-sources\services\online-payments `
  --owner mon-organisation `
  --repository registre-services `
  --key-id online-payments-release
```
