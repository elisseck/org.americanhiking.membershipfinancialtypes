<?php

require_once 'membershipfinancialtypes.civix.php';
use CRM_Membershipfinancialtypes_ExtensionUtil as E;

function membershipfinancialtypes_civicrm_pre($op, $objectName, $id, &$params) {
  if($objectName == 'Membership' && ($op == 'create' || $op == 'edit')) {
    $result = civicrm_api3('Membership', 'getsingle', [
      'id' => $id,
    ]);
    $sixMonthsAgo = _membershipfinancialtypes_six_months($result['end_date']);
    if ($sixMonthsAgo) {
      $value = "Yes";
    }
    else {
      $value = "No";
    }
    $result = civicrm_api3('Contact', 'create', [
      'id' => $result['id'],
      'custom_15' => $value,
    ]);
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
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message($error);
      }
      if (isset($payments)) {
        $yeartypes = array(8, 9, 11, 12, 13, 14, 17, 18, 19, 20, 1);
        $monthtypes = array(6, 7, 15, 16, 5);
if (in_array($membership['membership_type_id'], $yeartypes)) {
          //check if it's greater than six months since the last membership end date for this contact, if so we should change financial types
          //$sixMonths = _membershipfinancialtypes_six_months($membership['contact_id']);
          if ($contact['custom_15'] == 'Yes') {
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
          //$sixMonths = _membershipfinancialtypes_six_months($membership['contact_id']);
          if ($contact['custom_15'] == 'Yes') {
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
}

function _updateContributionType($id) {
  try {
    $result = civicrm_api3('Contribution', 'create', [
      'financial_type_id' => 5,
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

/**
 *  check if all memberships for this contact have and end date greater than 6 months ago
 */
function _membershipfinancialtypes_six_months($end_date) {
  /*try {
    $result = civicrm_api3('Membership', 'get', [
      'sequential' => 1,
      'contact_id' => $contact_id,
    ]);
  }
  catch (CiviCRM_API3_Exception $e) {
    $error = $e->getMessage();
    CRM_Core_Error::debug_log_message($error);
  }*/
  //$count = 0;
  //foreach ($result['values'] as $mem) {
    $now = new DateTime();
      $input = DateTime::createFromFormat('Y-m-d', $end_date);
    if ($input) {
      $diff = $input->diff($now);
      if ($diff->m > 6) {
        return TRUE;
      }
    }
    return FALSE;
  //}
  //If all checked memberships are greater than six months in the past, return true
  /*if ($count == $result['count']) {
    return TRUE;
  }
  else {
    return FALSE;
  }*/
}

/**
 * for 12 month memberships, check if the last payment was a "new" financial type, if so, check if we're in the middle of that 12 month cycle and if we're in the middle, return true
 */
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
    if ($recent['financial_type_id'] == 12) {
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

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 *
function membershipfinancialtypes_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 *
function membershipfinancialtypes_civicrm_navigationMenu(&$menu) {
  _membershipfinancialtypes_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _membershipfinancialtypes_civix_navigationMenu($menu);
} // */
