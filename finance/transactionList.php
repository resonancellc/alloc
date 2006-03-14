<?php
include("alloc.inc");

global $sortBy, $month, $year, $tfID, $status, $transactionType;
$current_user->check_employee();


// If they want to sort
if ($sortBy) {
  $sortTransactions = $sortBy;
} else if (!$sortTransactions) {
  $sortTransactions = "transactionDate";
}

// List may only be sorted by one of these.
$valid_orders_by = array("transactionDate","lastModified");
in_array($sortTransactions,$valid_orders_by) or $sortTransactions = "transactionDate";

// Store a user variable to record the users preference.
$user->register("sortTransactions");


// Check perm of requested tf
$tf = new tf;
$tf->set_id($tfID);
$tf->select();
$tf->check_perm();
$TPL["tfID"] = $tfID;

// Defaults
$month or $month = date("m");
$year  or $year = date("Y");
$base_url = $TPL["url_alloc_transactionList"]."&tfID=$tfID";


// Build month dropdown
while ($i < 12) {
  $i++;
  $m = date("m", mktime(0,0,0,$i,1,1981)); # a fine year, to be sure, to be sure
  $M = date("F", mktime(0,0,0,$i,1,1981)); # a fine year, to be sure, to be sure
  $mSel = "";
  $m == $month and $mSel = " selected";
  $TPL["monthOptions"].= "<option value=\"".$m."\"".$mSel.">".$M;
}

// Build year dropdown
$i = 0;
while ($i < 10) {
  $i++;
  $Y = date("Y", mktime(0,0,0,1,1,$year+$i-5));
  $ySel = "";
  $Y == $year and $ySel = " selected";
  $TPL["yearOptions"].= "<option value=\"".$Y."\"".$ySel.">".$Y;
}

// Get the start and end dates from the month and year dropdown selections
list($statement_start_date, $statement_end_date) = get_statement_start_and_end_dates($month,$year);



// Transaction status filter
if ($status && $status != "ALL") {
  $where["status"] = $status;
}
$TPL["statusOptions"] = get_options_from_array(array("pending"=>"Pending","approved"=>"Approved","rejected"=>"Rejected"),$status);


// Transaction status filter
if ($transactionType && $transactionType != "ALL") {
  $where["transactionType"] = $transactionType;
}
$transactionTypeOptions = array('invoice'=>'Invoice'
                               ,'expense'=>'Expense'
                               ,'salary'=>'Salary'
                               ,'commission'=>'Commission'
                               ,'timesheet'=>'Timesheet'
                               ,'adjustment'=>'Adjustment'
                               ,'insurance'=>'Insurance');
$TPL["transactionTypeOptions"] = get_options_from_array($transactionTypeOptions,$transactionType);




// WHERE lastModified or transactionDate is <= end date
$where[$sortTransactions] = array(" <= ",$statement_end_date);

// Add pending transactions filter to get pending amount balance
$where["status"] = "pending";
$TPL["pending_amount"] = number_format($tf->get_balance(array("status"=>"pending")), 2);

// Determine opening balance
$opening_balance_where[$sortTransactions] = array(" < ", $statement_start_date);
$opening_balance = $tf->get_balance($opening_balance_where);
$TPL["opening_balance"] = number_format($opening_balance, 2);

// Overall balance
$TPL["balance"] = number_format($tf->get_balance(), 2);

// Setup the info for the href linking to the alternate sort
if ($sortTransactions == "lastModified") {
  $sortBy = "transactionDate";
  $link_text = "Sort By Transaction Date";
} else {
  $sortBy = "lastModified";
  $link_text = "Sort By Date Last Modified";
}

$TPL["switch_sort_views"].= "<a href=\"".$base_url."&month=".$month."&year=".$year."&sortBy=".$sortBy."&status=".$status."\">";
$TPL["switch_sort_views"].= $link_text;
$TPL["switch_sort_views"].= "</a>";
$TPL["title"] = "Statement for TF ".$tf->get_value("tfName")." from ".$statement_start_date." to ".$statement_end_date;

list($prev_month, $prev_month_year) = add_month_to_date($month, $year, -1);
$TPL["month_prev_link"] = $base_url."&month=".$prev_month."&year=".$prev_month_year."&sortBy=".$sortTransactions;
$TPL["month_prev_link"].= "&status=".$status."&transactionType=".$transactionType;

list($next_month, $next_month_year) = add_month_to_date($month, $year, 1);
$TPL["month_next_link"] = $base_url."&month=".$next_month."&year=".$next_month_year."&sortBy=".$sortTransactions;
$TPL["month_next_link"].= "&status=".$status."&transactionType=".$transactionType;

$TPL["month_curr_link"] = $base_url."&month=".date("m")."&year=".date("Y")."&sortBy=".$sortTransactions;
$TPL["month_curr_link"].= "&status=".$status."&transactionType=".$transactionType;
$TPL["now"] = date("M y");

include_template("templates/transactionListM.tpl");


function get_statement_start_and_end_dates($month,$year) {

  if ($month == "ALL") {
    $month_start = 1;
    $month_end = 13;
  } else {
    $month_start = $month;
    $month_end = $month+1;
  }
  $start_date = date("Y-m-d",mktime(0, 0, 0, $month_start, 1, $year));
  $end_date   = date("Y-m-d",mktime(0, 0, 0, $month_end, 0, $year));
  return array($start_date, $end_date);
}

function add_month_to_date($month, $year, $month_increm)
{
	if ($month == "ALL") {
		return array($month, $year); #is this sensible -- do nothing if all is selected?
		echo "DEBUG: year=$year month=$month month_rtn= $month_rtn; year_rtn=$year_rtn";
 } else {
		$new_ts = mktime(0, 0, 0, $month + 1 + $month_increm, 0, $year);
		// echo "DEBUG: year=$year month=$month month_rtn= $month_rtn; year_rtn=$year_rtn";
		return array(date("m", $new_ts), date("Y", $new_ts));
	}
}
	

function show_transaction($template_name) {

  global $db, $tfID, $TPL, $month, $year, $sortTransactions, $status, $opening_balance, $transactionType;

  $running_balance = $opening_balance;
  $db = new db_alloc;

  // Get the start and end dates from the month and year dropdown selections
  list($statement_start_date, $statement_end_date) = get_statement_start_and_end_dates($month,$year);
  
  $status && $status != "ALL" and $status_sql = "AND status = '".db_esc($status)."'";
  $transactionType && $transactionType != "ALL" and $transactionType_sql = "AND transactionType = '".db_esc($transactionType)."'";

  // Query
  $query = sprintf("SELECT * FROM transaction 
                    WHERE tfID = %d
                    AND %s >= '%s'
                    AND %s <= '%s'
                    %s
                    %s
                    ORDER BY %s
                  ",$tfID,$sortTransactions,$statement_start_date,$sortTransactions,$statement_end_date,$status_sql,$transactionType_sql,$sortTransactions);
  $db->query($query);

  while ($db->next_record()) {
    $entityID = "";

    $transaction = new transaction;
    $transaction->read_db_record($db);
    $transaction->set_tpl_values();

    $i++;
    $TPL["row_class"] = "odd";
    $i % 2 == 0 and $TPL["row_class"] = "even";
    #if ($transaction->get_value("status") == "approved") {
      $running_balance += $transaction->get_value("amount");
    #}
   
    $TPL["amount_positive"] = "";
    $TPL["amount_negative"] = "";
 
    if ($transaction->get_value("amount") > 0) {
      $TPL["amount_positive"] = number_format($transaction->get_value("amount"), 2);
      $TPL["total_amount_positive"] += $transaction->get_value("amount");
    } else {
      $TPL["amount_negative"] = number_format($transaction->get_value("amount"), 2);
      $TPL["total_amount_negative"] += $transaction->get_value("amount");
    }


    $TPL["total_amount_positive"] = number_format($TPL["total_amount_positive"], 2);
    $TPL["total_amount_negative"] = number_format($TPL["total_amount_negative"], 2);
    $TPL["running_balance"] = number_format($running_balance, 2);
    $TPL["closing_balance"] = $TPL["running_balance"];

    $TPL["lastModified"] = get_mysql_date_stamp($TPL["lastModified"]);

    $type = $transaction->get_value("transactionType");


    // Transaction stems from an invoice
    if ($type == "invoice") {
      $invoiceItem = $transaction->get_foreign_object("invoiceItem");
      $invoice = $invoiceItem->get_foreign_object("invoice");
      $invoice->get_value("invoiceNum") and $entityID = $invoice->get_value("invoiceNum");
      if ($transaction->get_value("invoiceItemID") && $invoiceItem && $invoiceItem->have_perm(PERM_READ_WRITE)) {
        $TPL["transactionType"] = "<a href=\"".$TPL["url_alloc_invoiceItem"]."&invoiceItemID=".$transaction->get_value("invoiceItemID");
        $TPL["transactionType"].= "\">".$type." ".$entityID."</a>";
      } 

    // Transaction is from an expenseform
    } else if ($type == "expense") {
      if (($transaction->get_value("expenseFormID") != "" && $transaction->get_value("expenseFormID") != "0")
      &&  ($expenseForm = $transaction->get_foreign_object("expenseForm")) && ($expenseForm->have_perm(PERM_READ_WRITE))) {
        $TPL["transactionType"] = "<a href=\"".$TPL["url_alloc_expOneOff"]."&expenseFormID=".$transaction->get_value("expenseFormID");
        $TPL["transactionType"].= "\">".$type." ".$transaction->get_value("expenseFormID")."</a>";
      }

    // Had to rewrite this so that people who had transactions on other peoples timesheets 
    // could see their own transactions, but not the other persons timesheet.
    } else if (($type == "timesheet" || $type == "insurance" || $type == "commission") && $transaction->get_value("timeSheetID")) {
      $timeSheet = new timeSheet;
      $timeSheet->set_id($transaction->get_value("timeSheetID"));
      if ($timeSheet->have_perm(PERM_READ_WRITE)) {
        $TPL["transactionType"] = "<a href=\"".$TPL["url_alloc_timeSheet"]."&timeSheetID=".$transaction->get_value("timeSheetID");
        $TPL["transactionType"].= "\">".$type." ".$transaction->get_value("timeSheetID")."</a>";
      }
    }

    include_template("templates/transactionListR.tpl");
  }


}













page_close();



?>
