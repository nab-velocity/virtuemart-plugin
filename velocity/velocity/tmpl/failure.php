<?php
/**
 *
 * Velocity payment plugin
 *
 * @author Velocity Team
 * @version $Id: failure.php
 * @package VirtueMart
 * @subpackage payment
 * Copyright (C) 2015 Velocity Team. All rights reserved.
 * @license 
 *
 * http://nabvelocity.com
 */
defined('_JEXEC') or die();
//if ($viewData['include_amazon_css']) {
//	$doc = JFactory::getDocument()->addStyleSheet(JURI::root(true) . '/plugins/vmpayment/amazon/assets/css/amazon.css');
//}
$failure = $viewData["failure"];

if($failure) {
?>
<table>
    <tr><th>Your Payment has been failed.</th></tr>
    <tr><td><?php echo $viewData["error"];  ?></td></tr>
</table>

<?php } ?>
