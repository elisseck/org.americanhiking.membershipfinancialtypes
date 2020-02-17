<?php

require_once 'membershipfinancialtypes.civix.php';
use CRM_Membershipfinancialtypes_ExtensionUtil as E;

//This shouldn't be in this extension but we don't have time to build a new one for them
function membershipfinancialtypes_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Event_Form_Registration_Register' || $formName == 'CRM_Event_Form_Registration_AdditionalParticipant') {
    CRM_Core_Resources::singleton()->addScriptFile('org.americanhiking.membershipfinancialtypes', 'js/prices12.js');
  }
}

function membershipfinancialtypes_civicrm_pre($op, $objectName, $id, &$params) {
  if($objectName == 'Membership' && ($op == 'create' || $op == 'edit')) {
    //check before we save any membership, if there's no id, it's a new membership and we'll get new financial type through payment #
    if ($id) {
      $result = civicrm_api3('Membership', 'getsingle', [
        'id' => $id,
      ]);
      //is this a renewal from more than six months ago?
      $sixMonthsAgo = _membershipfinancialtypes_six_months($result['end_date']);
      //make sure we don't generate a bad sql query
      if ($sixMonthsAgo) {
        $value = 1;
      }
      else {
        $value = 0;
      }
      //generate SQL insert a 'six month' value if we should give the new financial type. We'll check this later.
      //remove all if not... because we're just going to check whether it exists
      if ($value == 1) {
        $sql = "INSERT INTO civicrm_membership_tracking (membership_id, six_month_status) VALUES ({$id}, {$value});";
      }
      else if ($value == 0) {
        $sql = "DELETE FROM civicrm_membership_tracking WHERE `membership_id` = {$id};";
      }
      $q = CRM_Core_DAO::executeQuery($sql);
    }
  }
}

function membershipfinancialtypes_civicrm_post($op, $objectName, $id, &$objectRef) {
  //whenever we create a membership payment, we should check if we need to modify the financial type
  if ($objectName == 'MembershipPayment' && $op == 'create') {
    //go get the membership payment
    try {
      $contribution = civicrm_api3('Contribution', 'getsingle', [
        'id' => $objectRef->contribution_id,
      ]);
      //go get the membership too, we will need info from that
      $membership = civicrm_api3('Membership', 'getsingle', [
        'id' => $objectRef->membership_id,
      ]);
      $contact = civicrm_api3('Contact', 'getsingle', [
        'id' => $membership['contact_id'],
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message($error);
    }
    //If we found the membership payment and the membership itself...
    if (isset($contribution) && isset($membership)) {
      //Get all payments for this membership, not just the current one
      try {
        $payments = civicrm_api3('MembershipPayment', 'get', [
          'sequential' => 1,
          'membership_id' => $membership['id'],
        ]);
        $sql = "SELECT * FROM `civicrm_membership_tracking` WHERE `membership_id` = {$membership['id']};";
        $tracking = CRM_Core_DAO::executeQuery($sql);
        $result = array();
        while ($tracking->fetch()) {
           $result[] = $tracking->toArray();
        }
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message($error);
      }
      if (isset($payments)) {
        $yeartypes = array(5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 18, 19, 20, 21, 22, 23);
        $monthtypes = array(1, 2, 3, 4, 17);
      if (in_array($membership['membership_type_id'], $yeartypes)) {
          //check if it's greater than six months since the last membership end date for this contact, if so we should change financial types
          if (count($result) > 0) {
            $sixMonths = TRUE;
          }
          //If we don't find any payments... this is the first for this membership and it gets the 'new' financial type
          if ($payments['count'] < 2 || $sixMonths) {
            //Set the financial type for this membership payment entity's contribution
            try {
              if ($contribution['id']) {
                //we don't automatically have a db transaction frame for this callback sometimes, so this avoids errors
                $tx = new CRM_Core_Transaction();
                CRM_Core_Transaction::addCallback(CRM_Core_Transaction::PHASE_POST_COMMIT,
                  '_updateContributionType', array($contribution['id']));
              }
            }
            catch (CiviCRM_API3_Exception $e) {
              $error = $e->getMessage();
              CRM_Core_Error::debug_log_message($error);
            }
          }
        }
        elseif (in_array($membership['membership_type_id'], $monthtypes)) {
          //check if it's greater than six months since the last membership end date for this contact
          $sql = "SELECT * FROM civicrm_membership_tracking WHERE membership_id = {$membership['id']};";
          $tracking = CRM_Core_DAO::executeQuery($sql);
          $result = array();
          while ($tracking->fetch()) {
             $result[] = $tracking->toArray();
          }
          if (count($result) > 0) {
            $sixMonths = TRUE;
          }
          //For the 12 month memberships... we also need to check if we're in the middle of a year of the new financial type payments
          $inNewCycle = _membershipfinancialtypes_check_monthly($payments);
          //If this is the first payment, if last membership was more than 6 months ago, or if the last payment was dev mem new AND we're in the middle of a 12 month cycle... change the financial type
          if ($payments['count'] < 2 || $sixMonths || $inNewCycle) {
            //Set the financial type for this membership payment entity's contribution
            try {
              if($contribution['id']) {
                //we don't automatically have a db transaction frame for this callback sometimes, so this avoids errors
                $tx = new CRM_Core_Transaction();
                CRM_Core_Transaction::addCallback(CRM_Core_Transaction::PHASE_POST_COMMIT,
                  '_updateContributionType', array($contribution['id']));
              }
            }
            catch (CiviCRM_API3_Exception $e) {
              $error = $e->getMessage();
              CRM_Core_Error::debug_log_message($error);
            }
          }
        }
      }
    }
  }
  //whenever we create a membership, check if the source has "Gift Membership" and send an email if so
  if ($objectName == 'Membership' && $op == 'create') {
    if (strpos($objectRef->source, 'Gift Membership') !== FALSE) {
      $result = civicrm_api3('MessageTemplate', 'getsingle', [
        'sequential' => 1,
        'id' => 69,
      ]);
      $contact = civicrm_api3('Contact', 'getsingle', [
        'id' => $objectRef->contact_id,
      ]);
      $type = civicrm_api3('MembershipType', 'getsingle', [
        'id' => $objectRef->membership_type_id,
      ]);
      $start_date = date('m/d/Y', strtotime($objectRef->start_date));
      $end_date = date('m/d/Y', strtotime($objectRef->end_date));
      $params = [];
      $params['text'] = $result['msg_text'];
      $params['html'] = $result['msg_html'];
      $params['subject'] = $result['msg_subject'];
      $params['from'] = '"American Hiking Society" <info@americanhiking.org>';
      $params['toEmail'] = $contact['email'];
      $params['html'] = str_replace("{contact.first_name}", $contact['first_name'], $params['html']);
      $params['html'] = str_replace("{membership.type}", $type['name'], $params['html']);
      $params['html'] = str_replace("{membership.start_date}", $start_date, $params['html']);
      $params['html'] = str_replace("{membership.end_date}", $end_date, $params['html']);
      CRM_Utils_Mail::send($params);
    }
  }
}

function _updateContributionType($id) {
  try {
    $result = civicrm_api3('Contribution', 'create', [
      'financial_type_id' => 9,
      'id' => $id,
    ]);
  }
  catch (CiviCRM_API3_Exception $e) {
    $error = $e->getMessage();
    CRM_Core_Error::debug_log_message($error);
  }
}

function _updateMembershipCustomField($id, $value = "No") {
  try {
      $result = civicrm_api3('Membership', 'create', [
        'custom_14' => $value,
        'id' => $id,
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message($error);
    }
}

function _membershipfinancialtypes_six_months($end_date) {
  $now = new DateTime();
  $input = DateTime::createFromFormat('Y-m-d', $end_date);
  if ($input) {
    $diff = $input->diff($now);
    $diff = $diff->format("%r%a");
    if ($diff > 180) {
      return TRUE;
    }
  }
  return FALSE;
}


function _membershipfinancialtypes_check_monthly($payments) {
  //Can't assume they're in chronological order so we have to check
  $ids = array();
  if ($payments['count'] > 0) {
    foreach ($payments['values'] as $payment) {
      $ids[] = $payment['contribution_id'];
    }
    try {
      $result = civicrm_api3('Contribution', 'get', [
        'sequential' => 1,
        'return' => ["financial_type_id", "receive_date"],
        'id' => ['IN' => $ids],
        'options' => ['sort' => "receive_date"],
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message($error);
    }
  }
  if (isset($result)) {
    //most recent payment
    $recent = array_pop($result['values']);
    //If we're in the new financial type
    if ($recent['financial_type_id'] == 9) {
      //This is post creation of the contribution so if we've got 12 of these we're done
      if (($result['count'] - 1) % 12 != 0) {
        return TRUE;
      }
    }
    else {
      return FALSE;
    }
  }
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function membershipfinancialtypes_civicrm_config(&$config) {
  _membershipfinancialtypes_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function membershipfinancialtypes_civicrm_xmlMenu(&$files) {
  _membershipfinancialtypes_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function membershipfinancialtypes_civicrm_install() {
  _membershipfinancialtypes_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function membershipfinancialtypes_civicrm_postInstall() {
  _membershipfinancialtypes_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function membershipfinancialtypes_civicrm_uninstall() {
  _membershipfinancialtypes_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function membershipfinancialtypes_civicrm_enable() {
  _membershipfinancialtypes_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function membershipfinancialtypes_civicrm_disable() {
  _membershipfinancialtypes_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function membershipfinancialtypes_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _membershipfinancialtypes_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function membershipfinancialtypes_civicrm_managed(&$entities) {
  _membershipfinancialtypes_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function membershipfinancialtypes_civicrm_caseTypes(&$caseTypes) {
  _membershipfinancialtypes_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function membershipfinancialtypes_civicrm_angularModules(&$angularModules) {
  _membershipfinancialtypes_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function membershipfinancialtypes_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _membershipfinancialtypes_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function membershipfinancialtypes_civicrm_entityTypes(&$entityTypes) {
  _membershipfinancialtypes_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_thems().
 */
function membershipfinancialtypes_civicrm_themes(&$themes) {
  _membershipfinancialtypes_civix_civicrm_themes($themes);
}
