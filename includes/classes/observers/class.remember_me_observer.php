<?php
// -----
// Part of the "Remember Me" plugin, modified for operation under Zen Cart v1.5.5 and later
// by Cindy Merkin (aka lat9) of Vinos de Frutas Tropicales (vinosdefrutastropicales.com).
//
// Version: 1.4.5
//
// Copyright (C) 2014-2017, Vinos de Frutas Tropicales
//
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!defined('PERMANENT_LOGIN_DEBUG')) {
    define('PERMANENT_LOGIN_DEBUG', 'false');  //-Set to either 'true' or 'false'
}

class remember_me_observer extends base 
{
    protected $secret,
              $cookie_name,
              $cookie_lifetime;
    
    public function __construct() 
    {
        $this->enabled = (defined('PERMANENT_LOGIN') && PERMANENT_LOGIN == 'true');
        $this->secret = (defined('PERMANENT_LOGIN_SECRET')) ? PERMANENT_LOGIN_SECRET : '';
        $this->cookie_lifetime = ((defined('PERMANENT_LOGIN_EXPIRES') && ((int)PERMANENT_LOGIN_EXPIRES) > 0) ? ((int)PERMANENT_LOGIN_EXPIRES) : 14) * 86400;
        $this->cookie_name = 'zcrm_' . md5(STORE_NAME);
        $this->debug = (PERMANENT_LOGIN_DEBUG == 'true');
        
        // -----
        // This flag "coordinates" the customer's "remembered" login with the plugin's init_include module,
        // allowing the customer's cart to be recorded within the currently-active session.  Used by the
        // restoreRememberedCart class-function and possibly set by the checkRememberCustomer class-function.
        //
        $this->customer_remembered = false;

        // -----
        // If a customer is not currently logged in, but there's a valid "Remember Me" cookie present ... then "Remember Them"!
        //
        if (!$_SESSION['customer_id'] && $this->enabled) {
            $remember_info = $this->decodeCookie();
            if (!is_array($remember_info)) {
                $this->removeCookie();
            } else {
                $this->checkRememberCustomer($remember_info);
            }
        }

        // -----
        // Refresh any "Remember Me" cookie currently set, updating its expiration data and recording the current
        // status of the session's cartID value.
        //
        $this->refreshCookie();

        // -----
        // Set up to listen for any related observers.
        //
        $this->attach ($this, array( 
            /* From /includes/modules/create_account.php */
            'NOTIFY_MODULE_CREATE_ACCOUNT_ADDED_CUSTOMER_RECORD', 
            /* From /includes/modules/pages/login/header_php.php */
            'NOTIFY_LOGIN_SUCCESS',
            /* From /includes/modules/pages/logoff/header_php.php */
            'NOTIFY_HEADER_START_LOGOFF',
        ));

    }

    public function update(&$class, $eventID, $paramsArray) 
    {
        global $db;
        switch ($eventID) {
            // -----
            // Upon successful login or account creation, if the customer has checked the "Remember Me" field then create the required cookie to keep them logged in.
            //
            case 'NOTIFY_LOGIN_SUCCESS': 
            case 'NOTIFY_MODULE_CREATE_ACCOUNT_ADDED_CUSTOMER_RECORD':
                if ($this->enabled && isset($_POST['permLogin']) && $_POST['permLogin'] == 1) {
                    $password_info = $db->Execute(
                        "SELECT customers_password 
                           FROM " . TABLE_CUSTOMERS . " 
                          WHERE customers_id = " . (int)$_SESSION['customer_id'] . " 
                          LIMIT 1"
                    );
                    if (!$password_info->EOF) {
                        $remember_me_info = array(
                            'id' => $_SESSION['customer_id'],
                            'password' => md5($this->secret . $password_info->fields['customers_password']),
                        );
                        $cookie_value = $this->encodeCookie($remember_me_info);
                        $this->setCookie($cookie_value, time() + $this->cookie_lifetime);
                    }
                }
                break;
                
            // -----
            // When the customer has chosen to click the "Logoff" link, expire any "Remember Me" cookies that might be present.
            //
            case 'NOTIFY_HEADER_START_LOGOFF':
                if ($this->enabled && !empty($_SESSION['customer_id'])) {
                    $this->removeCookie();
                }
                break;
                
            default:
                break;
        }
    }

    protected function checkRememberCustomer($remember_info) 
    {
        global $db;
        $customers_id = (isset($remember_info['id'])) ? (int)$remember_info['id'] : 0;
        $customers_hashed_password = (isset($remember_info['password'])) ? $remember_info['password'] : '~~~~';
        $customer_info = $db->Execute(
            "SELECT customers_firstname, customers_lastname, customers_password, customers_default_address_id, customers_authorization, customers_referral 
               FROM " . TABLE_CUSTOMERS . " 
              WHERE customers_id = $customers_id 
              LIMIT 1"
        );
        if ($customer_info->EOF || md5($this->secret . $customer_info->fields['customers_password']) != $customers_hashed_password) {
            $this->removeCookie();
          
        } else {
            $this->customer_remembered = true;  //- Indicates that the customer's cart should be restored once the cart is instantiated.

            $_SESSION['customer_id'] = $customers_id;
            $_SESSION['currency'] = $remember_info['currency'];
            $_SESSION['language'] = $remember_info['language'];
            $_SESSION['languages_id'] = $remember_info['languages_id'];
            $_SESSION['languages_code'] = $remember_info['languages_code'];
            $_SESSION['securityToken'] = $remember_info['securityToken'];

            $this->setCartId = (isset($remember_info['cartIdSet']) && $remember_info['cartIdSet'] === true);
            
            $check_country_query = 
                "SELECT entry_country_id, entry_zone_id 
                   FROM " . TABLE_ADDRESS_BOOK . " 
                  WHERE customers_id = $customers_id 
                    AND address_book_id = :addressBookID 
                  LIMIT 1";
            $check_country_query = $db->bindVars($check_country_query, ':addressBookID', $customer_info->fields['customers_default_address_id'], 'integer');
            $check_country = $db->Execute($check_country_query);
        
            $_SESSION['customer_default_address_id'] = $customer_info->fields['customers_default_address_id'];
            $_SESSION['customers_authorization'] = $customer_info->fields['customers_authorization'];
            $_SESSION['customer_first_name'] = $customer_info->fields['customers_firstname'];
            $_SESSION['customer_last_name'] = $customer_info->fields['customers_lastname'];
            $_SESSION['customer_country_id'] = $check_country->fields['entry_country_id'];
            $_SESSION['customer_zone_id'] = $check_country->fields['entry_zone_id'];
            $_SESSION['customer_remembered'] = true;

            $db->Execute(
                "UPDATE " . TABLE_CUSTOMERS_INFO . "
                    SET customers_info_date_of_last_logon = now(),
                        customers_info_number_of_logons = customers_info_number_of_logons+1
                  WHERE customers_info_id = $customers_id
                  LIMIT 1"
            );
        }
    }
    
    public function restoreRememberedCart()
    {
        if ($this->customer_remembered) {
            $_SESSION['cart']->restore_contents();
            if ($this->setCartId) {
                $_SESSION['cartID'] = $_SESSION['cart']->cartID;
            }
        }
    }

    public function create_checkbox() 
    {
        return ($this->enabled) ? ('<label class="checkboxLabel" for="permLogin" title="' . TEXT_REMEMBER_ME_ALT . '">' . TEXT_REMEMBER_ME . '</label>' . zen_draw_checkbox_field ('permLogin', '1', false, 'id="permLogin"') . '<br class="clearBoth" />') : '';
    }
    
    protected function encodeCookie($cookie_data)
    {
        $cookie_data['currency'] = $_SESSION['currency'];
        $cookie_data['language'] = $_SESSION['language'];
        $cookie_data['languages_id'] = $_SESSION['languages_id'];
        $cookie_data['languages_code'] = $_SESSION['languages_code'];
        $cookie_data['cartIdSet'] = isset($_SESSION['cartID']);
        $cookie_data['securityToken'] = $_SESSION['securityToken'];
        
        $encoded_cookie = base64_encode(gzcompress(serialize($cookie_data), 9));
        if ($this->debug) {
            error_log(date('Y-m-d H:i:s') . " encodeCookie, value = '$encoded_cookie': " . var_export($cookie_data, true) . PHP_EOL, 3, DIR_FS_LOGS . '/remember_me.log');
        }
        return $encoded_cookie;
    }
    
    protected function removeCookie()
    {
        unset($_COOKIE[$this->cookie_name]);
        $this->customer_remembered = false;
        $this->setCookie('', time() - 3600);
    }
    
    protected function decodeCookie()
    {
        $remember_info = false;
        $cookie_value = 'Not valid';
        if (isset($_COOKIE[$this->cookie_name])) {
            $cookie_value = $_COOKIE[$this->cookie_name];
            $remember_info = base64_decode($cookie_value);
            if ($remember_info !== false) {
                $remember_info = @gzuncompress($remember_info);
                if ($remember_info === false) {
                    trigger_error("gzuncompress error in decodeCookie, value = $cookie_value, _SERVER[HTTP_USER_AGENT] = " . $_SERVER['HTTP_USER_AGENT'] . ", _COOKIE = " . var_export($_COOKIE, true), E_USER_WARNING);
                } else {
                    $remember_info = unserialize($remember_info);
                }
            }
        }
        if ($this->debug) {
            error_log(date('Y-m-d H:i:s') . " decodeCookie, value = '$cookie_value': " . var_export($remember_info, true) . PHP_EOL, 3, DIR_FS_LOGS . '/remember_me.log');
        }
        return $remember_info;
    }
    
    protected function refreshCookie()
    {
        if (isset($_COOKIE[$this->cookie_name])) {
            $cookie_data = $this->decodeCookie();
            if (!is_array($cookie_data)) {
                $this->removeCookie();
            } else {
                $this->setCookie($this->encodeCookie($cookie_data), time() + $this->cookie_lifetime);
            }
        }
    }
    
    // -----
    // Set the "remember-me" cookie for the specific path associated with the current
    // store's configuration.
    //
    protected function setCookie($value, $expiration)
    {
        if (!isset($this->domain)) {
            $this->domain = str_replace(
                array(
                    'https://',
                    'http://',
                    '//'
                ),
                '',
                strtolower(HTTP_SERVER)
            );
            $this->path = DIR_WS_CATALOG;
        }
        setcookie($this->cookie_name, $value, $expiration, $this->path, $this->domain);
    }
 
}