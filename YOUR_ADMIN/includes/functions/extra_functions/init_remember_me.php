<?php
// -----
// Part of the "Remember Me" plugin, modified for operation under Zen Cart v1.5.5 and later
// by Cindy Merkin (aka lat9) of Vinos de Frutas Tropicales (vinosdefrutastropicales.com).
//
// Configuration settings are present in "Configuration->Customer Details".
//
// Copyright (C) 2014-2020.  Vinos de Frutas Tropicales
//
if (!defined('PERMANENT_LOGIN')) {
    $db->Execute(
        "INSERT INTO " . TABLE_CONFIGURATION . " 
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function, set_function) 
         VALUES 
            ( 'Enable Automatic Login?', 'PERMANENT_LOGIN', 'false', 'Enable the automatic login feature which will automatically log in a returning customer.', 5, 101, now(), NULL, 'zen_cfg_select_option (array(\'true\', \'false\'), ')"
    );

    $db->Execute(
        "INSERT INTO " . TABLE_CONFIGURATION . " 
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function, set_function) 
         VALUES 
            ( 'Automatic Login (Cookie Lifetime)', 'PERMANENT_LOGIN_EXPIRES', 14, 'Set the number of days to keep the customer logged in.', 5, 103, now(), NULL, NULL)"
    );

    $db->Execute(
        "INSERT INTO " . TABLE_CONFIGURATION . " 
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function, set_function) 
         VALUES 
            ( 'Automatic Login (Secret Key)', 'PERMANENT_LOGIN_SECRET', 'Remember My Secret!', 'Choose some random characters that will be used as the  secret key when saving the &quot;Remember Me&quot; cookie.', 5, 104, now(), NULL, NULL)"
    );
}

// -----
// These settings added for v2.0.0 and later
//
if (!defined('PERMANENT_LOGIN_CHECKBOX_DEFAULT')) {
    $db->Execute(
        "INSERT INTO " . TABLE_CONFIGURATION . " 
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function, set_function) 
         VALUES 
            ( 'Automatic Login (Checkbox Default)', 'PERMANENT_LOGIN_CHECKBOX_DEFAULT', 'false', 'Identify the default value for the display of the <b>Remember Me?</b> checkbox, present on the <code>login</code> and <code>create_account</code> pages.', 5, 105, now(), NULL, 'zen_cfg_select_option (array(\'true\', \'false\'), ')"
    );
}
