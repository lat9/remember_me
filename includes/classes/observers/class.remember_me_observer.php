<?php
// -----
// Part of the "Remember Me" plugin, modified for operation under Zen Cart v1.5.0 and later
// by Cindy Merkin (aka lat9) of Vinos de Frutas Tropicales (vinosdefrutastropicales.com).
//
// Copyright (C) 2014.  Vinos de Frutas Tropicales
//
if (!defined('IS_ADMIN_FLAG')) {
  die('Illegal Access');
}

class remember_me_observer extends base {

  function __construct () {
    $this->enabled = (defined ('PERMANENT_LOGIN') && PERMANENT_LOGIN == 'true');
    $this->secret = (defined ('PERMANENT_LOGIN_SECRET')) ? PERMANENT_LOGIN_SECRET : '';
    $this->cookie_lifetime = ((defined ('PERMANENT_LOGIN_EXPIRES') && ((int)PERMANENT_LOGIN_EXPIRES) > 0) ? ((int)PERMANENT_LOGIN_EXPIRES) : 14) * 86400;
    $this->cookie_name = 'zcrm_' . md5 (STORE_NAME);
    
    // -----
    // If a customer is not currently logged in, but there's a "Remember Me" cookie present then "Remember Them"!
    //
    if (!$_SESSION['customer_id'] && $this->enabled && isset ($_COOKIE[$this->cookie_name])) {
      $remember_info = base64_decode ($_COOKIE[$this->cookie_name]);
      if ($remember_info !== false) {
        $remember_info = gzuncompress ($remember_info);
        if ($remember_info !== false) {
          $remember_info = unserialize ($remember_info);
        }
      }
      if (!is_array ($remember_info)) {
        setcookie ($this->cookie_name, '', time() - 3600);
        
      } else {
        $this->check_remember_customer ($remember_info);
        
      }
    }
    
    // -----
    // If the "Remember Me" cookie exists, refresh its expiration time.
    //
    if (isset ($_COOKIE[$this->cookie_name])) {
      setcookie ($this->cookie_name, $_COOKIE[$this->cookie_name], time() + $this->cookie_lifetime);
      
    }
    
    // -----
    // Set up to listen for any related observers.
    //
    $this->attach ($this, array ( /* From /includes/modules/create_account.php */
                                  'NOTIFY_MODULE_CREATE_ACCOUNT_ADDED_CUSTOMER_RECORD', 
                                  /* From /includes/modules/pages/login/header_php.php */
                                  'NOTIFY_LOGIN_SUCCESS',
                                  /* From /includes/modules/pages/logoff/header_php.php */
                                  'NOTIFY_HEADER_START_LOGOFF') );
    
  }
  
  function update (&$class, $eventID, $paramsArray) {
    global $db;
    switch ($eventID) {
      // -----
      // Upon successful login or account creation, if the customer has checked the "Remember Me" field then create the required cookie to keep them logged in.
      //
      case 'NOTIFY_LOGIN_SUCCESS': 
      case 'NOTIFY_MODULE_CREATE_ACCOUNT_ADDED_CUSTOMER_RECORD': {
        if ($this->enabled && isset ($_POST['permLogin']) && $_POST['permLogin'] == 1) {
          $password_info = $db->Execute ("SELECT customers_password FROM " . TABLE_CUSTOMERS . " WHERE customers_id = '" . $_SESSION['customer_id'] . "'");
          if (!$password_info->EOF) {
            $remember_me_info = array ( 'id' => $_SESSION['customer_id'],
                                        'password' => md5 ($this->secret . $password_info->fields['customers_password']) );
            $cookie_value = base64_encode (gzcompress (serialize ($remember_me_info), 9));
            setcookie ($this->cookie_name, $cookie_value, time() + $this->cookie_lifetime);
            
          }
        }
        break;
      }
      // -----
      // When the customer has chosen to click the "Logoff" link, expire any "Remember Me" cookies that might be present.
      //
      case 'NOTIFY_HEADER_START_LOGOFF': {
        if ($this->enabled && !empty ($_SESSION['customer_id'])) {
          setcookie ($this->cookie_name, '', time() - 3600);
          
        }
        break;
      }
      default: {
        break;
      }
    }
  }
  
  function check_remember_customer ($remember_info) {
    global $db;
    $customers_id = (isset ($remember_info['id'])) ? (int)$remember_info['id'] : 0;
    $customers_hashed_password = (isset ($remember_info['password'])) ? $remember_info['password'] : '~~~~';
    $customer_info = $db->Execute ("SELECT customers_firstname, customers_lastname, customers_password, customers_default_address_id, customers_authorization, customers_referral FROM " . TABLE_CUSTOMERS . " WHERE customers_id = $customers_id LIMIT 1");
    if ($customer_info->EOF || md5 ($this->secret . $customer_info->fields['customers_password']) != $customers_hashed_password) {
      setcookie ($this->cookie_name, '', time() - 3600);
      
    } else {
      $check_country_query = "SELECT entry_country_id, entry_zone_id FROM " . TABLE_ADDRESS_BOOK . " WHERE customers_id = $customers_id AND address_book_id = :addressBookID";
      $check_country_query = $db->bindVars ($check_country_query, ':addressBookID', $customer_info->fields['customers_default_address_id'], 'integer');
      $check_country = $db->Execute ($check_country_query);

      $_SESSION['customer_id'] = $customers_id;
      $_SESSION['customer_default_address_id'] = $customer_info->fields['customers_default_address_id'];
      $_SESSION['customers_authorization'] = $customer_info->fields['customers_authorization'];
      $_SESSION['customer_first_name'] = $customer_info->fields['customers_firstname'];
      $_SESSION['customer_last_name'] = $customer_info->fields['customers_lastname'];
      $_SESSION['customer_country_id'] = $check_country->fields['entry_country_id'];
      $_SESSION['customer_zone_id'] = $check_country->fields['entry_zone_id'];
      $_SESSION['customer_remembered'] = true;

      $db->Execute ("UPDATE " . TABLE_CUSTOMERS_INFO . "
                        SET customers_info_date_of_last_logon = now(),
                            customers_info_number_of_logons = customers_info_number_of_logons+1
                      WHERE customers_info_id = $customers_id");      
  
    }
  }
  
  function create_checkbox () {
    $checkbox = '';
    return ($this->enabled) ? ('<label class="checkboxLabel" for="permLogin" title="' . TEXT_REMEMBER_ME_ALT . '">' . TEXT_REMEMBER_ME . '</label>' . zen_draw_checkbox_field ('permLogin', '1', false, 'id="permLogin"') . '<br class="clearBoth" />') : '';

  }
 
}