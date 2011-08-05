<?php

/*
 * Copyright (C) 2006-2011 Alex Lance, Clancy Malcolm, Cyber IT Solutions
 * Pty. Ltd.
 * 
 * This file is part of the allocPSA application <info@cyber.com.au>.
 * 
 * allocPSA is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or (at
 * your option) any later version.
 * 
 * allocPSA is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU Affero General Public
 * License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with allocPSA. If not, see <http://www.gnu.org/licenses/>.
*/


class alloc_services {

  public function __construct($sessID="") {
    global $current_user; 
    $current_user = $this->get_current_user($sessID);
  }

  public function authenticate($username,$password) {
    $person = new person;
    $sess = new Session;
    $row = $person->get_valid_login_row($username,$password); 
    if ($row) {
      $sess->Start($row,false);
      $sess->UseGet();
      $sess->Save();
      return $sess->GetKey();
    } else {
      die("Authentication Failed(1)."); 
    }
  }  

  private function get_current_user($sessID) {
    $sess = new Session($sessID);
    if (!$sess->Started()) {
      die("Authentication Failed(2).");
    } else {
      $person = new person;
      $person->load_current_user($sess->Get("personID"));
      return $person;
    }
  }

  public function get_task_comments($taskID) {
    //global $current_user; // Always need this :(
    //$current_user = $this->get_current_user($sessID);
    if ($taskID) {
      $task = new task;
      $task->set_id($taskID);
      $task->select();
      return $task->get_task_comments_array();
    }
  }

  public function get_people($people="") {
    $person_table = get_cached_table("person");

    $people = explode(",",$people);
    foreach ($people as $person) {
      // personID
      if (is_numeric($person)) {
        $rtn[$person] = $person_table[$person];

      // username
      } else if (strpos($person," ") === false) {
        foreach ($person_table as $pid => $data) {
          strtolower($person) == strtolower($data["username"]) and $rtn[$pid] = $data;
        }
  
      // full name
      } else {
        foreach ($person_table as $pid => $data) {
          strtolower($person) == strtolower($data["name"]) and $rtn[$pid] = $data;
        }
      }
    }
    foreach ($rtn as $pid => $p) {
      $rtn[$pid] = $this->reduce_person_info($p);
    }
    return (array)$rtn;
  }

  public function reduce_person_info($person) {
    $rtn["personID"] = $person["personID"];
    $rtn["username"] = $person["username"];
    $rtn["name"]     = $person["name"];
    $rtn["emailAddress"] = $person["emailAddress"];
    return $rtn;
  }

  public function add_timeSheetItem($options) {
    //global $current_user; // Always need this :(
    //$current_user = $this->get_current_user($sessID);
    $rtn = timeSheet::add_timeSheetItem($options);
    if ($rtn["status"] == "yay") {
      return $rtn["message"];
    } else {
      die(print_r($rtn,1));
    }
  }

  public function change_timeSheet_status($timeSheetID,$direction) {
    $timeSheet = new timeSheet();
    $timeSheet->set_id($timeSheetID);
    $timeSheet->select();
    return $timeSheet->change_status($direction);
  }

  public function get_tfID($name) {
    return tf::get_tfID($name);
  } 

  public function get_list($entity, $options=array()) {
    global $current_user; // Always need this :(
    //$current_user = $this->get_current_user($sessID);
    if (class_exists($entity)) {
      $options = obj2array($options);
      $e = new $entity;
      if (method_exists($e, "get_list")) {
        ob_start();
        $rtn = $e->get_list($options);
        $echoed = ob_get_contents();
        if (!$rtn && $echoed) {
          return array("error"=>$echoed);
        } else {
          if (isset($rtn["rows"])) {
            return $rtn["rows"];
          } else {
            return $rtn;
          }
        }
      } else {
        die("Entity method '".$entity."->get_list()' does not exist."); 
      }
    } else {
      die("Entity '".$entity."' does not exist."); 
    }
  }

  public function search_emails($str) {
    if ($str) {
      $uids = $this->get_comment_email_uids_search($str);
      foreach ((array)$uids as $uid) {
        $emails.= $this->get_email($uid);
      }
    }
    return $emails;
  }

  public function get_timeSheetItem_comments($taskID) {
    $people = get_cached_table("person");
    $rows = timeSheetItem::get_timeSheetItemComments($taskID);
    foreach ($rows as $row) {
      $timestamp = format_date("U",$row["date"]);
      $name = $people[$row["personID"]]["name"];
      $str.= $br."From allocPSA ".date('D M  j G:i:s Y',$timestamp);
      $str.= "\n".$name." ".$row["duration"]." ".$row["comment"];
      $br = "\n\n";
    } 
    return $str;
  }

  public function init_email_info() {
    global $current_user; // Always need this :(
    $info["host"] = config::get_config_item("allocEmailHost");
    $info["port"] = config::get_config_item("allocEmailPort");
    $info["username"] = config::get_config_item("allocEmailUsername");
    $info["password"] = config::get_config_item("allocEmailPassword");
    $info["protocol"] = config::get_config_item("allocEmailProtocol");
    if (!$info["host"]) {
      die("Email mailbox host not defined, assuming email fetch function is inactive.");
    }
    return $info;
  }

  public function get_email($emailUID) {
    global $current_user; // Always need this :(
    //$lockfile = ATTACHMENTS_DIR."mail.lock.person_".$current_user->get_id();
    if ($emailUID) {
      $info = $this->init_email_info();
      $mail = new alloc_email_receive($info);
      $mail->open_mailbox(config::get_config_item("allocEmailFolder"),OP_HALFOPEN+OP_READONLY);
      list($header,$body) = $mail->get_raw_email_by_msg_uid($emailUID);
      $mail->close();
      $m = new alloc_email();
      $m->set_headers($header);
      $timestamp = $m->get_header('Date');
      $str = "From allocPSA ".date('D M  j G:i:s Y',strtotime($timestamp))."\r\n".$header.$body;
      return utf8_encode(str_replace("\r\n","\n",$str));
    }
  }

  public function get_comment_email_uids_search($str) {
    if ($str) { 
      global $current_user; // Always need this :(
      //$lockfile = ATTACHMENTS_DIR."mail.lock.person_".$current_user->get_id();
      $info = $this->init_email_info();
      $mail = new alloc_email_receive($info);
      $mail->open_mailbox(config::get_config_item("allocEmailFolder"),OP_HALFOPEN+OP_READONLY);
      $rtn = $mail->get_emails_UIDs_search($str);
      $mail->close();
    }
    return (array)$rtn;
  }

  public function get_help($topic="") {
    $this_methods = get_class_methods($this);

    if (!$topic) {
      foreach ($this_methods as $method) {
        $m = $method."_help";
        if (method_exists($this,$m)) {
          $available_topics.= $commar.$method;
          $commar = ", ";
        }
      }
      die("Help is available for the following methods: ".$available_topics);

    } else {
      $m = $topic."_help";
      if (method_exists($this,$m)) {
        return $this->$m();
      } else {
        die("No help exists for this method: ".$topic); 
      }
    }
  }

  public function save_interestedParty($options) {
    // Python will submit None instead of ''
    foreach ($options as $k=>$v) { strtolower($v) != 'none' and $data[$k] = $v; }

    // Check we have the minimum of fields
    if ($data["entity"] && $data["entityID"] && $data["emailAddress"]) {
      interestedParty::delete_interested_party($data["entity"],$data["entityID"],$data["emailAddress"]);
      interestedParty::add_interested_party($data);
    }
  }

  public function delete_interestedParty($options) {
    // Python will submit None instead of ''
    foreach ($options as $k=>$v) { strtolower($v) != 'none' and $data[$k] = $v; }

    // Delete existing entries
    if ($data["entity"] && $data["entityID"] && $data["emailAddress"]) {
      interestedParty::delete_interested_party($data["entity"],$data["entityID"],$data["emailAddress"]);
    }
  }

  private function get_list_help() {
    # This function does not require authentication.
    #global $current_user; // Always need this :(
    #$current_user = $this->get_current_user($sessID);

    global $modules;
    foreach ($modules as $name => $object) {  
      if (is_object($object) && is_array($object->db_entities)) {
        foreach ($object->db_entities as $entity) {
          unset($commar2);
          if (class_exists($entity)) {
            $e = new $entity;
            if (method_exists($e, "get_list")) {
              $rtn.= "\n\nEntity: ".$entity."\nOptions:\n";
              if (method_exists($e, "get_list_vars")) {
                $options = $e->get_list_vars();
                foreach ($options as $option=>$help) {
                  $padding = 30 - strlen($option);
                  $rtn.= $commar2."    ".$option.str_repeat(" ",$padding).$help;
                  $commar2 = "\n";
                }
              }
            }
          }
        }
      }
    }
    die("Usage: get_list(entity, options). The following entities are available: ".$rtn);
  }

  public function edit_entity($entity,$id,$package=false) {

    $people = get_cached_table("person");
    foreach ($people as $personID => $person) {
      $people_by_username[$person["username"]] = $person;
    }

    $task_fields = array(
      "status"    => array("taskStatus",      "inprogress, notstarted, info, client, manager, invalid, duplicate, incomplete, complete; or: open, pending, closed")
     ,"name"      => array("taskName",        "task's title")
     ,"assign"    => array("personID",        "username of the person that the task is assigned to")
     ,"manage"    => array("managerID",       "username of the person that the task is managed by")
     ,"desc"      => array("taskDescription", "task's long description")
     ,"priority"  => array("priority",        "1, 2, 3, 4 or 5")
     ,"limit"     => array("timeLimit",       "limit in hours for effort spend on this task")
     ,"best"      => array("timeBest",        "shortest estimate of how many hours of effort this task will task")
     ,"likely"    => array("timeExpected",    "most likely amount of hours of effort this task will task")
     ,"worst"     => array("timeWorst",       "longest estimate of how many hours of effort this task will task")
     ,"project"   => array("projectID",       "task's project ID")
     ,"type"      => array("taskTypeID",      "Task, Fault, Message, Milestone or Parent")
     ,"dupe"      => array("duplicateTaskID", "If the task status is duplicate, then this should be set to the task ID of the related dupe")
    );

    $timeSheetItem_fields = array(
      "tsid"      => array("timeSheetID",           "time sheet that this item belongs to")
     ,"date"      => array("dateTimeSheetItem",     "time sheet item's date")
     ,"duration"  => array("timeSheetItemDuration", "time sheet item's duration")
     ,"unit"      => array("timeSheetItemDurationUnitID", "time sheet item's unit of duration eg: 1=hours 2=days 3=week 4=month 5=fixed")
     ,"task"      => array("taskID",                "ID of the time sheet item's task")
     ,"rate"      => array("rate",                  "\$rate of the time sheet item's")
     ,"private"   => array("commentPrivate",        "privacy setting of the time sheet item's comment eg: 1=private 0=normal")
     ,"comment"   => array("comment",               "time sheet item comment")
     ,"mult"      => array("multiplier",            "time sheet item multiplier eg: 1=standard 1.5=time-and-a-half 2=double-time 3=triple-time 0=no-charge")
    );


    // Default
    $status = 'die';
    $message = 'Not sure what to do with entity: '.$entity.', id: '.$id.' and package: '.$package;

    // If editing a task
    if ($entity == "task") {
  
      // Perhaps just return help info
      if ($id == "help") {
        $message = "Task fields:";
        foreach ($task_fields as $k => $arr) {
          $message.= "\n      ".$k.":\t".$arr[1];
        }
        $status = 'msg';

      // Else create or update a task
      } else if ($id && $package) {
        $package = alloc_json_decode($package);

        $task = new task;
        if (strtolower($id) != "new") {
          $task->set_id($id);
        }
        $task->select();
        foreach ($package as $k => $v) {

          // Validate/coerce the fields
          if ($k == "status") {
            $v == "inprogress" and $v = "open_inprogress";
            $v == "notstarted" and $v = "open_notstarted";
            $v == "info"       and $v = "pending_info";
            $v == "client"     and $v = "pending_client";
            $v == "manager"    and $v = "pending_manager";
            $v == "invalid"    and $v = "closed_invalid";
            $v == "duplicate"  and $v = "closed_duplicate";
            $v == "incomplete" and $v = "closed_incomplete";
            $v == "complete"   and $v = "closed_complete";
            $v == "open"       and $v = "open_inprogress";
            $v == "pending"    and $v = "pending_info";
            $v == "close"      and $v = "closed_complete";
            $v == "closed"     and $v = "closed_complete";
          } else if ($k == "assign") {
            $v = sprintf("%d",$people_by_username[$v]["personID"]);
          } else if ($k == "manage") {
            $v = sprintf("%d",$people_by_username[$v]["personID"]);
          } else if ($k == "priority") {
            in_array($v,array(1,2,3,4,5)) or $err[] = "Invalid priority.";
          } else if ($k == "type") {
            $v = ucwords($v);
            in_array($v,array("Task","Fault","Message","Milestone","Parent")) or $err[] = "Invalid Task Type.";
          }

          // Plug the value in
          $task->set_value($task_fields[$k][0],$v);
        }

        // Save task
        if (!$err && $task->save()) {
          $status = "yay";
          $message = "Task updated.";
          strtolower($id) == "new" and $message = "Task ".$task->get_id()." created.";

        // Problems
        } else {
          $status = "err";
          $message = "Problem updating task: ".implode("\n",(array)$err);
        }
      }
  
    } else if ($entity == "project") {
      // TODO
    } else if ($entity == "timesheet") {
      // TODO
    } else if ($entity == "item") {
  
      // Perhaps just return help info
      if ($id == "help") {
        $message = "Time sheet item fields:";
        foreach ($timeSheetItem_fields as $k => $arr) {
          $message.= "\n      ".$k.":\t".$arr[1];
        }
        $status = 'msg';

      // Else create or update a timeSheetItem
      } else if ($id && $package) {
        $package = alloc_json_decode($package);

        $timeSheetItem = new timeSheetItem;
        if (strtolower($id) != "new") {
          $timeSheetItem->set_id($id);
        }
        $timeSheetItem->select();
        $timeSheet = $timeSheetItem->get_foreign_object("timeSheet");
        foreach ($package as $k => $v) {

          // Validate/coerce the fields
          if ($k == "unit") {
            in_array($v,array(1,2,3,4,5)) or $err[] = "Invalid unit. Try a number from 1-5.";
          } else if ($k == "task") {
            $t = new task;
            $t->set_id($v);
            $t->select();
            is_object($timeSheet) && $timeSheet->get_id() && $t->get_value("projectID") != $timeSheet->get_value("projectID") and $err[] = "Invalid task. Task belongs to different project.";
          }

          // Plug the value in
          $timeSheetItem->set_value($timeSheetItem_fields[$k][0],$v);
        }

        // Save task
        if (!$err && $timeSheetItem->save()) {
          $status = "yay";
          $message = "Time sheet item updated.";
          strtolower($id) == "new" and $message = "Time sheet item ".$timeSheetItem->get_id()." created.";

        // Problems
        } else {
          $status = "err";
          $message = "Problem updating time sheet item: ".implode("\n",(array)$err);
        }
      }


    }

    // Status will be yay, msg, err or die, i.e. tied to the alloc-cli messaging system
    return array("status"=>$status,"message"=>$message);
  }

} 

?>
