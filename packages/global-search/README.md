# Global Search

Recherche unifiée dans les contenus publics et les données du tableau de bord.

## Dépendances

Aucun service de contenu n’est requis obligatoirement. Les types de recherche
sont ajoutés uniquement lorsque le service correspondant est installé et que
ses tables attendues existent. Les comptes et groupes sont fournis par le
framework hôte.

Routes publiques : `/api/search` et `/search/results`. Routes du tableau de
bord : `/dashboard/search/api` et `/dashboard/search/results`. Les styles et
scripts du service sont servis depuis son propre paquet.

Quand le paquet est actif, sa configuration public/tableau de bord est
accessible depuis sa carte dans Paramètres → Services installables. Le paquet
fournit lui-même la modal et la route de sauvegarde.

La table `global_search_settings` est créée par la migration du paquet, et non
par les migrations hôtes. Si une ancienne installation possède déjà cette
table, la migration vérifie son schéma et son retour arrière la conserve.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\global-search
```
