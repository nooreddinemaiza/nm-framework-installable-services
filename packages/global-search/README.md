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

La page Services du framework hôte reste responsable de la sauvegarde de la
configuration public/tableau de bord.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\global-search
```
