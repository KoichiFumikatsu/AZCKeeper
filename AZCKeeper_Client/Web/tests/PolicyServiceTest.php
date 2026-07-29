<?php
use Keeper\PolicyService;

suite('PolicyService::deepMerge');

// El bug: las listas se combinaban por índice numérico y heredaban la cola de la base.
assertSame_(
    ['domains' => ['solo-este.com']],
    PolicyService::deepMerge(
        ['domains' => ['facebook.com', 'instagram.com', 'x.com']],
        ['domains' => ['solo-este.com']]
    ),
    'lista mas corta reemplaza completa, no hereda cola'
);

assertSame_(
    ['domains' => []],
    PolicyService::deepMerge(
        ['domains' => ['facebook.com', 'x.com']],
        ['domains' => []]
    ),
    'lista vacia vacia la lista base'
);

assertSame_(
    ['domains' => ['a.com', 'b.com', 'c.com']],
    PolicyService::deepMerge(
        ['domains' => ['a.com']],
        ['domains' => ['a.com', 'b.com', 'c.com']]
    ),
    'lista mas larga reemplaza completa'
);

// Los mapas asociativos SI deben mergearse en profundidad.
assertSame_(
    ['webBlocking' => ['enabled' => true, 'syncIntervalSeconds' => 600]],
    PolicyService::deepMerge(
        ['webBlocking' => ['enabled' => false, 'syncIntervalSeconds' => 600]],
        ['webBlocking' => ['enabled' => true]]
    ),
    'mapa anidado se mergea en profundidad'
);

assertSame_(
    ['enabled' => true],
    PolicyService::deepMerge(['enabled' => false], ['enabled' => true]),
    'escalar sobrescribe'
);

assertSame_(
    ['a' => 1, 'b' => 2],
    PolicyService::deepMerge(['a' => 1], ['b' => 2]),
    'clave nueva se agrega'
);

// Caso realista completo: politica de usuario sobre global.
assertSame_(
    [
        'webBlocking' => [
            'enabled' => true,
            'syncIntervalSeconds' => 300,
            'domains' => ['facebook.com'],
        ],
        'blocking' => ['enableDeviceLock' => false],
    ],
    PolicyService::deepMerge(
        [
            'webBlocking' => [
                'enabled' => true,
                'syncIntervalSeconds' => 300,
                'domains' => ['facebook.com', 'instagram.com', 'x.com', 'netflix.com'],
            ],
            'blocking' => ['enableDeviceLock' => false],
        ],
        [
            'webBlocking' => ['domains' => ['facebook.com']],
        ]
    ),
    'politica de usuario acorta dominios sin tocar el resto'
);
