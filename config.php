<?php

return [
    'id' => 'fediverse',
    'class' => 'humhub\modules\fediverse\Module',
    'namespace' => 'humhub\modules\fediverse',
    'events' => [
        // Event handlers are registered in later plans (outbound listeners, etc.).
    ],
    'consoleControllerMap' => [
        'fediverse' => 'humhub\modules\fediverse\commands\FediverseController',
    ],
    'urlManagerRules' => [
        ['pattern' => '.well-known/webfinger', 'route' => 'fediverse/webfinger/index'],
        ['pattern' => '.well-known/nodeinfo', 'route' => 'fediverse/nodeinfo/index'],
        ['pattern' => 'nodeinfo/2.1', 'route' => 'fediverse/nodeinfo/schema'],
        ['pattern' => 'fediverse/instance-actor', 'route' => 'fediverse/actor/instance'],
        ['pattern' => 'fediverse/actor/<guid:[\w\-]+>', 'route' => 'fediverse/actor/index'],
    ],
];
