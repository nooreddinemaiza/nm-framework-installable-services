<?php

use NM\Services\Translation\Controllers\LocaleController;

foreach ($router->getRoutes()->get('POST') as $route) {
    if ($route->getUri() === '/locale') {
        return;
    }
}

$router->post('/locale', [LocaleController::class, 'update']);
