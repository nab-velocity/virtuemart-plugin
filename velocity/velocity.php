<?php
/**
 *
 * @author Velocity Team
 * @version $Id: velocity.php
 * @package VirtueMart
 * @subpackage payment
 * @copyright Copyright (C) 2015 The Velocity team - All rights reserved.
 * @license 
 *
 * http://nabvelocity.com/
 */
defined('_JEXEC') or die('Restricted access');

if (!class_exists('Creditcard')) {
    require_once(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'creditcard.php');
}
if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmpaymentVelocity extends vmPSPlugin {

    private $_cc_name = '';
    private $_cc_type = '';
    private $_cc_number = '';
    private $_cc_cvv = '';
    private $_cc_expire_month = '';
    private $_cc_expire_year = '';
    private $_cc_valid = FALSE;
    private $_errormessage = array();
    protected $_velocity_params = array(
        "version" => "3.1",
        "delim_char" => ",",
        "delim_data" => "TRUE",
        "relay_response" => "FALSE",
        "encap_char" => "|",
    );
    public $approved;
    public $declined;
    public $error;
    public $held;

    const APPROVED = 1;
    const DECLINED = 2;
    const ERROR = 3;
    const HELD = 4;

    const VELOCITY_DEFAULT_PAYMENT_CURRENCY = "USD";

    /**
     * Constructor
     *
     * For php4 compatability we must not use the __constructor as a constructor for plugins
     * because func_get_args ( void ) returns a copy of all passed arguments NOT references.
     * This causes problems with cross-referencing necessary for the observer design pattern.
     *
     * @param object $subject The object to observe
     * @param array $config  An array that holds the plugin configuration
     * @since 1.5
     */
    // instance of class
    function __construct(& $subject, $config) {
        
        parent::__construct($subject, $config);
        $this->_loggable = TRUE;
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
        //echo '<pre>'; print_r($subject); die; 
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     *
     * @author Velocity Team
     */
    protected function getVmPluginCreateTableSQL() {
                
        return $this->createTableSQL('Payment Velocity Table');
    }

    /**
     * Fields to create the payment table
     *
     * @return string SQL Fileds
     */
    function getTableSQLFields() {
        
        $SQLfields = array(
                'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
                'transaction_id' => 'varchar(64)',
                'transaction_status' => 'varchar(20)',
                'virtuemart_order_id' => 'int(20)',
                'request_obj' => 'text',
                'response_obj' => 'text',
        );
        return $SQLfields;
    }

    /**
     * This shows the plugin for choosing in the payment list of the checkout process.
     *
     * @author Velocity Team
     */
    function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {

        //JHTML::_ ('behavior.tooltip');

        if ($this->getPluginMethods($cart->vendorId) === 0) {
                if (empty($this->_name)) {
                        $app = JFactory::getApplication();
                        $app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
                        return FALSE;
                } else {
                        return FALSE;
                }
        }
        $html = array();
        $method_name = $this->_psType . '_name';

        VmConfig::loadJLang('com_virtuemart', true);
        vmJsApi::jCreditCard();
        $htmla = '';
        $html = array(); 
        foreach ($this->methods as $this->_currentMethod) {
                if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {
                        $cartPrices=$cart->cartPrices; 
                        $methodSalesPrice = $this->setCartPrices($cart, $cartPrices, $this->_currentMethod);
                        $this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);
                        $html = $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
                        if ($selected == $this->_currentMethod->virtuemart_paymentmethod_id) {
                                $this->_getVelocityFromSession();
                        } else {
                                $this->_cc_type = '';
                                $this->_cc_number = '';
                                $this->_cc_cvv = '';
                                $this->_cc_expire_month = '';
                                $this->_cc_expire_year = '';
                        }

                        if (empty($this->_currentMethod->creditcards)) {
                                $this->_currentMethod->creditcards = self::getCreditCards();
                        } elseif (!is_array($this->_currentMethod->creditcards)) {
                                $this->_currentMethod->creditcards = (array)$this->_currentMethod->creditcards;
                        }
                        $creditCards = $this->_currentMethod->creditcards;
                        $creditCardList = '';
                        if ($creditCards) {
                                $creditCardList = ($this->_renderCreditCardList($creditCards, $this->_cc_type, $this->_currentMethod->virtuemart_paymentmethod_id, FALSE));
                        }
                        $sandbox_msg = "";
                        if ($this->_currentMethod->payment_mode) {
                                $sandbox_msg .= '<br />' . vmText::_('VMPAYMENT_VELOCITY_SANDBOX_TEST_NUMBERS');
                        }

                        $cvv_images = $this->_displayCVVImages($this->_currentMethod);
                        $html .= '<br /><span class="vmpayment_cardinfo">' . vmText::_('VMPAYMENT_VELOCITY_COMPLETE_FORM') . $sandbox_msg . '
            <table border="0" cellspacing="0" cellpadding="2" width="100%">
            <tr valign="top">
                <td nowrap width="10%" align="right">
                        <label for="creditcardtype">' . vmText::_('VMPAYMENT_VELOCITY_CCTYPE') . '</label>
                </td>
                <td>' . $creditCardList .
                                '</td>
            </tr>
            <tr valign="top">
                <td nowrap width="10%" align="right">
                        <label for="cc_type">' . vmText::_('VMPAYMENT_VELOCITY_CCNUM') . '</label>
                </td>
                <td>
                        <script type="text/javascript">
                        //<![CDATA[  
                          function checkVelocity(id, el)
                           {
                             ccError=razCCerror(id);
                                CheckCreditCardNumber(el.value, id);
                                if (!ccError) {
                                el.value=\'\';}
                           }
                        //]]> 
                        </script>
                <input type="text" class="inputbox" id="cc_number_' . $this->_currentMethod->virtuemart_paymentmethod_id . '" name="cc_number_' . $this->_currentMethod->virtuemart_paymentmethod_id . '" value="' . $this->_cc_number . '"    autocomplete="off"   onchange="javascript:checkVelocity(' . $this->_currentMethod->virtuemart_paymentmethod_id . ', this);"  />
                <div id="cc_cardnumber_errormsg_' . $this->_currentMethod->virtuemart_paymentmethod_id . '"></div>
            </td>
            </tr>
            <tr valign="top">
                <td nowrap width="10%" align="right">
                        <label for="cc_cvv">' . vmText::_('VMPAYMENT_VELOCITY_CVV2') . '</label>
                </td>
                <td>
                    <input type="text" class="inputbox" id="cc_cvv_' . $this->_currentMethod->virtuemart_paymentmethod_id . '" name="cc_cvv_' . $this->_currentMethod->virtuemart_paymentmethod_id . '" maxlength="4" size="5" value="' . $this->_cc_cvv . '" autocomplete="off" />

                <span class="hasTip" title="' . vmText::_('VMPAYMENT_VELOCITY_WHATISCVV') . '::' . vmText::sprintf("VMPAYMENT_VELOCITY_WHATISCVV_TOOLTIP", $cvv_images) . ' ">' .
                                vmText::_('VMPAYMENT_VELOCITY_WHATISCVV') . '
                </span></td>
            </tr>
            <tr>
                <td nowrap width="10%" align="right">' . vmText::_('VMPAYMENT_VELOCITY_EXDATE') . '</td>
                <td> ';
                        $html .= shopfunctions::listMonths('cc_expire_month_' . $this->_currentMethod->virtuemart_paymentmethod_id, $this->_cc_expire_month);
                        $html .= " / ";
                        $html .= '
                        <script type="text/javascript">
                        //<![CDATA[  
                          function changeDate(id, el)
                           {
                             var month = document.getElementById(\'cc_expire_month_\'+id); if(!CreditCardisExpiryDate(month.value,el.value, id))
                                 {el.value=\'\';
                                 month.value=\'\';}
                           }
                        //]]> 
                        </script>';

                        $html .= shopfunctions::listYears('cc_expire_year_' . $this->_currentMethod->virtuemart_paymentmethod_id, $this->_cc_expire_year, NULL, null, " onchange=\"javascript:changeDate(" . $this->_currentMethod->virtuemart_paymentmethod_id . ", this);\" ");
                        $html .= '<div id="cc_expiredate_errormsg_' . $this->_currentMethod->virtuemart_paymentmethod_id . '"></div>';
                        $html .= '</td>  </tr>  	</table></span>';

                        $htmla[] = $html;
                }
        }
        $htmlIn[] = $htmla;

        return TRUE;
    }

    /**
     * credit card list
     */
    static function getCreditCards() {        
        return array(
                'Visa',
                'MasterCard',
                'AmericanExpress',
                'Discover',
        );
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @author: Velocity Team
     *
     * @param $cart_prices: cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices) {
        $this->convert_condition_amount($method);
        $amount = $this->getCartAmount($cart_prices);
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
                OR
                ($method->min_amount <= $amount AND ($method->max_amount == 0)));
        if (!$amount_cond) {
                return FALSE;
        }
        $countries = array();
        if (!empty($method->countries)) {
                if (!is_array($method->countries)) {
                        $countries[0] = $method->countries;
                } else {
                        $countries = $method->countries;
                }
        }

        // probably did not gave his BT:ST address
        if (!is_array($address)) {
                $address = array();
                $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
                $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
                return TRUE;
        }

        return FALSE;
    }


    function _setVelocityIntoSession ()
    {
        if (!class_exists('vmCrypt')) {
                require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'vmcrypt.php');
        }
        $session = JFactory::getSession();
        $sessionVelocity = new stdClass();
        // card information
        $sessionVelocity->cc_type = $this->_cc_type;
        $sessionVelocity->cc_number = vmCrypt::encrypt($this->_cc_number);
        $sessionVelocity->cc_cvv = vmCrypt::encrypt($this->_cc_cvv);
        $sessionVelocity->cc_expire_month = $this->_cc_expire_month;
        $sessionVelocity->cc_expire_year = $this->_cc_expire_year;
        $sessionVelocity->cc_valid = $this->_cc_valid;
        $session->set('velocity', json_encode($sessionVelocity), 'vm');
    }

    function _getVelocityFromSession() {
        if (!class_exists('vmCrypt')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'vmcrypt.php');
        }
        $session = JFactory::getSession();
        $sessionVelocity = $session->get('velocity', 0, 'vm');

        if (!empty($sessionVelocity)) {
            $velocityData = (object)json_decode($sessionVelocity,true);
            $this->_cc_type = $velocityData->cc_type;
            $this->_cc_number =  vmCrypt::decrypt($velocityData->cc_number);
            $this->_cc_cvv =  vmCrypt::decrypt($velocityData->cc_cvv);
            $this->_cc_expire_month = $velocityData->cc_expire_month;
            $this->_cc_expire_year = $velocityData->cc_expire_year;
            $this->_cc_valid = $velocityData->cc_valid;
        }
    }

    /**
     * This is for checking the input data of the payment method within the checkout
     *
     * @author Velocity Team
     */
    function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart) {

        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
                return NULL; // Another method was selected, do nothing
        }

        if (!($this->_currentMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
                return FALSE;
        }
        $this->_getVelocityFromSession();
        return $this->_validate_velocity_creditcard_data(TRUE);

    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
        return parent::onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This is for adding the input data of the payment method to the cart, after selecting
     *
     * @author Velocity Team
     *
     * @param VirtueMartCart $cart
     * @return null if payment not selected; true if card infos are correct; string containing the errors id cc is not valid
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg) {

        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return NULL; // Another method was selected, do nothing
        }

        if (!($this->_currentMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
            return FALSE;
        }

        //$cart->creditcard_id = vRequest::getVar('creditcard', '0');
        $this->_cc_type = vRequest::getVar('cc_type_' . $cart->virtuemart_paymentmethod_id, '');
        $this->_cc_name = vRequest::getVar('cc_name_' . $cart->virtuemart_paymentmethod_id, '');
        $this->_cc_number = str_replace(" ", "", vRequest::getVar('cc_number_' . $cart->virtuemart_paymentmethod_id, ''));
        $this->_cc_cvv = vRequest::getVar('cc_cvv_' . $cart->virtuemart_paymentmethod_id, '');
        $this->_cc_expire_month = vRequest::getVar('cc_expire_month_' . $cart->virtuemart_paymentmethod_id, '');
        $this->_cc_expire_year = vRequest::getVar('cc_expire_year_' . $cart->virtuemart_paymentmethod_id, '');

        if (!$this->_validate_velocity_creditcard_data(TRUE)) {
            return FALSE; // returns string containing errors
        }
        $this->_setVelocityIntoSession();
        return TRUE;
    }

    public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$payment_name) {

        if (!($this->_currentMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
                return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
                return FALSE;
        }

        $this->_getVelocityFromSession();
        $cart_prices['payment_tax_id'] = 0;
        $cart_prices['payment_value'] = 0;

        if (!$this->checkConditions($cart, $this->_currentMethod, $cart_prices)) {
                return FALSE;
        }
        $payment_name = $this->renderPluginName($this->_currentMethod);

        $this->setCartPrices($cart, $cart_prices, $this->_currentMethod);

        return TRUE;
    }

    /*
     * @param $plugin plugin
     */

    protected function renderPluginName($plugin) {

        $return = '';
        $plugin_name = $this->_psType . '_name';
        $plugin_desc = $this->_psType . '_desc';
        $description = '';

        $logosFieldName = $this->_psType . '_logos';
        $logos = $plugin->$logosFieldName;
        if (!empty($logos)) {
                $return = $this->displayLogos($logos) . ' ';
        }
        $sandboxWarning = '';
        if ($plugin->payment_mode) {
                $sandboxWarning .= ' <span style="color:red;font-weight:bold">Sandbox (' . $plugin->virtuemart_paymentmethod_id . ')</span><br />';
        }
        if (!empty($plugin->$plugin_desc)) {
                $description = '<span class="' . $this->_type . '_description">' . $plugin->$plugin_desc . '</span>';
        } 
        $this->_getVelocityFromSession();
        $extrainfo = $this->getExtraPluginNameInfo();
        $pluginName = $return . '<span class="' . $this->_type . '_name">' . $plugin->$plugin_name . '</span>' . $description;
        $pluginName .= $sandboxWarning . $extrainfo;
        return $pluginName;
    }

    /**
     * Display stored payment data for an order
     *
     * @see components/com_virtuemart/helpers/vmPaymentPlugin::plgVmOnShowOrderPaymentBE()
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) { 
        if (!($this->_currentMethod = $this->selectedThisByMethodId($virtuemart_payment_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return NULL;
        }
        
        $user = JFactory::getUser(); 
        VmConfig::loadJLang('com_virtuemart');

        $db = JFactory::getDBO();
        $q1 = 'SELECT * FROM `#__virtuemart_orders` where virtuemart_order_id = ' . $virtuemart_order_id;
        $db->setQuery($q1);
        $ship = $db->loadObjectList ();
        $shipment = $ship[0]->order_shipment;
        $shipmenttax = $ship[0]->order_shipment_tax;
        $total_ship_amount = $shipment + $shipmenttax;
        $obcurr = CurrencyDisplay::getInstance();
                
        $resObj = unserialize($paymentTable->response_obj);
        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', self::getPaymentName($virtuemart_payment_id));
        $html .= $this->getHtmlRowBE('VELOCITY_PAYMENT_ORDER_TOTAL', $resObj['Amount'] . " " . self::VELOCITY_DEFAULT_PAYMENT_CURRENCY);
        $html .= $this->getHtmlRowBE('VELOCITY_COST_PER_TRANSACTION', $resObj['FeeAmount']);
        $html .= $this->getHtmlRowBE('VMPAYMENT_VELOCITY_CASH_BACK_AMOUNT', $resObj['CashBackAmount']);
        $html .= $this->getHtmlRowBE(vmText::sprintf('VMPAYMENT_VELOCITY_REFUND_LINK','JavaScript:void(0)'), '');
        $html .= $this->getHtmlRowBE(vmText::sprintf('VMPAYMENT_VELOCITY_REFUND_BLOCK', str_replace('administrator/' , '', JURI::base()) . 'plugins/vmpayment/velocity/velocityRefund.php', $virtuemart_order_id, $user->id, $obcurr->roundForDisplay($total_ship_amount), self::VELOCITY_DEFAULT_PAYMENT_CURRENCY));
        
        $code = "velocity_response_";
        foreach ($paymentTable as $key => $value) {
            if (substr($key, 0, strlen($code)) == $code) {
                $html .= $this->getHtmlRowBE($key, $value);
            }
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Reimplementation of vmPaymentPlugin::plgVmOnConfirmedOrderStorePaymentData()
     */

    /**
     * Reimplementation of vmPaymentPlugin::plgVmOnConfirmedOrder()
     *
     * @link http://nabvelocity.com/
     * Credit Cards Test Numbers
     * Visa Test Account           4007000000027
     * Amex Test Account           370000000000002
     * Master Card Test Account    6011000000000012
     * Discover Test Account       5424000000000015
     * @author Velocity Team
     */
    function plgVmConfirmedOrder(VirtueMartCart $cart, $order) { 

        if (!($this->_currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
                return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
                return FALSE;
        }

        $this->setInConfirmOrder($cart);
        $usrBT = $order['details']['BT'];
        $usrST = ((isset($order['details']['ST'])) ? $order['details']['ST'] : '');
        $session = JFactory::getSession();
        $return_context = $session->getId();

        $payment_currency_id = shopFunctions::getCurrencyIDByName(self::VELOCITY_DEFAULT_PAYMENT_CURRENCY);
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $payment_currency_id);
        $cd = CurrencyDisplay::getInstance($cart->pricesCurrency);

        if (!class_exists('ShopFunctions'))
                require(VMPATH_ADMIN . DS . 'helpers' . DS . 'shopfunctions.php');

        $statecode   = self::get2cStateByID($usrBT->virtuemart_state_id);
        $countrycode = self::get3cCountryByID($usrBT->virtuemart_country_id) == 'USA' ? self::get3cCountryByID($usrBT->virtuemart_country_id) : 'USA';

        $avsData = array (
            'Street'        => $usrBT->address_1 . ' ' . $usrBT->address_2,
            'City'          => $usrBT->city,
            'StateProvince' => $statecode,
            'PostalCode'    => $usrBT->zip,
            'Country'       => $countrycode
         );


        $cardData = array(
            'cardtype'      => str_replace(' ', '', $this->_cc_type), 
            'pan'           => $this->_cc_number, 
            'expire'        => sprintf("%02d", $this->_cc_expire_month).substr($this->_cc_expire_year, -2), 
            'cvv'           => $this->_cc_cvv,
            'track1data'    => '', 
            'track2data'    => ''
        );

        $identitytoken        = $this->_vmpCtable->identitytoken;
        $workflowid           = $this->_vmpCtable->workflowid;
        $applicationprofileid = $this->_vmpCtable->applicationprofileid;
        $merchantprofileid    = $this->_vmpCtable->merchantprofileid;

        if ($this->_vmpCtable->payment_mode)
            $isTestAccount = TRUE;
        else                   
            $isTestAccount = FALSE;

        include_once('sdk' . DS . 'configuration.php');
        include_once('sdk' . DS . 'Velocity.php');

        // Prepare data that should be stored in the database
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['virtuemart_order_id'] = $order['details']['BT']->virtuemart_order_id;
        $dbValues['payment_method_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['return_context'] = $return_context;
        $dbValues['payment_name'] = parent::renderPluginName($this->_currentMethod);
        $dbValues['cost_per_transaction'] = $this->_currentMethod->cost_per_transaction;
        $dbValues['cost_percent_total'] = $this->_currentMethod->cost_percent_total;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
        $dbValues['payment_currency'] = $payment_currency_id;

        $this->debugLog("before store", "plgVmConfirmedOrder", 'debug');

        $this->storePSPluginInternalData($dbValues);

        $errMsg = '';

        try {            
            $velocityProcessor = new VelocityProcessor( $applicationprofileid, $merchantprofileid, $workflowid, $isTestAccount, $identitytoken );    
        } catch (Exception $e) {
            $this->error = TRUE;
            $errMsg .= '<br>' . vmText::_($e->getMessage());
        }

        /* Request for the verify avsdata and card data*/
        try {        
            $response = $velocityProcessor->verify(array(  
                'amount'       => $totalInPaymentCurrency['value'],
                'avsdata'      => $avsData, 
                'carddata'     => $cardData,
                'entry_mode'   => 'Keyed',
                'IndustryType' => 'Ecommerce',
                'Reference'    => 'xyz',
                'EmployeeId'   => '11'
            ));

        } catch (Exception $e) {
            $this->error = TRUE;
            $errMsg .= '<br>' . vmText::_($e->getMessage());
        }

        if (is_array($response) && isset($response['Status']) && $response['Status'] == 'Successful') {

            /* Request for the authrizeandcapture transaction */
            try {
                
                $xml = VelocityXmlCreator::authorizeandcaptureXML(array(  
                    'amount'       => $totalInPaymentCurrency['value'],
                    'avsdata'      => $avsData, 
                    'token'        => $response['PaymentAccountDataToken'], 
                    'order_id'     => $order['details']['BT']->order_number,
                    'entry_mode'   => 'Keyed',
                    'IndustryType' => 'Ecommerce',
                    'Reference'    => 'xyz',
                    'EmployeeId'   => '11'
                ));  // got authorizeandcapture xml object. 

                $req = $xml->saveXML();
                $obj_req = serialize($req);
            
                $cap_response = $velocityProcessor->authorizeAndCapture( array(
                    'amount'       => $totalInPaymentCurrency['value'], 
                    'avsdata'      => $avsData,
                    'token'        => $response['PaymentAccountDataToken'], 
                    'order_id'     => $order['details']['BT']->order_number,
                    'entry_mode'   => 'Keyed',
                    'IndustryType' => 'Ecommerce',
                    'Reference'    => 'xyz',
                    'EmployeeId'   => '11'
                ));

                if ( is_array($cap_response) && !empty($cap_response) && isset($cap_response['Status']) && $cap_response['Status'] == 'Successful') {

                    /* save the authandcap response into 'virtuemart_payment_plg_velocity' custom table.*/   
                    $response_fields['transaction_id'] = $cap_response['TransactionId'];
                    $response_fields['transaction_status'] = $cap_response['TransactionState'];
                    $response_fields['virtuemart_order_id'] = $order['details']['BT']->virtuemart_order_id;
                    $response_fields['request_obj'] = $obj_req;
                    $response_fields['response_obj'] = serialize($cap_response);
                    $this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', TRUE);
                    
                    $html = '<table class="adminlist table">' . "\n";
                    $html .= $this->getHtmlRow('VELOCITY_PAYMENT_NAME', $this->_vmpCtable->payment_name);
                    $html .= $this->getHtmlRow('VELOCITY_ORDER_NUMBER', $order['details']['BT']->order_number);
                    $html .= $this->getHtmlRow('VELOCITY_AMOUNT', $cap_response['Amount']);
                    $html .= $this->getHtmlRow('VMPAYMENT_VELOCITY_APPROVAL_CODE', $cap_response['ApprovalCode']);
                    if ($cap_response['TransactionId']) {
                            $html .= $this->getHtmlRow('VELOCITY_RESPONSE_TRANSACTION_ID', $cap_response['TransactionId']);
                    }
                    $html .= '</table>' . "\n";
                    $this->debugLog(vmText::_('VMPAYMENT_VELOCITY_ORDER_NUMBER') . " " . $order['details']['BT']->order_number . ' payment approved', '_handleResponse', 'debug');

                    $comment = 'ApprovalCode: ' . $cap_response['ApprovalCode'] . '<br>Transaction_Id: ' . $cap_response['TransactionId'];
                    $this->_clearVelocitySession();
                    $new_status = 'U';

                } else if ( is_array($cap_response) && !empty($cap_response) ) {
                    $this->error = TRUE;
                    $errMsg .= vmText::_($cap_response['StatusMessage']);
                } else if ( is_string($cap_response) ) {
                    $this->error = TRUE;
                    $errMsg .= '<br>' . vmText::_($cap_response);
                } else {
                    $this->error = TRUE;
                    $errMsg .= '<br>' . vmText::_('VMPAYMENT_VELOCITY_UNKNOWN_ERROR');
                }
            } catch(Exception $e) {
                $errMsg .= '<br>' . vmText::_($e->getMessage());
            }

        } else if (is_array($response) &&(isset($response['Status']) && $response['Status'] != 'Successful')) {
            $this->error = TRUE;
            $errMsg .= '<br>' . vmText::_($response['StatusMessage']);
        } else if (is_string($response)) {
            $this->error = TRUE;
            $errMsg .= '<br>' . vmText::_($response);
        } else {
            $this->error = TRUE;
            $errMsg .= '<br>' . vmText::_('VMPAYMENT_VELOCITY_UNKNOWN_ERROR');
        }

        $this->debugLog($response, "plgVmConfirmedOrder", 'debug');
        $modelOrder = VmModel::getModel('orders');
        
        if ($this->error) {
            $this->debugLog($errMsg, 'getOrderIdByOrderNumber', 'message');
            $this->_handlePaymentCancel($order['details']['BT']->virtuemart_order_id, $errMsg);
            return;
        }

        $order['order_status'] = $new_status;
        $order['customer_notified'] = 1;
        $order['comments'] = $comment;
        $modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, TRUE);

        //We delete the old stuff
        $cart->emptyCart();
        vRequest::setVar('html', $html);
    }

    function _handlePaymentCancel($virtuemart_order_id, $html) {

        if (!class_exists('VirtueMartModelOrders')) {
                require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }
        $modelOrder = VmModel::getModel('orders');
        $mainframe = JFactory::getApplication();
        $mainframe->enqueueMessage($html);
        $mainframe->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&task=editpayment', FALSE), vmText::_('COM_VIRTUEMART_CART_ORDERDONE_DATA_NOT_VALID'));
    }
        
        
    /**
     * Return the state 2 digit code of a given virtuemart_state_id
     *
     * @author Velocity Team
     * @access private
     * @param int $id State ID
     * @return string state 2 digit code
     */
    static private function get2cStateByID ($id) {

        if (empty($id)) {
                return '';
        }
        $db = JFactory::getDBO ();
        $q = 'SELECT state_2_code FROM `#__virtuemart_states` WHERE virtuemart_state_id = "' . (int)$id . '"';
        $db->setQuery ($q);
        $r = $db->loadObject ();
        return $r->state_2_code;
    }

    /**
     * Return the Country 3 digit code of a given countryID
     *
     * @author Velocity Team
     * @access private
     * @param int $id Country ID
     * @return string Country 3 digit code
     */
    static private function get3cCountryByID ($id) {

        if (empty($id)) {
                return '';
        }

        $id = (int)$id;
        $db = JFactory::getDBO ();

        $q = 'SELECT country_3_code FROM `#__virtuemart_countries` WHERE virtuemart_country_id = ' . (int)$id;
        $db->setQuery ($q);
        $c3c = $db->loadResult ();
        return $c3c->country_3_code;
    }

    /**
     * Return the Payment Name of a given paymentID
     *
     * @author Velocity Team
     * @access private
     * @param int $id Payment ID
     * @return string Payment Name
     */
    static private function getPaymentName ($id) {

        if (empty($id)) {
                return '';
        }

        $id = (int)$id;
        $db = JFactory::getDBO ();

        $q = 'SELECT payment_name FROM `#__virtuemart_paymentmethods_en_gb` WHERE virtuemart_paymentmethod_id = ' . (int)$id;
        $db->setQuery ($q);
        $pid = $db->loadResult ();
        return $pid;
    }

    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     * @return bool|null
     */
    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

        if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
                return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
                return FALSE;
        }
        $this->_currentMethod->payment_currency = self::VELOCITY_DEFAULT_PAYMENT_CURRENCY;

        if (!class_exists('VirtueMartModelVendor')) {
                require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'vendor.php');
        }
        $vendorId = 1; //VirtueMartModelVendor::getLoggedVendor();
        $db = JFactory::getDBO();

        $q = 'SELECT   `virtuemart_currency_id` FROM `#__virtuemart_currencies` WHERE `currency_code_3`= "' . self::VELOCITY_DEFAULT_PAYMENT_CURRENCY . '"';
        $db->setQuery($q);
        $paymentCurrencyId = $db->loadResult();
    }

    function _clearVelocitySession() {

        $session = JFactory::getSession();
        $session->clear('velocity', 'vm');
    }

    /**
     * renderPluginName
     * Get the name of the payment method
     *
     * @author Velocity Team
     * @param  $payment
     * @return string Payment method name
     */
    function getExtraPluginNameInfo() {

        $creditCardInfos = '';
        if ($this->_validate_velocity_creditcard_data(FALSE)) {
                $cc_number = "**** **** **** " . substr($this->_cc_number, -4);
                $creditCardInfos .= '<br /><span class="vmpayment_cardinfo">' . vmText::_('VMPAYMENT_VELOCITY_CCTYPE') . $this->_cc_type . '<br />';
                $creditCardInfos .= vmText::_('VMPAYMENT_VELOCITY_CCNUM') . $cc_number . '<br />';
                $creditCardInfos .= vmText::_('VMPAYMENT_VELOCITY_CVV2') . '****' . '<br />';
                $creditCardInfos .= vmText::_('VMPAYMENT_VELOCITY_EXDATE') . $this->_cc_expire_month . '/' . $this->_cc_expire_year;
                $creditCardInfos .= "</span>";
        }
        return $creditCardInfos;
    }

    /**
     * Creates a Drop Down list of available Creditcards
     *
     * @author Velocity Team
     */
    function _renderCreditCardList($creditCards, $selected_cc_type, $paymentmethod_id, $multiple = FALSE, $attrs = '') {

        $idA = $id = 'cc_type_' . $paymentmethod_id;
        if (!is_array($creditCards)) {
                $creditCards = (array)$creditCards;
        }
        foreach ($creditCards as $creditCard) {
                $options[] = JHTML::_('select.option', $creditCard, vmText::_('VMPAYMENT_VELOCITY_' . strtoupper($creditCard)));
        }
        if ($multiple) {
                $attrs = 'multiple="multiple"';
                $idA .= '[]';
        }
        return JHTML::_('select.genericlist', $options, $idA, $attrs, 'value', 'text', $selected_cc_type);
    }

    /*
     * validate_creditcard_data
     * @author Velocity Team
     */

    function _validate_velocity_creditcard_data($enqueueMessage = TRUE) {
        static $force=true;

        if(empty($this->_cc_number) and empty($this->_cc_cvv)) {
                return false;
        }
        $html = '';
        $this->_cc_valid = !empty($this->_cc_number) and !empty($this->_cc_cvv) and !empty($this->_cc_expire_month) and !empty($this->_cc_expire_year);

        if (!empty($this->_cc_number) and !Creditcard::validate_credit_card_number($this->_cc_type, $this->_cc_number)) {
                $this->_errormessage[] = 'VMPAYMENT_VELOCITY_CARD_NUMBER_INVALID';
                $this->_cc_valid = FALSE;
        }

        if (!Creditcard::validate_credit_card_cvv($this->_cc_type, $this->_cc_cvv)) {
                $this->_errormessage[] = 'VMPAYMENT_VELOCITY_CARD_CVV_INVALID';
                $this->_cc_valid = FALSE;
        }

        if (!Creditcard::validate_credit_card_date($this->_cc_type, $this->_cc_expire_month, $this->_cc_expire_year)) {
                $this->_errormessage[] = 'VMPAYMENT_VELOCITY_CARD_EXPIRATION_DATE_INVALID';
                $this->_cc_valid = FALSE;
        }
        if (!$this->_cc_valid) {
                //$html.= "<ul>";
                foreach ($this->_errormessage as $msg) {
                        //$html .= "<li>" . vmText::_($msg) . "</li>";
                        $html .= vmText::_($msg) . "<br/>";
                }
                //$html.= "</ul>";
        }
        if (!$this->_cc_valid && $enqueueMessage && $force) {
                $app = JFactory::getApplication();
                $app->enqueueMessage($html);
                $force=false;
        }
        return $this->_cc_valid;
    }

    /**
     * _getFormattedDate
     *
     *
     */
    function _getFormattedDate($month, $year) {

        return sprintf('%02d-%04d', $month, $year);
    }

    /**
     * @param $method
     * @return html|mixed|string
     */
    public function _displayCVVImages($method) {

        $cvv_images = $method->cvv_images;
        $img = '';
        if ($cvv_images) {
                $img = $this->displayLogos($cvv_images);
                $img = str_replace('"', "'", $img);
        }
        return $img;
    }

    /**
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @author Velocity Team
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {

        $return = $this->onCheckAutomaticSelected($cart, $cart_prices);
        if (isset($return)) {
                return 0;
        } else {
                return NULL;
        }
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Velocity Team
     */
    protected function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {

        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
        return TRUE;
    }

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Velocity Team
     */
    function plgVmOnShowOrderPrintPayment($order_number, $method_id) {

        return parent::onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3( &$data) {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {

        return $this->setOnTablePluginParams($name, $id, $table);
    }

}

// No closing tag