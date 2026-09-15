<?php

/**
 * Collection of upgrade steps
 */
class CRM_BbpriorityCash_Upgrader extends CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  public function getCurrentRevision() {
    // reset the saved extension version as well
    try {
      $xmlfile = CRM_Core_Resources::singleton()->getPath('info.kabbalah.payment.bbpriorityCash','info.xml');
      $myxml = simplexml_load_file($xmlfile);
      $version = (string)$myxml->version;
      //CRM_Core_BAO_Setting::setItem($version, 'BB Payments Extension', 'bb_extension_version');
      \Civi::settings()->set('bb_extension_version', $version);
    }
    catch (Exception $e) {
      // ignore
    }
    return parent::getCurrentRevision();
  }
  /**
   * Standard: run an install sql script
   */
  public function install() {
    $this->executeSqlFile('sql/install.sql');
  }

  /**
   * Repair the currency on financial transactions this processor wrote.
   *
   * doPayment used to pass a Pelecard numeric currency code — 1 for ILS, 2 for
   * USD, 978 for EUR — into civicrm_financial_trxn.currency, which is
   * varchar(3) holding a civicrm_currency.name.
   *
   * The numbers are not what ends up in the column. CRM_Financial_BAO_FinancialTrxn
   * (see its create path, "correct the currency value") tests the incoming code
   * against the real currency list and silently substitutes the site default
   * when it fails. So a EUR or USD cash payment was recorded against the
   * default currency instead, while its contribution kept the right one — a
   * wrong but valid value, which is why nothing ever flagged it.
   *
   * Matched on that disagreement rather than on the numbers, which never
   * survive. Restored from the contribution, which was always correct. Scoped
   * to this processor by class_name, so no other processor's rows are touched.
   *
   * @return TRUE on success
   */
  public function upgrade_3001() {
    $this->ctx->log->info('Applying update 3001: repair cash financial_trxn currency');

    $scope = "
      FROM civicrm_financial_trxn ft
      INNER JOIN civicrm_entity_financial_trxn eft
              ON eft.financial_trxn_id = ft.id
             AND eft.entity_table = 'civicrm_contribution'
      INNER JOIN civicrm_contribution co
              ON co.id = eft.entity_id
      WHERE ft.payment_processor_id IN (
              SELECT pp.id
              FROM civicrm_payment_processor pp
              INNER JOIN civicrm_payment_processor_type ppt
                      ON ppt.id = pp.payment_processor_type_id
              WHERE ppt.class_name = 'Payment_BBPriorityCash'
            )
        AND ft.currency <> co.currency
        AND co.currency REGEXP '^[A-Z]{3}$'
    ";

    $before = CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) {$scope}");
    $this->ctx->log->info("update 3001: {$before} financial transactions to repair");

    if ($before > 0) {
      CRM_Core_DAO::executeQuery("
        UPDATE civicrm_financial_trxn ft
        INNER JOIN civicrm_entity_financial_trxn eft
                ON eft.financial_trxn_id = ft.id
               AND eft.entity_table = 'civicrm_contribution'
        INNER JOIN civicrm_contribution co
                ON co.id = eft.entity_id
        SET ft.currency = co.currency
        WHERE ft.payment_processor_id IN (
                SELECT pp.id
                FROM civicrm_payment_processor pp
                INNER JOIN civicrm_payment_processor_type ppt
                        ON ppt.id = pp.payment_processor_type_id
                WHERE ppt.class_name = 'Payment_BBPriorityCash'
              )
          AND ft.currency <> co.currency
          AND co.currency REGEXP '^[A-Z]{3}$'
      ");

      $after = CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) {$scope}");
      $this->ctx->log->info("update 3001: repaired " . ($before - $after) . ", {$after} left");
    }

    return TRUE;
  }

  /**
   * Standard: run an uninstall script
   */
  public function uninstall() {
   $this->executeSqlFile('sql/uninstall.sql');
  }


  /**
   * Example: Run a simple query when a module is enabled
   *
  public function enable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  }
  */

  /**
   * Example: Run a simple query when a module is disabled
   *
  public function disable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  }
  */

  /**
   * Example: Run an external SQL script
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4202() {
    $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

    $this->addTask(ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(ts('Process second step'), 'processPart3', $arg5);
    return TRUE;
  }
  public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  public function processPart3($arg5) { sleep(10); return TRUE; }
  // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4203() {
    $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

    $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = ts('Upgrade Batch (%1 => %2)', array(
        1 => $startId,
        2 => $endId,
      ));
      $sql = '
        UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
        WHERE id BETWEEN %1 and %2
      ';
      $params = array(
        1 => array($startId, 'Integer'),
        2 => array($endId, 'Integer'),
      );
      $this->addTask($title, 'executeSql', $sql, $params);
    }
    return TRUE;
  } // */

}
