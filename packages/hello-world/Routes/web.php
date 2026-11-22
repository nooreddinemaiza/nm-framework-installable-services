<?php

$router->get(
    '/hello-world/hello',
    static fn() => new \Core\Http\Response('Hello from the installable service!')
);
