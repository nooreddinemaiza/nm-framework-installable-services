# Translation

Choix de la langue par visiteur et traductions d’interface en français de
France et en arabe marocain.

## Dépendances

Aucun autre service installable n’est requis. Le framework hôte fournit le
gestionnaire de session, le middleware de locale et le contrat de traduction.
Le paquet ajoute ses fichiers `ui.php` au traducteur du framework sans écraser
les traductions fournies par l’application.

La préférence du visiteur est enregistrée par `POST /locale`; le jeton CSRF et
la destination de retour sont validés avant la redirection. Si l’application
hôte possède déjà cette route, le paquet la conserve au lieu d’en enregistrer
une seconde.

Vérifier la structure :

```powershell
python tools\verify_service.py --path service-publisher-sources\services\translation
```
