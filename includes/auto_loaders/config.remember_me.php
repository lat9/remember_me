<?php
// -----
// Part of the "Remember Me" plugin, modified for operation under Zen Cart v1.5.0 and later
// by Cindy Merkin (aka lat9) of Vinos de Frutas Tropicales (vinosdefrutastropicales.com).
//
// Copyright (C) 2014-2017, Vinos de Frutas Tropicales
//
// -----
// Load and instantiate the plugin's observer class.  This needs to be at a breakpoint after
// the session is established (70) and the shopping-cart is instantiated (80).
//
$autoLoadConfig[71][] = array(
    'autoType' => 'class',
    'loadFile' => 'observers/class.remember_me_observer.php'
);
$autoLoadConfig[71][] = array(
    'autoType' => 'classInstantiate',
    'className' => 'remember_me_observer',
    'objectName' => 'remember_me'
);

// -----
// Load the plugin's initialization file, just after the shopping-cart is instantiated.  This
// module "extends" the class functionality to ensure that the just-restored customer's cart
// is also remembered.
//
$autoLoadConfig[81][] = array(
    'autoType' => 'init_script',
    'loadFile' => 'init_remembered_cart.php'
);
