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

La migration réutilise la table `global_search_settings` déjà créée par
l’application hôte lorsqu’elle existe et vérifie que son schéma est compatible.
Dans le cas contraire, le paquet la crée et garde une marque de propriété afin
que son retour arrière ne supprime pas une table gérée par l’hôte.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\global-search
```
