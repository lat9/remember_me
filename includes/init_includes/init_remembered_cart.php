<?php
// -----
// Part of the "Remember Me" plugin, modified for operation under Zen Cart v1.5.0 and later
// by Cindy Merkin (aka lat9) of Vinos de Frutas Tropicales (vinosdefrutastropicales.com).
//
// Copyright (C) 2017, Vinos de Frutas Tropicales
//
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

// -----
// Restore the "remembered" customer's cart contents, if the current session was restored.
// The plugin's observer-class will perform that restoration if the current page-load
// resulted in the customer being "remembered".
//
$remember_me->restoreRememberedCart();