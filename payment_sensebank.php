<?php
if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');
if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
require(VMPATH_ROOT .DS.'plugins'.DS.'vmpayment'.DS.'payment_sensebank'.DS.'payment_sensebank'.DS.'include.php');
if (PAYMENT_SENSEBANK_ENABLE_CART_OPTIONS === true) {
    if (!class_exists('DiscountHelper')) {
        require(VMPATH_ROOT . DS . 'plugins' . DS . 'vmpayment' . DS . 'payment_sensebank' . DS . 'payment_sensebank' . DS . 'DiscountHelper.php');
    }
}
class plgVMPaymentPayment_Sensebank extends vmPSPlugin
{
    public static $_this = false;
    public static $flag = false;
    private $redirect_immediately = true;
    static protected $vats = array(
        'none' => 0,
        0 => 1,
        10 => 2,
        18 => 3,
        20 => 6,
    );
    static protected $measureList = array(
        'P' => 0,
        'KG' => 11,
        'M' => 22,
        'SM' => 32,
        'L' => 41,
    );
    public $j_version;
    public $allowCallbacks = PAYMENT_SENSEBANK_ENABLE_CALLBACK;
    public $cacert_path = null;

    function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = $this->getVarsToPush();
        $this->addVarsToPushCore($varsToPush,1);
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
        $j = new JVersion();
        $this->j_version = $j->getLongVersion();
    }
    public function plgVmOnUpdateOrderPayment(&$order, $old_order_status) {
        if (!($this->_currentMethod = $this->getVmPluginMethod($order->virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod -> payment_element)) {
            return NULL;
        }
        if (!defined('PAYMENT_SENSEBANK_ENABLE_REFUNDS') || PAYMENT_SENSEBANK_ENABLE_REFUNDS === false) {
            return true;
        }

        $oModel = VmModel::getModel('orders');
        $orderModelData = $oModel->getOrder($order->virtuemart_order_id);
        if ($order->order_status == "R" && $old_order_status == "F") {
            $db = JFactory::getDBO();
            $q = 'SELECT * FROM `' . $this->_tablename . '` '
                . 'WHERE `virtuemart_order_id` = ' . $order->virtuemart_order_id;
            $db->setQuery($q);
            if (!($paymentTable = $db->loadObject())) {
                vmWarn(500, $q . " " . $db->getErrorMsg());
                return false;
            }
            if (!($method = $this->getVmPluginMethod($order->virtuemart_paymentmethod_id))) {
                return null;
            }
            $args = array(
                'userName' => $method->merchant,
                'password' => $method->password,
                'orderId' => $paymentTable->payment_sensebank_order_id,
                'amount' => $order->order_total * 100
            );
            if ($method->test_mode == '1') {
                $action_adr = PAYMENT_SENSEBANK_TEST_URL;
            } else {
                $action_adr = PAYMENT_SENSEBANK_PROD_URL;
            }
            $gose = $this->_sendGatewayData(http_build_query($args, '', '&'), $action_adr . 'getOrderStatusExtended.do');
            $res = json_decode($gose, true);
            if ($res["orderStatus"] == "2") { //DEPOSITED
                $result = $this->_sendGatewayData(http_build_query($args, '', '&'), $action_adr . 'refund.do');
                $this->_writeLog("[DEPOSITED REFUND RESPONSE]: ", $args, json_encode($result));
                vmWarn(json_decode($result)->errorMessage);
            } elseif ($res["orderStatus"] == "1") { //APPROVED 2x
                unset($args['amount']);
                $result = $this->_sendGatewayData(http_build_query($args, '', '&'), $action_adr . 'reverse.do');
                $this->_writeLog("[APPROVED REVERSE RESPONSE]: ", $args, json_encode($result));
                vmWarn(json_decode($result)->errorMessage);
            } else {
                vmError("WRONG GateWay order state [" . $res["orderStatus"] ."]");
                return null;
            }
            $response = json_decode($result, true);
            if ($response["errorCode"] != "0") {
                if ($response["errorCode"] == "7") {
                    return false;
                }
                return false;
            } else {
                $result = $this->_sendGatewayData(http_build_query($args, '', '&'), $action_adr . 'getOrderStatusExtended.do');
                $response = json_decode($result, true);
                $orderStatus = $response['orderStatus'];
                $this->_writeLog("[AFTER REFUND/REVERSE CHECK STATUS]: ", $args, json_encode($result));
                if ($orderStatus == '4' || $orderStatus == '3') {
                    return true;
                } elseif ($orderStatus == '1') {
                    return true;
                }
            }
        }
        return true;
    }
    function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'int(11) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_order_id' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'char(32) DEFAULT NULL',
            'payment_name' => 'varchar(5000)',
            'payment_sensebank_order_id' => 'char(36) DEFAULT NULL'
        );
        return $SQLfields;
    }
    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        if (!class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
        }
        $amount = round($order['details']['BT']->order_total * 100);
        $currency_numeric_code = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_numeric_code');
        $jsonParams_array = array(
            'CMS' => $this->j_version,
            'Module-Version' => "2.2.6"
        );
        $jsonParams_array['email'] = $order['details']['BT']->email;
        $jsonParams_array['phone'] =  preg_replace("/(\W*)/", "", $order['details']['BT']->phone_1);
        if (defined('PAYMENT_SENSEBANK_ENABLE_BACK_URL_SETTINGS')
            && PAYMENT_SENSEBANK_ENABLE_BACK_URL_SETTINGS === true
            && !empty($method->backToShopUrl)
        ) {
            $jsonParams_array['backToShopUrl'] = $method->backToShopUrl;
        }

        $args = Array(
            'userName' => $method->merchant,
            'password' => $method->password,
            'amount' => $amount,
            'description' => 'Payment for order #' . $order['details']['BT']->order_number,
            'currency' => $currency_numeric_code,
            'orderNumber' => $order['details']['BT']->order_number,
            'returnUrl' => JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&method=payment_sensebank&action=result',
            'jsonParams' => json_encode($jsonParams_array),
        );
        $language = substr(JFactory::getLanguage()->getTag(), 0, 2);
        switch ($language) {
            case  ('uk'):
                $language = 'ua';
                break;
            case ('be'):
                $language = 'by';
                break;
        }
        $args['language'] = $language;
        if (!empty($order['details']['BT']->virtuemart_user_id && $order['details']['BT']->virtuemart_user_id > 0)) {
            $client_email = !empty($order['details']['BT']->email) ? $order['details']['BT']->email : "";
            $args['clientId'] = md5($order['details']['BT']->virtuemart_user_id . $client_email . JURI::root());
        }
        if (PAYMENT_SENSEBANK_ENABLE_CART_OPTIONS === true && $method->payment_sensebank_send_order == 1) {
            $orderBundle = array(
                'orderCreationDate' => time(),
                'customerDetails' => array(
                    'email' => $order['details']['BT']->email,
                    'phone' => preg_match('/[7]\d{9}/', $order['details']['BT']->phone_1) ? $order['details']['BT']->phone_1 : ''
                ),
            );
            $itemsCnt = 1;
            $optionTaxes = array(
                'Tax',
                'DATax',
                'VatTax',
                'bDBTax',
                'bMarge'
            );
            if ($cart->products) {
                foreach ($cart->products as $key => $product) {
                    $price = round($cart->cartPrices[$key]['basePriceVariant'] * 100);
                    $vat = $method->payment_sensebank_tax_type;
                    foreach ($optionTaxes as $selectUserTax) {
                        if (!empty($cart->cartPrices[$key][$selectUserTax])) {
                            $idTax = array_keys($cart->cartPrices[$key][$selectUserTax]);
                            $paramsTax = ShopFunctions::getTaxByID($idTax[0]);
                            $price = round($cart->cartPrices[$key]['salesPrice'] * 100);
                            if ($paramsTax['calc_value_mathop'] === '+%') {
                                $taxCalcValue = (int)$paramsTax['calc_value'];
                                $vat = isset(self::$vats[$taxCalcValue]) ? self::$vats[$taxCalcValue] : $method->payment_sensebank_tax_type;
                            } else {
                                $vat = $method->payment_sensebank_tax_type;
                            }
                        }
                    }
                    $item = array();
                    $item['positionId'] = $itemsCnt++;
                    $item['name'] = mb_substr($product->product_name, 0, 64);
                    if ($method->payment_sensebank_ffd_version == 'v1_05') {
                        $item['quantity'] = array(
                            'value' => $product->quantity,
                            'measure' => (!empty($product->product_unit)) ? $product->product_unit : PAYMENT_SENSEBANK_MEASUREMENT_NAME
                        );
                    }
                    if ($method->payment_sensebank_ffd_version == 'v1_2') {
                        $item['quantity'] = array(
                            'value' => $product->quantity,
                            'measure' => PAYMENT_SENSEBANK_MEASUREMENT_CODE
                        );
                    }
                    $item['itemAmount'] = round($price * $product->quantity);
                    $item['itemCode'] = $product->virtuemart_product_id;
                    $item['tax'] = array(
                        'taxType' => $vat,
                        'taxSum' => $product->product_tax * 100,
                    );
                    $item['itemPrice'] = $price;
                    $attributes = array();
                    $attributes[] = array(
                        "name" => "paymentMethod",
                        "value" => $method->ffd_paymentMethodType
                    );
                    $attributes[] = array(
                        "name" => "paymentObject",
                        "value" => $method->ffd_paymentObjectType
                    );
                    $item['itemAttributes']['attributes'] = $attributes;
                    $orderBundle['cartItems']['items'][] = $item;
                }
            }
            if ($order['details']['BT']->order_shipment > 0) {
                $delivery_price = round(($order['details']['BT']->order_shipment + $order['details']['BT']->order_shipment_tax) * 100);
                $tax = ShopFunctions::getTaxByID($cart->cartPrices['shipment_calc_id'][0]);
                $vat = $method->payment_sensebank_tax_type;
                if (!empty($tax)) {
                    if ($tax['calc_value_mathop'] === '+%') {
                        $taxCalcValue = (int)$tax['calc_value'];
                        $vat = isset(self::$vats[$taxCalcValue]) ? self::$vats[$taxCalcValue] : self::$method->payment_sensebank_tax_type;
                    } else {
                        $vat = $method->payment_sensebank_tax_type;
                    }
                }
                $delivery = array(
                    'positionId' => $itemsCnt,
                    'name' => "Delivery",
                    'itemAmount' => $delivery_price,
                    'itemCode' => "delivery",
                    'tax' => array(
                        'taxType' => $method->payment_sensebank_tax_type,
                        'taxSum' => 0,
                    ),
                    'itemPrice' => $delivery_price,
                );

                if ($method->payment_sensebank_ffd_version == 'v1_05') {
                    $delivery['quantity'] = array(
                        'value' => 1,
                        'measure' => PAYMENT_SENSEBANK_MEASUREMENT_NAME
                    );
                }
                if ($method->payment_sensebank_ffd_version == 'v1_2') {
                    $delivery['quantity'] = array(
                        'value' => 1,
                        'measure' => PAYMENT_SENSEBANK_MEASUREMENT_CODE
                    );
                }

                $attributes = array();
                $attributes[] = array(
                    "name" => "paymentMethod",
                    "value" => $method->ffd_paymentMethodTypeDelivery
                );
                $attributes[] = array(
                    "name" => "paymentObject",
                    "value" => 4
                );
                $delivery['itemAttributes']['attributes'] = $attributes;
                $orderBundle['cartItems']['items'][] = $delivery;
            }
            if(class_exists('DiscountHelper')) {
                $this->discountHelper = new DiscountHelper();
                $discount = $this->discountHelper->discoverDiscount($amount, $orderBundle['cartItems']['items']);
                if ($discount > 0) {
                    $this->discountHelper->setOrderDiscount($discount);
                    $recalculatedPositions = $this->discountHelper->normalizeItems($orderBundle['cartItems']['items']);
                    $recalculatedAmount = $this->discountHelper->getResultAmount();
                    $orderBundle['cartItems']['items'] = $recalculatedPositions;
                }
            }
            $args['taxSystem'] = $method->payment_sensebank_tax_system;
            $args['orderBundle']['orderCreationDate'] = date('c');
            $args['orderBundle'] = json_encode($orderBundle);
        } //END send_order
        $action_adr = $this->_getGatewayUrl($method);
        if ($this->allowCallbacks === true) {
            $callback_addresses_string = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&method=payment_sensebank&action=callback';
            $login = $method->merchant;
            $password = $method->password;
            if ($method->test_mode == '1') {
                $gate_url = str_replace("payment/rest", "mportal/mvc/public/merchant/update", $action_adr);
            } else {
                $gate_url = str_replace("payment/rest", "mportal/mvc/public/merchant/update", $action_adr);
                if (defined('PAYMENT_SENSEBANK_PROD_URL_ALTERNATIVE_DOMAIN')) {
                    $pattern = '/^https:\/\/[^\/]+/';
                    $gate_url = preg_replace($pattern, rtrim(PAYMENT_SENSEBANK_PROD_URL_ALTERNATIVE_DOMAIN, '/'), $gate_url);
                }
            }
            $gate_url .= substr($login, 0, -4); // we guess username = login w/o "-api"
            $headers = array(
                'Content-Type:application/json',
                'Authorization: Basic ' . base64_encode($login . ":" . $password)
            );
            $data['callbacks_enabled'] = true;
            $data['callback_type'] = "STATIC";
            $data['callback_addresses'] = $callback_addresses_string;
            $data['callback_http_method'] = "GET";
            $data['callback_operations'] = "deposited,approved,declinedByTimeout";
            $response = $this->_sendGatewayData(json_encode($data), $gate_url, $headers);
            $this->_writeLog('ACTION: ' . $gate_url, $data, json_encode($response));
        }
        if ($method->two_step == '1') {
            $action_adr .= 'registerPreAuth.do';
        } else {
            $action_adr .= 'register.do';
        }
        if (file_exists(dirname(__FILE__) . "/payment_sensebank/cacert.cer") && $method->enable_cacert == 1) {
            $this->cacert_path = dirname(__FILE__) . "/payment_sensebank/cacert.cer";
        }
        $response = $this->_sendGatewayData(http_build_query($args, '', '&'), $action_adr, array('CMS: ' . $args['CMS'], 'Module-Version: ' . "2.2.6"), $this->cacert_path);
        $response = json_decode($response, true);
        $this->_writeLog("ACTION: " . $action_adr, $args, json_encode($response));
        $errorCode = $response['errorCode'];
        $html = '';
        if ($errorCode == 0) {
            $dbValues = array();
            $dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
            $dbValues['payment_name'] = $this->renderPluginName($method) . '<br />' . $method->payment_info;
            $dbValues['virtuemart_order_number'] = $order['details']['BT']->order_number;
            $dbValues['virtuemart_order_id'] = $order['details']['BT']->virtuemart_order_id;
            $dbValues['payment_sensebank_order_id'] = $response['orderId'];
            $this->storePSPluginInternalData($dbValues);
            if (!empty($response['formUrl']) && $this->redirect_immediately == true) {
                header("Location: " . $response['formUrl']);
                exit;
            } else {
                $html .= 'Please wait, we are redirecting you to the payment screen... <br>';
                $html .= '<a class="button cancel" href="' . $response['formUrl'] . '">Click here</a> if you do not get redirected.';
            }
        } else {
            $html .= "ERRORCODE #" . $errorCode . ": " . $response['errorMessage'];
        }
        return $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $method->payment_name);
    }
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return null;
        }
        $db = JFactory::getDBO();
        $q = 'SELECT * FROM `' . $this->_tablename . '` '
            . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            vmWarn(500, $q . " " . $db->getErrorMsg());
            return '';
        }
        $this->getPaymentCurrency($paymentTable);
        $html = '<table class="adminlist table">';
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('Payment_Sensebank', $paymentTable->payment_name);
        $html .= '</table>';
        return $html;
    }
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        if (preg_match('/%$/', $method->cost_percent_total)) {
            $cost_percent_total = substr($method->cost_percent_total, 0, -1);
        } else {
            $cost_percent_total = $method->cost_percent_total;
        }
        return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }
    function plgVmOnPaymentResponseReceived(&$html)
    {

        $input = JFactory::getApplication()->input;
        if ($input->get('method', '', 'cmd') != 'payment_sensebank') {
            return NULL;
        }
        if (self::$flag) return null;
        $jlang = JFactory::getLanguage();
        $jlang->load('plg_vmpayment_payment_sensebank', JPATH_ADMINISTRATOR);
        $payment_data = vRequest::getGet();
        if (isset($payment_data['method']) && $payment_data['method'] == "payment_sensebank") {
            vmdebug('plgVmOnPaymentResponseReceived', $payment_data);
            if (!class_exists('VirtueMartModelOrders')) {
                require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
            }
            $args = array();
            $action = $payment_data['action'];
            switch ($action) {
                case "result":
                    $args['orderId'] = isset($payment_data['orderId']) ? $payment_data['orderId'] : null;
                    break;
                case "callback":
                    $args['orderId'] = isset($payment_data['mdOrder']) ? $payment_data['mdOrder'] : null;
                    break;
            }
            $payment_sensebank_order_id = $args['orderId'];
            $q = 'SELECT `virtuemart_order_id` FROM `' . $this->_tablename . '` WHERE `payment_sensebank_order_id`="' . $payment_sensebank_order_id . '"';
            $db = JFactory::getDBO();
            $db->setQuery($q);
            $virtuemart_order_id = $db->loadResult();
            $q = 'SELECT `virtuemart_paymentmethod_id` FROM `' . $this->_tablename . '` WHERE `payment_sensebank_order_id`="' . $payment_sensebank_order_id . '"';
            $db = JFactory::getDBO();
            $db->setQuery($q);
            $virtuemart_paymentmethod_id = $db->loadResult();
            $method = $this->getVmPluginMethod($virtuemart_paymentmethod_id);
            $args['userName'] = $method->merchant;
            $args['password'] = $method->password;
            $action_adr = $this->_getGatewayUrl($method);
            $action_adr .= 'getOrderStatusExtended.do';
            $response = $this->_sendGatewayData(http_build_query($args, '', '&'), $action_adr);
            $this->_writeLog("ACTION: " . $action_adr, $args, json_encode($response));
            $response = json_decode($response, true);
            $modelOrder = VmModel::getModel('orders');
            $orderStatus = $response['orderStatus'];
            switch ($action) {
                case "result":
                    if ($orderStatus == '1' || $orderStatus == '2') {
                        $order = array();
                        $current_order = $modelOrder->getOrder($virtuemart_order_id);
                        $html .= vmText::_('VMPAYMENT_PAYMENT_SENSEBANK_ENTRY_ORDER') . " " . $current_order['details']['BT']->order_number . ", " . vmText::_('VMPAYMENT_PAYMENT_SENSEBANK_ENTRY_WAS_PAID') . ".";
                        //if ($this->allowCallbacks === false) {
                            $order['order_status'] = "F";
                            $order['paid'] = $current_order['details']['BT']->order_total;
                            $date = JFactory::getDate();
                            $today = $date->toSQL();
                            $order['paid_on'] = $today;
                            $order['comments'] = 'Payment_Sensebank [' . $payment_sensebank_order_id . ']';
                            if ($current_order['details']['BT']->order_status == "P") {
                                $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
                            }
                        //}
                        if (!class_exists('VirtueMartCart')) {
                            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
                        }
                        $cart = VirtueMartCart::getCart();
                        if (!class_exists('VirtueMartModelOrders')) {
                            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
                        }
                        $cart->emptyCart();
                        self::$flag = true;
                        return true;
                    } else {
                        //if ($this->allowCallbacks === false) {
                            $order = array();
                            $order['order_status'] = "X";
                            $order['comments'] = 'Payment_Sensebank ' . $payment_sensebank_order_id;
                            $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
                        //}
                        $html .= vmText::_('VMPAYMENT_PAYMENT_SENSEBANK_ENTRY_PAYMENT_ERROR') . ' <a href="' . JURI::root() . 'index.php?option=com_virtuemart&view=cart"> ' . vmText::_('VMPAYMENT_PAYMENT_SENSEBANK_ENTRY_TRY_AGAIN') . '</a>.';
                    }
                    return null;
                    break;
                case "callback":
                    if ($orderStatus == '1' || $orderStatus == '2') {
                        $order = array();
                        $current_order = $modelOrder->getOrder($virtuemart_order_id);
                        $order['order_status'] = "C";
                        $order['comments'] = 'Payment_Sensebank [' . $payment_sensebank_order_id . ']';
                        if ($current_order['details']['BT']->order_status == "P") {
                            $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
                        }
                        return true;
                    } else {
                        $order = array();
                        $order['order_status'] = "X";
                        $order['comments'] = 'Payment_Sensebank ' . $payment_sensebank_order_id;
                        $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
                    }
                    break;
            }
        }
    }
    function plgVmOnUserPaymentCancel()
    {
        return null;
    }
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }
    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     **
     * @param VirtueMartCart $cart: the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     */
    public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {
        return $this->OnSelectCheck ($cart);
    }
    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the plugin methods in the cart (edit shipment/payment) for example
     *
     * @param object  $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on success, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     */
    public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected, &$htmlIn) {
        return $this->displayListFE ($cart, $selected, $htmlIn);
    }

    public function plgVmOnSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
        return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
    }
    function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
        if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement ($method->payment_element)) {
            return FALSE;
        }
        $this->getPaymentCurrency ($method);
        $paymentCurrencyId = $method->payment_currency;
        return;
    }
    function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices, &$paymentCounter) {
        return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
    }
    public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
        $this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    function plgVmOnShowOrderPrintPayment ($order_number, $method_id) {
        return $this->onShowOrderPrint ($order_number, $method_id);
    }
    function plgVmDeclarePluginParamsPaymentVM3( &$data) {
        return $this->declarePluginParams('payment', $data);
    }
    function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {
        return $this->setOnTablePluginParams ($name, $id, $table);
    }
    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment %MODULE_NAME% Table');
    }
    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }
    public function _sendGatewayData($data, $action_address, $headers = array(), $ca_info = null)
    {
        $curl_opt = array(
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_VERBOSE => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_URL => $action_address,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HEADER => true,
        );
        $ssl_verify_peer = false;
        if ($ca_info != null) {
            $ssl_verify_peer = true;
            $curl_opt[CURLOPT_CAINFO] = $ca_info;
        }
        $curl_opt[CURLOPT_SSL_VERIFYPEER] = $ssl_verify_peer;
        $ch = curl_init();
        curl_setopt_array($ch, $curl_opt);
        $response = curl_exec($ch);
        if ($response === false) {
            $response['errorCode'] = 999;
            $response['errorMessage'] = curl_error($ch);
            return json_encode($response);
        }
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return substr($response, $header_size);
    }
    function _getGatewayUrl($method) {
        if ($method->test_mode == '1') {
            $action_adr = PAYMENT_SENSEBANK_TEST_URL;
        } else {
            $action_adr = PAYMENT_SENSEBANK_PROD_URL;
            if (defined('PAYMENT_SENSEBANK_PROD_URL_ALTERNATIVE_DOMAIN') && defined('PAYMENT_SENSEBANK_PROD_URL_ALT_PREFIX')) {
                if (substr($method->merchant, 0, strlen(PAYMENT_SENSEBANK_PROD_URL_ALT_PREFIX)) == RPAYMENT_SENSEBANK_PROD_URL_ALT_PREFIX) {
                    $pattern = '/^https:\/\/[^\/]+/';
                    $action_adr = preg_replace($pattern, rtrim(PAYMENT_SENSEBANK_PROD_URL_ALTERNATIVE_DOMAIN, '/'), $action_adr);
                } else {
                    $this->allowCallbacks = false;
                }
            }
        }
        return $action_adr;
    }

    function _writeLog($info, $request, $response)
    {
        if (defined('PAYMENT_SENSEBANK_ENABLE_LOGGING') && PAYMENT_SENSEBANK_ENABLE_LOGGING === true) {
            if (isset($request['password'])) {
                $request['password'] = "***removed from log***";
            }
            $date = JFactory::getDate();
            $path = JPATH_ADMINISTRATOR . "/logs/vm_payment_sensebank_" . date('Y-m') . ".log";
            error_log($date->Format('Y-m-d H:i:s') . " " . $info . "\nREQUEST: " . json_encode($request) . "\nRESPONSE: " . $response . "\n\n", 3, $path);
        } else {
            return false;
        }
    }

    function logingActions($params)
    {
        jimport('joomla.error.log');
        $options = array('format' => "{DATE}\t{TIME}\t{ORDER}\t{ACTION}");
        $log = &JLog::getInstance('payment_events.log.php', $options);
        $log->addEntry(array('ORDER' => $params['ORDER'], 'ACTION' => $params['ACTION']));
    }
}