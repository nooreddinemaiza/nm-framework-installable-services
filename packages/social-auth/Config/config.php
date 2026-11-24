<?php

declare(strict_types=1);

/**
 * Configuration du service Social Auth.
 *
 * Les paramètres des fournisseurs OAuth sont définis avec 'enabled' => false par défaut.
 * Activez-les en définissant des variables d'environnement SOCIAL_{ID}_ENABLED=true
 * et en configurant SOCIAL_{ID}_CLIENT_ID et SOCIAL_{ID}_CLIENT_SECRET.
 *
 * Exemple pour Google :
 *   SOCIAL_GOOGLE_ENABLED=true
 *   SOCIAL_GOOGLE_CLIENT_ID=votre-client-id
 *   SOCIAL_GOOGLE_CLIENT_SECRET=votre-client-secret
 */
return [
    'providers' => [],
];