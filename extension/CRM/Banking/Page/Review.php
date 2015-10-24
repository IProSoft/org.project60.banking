<?php
/*-------------------------------------------------------+
| Project 60 - CiviBanking                               |
| Copyright (C) 2013-2014 SYSTOPIA                       |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL v3 license. You can redistribute it and/or  |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

require_once 'CRM/Core/Page.php';
require_once 'CRM/Banking/Helpers/OptionValue.php';
require_once 'CRM/Banking/Helpers/URLBuilder.php';

class CRM_Banking_Page_Review extends CRM_Core_Page {

  function run() {
      // set this variable to request a redirect
      $url_redirect = NULL;

      // Get the current ID
      if (isset($_REQUEST['list'])) {
        $list = explode(",", $_REQUEST['list']);
      } else if (isset($_REQUEST['s_list'])) {
        $list = CRM_Banking_Page_Payments::getPaymentsForStatements($_REQUEST['s_list']);
        $list = explode(",", $list);
      } else {
        $list = array();
        array_push($list, $_REQUEST['id']);
      }

      if (isset($_REQUEST['id'])) {
        $pid = $_REQUEST['id'];
      } else {
        $pid = $list[0];
      }

      // find position in the list
      $index = array_search($pid, $list);
      if ($index>=0) {
        if (isset($list[($index + 1)])) {
          $next_pid = $list[($index + 1)];
        }
        if (isset($list[($index - 1)])) {
          $prev_pid = $list[($index - 1)];
        }
      }

      $btx_bao = new CRM_Banking_BAO_BankTransaction();
      $btx_bao->get('id', $pid);        

      // read the list of BTX statuses
      $choices = banking_helper_optiongroup_id_name_mapping('civicrm_banking.bank_tx_status');

      // If the exercution was triggered, run that first
      if (isset($_REQUEST['execute'])) {
        $execute_bao = ($_REQUEST['execute']==$pid) ? $btx_bao : NULL;
        $this->execute_suggestion($_REQUEST['execute_suggestion'], $_REQUEST, $execute_bao, $choices);

        // after execution -> exit if this was the last in the list
        if (!isset($next_pid) && ($_REQUEST['execute']==$pid)) {
          $url_redirect = banking_helper_buildURL('civicrm/banking/payments',  $this->_pageParameters());
        }
      }

      // parse structured data
      $this->assign('btxstatus', $choices[$btx_bao->status_id]);
      $this->assign('payment', $btx_bao);
      $this->assign('payment_data_raw', json_decode($btx_bao->data_raw, true));

      $data_parsed = json_decode($btx_bao->data_parsed, true);
      $this->assign('payment_data_parsed', $data_parsed);
      if (!empty($data_parsed['iban'])) $data_parsed['iban'] = CRM_Banking_BAO_BankAccountReference::format('iban',$data_parsed['iban']);
    
      if (empty($contact) && !empty($data_parsed['contact_id'])) {
        // convention: the contact was identified with acceptable precision
        $contact = civicrm_api('Contact','getsingle',array('version'=>3,'id'=>$data_parsed['contact_id']));
      }
      if (!empty($contact)) { 
        $this->assign('contact', $contact); 
      } else {
        $this->assign('contact', NULL);
      }

      $extra_data = array();
      $_data_raw = json_decode($btx_bao->data_raw, true);
      if (is_array($_data_raw)) {
        $extra_data = $_data_raw;
      } else {
        $extra_data['raw'] = $btx_bao->data_raw;
      }
      if (is_array($btx_bao->getDataParsed())) $extra_data = array_merge($extra_data, $btx_bao->getDataParsed());
      $this->assign('extra_data', $extra_data);


      // Look up bank accoutns
      $my_bao = new CRM_Banking_BAO_BankAccount();
      $my_bao->get('id', $btx_bao->ba_id);
      $this->assign('my_bao', $my_bao);

      if ($btx_bao->party_ba_id) {
        // there is a party bank account connected to this
        $ba_bao = new CRM_Banking_BAO_BankAccount();
        $ba_bao->get('id', $btx_bao->party_ba_id);        

        $this->assign('party_ba', $ba_bao);
        $this->assign('party_ba_data_parsed', json_decode($ba_bao->data_parsed, true));
        $this->assign('party_ba_references', $ba_bao->getReferences());

        // deprecated: contact can also be indetified via other means, see below
        if ($ba_bao->contact_id) {
          $contact = civicrm_api('Contact','getsingle',array('version'=>3,'id'=>$ba_bao->contact_id));
        }
      } else {
        // there is no party bank account connected this (yet)
        foreach ($data_parsed as $key => $value) {
          if (preg_match('/^_party_[IN]BAN(_..)?$/', $key)) {
            $reftype = substr($key, 7);
            $this->assign('party_account_ref',     $value);
            $this->assign('party_account_reftype', $reftype);
            $reftype_name = CRM_Core_OptionGroup::getValue('civicrm_banking.reference_types', $reftype, 'name', 'String', 'label');
            $this->assign('party_account_reftypename', $reftype_name);
            if ($reftype=='IBAN') {
              $this->assign('party_account_reftype2', $reftype);  
            } else {
              $this->assign('party_account_reftype2', substr($reftype, 5));
            }
          }
        }
      }


      // check if closed ('processed' or 'ignored')
      if ($choices[$btx_bao->status_id]['name']=='processed' || $choices[$btx_bao->status_id]['name']=='ignored') {
        // this is a closed BTX, generate execution information
        $execution_info = array();
        $execution_info['status'] = $choices[$btx_bao->status_id]['name'];
        $suggestion_objects = $btx_bao->getSuggestionList();
        foreach ($suggestion_objects as $suggestion) {
          if ($suggestion->isExecuted()) {
            $execution_info['date'] = $suggestion->isExecuted();
            $execution_info['visualization'] = $suggestion->visualize_execution($btx_bao);
            $execution_info['executed_by'] = $suggestion->getParameter('executed_by');
            $execution_info['executed_automatically'] = $suggestion->getParameter('executed_automatically');
            break;
          }
        }
        $this->assign('execution_info', $execution_info);

        // generate message
        if (!empty($execution_info['date'])) {
          $execution_date = CRM_Utils_Date::customFormat($execution_info['date'], CRM_Core_Config::singleton()->dateformatFull);          
        } else {
          $execution_date = ts("<i>unknown date</i>");
        }

        if (!empty($execution_info['executed_by'])) {
          // visualize more info, see https://github.com/Project60/CiviBanking/issues/71
          // try to load contact
          $user_id = $execution_info['executed_by'];
          $user = civicrm_api('Contact', 'getsingle', array('id' => $user_id, 'version' => 3));
          if (empty($user['is_error'])) {
            $user_link = CRM_Utils_System::url("civicrm/contact/view", "&reset=1&cid=$user_id");            
            $user_string = "<a href='$user_link'>" . $user['display_name'] . "</a>";
          } else {
            $user_string = ts('Unknown User') . ' ['.$user_id.']';
          }

          if (empty($execution_info['executed_automatically'])) {
            $automated = '';
          } else {
            $automated = ts('automatically');
          }

          if ($choices[$btx_bao->status_id]['name']=='processed') {
            $message = sprintf(ts("This transaction was <b>%s processed</b> on %s by %s."), $automated, $execution_date, $user_string);
          } else {
            $message = sprintf(ts("This transaction was <b>%s ignored</b> on %s by %s."), $automated, $execution_date, $user_string);
          }          
        } else {
          // visualize the previous, reduced information
          if ($choices[$btx_bao->status_id]['name']=='processed') {
            $message = sprintf(ts("This transaction was <b>processed</b> on %s."), $execution_date);
          } else {
            $message = sprintf(ts("This transaction was marked to be <b>ignored</b> on %s."), $execution_date);
          }          
        }
        $this->assign('status_message', $message);

      } else {
        // this is an open (new or analysed) BTX:  create suggestion list
        $suggestions = array();
        $suggestion_objects = $btx_bao->getSuggestionList();
        foreach ($suggestion_objects as $suggestion) {
          $color = $this->translateProbability($suggestion->getProbability() * 100);
            array_push($suggestions, array(
                'hash' => $suggestion->getHash(),
                'probability' => sprintf('%d&nbsp;%%', ($suggestion->getProbability() * 100)),
                'color' => $color,
                'visualization' => $suggestion->visualize($btx_bao),
                'title' => $suggestion->getTitle(),
                'actions' => $suggestion->getActions(),
            ));
        }
        $this->assign('suggestions', $suggestions);
      }

      // URLs & stats
      $unprocessed_count = 0;
      $this->assign('url_back', banking_helper_buildURL('civicrm/banking/payments',  $this->_pageParameters()));

      if (isset($next_pid)) {
        $this->assign('url_skip_forward', banking_helper_buildURL('civicrm/banking/review',  $this->_pageParameters(array('id'=>$next_pid))));
        $this->assign('url_execute', banking_helper_buildURL('civicrm/banking/review',  $this->_pageParameters(array('id'=>$next_pid, 'execute'=>$pid))));

        $unprocessed_info = $this->getUnprocessedInfo($list, $next_pid, $choices);
        if ($unprocessed_info) {
          $this->assign('url_skip_processed', banking_helper_buildURL('civicrm/banking/review',  $this->_pageParameters(array('id'=>$unprocessed_info['next_unprocessed_pid']))));
          $unprocessed_count = $unprocessed_info['unprocessed_count'];
        }
      } else {
        $this->assign('url_execute', banking_helper_buildURL('civicrm/banking/review',  $this->_pageParameters(array('execute'=>$pid))));
      }

      if (isset($prev_pid)) {
        $this->assign('url_skip_back', banking_helper_buildURL('civicrm/banking/review',  $this->_pageParameters(array('id'=>$prev_pid))));
      }
      $this->assign('url_show_payments', banking_helper_buildURL('civicrm/banking/payments', array('show'=>'payments')));
      
      global $base_url;
      $this->assign('base_url',$base_url);

      // Set the page-title dynamically
      if (count($list) > 1) {
        CRM_Utils_System::setTitle(ts("Review Bank Transaction %1 of %2 (%3 unprocessed ahead)", 
          array(1=>$index+1, 2=>count($list), 3=>$unprocessed_count)));
      } else {
        CRM_Utils_System::setTitle(ts("Review Bank Transaction"));
      }

      // perform redirect, if requested
      if ($url_redirect) {
        CRM_Utils_System::redirect($url_redirect);
      }
      parent::run();
  }





  /**
   * provides the color coding for the various probabilities
   */
  private function translateProbability( $pct ) {
    if ($pct >= 90) return '#393';
    if ($pct >= 80) return '#cc0';
    if ($pct >= 60) return '#fc3';
    if ($pct >= 30) return '#f90';
    return '#900';
  }

  /**
   * creates an array of all properties defining the current page's state
   * 
   * if $override is given, it will be taken into the array regardless
   */
  function _pageParameters($override=array()) {
    $params = array();
    if (isset($_REQUEST['id']))
        $params['id'] = $_REQUEST['id'];
    if (isset($_REQUEST['list']))
        $params['list'] = $_REQUEST['list'];
    if (isset($_REQUEST['s_list']))
        $params['s_list'] = $_REQUEST['s_list'];

    foreach ($override as $key => $value) {
        $params[$key] = $value;
    }
    return $params;
  }

  /**
   * will find the next unprocessed item in the list of remaining pids
   *
   * @return array( 'next_unprocessed_pid' => <id of next unprocessed tx in list>,
   *                'unprocessed_count'    => <number of unprocessed tx in list>)
   */
  function getUnprocessedInfo($pid_list, $next_pid, $choices) {
    // first, only query the remaining items
    $index = array_search($next_pid, $pid_list);
    $remaining_list = implode(',', array_slice($pid_list, $index));
    
    $unprocessed_states   = $choices['ignored']['id'].','.$choices['processed']['id'];
    $unprocessed_sql      = "SELECT id FROM civicrm_bank_tx WHERE `status_id` NOT IN ($unprocessed_states) AND `id` IN ($remaining_list)";
    $unprocessed_query    = CRM_Core_DAO::executeQuery($unprocessed_sql);
    $next_unprocessed_pid = count($pid_list) + 1;
    $unprocessed_count    = 0;
    while ($unprocessed_query->fetch()) {
      $unprocessed_count++;
      $unprocessed_id = $unprocessed_query->id;
      $new_index = array_search($unprocessed_query->id, $pid_list);
      if ($new_index < $next_unprocessed_pid) 
        $next_unprocessed_pid = $new_index;
    }

    if ($next_unprocessed_pid < count($pid_list)) {
      // this is the index of the next, unprocessed ID in list
      return array(
        'next_unprocessed_pid' => $pid_list[$next_unprocessed_pid],
        'unprocessed_count'    => $unprocessed_count
        );
    } else {
      // no unprocessed pids found
      return null;
    }
  }

  /**
   * Will trigger the execution of the given suggestion (identified by its hash)
   */
  function execute_suggestion($suggestion_hash, $parameters, $btx_bao, $choices) {
    // load BTX object if not provided
    if (!$btx_bao) {
      $btx_bao = new CRM_Banking_BAO_BankTransaction();
      $btx_bao->get('id', $parameters['execute']);
    }
    $suggestion = $btx_bao->getSuggestionByHash($suggestion_hash);
    if ($suggestion) {
      // update the parameters
      $suggestion->update_parameters($parameters);
      $btx_bao->saveSuggestions();
      $suggestion->execute($btx_bao);

      // create a notification bubble for the user
      $text = $suggestion->visualize_execution($btx_bao);
      if ($btx_bao->status_id==$choices['processed']['id']) {
        CRM_Core_Session::setStatus(ts("The transaction was booked.")."<br/>".$text, ts("Transaction closed"), 'info');
      } elseif ($btx_bao->status_id==$choices['ignored']['id']) {
        CRM_Core_Session::setStatus(ts("The transaction was ignored.")."<br/>".$text, ts("Transaction closed"), 'info');
      } else {
        CRM_Core_Session::setStatus(ts("The transaction could not be closed."), ts("Error"), 'alert');
      }
    } else {
      CRM_Core_Session::setStatus(ts("Selected suggestions disappeared. Suggestion NOT executed!"), ts("Internal Error"), 'error');
    }
  }
}
