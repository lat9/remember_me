<?php
// -----
// Part of the "Remember Me" plugin, modified for operation under Zen Cart v1.5.8 and later
// by Cindy Merkin (aka lat9) of Vinos de Frutas Tropicales (vinosdefrutastropicales.com).
//
// Copyright (C) 2014-2025, Vinos de Frutas Tropicales
//
// -----
// Load and instantiate the plugin's observer class.  This needs to be at a breakpoint after
// the session is established (70) and before the shopping-cart is instantiated (80).
//
$autoLoadConfig[71][] = [
    'autoType' => 'class',
    'loadFile' => 'observers/class.remember_me_observer.php',
];
$autoLoadConfig[71][] = [
    'autoType' => 'classInstantiate',
    'className' => 'remember_me_observer',
    'objectName' => 'remember_me',
];

// -----
// Once the cart is instantiated, ensure that a just-restored customer's cart
// is also remembered.
//
$autoLoadConfig[81][] = [
    'autoType' => 'objectMethod',
    'objectName' => 'remember_me',
    'methodName' => 'restoreRememberedCart',
];
