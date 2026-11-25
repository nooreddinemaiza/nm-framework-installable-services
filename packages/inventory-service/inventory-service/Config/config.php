<?php

declare(strict_types=1);

return [
    'catalog' => [
        'default_status' => 'active',
        'category_max_depth' => 10,
        'sku_prefix' => 'CAT',
        'sku_padding' => 8,
    ],
    'inventory' => [
        'enabled' => true,
        'multi_warehouse' => false,
        'quantity_scale' => 6,
        'allow_backdating' => false,
        'concurrent_movement_mode' => 'snapshot_adjust',
        'transaction_mode' => 'join',
    ],
];
