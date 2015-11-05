<?php    
/**
 *
 * @author Velocity Team
 * @version $Id: velocityRefund.php
 * @package VirtueMart
 * @subpackage payment
 * @copyright Copyright (C) 2015 The Velocity team - All rights reserved.
 * @license 
 *
 * http://nabvelocity.com/
 */
if (isset($_POST)) {

    define( '_JEXEC', 1 );
    define( 'DS', DIRECTORY_SEPARATOR );
    define( 'JPATH_BASE', $_SERVER['DOCUMENT_ROOT'] );

    require_once( '../../../includes/defines.php' );
    require_once( '../../..' . DS . 'libraries' . DS . 'import.php' ); // framework
    require_once( '../../..' . DS . 'configuration.php' ); // config file
    require_once( '../../..' . DS . 'libraries' . DS . 'simplepie' . DS . 'idn' . DS . 'idna_convert.class.php' ); // punycode for email

    $db = JFactory::getDBO();

    $q1 = 'SELECT transaction_id FROM `#__virtuemart_payment_plg_velocity` where virtuemart_order_id = ' . (int)$_POST['orderid'];
    $db->setQuery ($q1);
    $txtid = $db->loadResult();

    if (isset($txtid)) {

        $q1 = 'SELECT * FROM `#__virtuemart_orders` where virtuemart_order_id = ' . (int)$_POST['orderid'];
        $db->setQuery($q1);
        $ship = $db->loadObjectList ();
        $cust_id = $ship[0]->virtuemart_user_id;
        $order_num = $ship[0]->order_number;
        $order_total = $ship[0]->order_total;
        if(isset($_POST['shipping']) && isset($_POST['shipamount']) && $_POST['shipping'] == 'true') {    
            $total_refund = (float)$_POST['amount'] + (float)$_POST['shipamount'];
        } else {
            $total_refund = (float)$_POST['amount'];
            $_POST['shipamount'] = 0;
        }

        $q2 = 'SELECT payment_params FROM `#__virtuemart_paymentmethods` where payment_element = "velocity"';
        $db->setQuery($q2);
        $vcred = $db->loadResult();
        $vcred = explode('"', $vcred);
        $identitytoken        = $vcred[1];
        $workflowid           = $vcred[3];
        $applicationprofileid = $vcred[5];
        $merchantprofileid    = $vcred[7];
        $payment_mode         = $vcred[9];

        include_once('sdk' . DS . 'Velocity.php');

        if ($payment_mode)
            $isTestAccount = TRUE;
        else                   
            $isTestAccount = FALSE;

        try {            
            $velocityProcessor = new VelocityProcessor( $applicationprofileid, $merchantprofileid, $workflowid, $isTestAccount, $identitytoken );    
        } catch (Exception $e) {
            echo $e->getMessage(); exit;
        }

        try {
            // request for refund
            $response = $velocityProcessor->returnById(array(  
                'amount'        => $total_refund,
                'TransactionId' => $txtid
            ));

            $xml = VelocityXmlCreator::returnByIdXML(number_format($total_refund, 2, '.', ''), $txtid);  // got ReturnById xml object.  
            $req = $xml->saveXML();

            if ( is_array($response) && !empty($response) && isset($response['Status']) && $response['Status'] == 'Successful') {
                
                $date = JFactory::getDate(); // for current datetime
                
                /* save the returnbyid response into 'velocity transactions' custom table.*/ 
                $queryR = $db->getQuery(true);
                $columns = array('transaction_id','transaction_status', 'virtuemart_order_id', 'request_obj', 'response_obj', 'created_on', 'created_by', 'modified_on', 'modified_by');
                $values = array($db->quote($response['TransactionId']), $db->quote($response['TransactionState']), (int)$_POST['orderid'], $db->quote(serialize($req)), $db->quote(serialize($response)), $db->quote($date->format(JDate::$format)), (int)$_POST['userid'], $db->quote($date->format(JDate::$format)), (int)$_POST['userid']);
                $queryR
                    ->insert($db->quoteName('#__virtuemart_payment_plg_velocity'))
                    ->columns($db->quoteName($columns))
                    ->values(implode(',', $values));
                $db->setQuery($queryR);
                $flagR = $db->execute();

                /* Update the refund detail into comment table at admin order detail..*/
                $comment = 'ApprovalCode: ' . $response['ApprovalCode'] . '<br>Refund Transaction_Id: ' . $response['TransactionId'] . '<br> Order Total: ' . round($order_total, 2) . ' '.$_POST['currency'] . '<br>Refunded Amount:' . $response['Amount'].' '.$_POST['currency'];
                $queryH = $db->getQuery(true);
                $columns = array('virtuemart_order_id','order_status_code', 'customer_notified', 'comments', 'published', 'created_on', 'created_by', 'modified_on', 'modified_by');
                $values = array((int)$_POST['orderid'], $db->quote('R'), 1, $db->quote($comment), 1, $db->quote($date->format(JDate::$format)), (int)$_POST['userid'], $db->quote($date->format(JDate::$format)), (int)$_POST['userid']);
                $queryH
                    ->insert($db->quoteName('#__virtuemart_order_histories'))
                    ->columns($db->quoteName($columns))
                    ->values(implode(',', $values));
                $db->setQuery($queryH);
                $flagH = $db->execute();

                /* update order status.*/ 
                $queryU = $db->getQuery(true);
                $fields = array(
                    $db->quoteName('order_status') . ' = ' . $db->quote('R')
                );
                $conditions = array(
                    $db->quoteName('virtuemart_order_id') . ' = ' . $_POST['orderid']
                );
                $queryU->update($db->quoteName('#__virtuemart_orders'))->set($fields)->where($conditions);
                $db->setQuery($queryU);
                $flagU = $db->execute();
                
                if($flagR && $flagH && $flagU) {
                    //send email to admin and user.
                    $qec = 'SELECT email FROM `#__users` where id = ' . (int)$cust_id;
                    $db->setQuery($qec);
                    $customer_email = $db->loadResult(); 
                    $qea = 'SELECT email FROM `#__users` where id = ' . (int)$_POST['userid'];
                    $db->setQuery($qea);
                    $admin_email = $db->loadResult(); 
                    
                    $orinfo = 'SELECT * FROM `#__virtuemart_order_userinfos` where virtuemart_order_id = ' . (int)$_POST['orderid'];
                    $db->setQuery($orinfo);
                    $ordercust = $db->loadObjectList ();
                    $title     = $ordercust[0]->title;
                    $fname     = $ordercust[0]->first_name;
                    $lname     = $ordercust[0]->last_name;
                    $cust_name = $title . ' ' . $fname . ' ' . $lname;
                    $company   = $ordercust[0]->company;
                    
                    $tempdetail = 'SELECT * FROM `#__virtuemart_vendors_en_gb` where virtuemart_vendor_id = 1';
                    $db->setQuery($tempdetail);
                    $tempdetails = $db->loadObjectList ();
                    $vendor_store_desc         = $tempdetails[0]->vendor_store_desc;
                    $vendor_legal_info         = $tempdetails[0]->vendor_legal_info;
                    $vendor_letter_css         = $tempdetails[0]->vendor_letter_css;
                    $vendor_letter_header_html = $tempdetails[0]->vendor_letter_header_html;
                    $vendor_letter_footer_html = $tempdetails[0]->vendor_letter_footer_html;
                    $vendor_store_name         = $tempdetails[0]->vendor_store_name;
                    $vendor_phone              = $tempdetails[0]->vendor_phone;
                    $customtitle               = $tempdetails[0]->customtitle;
                    $vendor_mail_css           = $tempdetails[0]->vendor_mail_css;
                    $slug                      = $tempdetails[0]->slug;
                    $vendor_url                = $tempdetails[0]->vendor_url;
                    
                    $subject   = $vendor_store_name . ' Refund Request Confirmation';
                    
                    $comp = 'SELECT company FROM `#__virtuemart_userinfos` where virtuemart_user_id = ' . (int)$_POST['userid'];
                    $db->setQuery($comp);
                    $vendor_company = $db->loadResult();
                    
                    $template = '<body style="background: #F2F2F2;word-wrap: break-word;">
                    <div style="background-color: #e6e6e6;" width="100%">
                    <table style="margin: auto;" cellpadding="10" cellspacing="0"  >
                    <tr><td>Hi ' . $cust_name . ', <br>The below payment has been refunded by ' . $vendor_store_name . ' to your account:'. '</td></tr>
                    <tr><td>Product Amount: $' . $_POST['amount'] . '<br>
                            Shipping Amount: $' . round($_POST['shipamount'], 2) . '<br>
                            Total Refund: $' . $response['Amount'] . '<br>
                            Order Number: ' . $order_num . '</td></tr>
                    </table><br/><br/>Thank you for your interest<br>' . $vendor_company . '<br>' . $vendor_store_name . '<br><br><br>' . $vendor_store_desc . '<br/><hr/><br/>' . $vendor_legal_info . '</div></body>';
                    
                    $mailer = JFactory::getMailer(); 
                    $config = JFactory::getConfig();
                    $sender = array( 
                        $config->get( 'mailfrom' ),
                        $config->get( 'fromname' ) 
                    );
                    $recipient = array( $customer_email, $admin_email );
                    
                    $mailer->addRecipient($recipient);
                    $mailer->isHTML(true);
                    $mailer->Encoding = 'base64';
                    $mailer->setSender($sender);
                    $mailer->setSubject($subject);
                    $mailer->setBody($template);
                    // Optionally add embedded image
                    //$mailer->AddEmbeddedImage( $vendor_url.'plugins/vmpayment/velocity/velocity/assets/img/logo.png', 'logo_id', 'logo.png', 'base64', 'image/png' );

                    $send = $mailer->Send();
                    if ( $send !== true ) {
                        echo 'Error sending email: ' . $send->__toString();
                    } else {
                        echo 'success';
                    }

                } else {
                    echo 'Sorry! transaction done but some issue';
                }    
                exit;
                
            } else if (is_array($response) && !empty($response)) {
                echo $response['StatusMessage']; exit;
            } else if (is_string($response)) {
                echo $response; exit;
            } else {
                echo 'Unknown Error please contact the site admin'; exit;
            }

        } catch(Exception $e) {
            echo $e->getMessage(); exit;
        }
    } else {
        echo 'Transaction Id not valid for refund'; exit;
    }

} else {
    echo 'Sorry Technical issue.'; exit;
}
/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */