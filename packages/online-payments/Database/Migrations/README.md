`001-online-payments.php` crée les tables du service après celles de
`sales-service`; la dépendance de paquet impose cet ordre. Chaque fichier doit
retourner une instance de `Core\Database\Schema\Migration`.
