<?php

return [
    'id' => 'fediverse',
    'class' => 'humhub\modules\fediverse\Module',
    'namespace' => 'humhub\modules\fediverse',
    'events' => [
        ['class' => 'humhub\modules\content\models\Content', 'event' => 'changedState', 'callback' => ['humhub\modules\fediverse\Events', 'onContentStateChanged']],
        ['class' => 'humhub\modules\post\models\Post', 'event' => 'afterSoftDelete', 'callback' => ['humhub\modules\fediverse\Events', 'onContentDeleted']],
    ],
    'consoleControllerMap' => [
        'fediverse' => 'humhub\modules\fediverse\commands\FediverseController',
    ],
    'urlManagerRules' => [
        ['pattern' => '.well-known/webfinger', 'route' => 'fediverse/webfinger/index'],
        ['pattern' => '.well-known/nodeinfo', 'route' => 'fediverse/nodeinfo/index'],
        ['pattern' => 'nodeinfo/2.1', 'route' => 'fediverse/nodeinfo/schema'],
        ['pattern' => 'fediverse/instance-actor', 'route' => 'fediverse/actor/instance'],
        ['pattern' => 'fediverse/inbox', 'route' => 'fediverse/inbox/shared', 'verb' => ['POST']],
        ['pattern' => 'fediverse/actor/<guid:[\w\-]+>/inbox', 'route' => 'fediverse/inbox/user', 'verb' => ['POST']],
        ['pattern' => 'fediverse/actor/<guid:[\w\-]+>/outbox', 'route' => 'fediverse/outbox/index'],
        ['pattern' => 'fediverse/actor/<guid:[\w\-]+>', 'route' => 'fediverse/actor/index'],
    ],
];
