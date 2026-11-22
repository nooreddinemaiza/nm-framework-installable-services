Les migrations sont découvertes et exécutées par ordre lexical. Chaque fichier
PHP doit retourner une instance de `Core\Database\Schema\Migration`.

Ordre Sales :

1. `001-sales-core.php` crée applications, modules, clés, connecteurs et journaux.
2. `002-sales-cart-orders.php` crée paniers, commandes et documents.
3. `003-sales-public-reference.php` ajoute les références publiques de commande.
4. `004-sales-payment-ledger.php` crée le registre de paiements et remboursements.
