Velocity Virtuemart(Joomla) Plugin Installation Documentation

Configuration Requirement: Joomla site Version 3.0 and virtuemart Version 3.0 or above version must be required for our velocity payment plugin installation.

Installation & Configuration of Plugin: There are two methods to install velocity plugin -

Method 1 -
1) Download Velocity Plugin by clicking on Download zip button on the right bottom of this page.
2) Extract the zip and re-zip the folder 'velocity' (inside the extracted folder).
3) Login Joomla admin panel and click on "Extentions->Extention Manager" Menu option then click on browse to upload and select the zipped 'velocity' file then click on 'Upload & Install'.
4) After Successful installation, goto "Extentions->Plugin Manager" select and enable the velocity payment plugin.

Method 2 -
1) Download Velocity Plugin by clicking on Download zip button on the right bottom of this page.
2) Extract the zip at any location on your machine.
3) Login Joomla admin panel and click on "Extentions->Extention Manager" Menu option and select 'Install from Directory' tab then paste the path of 'velcotiy' folder (inside the extracted folder) in the provided field and click 'Install' button.
4) After Successful installation, goto "Extentions->Plugin Manager" select and enable the velocity payment plugin.

For configure the velocity payment plugin to our shop goto "Components->Virtuemart->Payment Methods" listed all the existing payment methods for new payment method click on "New" button and enter name and select velocity payment plugin from dropdown and save the payment method after saving the payment method click on right tab configuration and fill all the velocity credential select test/production mode and save.

VELOCITY CREDENTIAL DETAILS 
1. IdentityToken - This is security Token provided by velocity to merchant. 
2.  WorkFlowId/ServiceId: - This is service id provided by velocity to merchant.
3.   ApplicationProfileId: - This is application id provided by velocity to merchant.
4.    MerchantProfileId: - This is merchant id provided by velocity to merchant. 
5.    Test Mode :- This is for test the module, if select radiobox for test or live payment.

For Refund option at admin side first click on "Components->Virtuemart->orders" and click on perticular order goto bottom of order payment detail click on Refund link to open the refund option put the refund amount and ckeckbox for shipping and click on "Refund Process" button to process the refund.

For uninstall the velocity plugin of joomla goto "Extentions->Extention Manager" click on left-side menu "Manage" and select velocity module to dissable and uninstall the velocity payment plugin.

We have saved the raw request and response objects in <prefix>_virtuemart_payment_plg_velocity table.