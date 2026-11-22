# Authentification sociale

Connexion OAuth avec Google, Microsoft, GitHub et Facebook, et association
explicite d’identités externes aux comptes locaux.

## Sécurité et configuration

- Utilise le flux Authorization Code, `state` à usage unique et PKCE lorsque le
  fournisseur le prend en charge.
- Les identités ne sont jamais rattachées automatiquement à un compte par
  adresse e-mail.
- Les secrets client restent dans l’environnement du serveur (`SOCIAL_*`) ; la
  base ne conserve que l’activation, le Client ID et l’URL de callback.
- La création de compte est refusée par défaut. L’option
  `SOCIAL_ACCOUNT_CREATION=verified_email` autorise uniquement les e-mails
  vérifiés par le fournisseur.
- L’authentification locale à deux facteurs reste requise après le retour OAuth.

Le framework hôte fournit l’authentification locale, le journal de connexion et
la page d’administration des fournisseurs.

Routes OAuth : `/connexion/social/{provider}` et
`/connexion/social/{provider}/callback`. Les associations de comptes sont
gérées depuis `/dashboard/account/securite/social`.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\social-auth
```
