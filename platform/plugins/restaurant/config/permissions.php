<?php

return [
    [
        'name' => 'Restaurant',
        'flag' => 'restaurant.index',
    ],
    [
        'name' => 'Menu Categories',
        'flag' => 'restaurant.menu-categories.index',
        'parent_flag' => 'restaurant.index',
    ],
    [
        'name' => 'Create',
        'flag' => 'restaurant.menu-categories.create',
        'parent_flag' => 'restaurant.menu-categories.index',
    ],
    [
        'name' => 'Edit',
        'flag' => 'restaurant.menu-categories.edit',
        'parent_flag' => 'restaurant.menu-categories.index',
    ],
    [
        'name' => 'Delete',
        'flag' => 'restaurant.menu-categories.destroy',
        'parent_flag' => 'restaurant.menu-categories.index',
    ],
    [
        'name' => 'Menu Items',
        'flag' => 'restaurant.menu-items.index',
        'parent_flag' => 'restaurant.index',
    ],
    [
        'name' => 'Create',
        'flag' => 'restaurant.menu-items.create',
        'parent_flag' => 'restaurant.menu-items.index',
    ],
    [
        'name' => 'Edit',
        'flag' => 'restaurant.menu-items.edit',
        'parent_flag' => 'restaurant.menu-items.index',
    ],
    [
        'name' => 'Delete',
        'flag' => 'restaurant.menu-items.destroy',
        'parent_flag' => 'restaurant.menu-items.index',
    ],
    [
        'name' => 'Reservations',
        'flag' => 'restaurant.reservations.index',
        'parent_flag' => 'restaurant.index',
    ],
    [
        'name' => 'Create',
        'flag' => 'restaurant.reservations.create',
        'parent_flag' => 'restaurant.reservations.index',
    ],
    [
        'name' => 'Edit',
        'flag' => 'restaurant.reservations.edit',
        'parent_flag' => 'restaurant.reservations.index',
    ],
    [
        'name' => 'Delete',
        'flag' => 'restaurant.reservations.destroy',
        'parent_flag' => 'restaurant.reservations.index',
    ],
    [
        'name' => 'Chefs',
        'flag' => 'restaurant.chefs.index',
        'parent_flag' => 'restaurant.index',
    ],
    [
        'name' => 'Create',
        'flag' => 'restaurant.chefs.create',
        'parent_flag' => 'restaurant.chefs.index',
    ],
    [
        'name' => 'Edit',
        'flag' => 'restaurant.chefs.edit',
        'parent_flag' => 'restaurant.chefs.index',
    ],
    [
        'name' => 'Delete',
        'flag' => 'restaurant.chefs.destroy',
        'parent_flag' => 'restaurant.chefs.index',
    ],
    [
        'name' => 'Settings',
        'flag' => 'restaurant.settings',
        'parent_flag' => 'restaurant.index',
    ],
];