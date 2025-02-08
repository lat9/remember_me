<?php
// -----
// Part of the "Remember Me" plugin, modified for operation under Zen Cart v1.5.8 and later
// by Cindy Merkin (aka lat9) of Vinos de Frutas Tropicales (vinosdefrutastropicales.com).
//
// Version: 2.1.1
//
// Copyright (C) 2014-2025, Vinos de Frutas Tropicales
//
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

zen_define_default('PERMANENT_LOGIN_DEBUG', 'false');               //-Set to either 'true' or 'false'
zen_define_default('PERMANENT_LOGIN_CHECKBOX_DEFAULT', 'false');    //-Set to either 'true' or 'false' (configuration setting for v2.0.0 and later).

class remember_me_observer extends base
{
    protected bool $enabled;
    protected string $secret;
    protected int $cookie_lifetime;
    protected bool $checkbox_default;
    protected string $cookie_name;
    protected bool $debug;
    protected string $logfilename;
    protected string $domain;
    protected string $path;
    public string $setJsFilename;

    public function __construct()
    {
        // -----
        // Check to see that the plugin is enabled via configuration and that the current session is not associated
        // with a COWOA or guest checkout or an admin's "Place Order" login.  If any case fails, there's nothing else to be done.
        //
        $this->enabled = ((defined('PERMANENT_LOGIN') && PERMANENT_LOGIN === 'true') && !isset($_SESSION['COWOA']) && !zen_in_guest_checkout() && !isset($_SESSION['emp_admin_login']));
        if (!$this->enabled) {
            return;
        }

        $this->secret = (defined('PERMANENT_LOGIN_SECRET')) ? PERMANENT_LOGIN_SECRET : '';
        $this->cookie_lifetime = ((defined('PERMANENT_LOGIN_EXPIRES') && ((int)PERMANENT_LOGIN_EXPIRES) > 0) ? ((int)PERMANENT_LOGIN_EXPIRES) : 14) * 86400;
        $this->checkbox_default = (PERMANENT_LOGIN_CHECKBOX_DEFAULT === 'true');
        $this->cookie_name = 'zcrm_' . hash('md5', STORE_NAME);
        $this->debug = (PERMANENT_LOGIN_DEBUG === 'true');
        $this->logfilename = DIR_FS_LOGS . '/remember_me_' . date('Ym') . '.log';

        // -----
        // If a customer is not currently logged in, but there's a valid "Remember Me" cookie present ... then "Remember Them"!
        //
        if (!$this->customerIsLoggedIn()) {
            $remember_info = $this->decodeCookie();
            if ($remember_info === false) {
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
        $this->attach($this, [
            /* From /includes/modules/create_account.php */
            'NOTIFY_MODULE_CREATE_ACCOUNT_ADDED_CUSTOMER_RECORD',

            /* From /includes/modules/pages/login/header_php.php */
            'NOTIFY_LOGIN_SUCCESS',

            /* From /includes/modules/pages/logoff/header_php.php */
            'NOTIFY_HEADER_START_LOGOFF',

            /* From /includes/classes/OnePageCheckout.php */
            'NOTIFY_OPC_CREATE_ACCOUNT_ORDER_UPDATED',
        ]);

    }

    public function update(&$class, string $eventID, $paramsArray): void
    {
        global $db;
        switch ($eventID) {
            // -----
            // Upon successful login or account creation, if the customer has checked the "Remember Me" field then create the required cookie to keep them logged in.
            //
            case 'NOTIFY_LOGIN_SUCCESS':
            case 'NOTIFY_MODULE_CREATE_ACCOUNT_ADDED_CUSTOMER_RECORD':
            case 'NOTIFY_OPC_CREATE_ACCOUNT_ORDER_UPDATED':
                if (isset($_POST['permLogin']) && $_POST['permLogin'] == 1) {
                    $password_info = $db->Execute(
                        "SELECT customers_password
                           FROM " . TABLE_CUSTOMERS . "
                          WHERE customers_id = " . (int)$_SESSION['customer_id'] . "
                          LIMIT 1"
                    );
                    if (!$password_info->EOF) {
                        $remember_me_info = [
                            'id' => $_SESSION['customer_id'],
                            'password' => hash('md5', $this->secret . $password_info->fields['customers_password']),
                        ];
                        $cookie_value = $this->encodeCookie($remember_me_info);
                        $this->setCookie($cookie_value, time() + $this->cookie_lifetime);
                    }
                }
                break;

            // -----
            // When the customer has chosen to click the "Logoff" link, expire any "Remember Me" cookies that might be present.
            //
            case 'NOTIFY_HEADER_START_LOGOFF':
                $this->removeCookie();
                break;

            // -----
            // When a customer is "remembered" and there's a javascript handler
            // that needs to be run for square_webPay (and other) payment modules,
            // include the required <script> processing to get that module run.
            //
            case 'NOTIFY_HTML_HEAD_END':
?>
                <script id="zcrm-setjs">
                    $(function() {
                        $.get('<?= HTTPS_SERVER . DIR_WS_HTTPS_CATALOG . $this->setJsFilename ?>');
                    });
                </script>
<?php
                break;

            default:
                break;
        }
    }

    protected function customerIsLoggedIn(): bool
    {
        return zen_is_logged_in();
    }

    protected function checkRememberCustomer(array $remember_info): void
    {
        global $db;
        $customers_id = (int)($remember_info['id'] ?? 0);
        $customers_hashed_password = $remember_info['password'] ?? '~~~~';
        $customer_info = $db->Execute(
            "SELECT customers_firstname, customers_lastname, customers_password, customers_email_address, customers_default_address_id, customers_authorization, customers_referral
               FROM " . TABLE_CUSTOMERS . "
              WHERE customers_id = $customers_id
              LIMIT 1"
        );
        if ($customer_info->EOF || hash('md5', $this->secret . $customer_info->fields['customers_password']) !== $customers_hashed_password) {
            $this->removeCookie();
        } else {
            $_SESSION['customer_id'] = $customers_id;
            $_SESSION['currency'] = $remember_info['currency'];
            $_SESSION['language'] = $remember_info['language'];
            $_SESSION['languages_id'] = $remember_info['languages_id'];
            $_SESSION['languages_code'] = $remember_info['languages_code'];
            $_SESSION['securityToken'] = $remember_info['securityToken'];

            $_SESSION['setRememberedCart'] = (bool)($remember_info['cartIdSet'] ?? false); //- Indicates that the customer's cart should be restored once the cart is instantiated.

            $check_country_query =
                "SELECT entry_country_id, entry_zone_id
                   FROM " . TABLE_ADDRESS_BOOK . "
                  WHERE customers_id = $customers_id
                    AND address_book_id = :addressBookID
                  LIMIT 1";
            $check_country_query = $db->bindVars($check_country_query, ':addressBookID', $customer_info->fields['customers_default_address_id'], 'integer');
            $check_country = $db->Execute($check_country_query);

            $_SESSION['customers_email_address'] = $customer_info->fields['customers_email_address'];
            $_SESSION['customer_default_address_id'] = $customer_info->fields['customers_default_address_id'];
            $_SESSION['customers_authorization'] = $customer_info->fields['customers_authorization'];
            $_SESSION['customer_first_name'] = $customer_info->fields['customers_firstname'];
            $_SESSION['customer_last_name'] = $customer_info->fields['customers_lastname'];
            $_SESSION['customer_country_id'] = $check_country->fields['entry_country_id'];
            $_SESSION['customer_zone_id'] = $check_country->fields['entry_zone_id'];

            $db->Execute(
                "UPDATE " . TABLE_CUSTOMERS_INFO . "
                    SET customers_info_date_of_last_logon = now(),
                        customers_info_number_of_logons = customers_info_number_of_logons+1
                  WHERE customers_info_id = $customers_id
                  LIMIT 1"
            );

            // -----
            // Redirect the 'remembered' customer to the site's homepage, just in case they timed-out
            // on a page that requires additional session information (e.g. checkout).
            //
            zen_redirect(zen_href_link(FILENAME_DEFAULT));
        }
    }

    public function restoreRememberedCart(): void
    {
        // -----
        // If the customer was just 'remembered', restore their cart if
        // there were previously items in their cart.
        //
        if (isset($_SESSION['setRememberedCart'])) {
            $_SESSION['cart']->restore_contents();
            $_SESSION['cartID'] = $_SESSION['cart']->cartID;
            unset($_SESSION['setRememberedCart']);

            // -----
            // Since we've bypassed the login page for this customer, need
            // to check for the presence of a file that provides a needed process
            // for square_webPay (and possibly other) payment methods.
            //
            $this->setJsFilename = 'setJS.php';
            if (file_exists(DIR_FS_CATALOG . $this->setJsFilename)) {
                $this->attach($this, ['NOTIFY_HTML_HEAD_END']);
            }
        }

    }

    public function create_checkbox(): string
    {
        if (!$this->enabled) {
            return '';
        }

        if (!function_exists('zca_bootstrap_active') || zca_bootstrap_active() === false) {
            $return_value =
                '<br>' .
                zen_draw_checkbox_field('permLogin', '1', $this->checkbox_default, 'id="permLogin"') .
                '<label class="checkboxLabel" for="permLogin" title="' . TEXT_REMEMBER_ME_ALT . '">' . TEXT_REMEMBER_ME . '</label>';
        } else {
            $return_value =
                '<div class ="custom-control custom-checkbox pt-2 pb-2">' .
                    zen_draw_checkbox_field ('permLogin', '1', $this->checkbox_default, 'id="permLogin"') .
                    '<label class="custom-control-label" for="permLogin" title="' . TEXT_REMEMBER_ME_ALT . '">' . TEXT_REMEMBER_ME . '</label>' .
                '</div>';
        }

        return $return_value;
    }

    protected function encodeCookie(array $cookie_data): string
    {
        $cookie_data['currency'] = $_SESSION['currency'];
        $cookie_data['language'] = $_SESSION['language'];
        $cookie_data['languages_id'] = $_SESSION['languages_id'];
        $cookie_data['languages_code'] = $_SESSION['languages_code'];
        $cookie_data['cartIdSet'] = isset($_SESSION['cartID']);
        $cookie_data['securityToken'] = $_SESSION['securityToken'];

        $encoded_cookie = base64_encode(gzcompress(serialize($cookie_data), 9));

        $this->debugTrace("encodeCookie, value = '$encoded_cookie': " . var_export($cookie_data, true));

        return $encoded_cookie;
    }

    protected function removeCookie(): void
    {
        $this->setCookie('', time() - 3600);
    }

    protected function decodeCookie(): bool|array
    {
        $remember_info = false;
        $cookie_value = 'Not valid';
        if (isset($_COOKIE[$this->cookie_name]) && $_COOKIE[$this->cookie_name] != 'deleted') {
            $cookie_value = $_COOKIE[$this->cookie_name];
            $remember_info = base64_decode($cookie_value);
            if ($remember_info !== false) {
                $remember_info = @gzuncompress($remember_info);
                if ($remember_info === false) {
                    $this->debugTrace("decodeCookie: gzuncompress error in decodeCookie, value = $cookie_value, remember_info = " . var_export($remember_info, true));
                } else {
                    $remember_info = unserialize($remember_info);
                    if (!is_array($remember_info)) {
                        $this->debugTrace('decodeCookie: Non-array value found: ' . var_export($remember_info, true));
                        $remember_info = false;
                    } else {
                        $remember_keys = array_keys($remember_info);
                        $required_keys = ['id', 'password', 'language', 'languages_id', 'languages_code', 'cartIdSet', 'securityToken'];
                        foreach ($required_keys as $key) {
                            if (!in_array($key, $remember_keys)) {
                                $this->debugTrace('decodeCookie: Missing required keys. ' . var_export($remember_info, true));
                                $remember_info = false;
                                break;
                            }
                        }
                    }
                }
            }
        }
        if ($remember_info !== false) {
            $this->debugTrace("decodeCookie, value = '$cookie_value': " . var_export($remember_info, true));
        }
        return $remember_info;
    }

    protected function refreshCookie(): void
    {
        $cookie_data = $this->decodeCookie();
        if ($cookie_data === false) {
            $this->removeCookie();
        } else {
            $this->setCookie($this->encodeCookie($cookie_data), time() + $this->cookie_lifetime);
        }
    }

    // -----
    // Set the "remember-me" cookie for the specific path associated with the current
    // store's configuration.
    //
    protected function setCookie(string $value, int $expiration): void
    {
        if (!isset($this->domain)) {
            $this->domain = str_replace(
                [
                    'https://',
                    'http://',
                    '//'
                ],
                '',
                strtolower(HTTP_SERVER)
            );

            // -----
            // Special-case for localhost testing.
            //
            if (str_starts_with($this->domain, 'localhost')) {
                $this->domain = '';
            }
            $this->path = DIR_WS_CATALOG;
        }

        // -----
        // Starting with PHP 7.3, the setcookie function now accepts an alternate array
        // input, enabling the setting of the "SameSite" cookie attribute.
        //
        $cookie_options = [
            'expires' => $expiration,
            'path' => $this->path,
            'domain' => $this->domain,
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Strict'
        ];
        setcookie($this->cookie_name, $value, $cookie_options);
    }

    // -----
    // Output, if enabled and a customer is **not** logged in, a debug message to a log-file.
    //
    // The output is restricted to non-logged-in customers to capture the information only when
    // a cookie is being checked so that the trace-log isn't filled with unimportant information.
    //
    protected function debugTrace(string $message): void
    {
        if (/*!$this->customerIsLoggedIn() && */ $this->debug) {
            error_log(date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL, 3, $this->logfilename);
        }
    }
}
