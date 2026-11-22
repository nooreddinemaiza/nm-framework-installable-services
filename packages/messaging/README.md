# Messaging

Conversations privées et de groupe, messages, accusés de lecture, politiques et médias.

## Dépendances

Aucun autre service installable n’est requis. Le framework hôte fournit les
comptes, groupes, rôles, sessions, permissions et la configuration du relais
temps réel. Le rôle hôte doit déclarer les permissions `conversations.*`.

Le tableau de bord est disponible sous `/dashboard/conversations`. Les médias
temporaires restent dans le stockage privé du framework; l’image de remplacement
par défaut est incluse dans les ressources du paquet.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\messaging
```

Ce service est indépendant du framework. Vérifiez sa structure avec `python tools\verify_service.py --path <dossier-du-service>` puis préparez son paquet avec `python tools\publish_service.py --source <dossier-du-service>`.
