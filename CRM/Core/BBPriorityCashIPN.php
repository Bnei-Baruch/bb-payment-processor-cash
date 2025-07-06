<?php

use Civi\Api4\Contribution;

class CRM_Core_Payment_BBPriorityCashIPN extends CRM_Core_Payment_BaseIPN {
    function __construct($inputData) {
        $this->setInputParameters($inputData);
        parent::__construct();
    }

    function main(&$paymentProcessor, &$input, &$ids): void {
        try {
            $contributionStatuses = array_flip(CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate'));
            $contributionID = $this->retrieve('contributionID', 'Integer');
            $contactID = $this->retrieve('contactID', 'Integer');
            $contribution = $this->getContribution($contributionID, $contactID);

            $statusID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution',
                $contribution->id, 'contribution_status_id'
            );
            if ($statusID === $contributionStatuses['Completed']) {
                Civi::log('BBPCash IPN')->debug('returning since contribution has already been handled');
                return;
            }
            $contribution->contribution_status_id = $contributionStatuses['Completed'];
            $contribution->trxn_id = 'Cash-' . $contribution->invoice_id;
            $contribution->update();

            $this->redirectSuccess($input);
            CRM_Utils_System::civiExit();
        } catch (CRM_Core_Exception $e) {
            Civi::log('BBPCash IPN')->debug($e->getMessage());
            echo 'Invalid or missing data';
        }
    }

    function getInput(&$input, &$ids) {
        $input = array(
            // GET Parameters
            'module' => $this->retrieve('md', 'String'),
            'component' => $this->retrieve('md', 'String'),
            'qfKey' => $this->retrieve('qfKey', 'String', false),
            'contributionID' => $this->retrieve('contributionID', 'String'),
            'contactID' => $this->retrieve('contactID', 'String'),
            'eventID' => $this->retrieve('eventID', 'String', false),
            'participantID' => $this->retrieve('participantID', 'String', false),
            'membershipID' => $this->retrieve('membershipID', 'String', false),
            'contributionPageID' => $this->retrieve('contributionPageID', 'String', false),
            'relatedContactID' => $this->retrieve('relatedContactID', 'String', false),
            'onBehalfDupeAlert' => $this->retrieve('onBehalfDupeAlert', 'String', false),
            'returnURL' => $this->retrieve('returnURL', 'String', false),
        );

        $ids = array(
            'contribution' => $input['contributionID'],
            'contact' => $input['contactID'],
        );
        if ($input['module'] == "event") {
            $ids['event'] = $input['eventID'];
            $ids['participant'] = $input['participantID'];
        } else {
            $ids['membership'] = $input['membershipID'];
            $ids['related_contact'] = $input['relatedContactID'];
            $ids['onbehalf_dupe_alert'] = $input['onBehalfDupeAlert'];
        }
    }

    function redirectSuccess(&$input): void {
        $url = $this->base64_url_decode($input['returnURL']);
        $key = "success";
        $value = "1";
        $url = preg_replace('/(.*)(?|&)' . $key . '=[^&]+?(&)(.*)/i', '$1$2$4', $url . '&');
        $url = substr($url, 0, -1);
        if (strpos($url, '?') === false) {
            $returnURL = ($url . '?' . $key . '=' . $value);
        } else {
            $returnURL = ($url . '&' . $key . '=' . $value);
        }

        // Print the tpl to redirect to success
        $template = CRM_Core_Smarty::singleton();
        $template->assign('url', $returnURL);
        print $template->fetch('CRM/Core/Payment/BbpriorityCash.tpl');
    }

    public function retrieve($name, $type, $abort = TRUE, $default = NULL) {
        $value = CRM_Utils_Type::validate(
            empty($this->_inputParameters[$name]) ? $default : $this->_inputParameters[$name],
            $type,
            FALSE
        );
        if ($abort && $value === NULL) {
            throw new CRM_Core_Exception("Could not find an entry for $name");
        }
        return $value;
    }

    private function getContribution($contribution_id, $contactID) {
        $contribution = new CRM_Contribute_BAO_Contribution();
        $contribution->id = $contribution_id;
        if (!$contribution->find(TRUE)) {
            throw new CRM_Core_Exception('Failure: Could not find contribution record for ' . (int)$contribution->id, NULL, ['context' => "Could not find contribution record: {$contribution->id} in IPN request: "]);
        }
        if ((int)$contribution->contact_id !== $contactID) {
            Civi::log("Contact ID in IPN not found but contact_id found in contribution.");
        }
        return $contribution;
    }

    function base64_url_decode($input) {
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
