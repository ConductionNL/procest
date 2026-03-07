<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load', 'url' => '/api/settings/load', 'verb' => 'POST'],
        ['name' => 'zgw_mapping#index', 'url' => '/api/zgw-mappings', 'verb' => 'GET'],
        ['name' => 'zgw_mapping#show', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'GET'],
        ['name' => 'zgw_mapping#update', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'PUT'],
        ['name' => 'zgw_mapping#destroy', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'DELETE'],
        ['name' => 'zgw_mapping#reset', 'url' => '/api/zgw-mappings/{resourceKey}/reset', 'verb' => 'POST'],
        // ZGW API routes.
        ['name' => 'zgw#index', 'url' => '/api/zgw/{zgwApi}/v1/{resource}', 'verb' => 'GET'],
        ['name' => 'zgw#create', 'url' => '/api/zgw/{zgwApi}/v1/{resource}', 'verb' => 'POST'],
        ['name' => 'zgw#show', 'url' => '/api/zgw/{zgwApi}/v1/{resource}/{uuid}', 'verb' => 'GET'],
        ['name' => 'zgw#update', 'url' => '/api/zgw/{zgwApi}/v1/{resource}/{uuid}', 'verb' => 'PUT'],
        ['name' => 'zgw#patch', 'url' => '/api/zgw/{zgwApi}/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'zgw#destroy', 'url' => '/api/zgw/{zgwApi}/v1/{resource}/{uuid}', 'verb' => 'DELETE'],
    ],
];
