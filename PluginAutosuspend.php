<?php

require_once 'library/CE/NE_MailGateway.php';

require_once 'modules/clients/models/UserPackageGateway.php';
require_once 'modules/billing/models/Invoice.php';
require_once 'modules/billing/models/InvoiceEntry.php';
require_once 'modules/admin/models/ServicePlugin.php';

/**
 * All plugin variables/settings to be used for this particular service.
 *
 * @return array The plugin variables.
 */

/**
* @package Plugins
*/
class PluginAutosuspend extends ServicePlugin
{
    protected $featureSet = 'products';
    public $hasPendingItems = true;

    function getVariables()
    {
        $variables = array(
            /*T*/'Plugin Name'/*/T*/   => array(
                'type'          => 'hidden',
                'description'   => /*T*/''/*/T*/,
                'value'         => /*T*/'Auto Suspend / Unsuspend'/*/T*/,
            ),
            /*T*/'Enabled'/*/T*/       => array(
                'type'          => 'yesno',
                'description'   => /*T*/'When enabled, overdue packages will be suspended when this service is run.'/*/T*/,
                'value'         => '0',
            ),
            /*T*/'E-mail Notifications'/*/T*/       => array(
                'type'          => 'textarea',
                'description'   => /*T*/'When a package requires manual suspension you will be notified at this E-mail address. If packages are suspended when this service is run, a summary E-mail will be sent to this address.'/*/T*/,
                'value'         => '',
            ),
            /*T*/'Suspend Customer'/*/T*/    => array(
                'type'          => 'yesno',
                'description'   => /*T*/'When enabled, all customers packages will be suspended if a recurringfee not associated with a package is overdue.'/*/T*/,
                'value'         => '1',
            ),
            /*T*/'Enable/Disable Unsuspension'/*/T*/ => array(
                'type'          => 'yesno',
                'description'   => /*T*/'When enabled, suspended and paid packages will be unsuspended when this service is run.'/*/T*/,
                'value'         => '0',
            ),
            /*T*/'Days Overdue Before Suspending'/*/T*/    => array(
                'type'          => 'text',
                'description'   => /*T*/'Only suspend packages that are this many days overdue. Enter 0 here to disable package suspension'/*/T*/,
                'value'         => '7',
            ),
            /*T*/'Run schedule - Minute'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '0',
                'helpid'        => '8',
            ),
            /*T*/'Run schedule - Hour'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '0',
            ),
            /*T*/'Run schedule - Day'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '*',
            ),
            /*T*/'Run schedule - Month'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number, range, list or steps'/*/T*/,
                'value'         => '*',
            ),
            /*T*/'Run schedule - Day of the week'/*/T*/  => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter number in range 0-6 (0 is Sunday) or a 3 letter shortcut (e.g. sun)'/*/T*/,
                'value'         => '*',
            ),
            /*T*/'Notified Package List'/*/T*/ => array(
                'type'          => 'hidden',
                'description'   => /*T*/'Used to store package IDs of manually suspended packages whose E-mail has already been sent.'/*/T*/,
                'value'         => ''
            )
        );

        return $variables;
    }

    /**
     * Execute the order processor.  We'll activate any pending users an then their packages
     * if they are paid and used the signup form.  Manually added packages will be left
     * untouched.
     *
     */
    function execute()
    {
        $gateway = new UserPackageGateway($this->user);

        $messages = array();
        $newPreEmailed = array();
        $autoUnsuspend = array();
        $autoSuspend = array();
        $preEmailed = unserialize($this->settings->get('plugin_autosuspend_Notified Package List'));
        $dueDays = $this->settings->get('plugin_autosuspend_Days Overdue Before Suspending');
        if ( $dueDays !=0 ) {
            $manualSuspend = array();
            $overdueArray = $this->_getOverduePackages();
            foreach ($overdueArray as $packageId => $dueDate) {
                $domain = new UserPackage($packageId, array(), $this->user);
                if ($gateway->hasServerPlugin($domain->getCustomField("Server Id"), $pluginName)) {
                    $errors = false;
                    try{
                        $domain->suspend(true, true);
                    }catch(Exception $ex){
                        $errors = true;
                    }

                    if($errors){
                        $manualSuspend[] = $domain->getID();
                    }else{
                        $autoSuspend[] = $domain->getID();
                    }
                } elseif (is_array($preEmailed) && !in_array($domain->getID(), $preEmailed)) {
                    $manualSuspend[] = $domain->getID();
                    $newPreEmailed[] = $domain->getID();
                } else {
                    $newPreEmailed[] = $domain->getID();
                }
            }
            $sendSummary = false;
            $body = $this->user->lang("Autosuspend Service Summary")."\n\n";
            if (sizeof($autoSuspend) > 0) {
                $sendSummary = true;
                $body .= $this->user->lang("Suspended").":\n\n";
                foreach ($autoSuspend as $id) {
                    $domain = new UserPackage($id, array(), $this->user);
                    $user = new User($domain->CustomerId);
                    $body .= $user->getFullName()." => ".$domain->getReference(true)."\n";
                }
                $body .= "\n";
            }
            if (sizeof($manualSuspend) > 0) {
                $sendSummary = true;
                $body .= $this->user->lang("Requires Manual Suspension").":\n\n";
                foreach ($manualSuspend as $id) {
                    $domain = new UserPackage($id, array(), $this->user);
                    $user = new User($domain->CustomerId);
                    $body .= $user->getFullName()." => ".$domain->getReference(true)."\n";
                }
            }

            if ($sendSummary && $this->settings->get('plugin_autosuspend_E-mail Notifications') != "") {
                $mailGateway = new NE_MailGateway();
                $destinataries = explode("\r\n", $this->settings->get('plugin_autosuspend_E-mail Notifications'));
                foreach ($destinataries as $destinatary) {
                    if ( $destinatary != '' ) {
                        $mailGateway->mailMessageEmail( $body,
                            $this->settings->get('Support E-mail'),
                            $this->settings->get('Support E-mail'),
                            $destinatary,
                            false,
                            $this->user->lang("AutoSuspend Service Summary"));
                    }
                }
            }

            // Store the new notified list
            array_unshift($messages, $this->user->lang('%s package(s) suspended', sizeof($autoSuspend)));
        }

        if ( $this->settings->get('plugin_autosuspend_Enable/Disable Unsuspension') !=0 ) {
            $manualUnsuspend = array();
            $suspendedArray = $this->_getSuspendedPackages();
            foreach ($suspendedArray as $packageId) {
                $domain = new UserPackage($packageId, array(), $this->user);
                if ($gateway->hasServerPlugin($domain->getCustomField("Server Id"), $pluginName)) {
                    $errors = false;
                    try{
                        $domain->unsuspend(true, true);
                    }catch(Exception $ex){
                        $errors = true;
                    }

                    if($errors){
                        $manualUnsuspend[] = $domain->getID();
                    }else{
                        $autoUnsuspend[] = $domain->getID();
                    }
                } elseif (is_array($preEmailed) && !in_array($domain->getID(), $preEmailed)) {
                    $manualUnsuspend[] = $domain->getID();
                    $newPreEmailed[] = $domain->getID();
                } else {
                    $newPreEmailed[] = $domain->getID();
                }
            }
            $sendSummary = false;
            $body = $this->user->lang("Autounsuspend Service Summary")."\n\n";
            if (sizeof($autoUnsuspend) > 0) {
                $sendSummary = true;
                $body .= $this->user->lang("Unsuspended").":\n\n";
                foreach ($autoUnsuspend as $id) {
                    $domain = new UserPackage($id, array(), $this->user);
                    $user = new User($domain->CustomerId);
                    $body .= $user->getFullName()." => ".$domain->getReference(true)."\n";
                }
                $body .= "\n";
            }
            if (sizeof($manualUnsuspend) > 0) {
                $sendSummary = true;
                $body .= $this->user->lang("Requires Manual Unsuspension").":\n\n";
                foreach ($manualUnsuspend as $id) {
                    $domain = new UserPackage($id, array(), $this->user);
                    $user = new User($domain->CustomerId);
                    $body .= $user->getFullName()." => ".$domain->getReference(true)."\n";
                }
            }

            if ($sendSummary && $this->settings->get('plugin_autounsuspend_E-mail Notifications') != "") {
                $mailGateway = new NE_MailGateway();
                $destinataries = explode("\r\n", $this->settings->get('plugin_autounsuspend_E-mail Notifications'));
                foreach ($destinataries as $destinatary) {
                    if ( $destinatary != '' ) {
                        $mailGateway->mailMessageEmail( $body,
                            $this->settings->get('Support E-mail'),
                            $this->settings->get('Support E-mail'),
                            $destinatary,
                            false,
                            $this->user->lang("AutoUnsuspend Service Summary"));
                    }
                }
            }
            array_unshift($messages, $this->user->lang('%s package(s) unsuspended', sizeof($autoUnsuspend)));
        }
        if($this->settings->get('plugin_autosuspend_Enable/Disable Unsuspension')==0 and $dueDays==0) {
            array_unshift($messages, $this->user->lang('As you disabled both the services.The system has nothing to do.'));
        }
        $this->settings->updateValue("plugin_autosuspend_Notified Package List", serialize($newPreEmailed));
        return $messages;
    }

    function pendingItems()
    {
        $gateway = new UserPackageGateway($this->user);
        $overdueArray = $this->_getOverduePackages();
        $suspendedArray = $this->_getSuspendedPackages();
        $returnArray = array();
        $returnArray['data'] = array();
        foreach ($overdueArray as $packageId => $dueDate) {
            $domain = new UserPackage($packageId, array(), $this->user);
            $user = new User($domain->CustomerId);
            if ($gateway->hasServerPlugin($domain->getCustomField("Server Id"), $pluginName)) {
                $auto = "No";
            } else {
                $auto = "<span style=\"color:red\"><b>Yes</b></span>";
            }

            $tmpInfo = array();
            $tmpInfo['customer'] = '<a href="index.php?fuse=clients&controller=userprofile&view=profilecontact&frmClientID=' . $user->getId() . '">' . $user->getFullName() . '</a>';
            $tmpInfo['package_type'] = $domain->getProductGroupName();
            if ( $domain->getProductType() == 3 ) {
                $tmpInfo['package'] = $domain->getProductGroupName();
            } else {
                $tmpInfo['package'] = $domain->getProductName();
            }
            $tmpInfo['domain'] = '<a href="index.php?fuse=clients&controller=userprofile&view=profileproduct&selectedtab=groupinfo&frmClientID=' . $user->getId() . '&id=' . $domain->getId() . '">' . $domain->getReference(true) . '</a>';
            $tmpInfo['date'] = date($this->settings->get('Date Format'), $dueDate);
            $tmpInfo['manual'] = $auto;
            $returnArray['data'][] = $tmpInfo;
        }
        foreach ($suspendedArray as $packageId) {
            $domain = new UserPackage($packageId, array(), $this->user);
            $user = new User($domain->CustomerId);
            if ($gateway->hasServerPlugin($domain->getCustomField("Server Id"),$pluginName)) {
                $auto = "No";
            } else {
                $auto = "<span style=\"color:red\"><b>Yes</b></span>";
            }

            $tmpInfo = array();
            $tmpInfo['customer'] = '<a href="index.php?fuse=clients&controller=userprofile&view=profilecontact&frmClientID=' . $user->getId() . '">' . $user->getFullName() . '</a>';
            $tmpInfo['package_type'] = $domain->getProductGroupName();
            if ( $domain->getProductType() == 3 ) {
                $tmpInfo['package'] = $domain->getProductGroupName();
            } else {
                $tmpInfo['package'] = $domain->getProductName();
            }
            $tmpInfo['domain'] = '<a href="index.php?fuse=clients&controller=userprofile&view=profileproduct&selectedtab=groupinfo&frmClientID=' . $user->getId() . '&id=' . $domain->getId() . '">' . $domain->getReference(true) . '</a>';
            $tmpInfo['date'] = '';
            $tmpInfo['manual'] = $auto;
            $returnArray['data'][] = $tmpInfo;
        }
        $returnArray["totalcount"] = count($returnArray['data']);
        $returnArray['headers'] = array (
            $this->user->lang('Customer'),
            $this->user->lang('Package Type'),
            $this->user->lang('Package Name'),
            $this->user->lang('Domain'),
            $this->user->lang('Due Date'),
            $this->user->lang('Requires Manual Suspension?')
        );
        return $returnArray;
    }

    function output() { }

    function dashboard()
    {
        $overdueArray = $this->_getOverduePackages();

        $autoSuspend = 0;
        $manualSuspend = 0;

        $gateway = new UserPackageGateway($this->user);

        foreach ($overdueArray as $packageId => $dueDate) {
            $domain = new UserPackage($packageId, array(), $this->user);
            if ($gateway->hasServerPlugin($domain->getCustomField("Server Id"), $pluginName)) {
                $autoSuspend++;
            } else {
                $manualSuspend++;
            }
        }

        $message = $this->user->lang('Number of packages pending auto suspension: %d', $autoSuspend);
        $message .= "<br>";

        $message .= $this->user->lang('Number of packages requiring manual suspension: %d', $manualSuspend);

        return $message;
    }

    function _getOverduePackages()
    {
        $query = "SELECT id FROM invoice WHERE (status=0 OR status=5) AND billdate < DATE_SUB( NOW() , INTERVAL ? DAY ) ORDER BY billdate ASC";
        $result = $this->db->query($query, @$this->settings->get('plugin_autosuspend_Days Overdue Before Suspending'));
        $overduePackages = array();
        $overdueCustomers = array();
        while ($row = $result->fetch()) {
            $invoice = new Invoice($row['id']);
            $user = new User($invoice->getUserID());
            if ($user->getStatus() != 1) continue;
            foreach ($invoice->getInvoiceEntries() as $invoiceEntry) {
                if ($invoiceEntry->AppliesTo() != 0) {
                    // Found an overdue package, add it to the list
                    if (!in_array($invoiceEntry->AppliesTo(), array_keys($overduePackages))) {
                        $package = new UserPackage($invoiceEntry->AppliesTo(), array(), $this->user);
                        if ($package->status != 1) continue;
                        // ignore this user package, as we are set to override the autosuspend.
                        if ( $package->getCustomField('Override AutoSuspend') == 1 ) {
                            continue;
                        }
                        $overduePackages[$invoiceEntry->AppliesTo()] = $invoice->getDate('timestamp');
                    }
                } else {
                    // Found an overdue recurring fee that doesn't belong to a package.  Assume the entire client is overdue.
                    if ( !in_array($invoiceEntry->GetCustomerID(), array_keys($overdueCustomers)) && $this->settings->get('plugin_autosuspend_Suspend Customer') == 1) {
                        $overdueCustomers[$invoiceEntry->GetCustomerID()] = $invoice->getDate('timestamp');
                    }
                }
            }
        }

        if ( $this->settings->get('plugin_autosuspend_Suspend Customer') == 1 ) {
            // Now we have all the overdue packages and clients.
            // We'll loop through the clients and all their packages to the list.
            foreach ($overdueCustomers as $customerId => $dueDate) {
                $query = "SELECT id "
                        ."FROM domains "
                        ."WHERE CustomerID = ? "
                        ."AND status = 1 ";
                $result = $this->db->query($query, $customerId);
                while ($row = $result->fetch()) {
                    if (!in_array($row['id'], array_keys($overduePackages))) {
                        $overduePackages[$row['id']] = $dueDate;
                    }
                }
            }
        }
        asort($overduePackages);

        return $overduePackages;
    }

    function _getSuspendedPackages()
    {
        // Select domains that should not be unsuspended due to an invoice being overdue with entries that do no apply to any domains. (apply to entire account)
        $query = "SELECT d.id AS domain_id "
                ."FROM `domains` d "
                ."WHERE d.`status` = 2 "
                ."AND (EXISTS(SELECT * "
                ."            FROM `invoice` i "
                ."            JOIN `invoiceentry` ie "
                ."            ON (i.id = ie.invoiceid) "
                ."            WHERE (i.`status` = 0 "
                ."            OR i.`status` = 5) "
                ."            AND d.`CustomerID` = i.`customerid` "
                ."            AND ie.appliestoid = 0)) ";
        $result = $this->db->query($query);
        $doNotUnsuspend = array();
        while ($row = $result->fetch())
        {
            $doNotUnsuspend[] = $row['domain_id'];
        }

        // Find all packages eligible for unsuspend
        $query = "SELECT d.id AS domain_id "
                ."FROM `domains` d "
                ."WHERE d.`status` = 2 "
                ."AND (NOT EXISTS(SELECT * "
                ."                FROM `invoice` i "
                ."                JOIN `invoiceentry` ie "
                ."                ON (i.id = ie.invoiceid) "
                ."                WHERE (i.`status` = 0 "
                ."                OR i.`status` = 5) "
                ."                AND d.`CustomerID` = i.`customerid` "
                ."                AND ie.`appliestoid` = d.id "
                ."                AND billdate < NOW())) ";
        $result = $this->db->query($query);
        $suspendedPackages = array();
        while ($row = $result->fetch())
        {
            // Verify that the packages can be unsuspended
            if (!in_array($row['domain_id'], $doNotUnsuspend))
                $suspendedPackages[] = $row['domain_id'];
        }

        asort($suspendedPackages);
        return $suspendedPackages;
    }
}