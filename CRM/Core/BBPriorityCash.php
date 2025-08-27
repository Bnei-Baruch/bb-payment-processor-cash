<?php

/**
 *
 * @package BBPriorityCash [after AuthorizeNet Payment Processor]
 * @author Gregory Shilin <gshilin@gmail.com>
 */

use Civi\Api4\FinancialTrxn;

require_once 'CRM/Core/Payment.php';
require_once 'BBPriorityCashIPN.php';


/**
 * BBPriorityCash payment processor
 */
class CRM_Core_BBPriorityCash extends CRM_Core_Payment {
    protected $_mode = NULL;

    protected $_params = array();

    /**
     * Constructor.
     *
     * @param string $mode
     *   The mode of operation: live or test.
     *
     * @param $paymentProcessor
     *
     */
    public function __construct($mode, &$paymentProcessor) {
        $this->_mode = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_setParam('processorName', 'BB Payment Cash');
    }

    /**
     * This function checks to see if we have the right config values.
     *
     * @return string
     *   the error message if any
     */
    public function checkConfig() {
        return NULL;
    }

    /**
     * Get an array of the fields that can be edited on the recurring contribution.
     *
     * Some payment processors support editing the amount and other scheduling details of recurring payments, especially
     * those which use tokens. Others are fixed. This function allows the processor to return an array of the fields that
     * can be updated from the contribution recur edit screen.
     *
     * The fields are likely to be a subset of these
     *  - 'amount',
     *  - 'installments',
     *  - 'frequency_interval',
     *  - 'frequency_unit',
     *  - 'cycle_day',
     *  - 'next_sched_contribution_date',
     *  - 'end_date',
     *  - 'failure_retry_day',
     *
     * The form does not restrict which fields from the contribution_recur table can be added (although if the html_type
     * metadata is not defined in the xml for the field it will cause an error.
     *
     * Open question - would it make sense to return membership_id in this - which is sometimes editable and is on that
     * form (UpdateSubscription).
     *
     * @return array
     */
    public function getEditableRecurringScheduleFields() {
        return array('amount', 'next_sched_contribution_date');
    }

    function doPayment(&$params, $component = 'contribute') {
        /* DEBUG
            echo "<pre>";
            var_dump($this->_paymentProcessor);
            var_dump($params);
            echo "</pre>";
            exit();
        */

        if ($component != 'contribute' && $component != 'event') {
            Civi::log()->error('bbprioritycc_payment_exception',
                ['context' => [
                    'message' => "Component '{$component}' is invalid."
                ]]);
            CRM_Utils_System::civiExit();
        }
        $this->_component = $component;

	$contributionID = $params['contributionID'];
	$amount = $params['total_amount'];
	$currencyName = $params['custom_1706'] ?? $params['currencyID'];
	\Civi\Api4\Contribution::update(false)
		->addWhere('id', '=', $contributionID)
		->addValue('currency', $currencyName)
		->execute();
        if ($currencyName == "EUR") {
            $currency = 978;
        } elseif ($currencyName == "USD") {
            $currency = 2;
        } else { // ILS -- default
            $currency = 1;
        }
        $trxn_id = $this->setTrxnId($this->_mode);
	$financialTypeID = self::array_column_recursive_first($params, "financialTypeID");
        if (empty($financialTypeID)) {
            $financialTypeID = self::array_column_recursive_first($params, "financial_type_id");
        }
	$financialAccountID = civicrm_api3('EntityFinancialAccount', 'getvalue', array('return' => "financial_account_id", 'entity_id' => $financialTypeID, 'account_relationship' => 1,));
	$this->createFinancialTrxn($contributionID, $amount, $params['trxn_id'], $this->_paymentProcessor["id"] , $financialAccountID, $currency);

        if (array_key_exists('successURL', $params)) {
            $returnURL = $params['successURL'];
            $cancelURL = $params['cancelURL'];
        } else {
            $url = ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
            $returnURL = CRM_Utils_System::url($url,
                "_qf_ThankYou_display=1&qfKey={$params['qfKey']}",
                TRUE, NULL, FALSE
            );
        }

        $merchantUrlParams = "contactID={$params['contactID']}&contributionID={$contributionID}";
        if ($component == 'event') {
            $merchantUrlParams .= "&eventID={$params['eventID']}&participantID={$params['participantID']}";
        } else {
            $membershipID = CRM_Utils_Array::value('membershipID', $params);
            if ($membershipID) {
                $merchantUrlParams .= "&membershipID=$membershipID";
            }
            $contributionPageID = CRM_Utils_Array::value('contributionPageID', $params) ||
                CRM_Utils_Array::value('contribution_page_id', $params);
            if ($contributionPageID) {
                $merchantUrlParams .= "&contributionPageID=$contributionPageID";
            }
            $relatedContactID = CRM_Utils_Array::value('related_contact', $params);
            if ($relatedContactID) {
                $merchantUrlParams .= "&relatedContactID=$relatedContactID";

                $onBehalfDupeAlert = CRM_Utils_Array::value('onbehalf_dupe_alert', $params);
                if ($onBehalfDupeAlert) {
                    $merchantUrlParams .= "&onBehalfDupeAlert=$onBehalfDupeAlert";
                }
            }
        }

        $base_url = CRM_Utils_System::baseURL();
        $merchantUrl = $base_url . '/civicrm/payment/ipn?processor_name=BBPCash&mode=' . $this->_mode
            . '&md=' . $component . '&qfKey=' . $params["qfKey"] . '&' . $merchantUrlParams
            . '&returnURL=' . $this->base64_url_encode($returnURL);

        $template = CRM_Core_Smarty::singleton();
        $template->assign('url', $merchantUrl);
        print $template->fetch('CRM/Core/Payment/BbpriorityCash.tpl');

        CRM_Utils_System::civiExit();
    }

    public function handlePaymentNotification() {
        $ipnClass = new CRM_Core_Payment_BBPriorityCashIPN(array_merge($_GET, $_REQUEST));

        $input = $ids = array();
        $ipnClass->getInput($input, $ids);

        $ipnClass->main($this->_paymentProcessor, $input, $ids);
    }


    public function _setParam(string $field, string $value) {
        $this->_params[$field] = $value;
    }

    function base64_url_encode($input) {
        return strtr(base64_encode($input), '+/', '-_');
    }

    // Record financial transaction
  private function createFinancialTrxn($contributionID, $totalAmount, $trxn_id, $paymentProcessorID, $financialAccountId, $currency) {
        $ftParams = [
          'total_amount' => $totalAmount,
          'contribution_id' => $contributionID,
	  'entity_id' => $contributionID,
          'trxn_id' => $trxn_id ?? $contributionID,
          'payment_processor_id' => $paymentProcessorID,
          'status_id:name' => 'Completed',
	  'currency' => $currency,
	  'to_financial_account_id' => $financialAccountId,
        ];
	FinancialTrxn::create(false)
		->setValues($ftParams)
		->execute();
  }

        public function setTrxnId(string $mode): string {
        $query = "SELECT MAX(trxn_id) AS trxn_id FROM civicrm_contribution WHERE trxn_id LIKE '{$mode}_%' LIMIT 1";
        $tid = CRM_Core_Dao::executeQuery($query);
        if (!$tid->fetch()) {
            throw new CRM_Core_Exception('Could not find contribution max id');
        }
        $trxn_id = strval($tid->trxn_id);
        $trxn_id = str_replace("{$mode}_", '', $trxn_id);
        $trxn_id = intval($trxn_id) + 1;
        $uniqid = uniqid();
        return "{$mode}_{$trxn_id}_{$uniqid}";
    }

    /* Find first occurrence of needle somewhere in haystack (on all levels) */
    static function array_column_recursive_first(array $haystack, $needle) {
        $found = [];
        array_walk_recursive($haystack, function ($value, $key) use (&$found, $needle) {
            if (gettype($key) == 'string' && $key == $needle) {
                $found[] = $value;
            }
        });
        return count($found) > 0 ? $found[0] : "";
    }

}
