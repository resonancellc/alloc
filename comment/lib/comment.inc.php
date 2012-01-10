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

class comment extends db_entity {
  public $classname = "comment";
  public $data_table = "comment";
  public $key_field = "commentID";
  public $data_fields = array("commentMaster"
                             ,"commentMasterID"
                             ,"commentType"
                             ,"commentLinkID"
                             ,"commentCreatedUser"
                             ,"commentCreatedTime"
                             ,"commentModifiedTime"
                             ,"commentModifiedUser"
                             ,"commentCreatedUserClientContactID"
                             ,"commentCreatedUserText"
                             ,"commentEmailRecipients"
                             ,"commentEmailUID"
                             ,"commentEmailMessageID"
                             ,"comment"
                             );

  function save() {
    if ($this->get_value("commentType") == "comment") {
      $parent_comment = new comment();
      $parent_comment->set_id($this->get_value("commentLinkID"));
      $parent_comment->select();
      $this->set_value("commentMaster",$parent_comment->get_value("commentType"));
      $this->set_value("commentMasterID",$parent_comment->get_value("commentLinkID"));
    } else {
      $this->set_value("commentMaster",$this->get_value("commentType"));
      $this->set_value("commentMasterID",$this->get_value("commentLinkID"));
    }
    return parent::save();
  }

  function delete() {
  
    if ($this->get_id()) {
      $dir = ATTACHMENTS_DIR."comment".DIRECTORY_SEPARATOR.$this->get_id();
      if (is_dir($dir)) {
        $handle = opendir($dir);
        clearstatcache();
        while (false !== ($file = readdir($handle))) {
          if ($file != "." && $file != ".." && file_exists($dir.DIRECTORY_SEPARATOR.$file)) {
            unlink($dir.DIRECTORY_SEPARATOR.$file);
            clearstatcache();
          }
        }
        is_dir($dir) && rmdir($dir);
      }
    }
    parent::delete();
  }

  function is_owner() {
    global $current_user;
    $entity = $this->get_value("commentType");
    $e = new $entity;
    $e->set_id($this->get_value("commentLinkID"));
    $e->select();
    
    // If the parent is a comment, then go up one more level
    if ($entity == "comment") {
      $entity = $e->get_value("commentType");
      $pe = new $entity;
      $pe->set_id($e->get_value("commentLinkID"));
      $pe->select();
      $e = $pe;
    }
    return $e->is_owner($current_user);
  }

  function has_attachment_permission($person) {
    return $this->is_owner();
  }

  function has_attachment_permission_delete($person) {
    return $this->is_owner();
  }

  function get_comments($commentMaster="",$commentMasterID="") {
    $rows = array();
    if ($commentMaster && $commentMasterID) {
      $q = sprintf("SELECT commentID, 
                           commentMaster, commentMasterID,
                           commentLinkID, commentType,
                           commentCreatedUser as personID, 
                           commentCreatedTime as date, 
                           commentModifiedTime, 
                           commentModifiedUser, 
                           comment, 
                           commentCreatedUserClientContactID as clientContactID,
                           commentCreatedUserText,
                           commentEmailRecipients,
                           commentEmailUID
                      FROM comment 
                     WHERE commentMaster = '%s' AND commentMasterID = %d 
                  ORDER BY commentCreatedTime"
                  ,$commentMaster, $commentMasterID);
      $db = new db_alloc;
      $db->query($q);
      while ($row = $db->row()) {
        if ($row["commentType"] == "comment") {
          $rows[$row["commentLinkID"]]["children"][] = $row;
        } else {
          foreach ($row as $k=>$v) { // need to do it this way so that "children" doesn't get overriden
            $rows[$row["commentID"]][$k] = $v;
          }
        }
      }
    }
    # commentID    commmentMaster   commmentMasterID  commentType   commentLinkID
    # 1            task             10                task          10
    # 2            task             10                comment       1
    # 3            task             10                comment       1
    return $rows;
  }

  function get_one_comment_array($v=array(),$all_parties=array()) {
    global $TPL, $current_user;
    $new = $v;
    $token = new token;
    if ($token->select_token_by_entity_and_action("comment",$new["commentID"],"add_comment_from_email")) {
      if ($token->get_value("tokenHash")) {
        $new["hash"] = $token->get_value("tokenHash");
        $new["hashKey"] = "{Key:".$new["hash"]."}";
        $new["hashHTML"] = " <em class=\"faint\">".$new["hashKey"]."</em>";
      }

      $ip = interestedParty::get_interested_parties("comment",$new["commentID"]);
      foreach((array)$ip as $email => $info) {
        $all_parties += $ip;
        if ($info["selected"]) {
          $sel[] = $email;
        }
      }
      foreach ($all_parties as $email=>$i) {
        in_array($email,(array)$sel) and $recipient_selected[] = $i["identifier"];
      }

      if (interestedParty::is_external("comment",$new["commentID"])) {
        $new["external"] = " loud";
        $label = "<em class='faint warn'>[ External Conversation ]</em>";
      } else {
        $label = "<em class='faint'>[ Internal Conversation ]</em>";
      }

      foreach((array)$all_parties as $email => $info) {
        $recipient_ops[$info["identifier"]] = $info["name"]. " <".$email.">";
      }
  
      $new["recipient_editor"] = "<span class='nobr' style='width:100%;display:inline;' class='recipient_editor'>";

      $new["recipient_editor"].= "<span class='hidden' id='recipient_dropdown_".$new["commentID"]."'>
                                    <form action='".$TPL["url_alloc_updateRecipients"]."' method='post'>
                                      <select name='comment_recipients[]' multiple='true'>
                                      ".page::select_options($recipient_ops,$recipient_selected)."
                                      </select>
                                      <input type='hidden' name='commentID' value='".$new["commentID"]."'>
                                      <input type='submit' value='Go' style='display:none'>
                                    </form>
                                  </span>";

      $new["recipient_editor"].= "<a class='magic recipient_editor_link' id='r_e_".$new["commentID"]."' style='text-decoration:none' href='#x'>".$label."</a>";

      $new["recipient_editor"].= "</span>";

    }

    if ($v["timeSheetID"]) {
      $timeSheet = new timeSheet();
      $timeSheet->set_id($v["timeSheetID"]);
      $v["ts_label"] = " (Time Sheet #".$timeSheet->get_id().")";
    }

    $new["attribution"] = comment::get_comment_attribution($v);
    $new["commentCreatedUserEmail"] = comment::get_comment_author_email($v);
    $s = commentTemplate::populate_string(config::get_config_item("emailSubject_taskComment"), $entity, $id);
    $new["commentEmailSubject"] = $s." ".$new["hashKey"];

    if (!$_GET["commentID"] || $_GET["commentID"] != $v["commentID"]) {

      if ($options["showEditButtons"] && $new["comment_buttons"]) {
        $new["form"] = '<form action="'.$TPL["url_alloc_comment"].'" method="post">';
        $new["form"].= '<input type="hidden" name="entity" value="'.$v["commentType"].'">';
        $new["form"].= '<input type="hidden" name="entityID" value="'.$v["commentLinkID"].'">';
        $new["form"].= '<input type="hidden" name="commentID" value="'.$v["commentID"].'">';
        $new["form"].= '<input type="hidden" name="comment_id" value="'.$v["commentID"].'">';
        $new["form"].= $new["comment_buttons"];
        $new["form"].= '<input type="hidden" name="sessID" value="'.$TPL["sessID"].'">';
        $new["form"].= '</form>';
      }
  
      if ($new["commentEmailUID"] && config::get_config_item("allocEmailHost")) { 
        $new['downloadEmail'] = '<a class="noprint" href="'.$TPL["url_alloc_downloadEmail"].'msg_uid='.$new["commentEmailUID"].'">';
        #$new['downloadEmail'].= '<img border="0" title="Download Email" src="'.$TPL["url_alloc_images"].'download_email.gif">';
        $new['downloadEmail'].= 'Download</a>';
      }

      $files = get_attachments("comment",$v["commentID"],array("sep"=>"<br>"));
      if (is_array($files)) {
        foreach($files as $key => $file) {
          $new["files"].= '<div align="center" style="float:left; display:inline; margin-right:14px;">'.$file["file"].'</div>';
        }
      }

      $v["commentEmailRecipients"] and $new["emailed"] = 'Emailed to '.page::htmlentities($v["commentEmailRecipients"]);
    }
    return (array)$new;
  }

  function util_get_comments_array($entity, $id, $options=array()) {
    global $TPL, $current_user;
    $rows = array();
    $new_rows = array();
    // Need to get timeSheet comments too for task comments
    if ($entity == "task") {
      $rows = comment::get_comments($entity,$id);
      $rows2 = timeSheetItem::get_timeSheetItemComments($id);
      $rows or $rows = array();
      $rows2 or $rows2 = array();
      $rows = array_merge($rows,$rows2);
      if (is_array($rows)) {
        usort($rows, array("comment","sort_task_comments_callback_func"));
      }
    } else {
      $rows = comment::get_comments($entity,$id);
    }

    $e = new $entity;
    $e->set_id($id);
    $e->select();
    $all_parties = $e->get_all_parties();
      
    foreach ((array)$rows as $v) {
      unset($children);
      foreach ((array)$v["children"] as $c) {
        $children[] = comment::get_one_comment_array($c,$all_parties);
      }
      $children and $v["children"] = $children;
      $new_rows[] = comment::get_one_comment_array($v,$all_parties);
    }
    return (array)$new_rows;
  }

  function util_get_comments($entity, $id, $options=array()) {
    global $TPL, $current_user;
    $rows = comment::util_get_comments_array($entity, $id, $options);
    foreach ((array)$rows as $row) {
      $rtn.= comment::get_comment_html_table($row);
    }
    return $rtn;
  }

  function get_comment_html_table($row=array()) {
    global $TPL;
    $comment = comment::add_shrinky_divs(page::htmlentities($row["comment"]),$row["commentID"]);
    $onClick = "return set_grow_shrink('comment_".$row["commentID"]."','button_comment_".$row["commentID"]."','true');";
    $rtn[] = '<div class="panel'.$row["external"].' corner">';
    $rtn[] = '<table width="100%" cellspacing="0" border="0">';
    $rtn[] = '<tr>';
    $rtn[] = '  <td style="padding-bottom:0px; white-space:normal" onClick="'.$onClick.'">'.$row["attribution"].$row["hashHTML"].'</td>';
    $rtn[] = '  <td align="right" style="padding-bottom:0px;" class="nobr">'.$row["form"].$row["downloadEmail"].$row["recipient_editor"].'</td>';
    $rtn[] = '</tr>';
    $rtn[] = '<tr>';
    $rtn[] = '  <td colspan="2" style="padding-top:0px; white-space:normal;">'.preg_replace("/<[^>]>/","",$row["emailed"])."</td>";
    $rtn[] = '</tr>';
    $rtn[] = '<tr>';
    $rtn[] = '  <td colspan="2" onClick="'.$onClick.'"><div><pre class="comment">'.$comment.'</pre></div></td>';
    $rtn[] = '</tr>';
    $row["children"] and $rtn[] = comment::get_comment_children($row["children"]);
    $row["files"] and $rtn[] = '<tr>';
    $row["files"] and $rtn[] = '  <td valign="bottom" align="left" colspan="2">'.$row["files"].'</td>';
    $row["files"] and $rtn[] = '</tr>';
    $rtn[] = '</table>';
    $rtn[] = '</div>';
    return implode("\n",$rtn);
  }

  function get_comment_attribution($comment=array()) {
    $str = '<b>'.comment::get_comment_author($comment).'</b> <span class="comment_date">'.$comment["date"]."</span>";
      if ($comment["commentModifiedTime"] || $comment["commentModifiedUser"]) {
        $str.= ", last modified by <b>".person::get_fullname($comment["commentModifiedUser"])."</b> ".$comment["commentModifiedTime"];
      }
      $str.= $comment["ts_label"];
    return $str;
  }

  function add_shrinky_divs($html="", $commentID) {
    if ($_GET["media"] == "print") return $html;
    $class = "comment_".$commentID;
    $lines = explode("\n","\n".$html."\n");
    foreach ($lines as $k => $line) {
      if (!$started && preg_match("/^&gt;/",$line)) {
        $started = true;
        $start_position = $k;
        $new_lines[$k] = $line;

      } else if ($started && !preg_match("/^&gt;/",$line)){
    
        $num_lines_hidden = $k-$start_position;

        if ($num_lines_hidden > 3) {
          $new_lines[$start_position-1].= "<div style=\"display:inline;\" class=\"hidden_text button_".$class."\"> --- ".$num_lines_hidden." lines hidden --- <br></div>";
          $new_lines[$start_position-1].= "<div style=\"display:none;\" class=\"hidden_text ".$class."\">";
          $new_lines[$k] = "</div>".$line;
    
        } else {
          $new_lines[$start_position-1].= "<div style=\"display:inline;\" class=\"hidden_text\">";
          $new_lines[$k] = "</div>".$line;
        }

        $started = false;

      } else {
        $new_lines[$k] = $line;
      }
    }

    // Hide signature too
    foreach ($new_lines as $k => $line) {
      if (!$sig_started && preg_match("/^--(\s|\n|\r|<br>|<br \/>)*$/",$line)) {
        $sig_started = true;
        $sig_start_position = $k;
      } 
      $new_lines2[$k] = $line;
    }

    $sig_num_lines_hidden = count($new_lines2)-1-$sig_start_position;
  
    if ($sig_started && $sig_num_lines_hidden > 1){
      $new_lines2[$sig_start_position-1].= "<div style=\"display:inline;\" class=\"hidden_text button_".$class."\"> --- ".$sig_num_lines_hidden." lines hidden (signature) --- <br></div>";
      $new_lines2[$sig_start_position-1].= "<div style=\"display:none;\" class=\"hidden_text ".$class."\">"; 
      $new_lines2[count($new_lines2)].= "</div>";
    } else if ($sig_started) {
    }

    return ltrim(rtrim(implode("\n",$new_lines2)));
  }

  function get_comment_children($children=array(), $padding=1) {
    $rtn = array();
    foreach($children as $child) {
      // style=\"padding:0px; padding-left:".($padding*15+5)."px; padding-right:6px;\"
      $rtn[] = "<tr><td colspan=\"2\" style=\"padding:0px; padding-left:6px; padding-right:6px;\">".comment::get_comment_html_table($child)."</td></tr>";
      if (is_array($child["children"]) && count($child["children"])) {
        $padding += 1;
        $rtn[] = comment::get_comment_children($child["children"],$padding);
        $padding -= 1;
      } 
    } 
    return implode("\n",$rtn);
  }

  function get_comment_author($comment=array()) {
    if ($comment["commentCreatedUserText"]) {
      $author = page::htmlentities($comment["commentCreatedUserText"]);
    } else if ($comment["clientContactID"]) {
      $cc = new clientContact;
      $cc->set_id($comment["clientContactID"]);
      $cc->select();
      #$author = " <a href=\"".$TPL["url_alloc_client"]."clientID=".$cc->get_value("clientID")."\">".$cc->get_value("clientContactName")."</a>";
      $author = $cc->get_value("clientContactName");
    } else {
      $author = person::get_fullname($comment["personID"]);
    }
    return $author;
  }

  function get_comment_author_email($comment=array()) {
    if ($comment["commentCreatedUser"]) {
      $personID = $comment["commentCreatedUser"];
      $p = new person;
      $p->set_id($personID);
      $p->select();
      $email = $p->get_from();
    } else if ($comment["clientContactID"]) {
      $cc = new clientContact;
      $cc->set_id($comment["clientContactID"]);
      $cc->select();
      $email = $cc->get_value("clientContactEmail");
    } else {
      $p= new person;
      $p->set_id($comment["personID"]);
      $p->select();
      $email = $p->get_from();
    }
    return $email;
  }

  function sort_task_comments_callback_func($a, $b) {
    return strtotime($a["date"]) > strtotime($b["date"]);
  }

  function make_token_add_comment_from_email() {
    global $current_user;
    $token = new token;
    $token->set_value("tokenEntity","comment");
    $token->set_value("tokenEntityID",$this->get_id());
    $token->set_value("tokenActionID",2);
    $token->set_value("tokenActive",1);
    $token->set_value("tokenCreatedBy",$current_user->get_id());
    $token->set_value("tokenCreatedDate",date("Y-m-d H:i:s"));
    $hash = $token->generate_hash();
    $token->set_value("tokenHash",$hash);
    $token->save();
    return $hash;
  }

  function add_comment_from_email($email_receive,$entity) {
    global $current_user, $guest_permission_cache;

    $commentID = comment::add_comment($entity->classname,$entity->get_id(),$email_receive->get_converted_encoding());

    $comment = new comment;
    $comment->set_id($commentID);
    $comment->select();
    $comment->set_value("commentEmailUID",$email_receive->msg_uid);

    // Have to allow guest users to update their newly created comment
    $guest_permission_cache[] = array("entity"=>"comment","entityID"=>$comment->get_id(),"perms"=>15);

    $comment->rename_email_attachment_dir($email_receive->dir);

    // Try figure out and populate the commentCreatedUser/commentCreatedUserClientContactID fields
    list($from_address,$from_name) = parse_email_address($email_receive->mail_headers->fromaddress);
    list($personID,$clientContactID,$from_name) = comment::get_person_and_client($from_address,$from_name,$entity->get_project_id());
    $personID and $comment->set_value('commentCreatedUser', $personID);
    $clientContactID and $comment->set_value('commentCreatedUserClientContactID', $clientContactID);

    $comment->set_value("commentCreatedUserText",trim($email_receive->mail_headers->fromaddress));
    $comment->set_value("commentEmailMessageID",trim($email_receive->mail_headers->message_id));

    $e = $entity->get_parent_object();
    if (method_exists($e, 'add_comment_hook')) {
      $e->add_comment_hook($comment);
    }

    $comment->updateSearchIndexLater = true;
    $comment->skip_modified_fields = true;
    $comment->save();
    return $comment;
  }

  function get_email_recipients($options=array(),$entity,$entityID) {
    $recipients = array();
    $people = get_cached_table("person");

    foreach ($options as $selected_option) {

      // Determine recipients 
      if ($selected_option == "interested") {
        $db = new db_alloc;
        if ($entity && $entityID) {
          $q = sprintf("SELECT * FROM interestedParty WHERE entity = '%s' AND entityID = %d AND interestedPartyActive = 1",$entity,$entityID);
        }
        $db->query($q);
        while($row = $db->next_record()) {
          $row["isCC"] = true;
          $row["name"] = $row["fullName"];
          $recipients[] = $row;
        }
      } else if (is_int($selected_option)){
        $recipients[] = $people[$selected_option];

      } else if (is_string($selected_option) && preg_match("/@/",$selected_option)) {
        list($email, $name) = parse_email_address($selected_option);
        $email and $recipients[] = array("name"=>$name,"emailAddress"=>$email);
      }
    }
    return $recipients;
  }

  function get_email_recipient_headers($recipients, $from_address) {
    global $current_user;

    $emailMethod = config::get_config_item("allocEmailAddressMethod");

    // Build up To: and Bcc: headers
    foreach ($recipients as $recipient) {
      unset($recipient_full_name);

      if ($recipient["firstName"] && $recipient["surname"]) {
        $recipient_full_name = $recipient["firstName"]." ".$recipient["surname"];
      } else if ($recipient["fullName"]) {
        $recipient_full_name = $recipient["fullName"];
      } else if ($recipient["name"]) {
        $recipient_full_name = $recipient["name"];
      }

      if ($recipient["emailAddress"] && !$done[$recipient["emailAddress"]]) {

        // If the person does *not* want to receive their own emails, skip adding them as a recipient
        if ($current_user->prefs["receiveOwnTaskComments"] == 'no' && same_email_address($recipient["emailAddress"],$from_address)) {
          continue;
        }

        $done[$recipient["emailAddress"]] = true;

        $name = $recipient_full_name or $name = $recipient["emailAddress"];
        $email_without_name = $recipient["emailAddress"];
        if ($recipient_full_name) {
          $name_and_email = $recipient_full_name." <".$recipient["emailAddress"].">";
        } else {
          $name_and_email = $recipient["emailAddress"];
        }

        if ($emailMethod == "to") {
          $to_address.= $commar.$name_and_email;
          $successful_recipients.= $commar.$name_and_email;
          $commar = ", ";

        } else if ($emailMethod == "bcc") {
          $bcc.= $commar.$email_without_name;
          $successful_recipients.= $commar.$name_and_email;
          $commar = ", ";

        // The To address contains no actual email addresses, ie "Alex Lance": ; all the real recipients are in the Bcc.
        } else if ($emailMethod == "tobcc") {
          $to_address.= $commar.'"'.$name.'": ;';
          $bcc.= $commar.$email_without_name;
          $successful_recipients.= $commar.$name_and_email;
          $commar = ", ";
        }

      }
    }
    return array($to_address, $bcc, $successful_recipients);
  }

  function send_emails($selected_option, $email_receive=false, $hash="", $is_a_reply_comment=false) {
    global $current_user;

    $e = $this->get_parent_object();
    $type = $e->classname."_comments";
    $body = $this->get_value("comment");

    if (is_object($email_receive)) {
      list($from_address,$from_name) = parse_email_address($email_receive->mail_headers->fromaddress);
    }

    if ($is_a_reply_comment) {
      $id = $this->get_value("commentLinkID");
    } else {
      $id = $this->get_id();
    } 

    $recipients = comment::get_email_recipients($selected_option,"comment",$id);
    list($to_address,$bcc,$successful_recipients) = comment::get_email_recipient_headers($recipients, $from_address);

    if ($successful_recipients) {
      $email = new alloc_email();

      if ($email_receive) {
        list($email_receive_header,$email_receive_body) = $email_receive->get_raw_header_and_body();
        $email->set_headers($email_receive_header); 
        $email->set_body($email_receive_body); 
        // Remove any existing To/Cc header, to prevent the email getting sent to the same recipients again.
        $email->del_header("To");
        $email->del_header("Cc");
        $subject = $email->get_header("subject");
        $subject = trim(preg_replace("/{Key:[^}]*}.*$/i","",$subject));
      } else {
        $email->set_body($body);
      }

      $bcc && $email->add_header("Bcc",$bcc);
      
      $email->add_header("X-Alloc-CommentID", $this->get_id());
      $email->add_header("X-Alloc-".ucwords($e->classname), $e->get_name(DST_VARIABLE));
      $email->add_header("X-Alloc-".ucwords($e->key_field->get_name()), $e->get_id());

      // Add project header too, if possible
      if ($e->classname != "project" && isset($e->data_fields["projectID"])) {
        $p = $e->get_foreign_object("project");
        $email->add_header("X-Alloc-Project", $p->get_value("projectName"));
        $email->add_header("X-Alloc-ProjectID", $p->get_id());
      }
      
      $email->set_to_address($to_address);
      is_object($current_user) && !$from_name and $from_name = $current_user->get_name();
      $messageid = $email->set_message_id($hash);
      $subject_extra = "{Key:".$hash."}";
      $email->set_date();

      if (!$subject) {
        $tpl = config::get_config_item("emailSubject_".$e->classname."Comment");
        $tpl and $subject = commentTemplate::populate_string($tpl, $e->classname, $e->get_id());
        $subject or $subject = ucwords($e->classname)." Comment: ".$e->get_id()." ".$e->get_name(DST_VARIABLE);
      }

      $email->set_subject($subject." ".$subject_extra);
      $email->set_message_type($type);

      if (defined("ALLOC_DEFAULT_FROM_ADDRESS") && ALLOC_DEFAULT_FROM_ADDRESS) {
        $email->set_reply_to("All parties via ".ALLOC_DEFAULT_FROM_ADDRESS);
        $email->set_from($from_name." via ".ALLOC_DEFAULT_FROM_ADDRESS);
      } else {
        $f = $current_user->get_from() or $f = config::get_config_item("allocEmailAdmin");
        $email->set_reply_to($f);
        $email->set_from($f);
      }

      // if we're bouncing a complete email body the attachments are already included
      if ($id && !$email_receive) {
        $files = get_attachments("comment",$id);
        if (is_array($files)) {
          foreach ($files as $file) {
            $email->add_attachment($file["path"]);
          }
        }
      }

      if ($email->send(false)) {
        return array($successful_recipients,$messageid);
      }
    }   
  }

  function get_list($_FORM=array()) {
    if ($_FORM["entity"] && in_array($_FORM["entity"],array("project","client","task","timeSheet")) && $_FORM["entityID"]) {
      $e = new $_FORM["entity"];
      $e->set_id($_FORM["entityID"]);
      if ($e->select()) { // this ensures that the user can read the entity
        return comment::util_get_comments_array($_FORM["entity"],$_FORM["entityID"],$_FORM);
      }
    }
  }

  function get_list_vars() {
    return array("entity"            => "The entity whose comments you want to fetch, eg: project | client | task | timeSheet"
                ,"entityID"          => "The ID of the particular entity"
                ,"showEditButtons"   => "Will fetch a form with edit comment buttons"
                );
  }

  function get_parent_object() {
    if (class_exists($this->get_value("commentType"))) {
      $parent_type = $this->get_value("commentType");
    
      $o = new $parent_type;
      $o->set_id($this->get_value("commentLinkID"));
      $o->select();

      if ($parent_type == "comment") {
        return $o->get_parent_object();
      } else {
        return $o;
      }
    }
  }

  function update_search_index_doc(&$index) {
    $arr["commentCreatedUserText"] = $this->get_value("commentCreatedUserText");
    $arr["clientContactID"]        = $this->get_value("commentCreatedUserClientContactID");
    $arr["personID"]               = $this->get_value("commentCreatedUser");

    $name = $this->get_value("commentCreatedTime")." ";
    $author = comment::get_comment_author($arr);
    $name.= $author;

    $entity = $this->get_value("commentType");
    $entity_id = $this->get_value("commentLinkID");
    $e = new $entity;
    $e->set_id($entity_id);
    $e->select();
    $entity_name = $e->get_name();
    // If the parent is a comment, then go up one more level
    if ($entity == "comment") {
      $entity = $e->get_value("commentType");
      $entity_id = $e->get_value("commentLinkID");
      $f = new $entity;
      $f->set_id($entity_id);
      $f->select();
      $entity_name = $f->get_name();
    }

    $doc = new Zend_Search_Lucene_Document();
    $doc->addField(Zend_Search_Lucene_Field::Keyword('id'   ,$this->get_id()));
    $doc->addField(Zend_Search_Lucene_Field::Text('name'    ,$name,"utf-8"));
    $doc->addField(Zend_Search_Lucene_Field::Text('type'    ,$entity,"utf-8"));
    $doc->addField(Zend_Search_Lucene_Field::Text('typeid'  ,$entity_id,"utf-8"));
    $doc->addField(Zend_Search_Lucene_Field::Text('typename',$entity_name,"utf-8"));
    $doc->addField(Zend_Search_Lucene_Field::Text('desc'    ,$this->get_value("comment"),"utf-8"));
    $doc->addField(Zend_Search_Lucene_Field::Text('creator' ,$author,"utf-8"));
    $doc->addField(Zend_Search_Lucene_Field::Text('dateCreated',str_replace("-","",$this->get_value("commentCreatedTime")),"utf-8"));
    $index->addDocument($doc);
  }

  function get_list_summary_filter($filter=array()) {
    
    // This takes care of projectID singular and plural
    $projectIDs = project::get_projectID_sql($filter,"task");
    $projectIDs and $sql1["projectIDs"] = $projectIDs;
    $projectIDs and $sql2["projectIDs"] = $projectIDs;
    $filter['taskID'] and $sql1[] = sprintf("(task.taskID = %d)", db_esc($filter["taskID"]));
    $filter['taskID'] and $sql2[] = sprintf("(task.taskID = %d)", db_esc($filter["taskID"]));
    $filter["fromDate"] and $sql1[] = sprintf("(date(commentCreatedTime) >= '%s')", db_esc($filter["fromDate"]));
    $filter["fromDate"] and $sql2[] = sprintf("(dateTimeSheetItem >= '%s')", db_esc($filter["fromDate"]));
    $filter["toDate"] and $sql1[] = sprintf("(date(commentCreatedTime) < '%s')", db_esc($filter["toDate"]));
    $filter["toDate"] and $sql2[] = sprintf("(dateTimeSheetItem < '%s')", db_esc($filter["toDate"]));
    $filter["personID"] and $sql1["personID"] = "(comment.commentCreatedUser IN (".implode(",",(array)$filter["personID"])."))";
    $filter["personID"] and $sql2[] = "(timeSheetItem.personID IN (".implode(",",(array)$filter["personID"])."))";
    $filter["clients"] or $sql1[] = "(commentCreatedUser IS NOT NULL)";
    $filter["clients"] && $filter["personID"] and $sql1["personID"] = "(comment.commentCreatedUser IN (".implode(",",(array)$filter["personID"]).") OR comment.commentCreatedUser IS NULL)";

    $filter["taskStatus"] and $sql1[] = task::get_taskStatus_sql($filter["taskStatus"]);
    $filter["taskStatus"] and $sql2[] = task::get_taskStatus_sql($filter["taskStatus"]);

    return array($sql1,$sql2);
  }

  function get_list_summary($_FORM=array()) {

    //$_FORM["fromDate"] = "2010-08-20";
    //$_FORM["projectID"] = "22";

    $_FORM["maxCommentLength"] or $_FORM["maxCommentLength"] = 500;

    list($filter1,$filter2) = comment::get_list_summary_filter($_FORM);

    is_array($filter1) && count($filter1) and $filter1 = " AND ".implode(" AND ",$filter1);
    is_array($filter2) && count($filter2) and $filter2 = " AND ".implode(" AND ",$filter2);

    if ($_FORM["clients"]) {
      $client_join = " LEFT JOIN clientContact on comment.commentCreatedUserClientContactID = clientContact.clientContactID";
      $client_fields = " , clientContact.clientContactName";
    }

    $q = sprintf("SELECT commentID as id
                       , commentCreatedUser as personID
                       , date(commentCreatedTime) as date
                       , commentMasterID as taskID
                       , task.taskName
                       , SUBSTRING(comment.comment,1,%d) AS comment_text
                       , commentCreatedUserText
                         ".$client_fields."
                    FROM comment
               LEFT JOIN task on comment.commentMasterID = task.taskID
                         ".$client_join."
                   WHERE commentMaster = 'task'
                         ".$filter1."
                ORDER BY commentCreatedTime, commentCreatedUser"
                ,$_FORM["maxCommentLength"]);
    $q.= " ";
              
    $people = get_cached_table("person");

    $db = new db_alloc();
    $db->query($q);
    while ($row = $db->row()) {
      $row["id"] = "comment_".$row["id"];
      $row["personID"] and $row["person"] = $people[$row["personID"]]["name"];
      $row["clientContactName"] and $row["person"] = $row["clientContactName"];
      $row["person"] or list($e,$row["person"]) = parse_email_address($row["commentCreatedUserText"]);
      if (!$tasks[$row["taskID"]]) {
        $t = new task;
        $t->set_id($row["taskID"]);
        $t->set_value("taskName",$row["taskName"]);
        $tasks[$row["taskID"]] = $t->get_task_link(array("prefixTaskID"=>true));
      }
      $rows[$row["taskID"]][$row["date"]][] = $row;
    }

    // Note that timeSheetItemID is selected twice so that the perms checking can work
    // timeSheetID is also used by the perms checking.
    $q2 = sprintf("SELECT timeSheetItemID as id
                         ,timeSheetItemID
                         ,timeSheetID
                         ,timeSheetItem.personID
                         ,dateTimeSheetItem as date
                         ,timeSheetItem.taskID
                         ,task.taskName
                         ,timeSheetItemDuration as duration
                         ,SUBSTRING(timeSheetItem.comment,1,%d) AS comment_text
                     FROM timeSheetItem
                LEFT JOIN task on timeSheetItem.taskID = task.taskID
                    WHERE 1
                          ".$filter2."
                 ORDER BY dateTimeSheetItem"
                 ,$_FORM["maxCommentLength"]);

    $db->query($q2);
    while ($row = $db->row()) {
      $timeSheetItem = new timeSheetItem();
      if (!$timeSheetItem->read_row_record($row,false))
        continue;
      $row["id"] = "timeitem_".$row["id"];
      $row["person"] = $people[$row["personID"]]["name"];
      if (!$tasks[$row["taskID"]]) {
        $t = new task;
        $t->set_id($row["taskID"]);
        $t->set_value("taskName",$row["taskName"]);
        $tasks[$row["taskID"]] = $t->get_task_link(array("prefixTaskID"=>true));
      }
      $totals[$row["taskID"]] += $row["duration"];
      $rows[$row["taskID"]][$row["date"]][] = $row;
    }

    // If there is a time sheet entry for 2010-10-10 but there is no comment entry
    // for that date, then the time sheet entry will appear out of sequence i.e. at
    // the very end of the whole list. So we need to manually sort them.
    foreach ($rows as $tid => $arr) {
      ksort($arr);
      $rows[$tid] = $arr;
    }

    foreach ((array)$rows as $taskID => $dates) {
      $rtn.= comment::get_list_summary_header($tasks[$taskID],$totals[$taskID],$_FORM);
      foreach ($dates as $date => $more_rows) {
        foreach ($more_rows as $row) {
          $rtn.= comment::get_list_summary_body($row);
        }
      }
      $rtn.= comment::get_list_summary_footer($rows,$tasks);
    }
    return $rtn;
  }

  function get_list_summary_header($task,$totals,$_FORM=array()) {
  
    if ($_FORM["showTaskHeader"]) {
      $rtn[] = "<table class='list' style='border-bottom:0;'>";
      $rtn[] = "<tr>";
      $rtn[] = "<td style='font-size:130%'>".$task."</td>";
      $rtn[] = "<td class='right bold'>".sprintf("%0.2f",$totals)."</td>";
      $rtn[] = "</tr>";
      $rtn[] = "</table>";
    }
    $rtn[] = "<table class=\"list sortable\" style='margin-bottom:10px'>";
    $rtn[] = "<tr>";
    $rtn[] = "<th>Date</th>";
    $rtn[] = "<th>Person</th>";
    $rtn[] = "<th>Comment</th>";
    $rtn[] = "<th>Hours</th>";
    $rtn[] ="</tr>";
    return implode("\n",$rtn);
  }

  function get_list_summary_body($row) {
    global $TPL;
    $TPL["row"] = $row;
    return include_template(dirname(__FILE__)."/../templates/summaryR.tpl", true);
  }

  function get_list_summary_footer($rows,$tasks) {
    $rtn[] = "</table>";
    return implode("\n",$rtn);
  }

  function get_project_id() {
    $this->select();
    if ($this->get_value("commentType") == "task" && $this->get_value("commentLinkID")) {
      $t = new task;
      $t->set_id($this->get_value("commentLinkID"));
      $t->select();
      $projectID = $t->get_value("projectID");
    } else if ($this->get_value("commentType") == "project" && $this->get_value("commentLinkID")) {
      $projectID = $this->get_value("commentLinkID");
    } else if ($this->get_value("commentType") == "timeSheet" && $this->get_value("commentLinkID")) {
      $t = new timeSheet;
      $t->set_id($this->get_value("commentLinkID"));
      $t->select();
      $projectID = $t->get_value("projectID");
    }
    return $projectID;
  }

  function get_person_and_client($from_address,$from_name,$projectID=null) {
    global $current_user;
    $person = new person;
    $personID = $person->find_by_email($from_address);
    $personID or $personID = $person->find_by_name($from_name);

    if (!$personID) {
      $cc = new clientContact();
      $clientContactID = $cc->find_by_email($from_address);
      $clientContactID or $clientContactID = $cc->find_by_name($from_name, $projectID);
    }

    // If we don't have a $from_name, but we do have a personID or clientContactID, get proper $from_name
    if (!$from_name) {
      if ($personID) {
        $from_name = person::get_fullname($personID);
      } else if ($clientContactID) {
        $cc = new clientContact;
        $cc->set_id($clientContactID);
        $cc->select();
        $from_name = $cc->get_value("clientContactName");
      } else {
        $from_name = $from_address;
      }
    }
    return array($personID,$clientContactID,$from_name);
  }

  function rename_email_attachment_dir($dir) {
    if ($dir && is_dir($dir)) {
      $b = basename($dir);
      $newdir = dirname($dir).DIRECTORY_SEPARATOR.$this->get_id();
      rename($dir, $newdir);
      rmdir_if_empty($newdir);
    }
  }


  // All you need to add a comment, add interested parties, attachments, and re-email it out

  function add_comment($entity,$entityID,$comment_text) {
    if ($entity && $entityID && $comment_text) {
      $comment = new comment;
      $comment->updateSearchIndexLater = true;
      $comment->set_value('commentType', $entity);
      $comment->set_value('commentLinkID', $entityID);
      $comment->set_value('comment', rtrim($comment_text));
      $comment->save();
      return $comment->get_id();
    }
  }

  function add_interested_parties($commentID,$ip=array(),$op=array()) {

    // We send this email to the default from address, so that a copy of the
    // original email is kept. The receiveEmail.php script will see that this
    // email is *from* the same address, and will then skip over it, when going
    // through the new emails.
    if (defined("ALLOC_DEFAULT_FROM_ADDRESS") && ALLOC_DEFAULT_FROM_ADDRESS) {
      list($from_address,$from_name) = parse_email_address(ALLOC_DEFAULT_FROM_ADDRESS);
      $emailRecipients[] = $from_address;
    }

    interestedParty::make_interested_parties("comment",$commentID,$ip);
    $emailRecipients[] = "interested";

    // Other parties that are added on-the-fly
    foreach ((array)$op as $email => $info) {
      if ($email && in_str("@",$email)) {
        unset($lt,$gt); // used above
        $str = $info["name"];
        $str and $str.=" ";
        $str and $lt = "<";
        $str and $gt = ">";
        $str.= $lt.str_replace(array("<",">"),"",$email).$gt;
        $emailRecipients[] = $str;

        // Add a new client contact
        if ($info["addContact"] && $info["clientID"]) {
          $q = sprintf("SELECT * FROM clientContact WHERE clientID = %d AND clientContactEmail = '%s'"
                      ,$info["clientID"],db_esc(trim($email)));
          $db = new db_alloc();
          if (!$db->qr($q)) {
            $cc = new clientContact;
            $cc->set_value("clientContactName",trim($info["name"]));
            $cc->set_value("clientContactEmail",trim($email));
            $cc->set_value("clientID",sprintf("%d",$info["clientID"]));
            $cc->save();
          }
        }
        // Add the person to the interested parties list
        if ($info["addIP"] && !interestedParty::exists("comment",$commentID,trim($email))) {
          $interestedParty = new interestedParty;
          $interestedParty->set_value("fullName",trim($info["name"]));
          $interestedParty->set_value("emailAddress",trim($email));
          $interestedParty->set_value("entityID",$commentID);
          $interestedParty->set_value("entity","comment");
          $interestedParty->set_value("external","1");
          $interestedParty->set_value("interestedPartyActive","1");
          if (is_object($cc) && $cc->get_id()) {
            $interestedParty->set_value("clientContactID",$cc->get_id());
          }
          $interestedParty->save();
        }
      }
    }
    return $emailRecipients;
  }

  function send_comment($commentID, $emailRecipients, $email_receive=false) {

    $comment = new comment();
    $comment->set_id($commentID);
    $comment->select();

    $token = new token;

    if ($comment->get_value("commentType") == "comment" && $comment->get_value("commentLinkID")) {
      $c = new comment;
      $c->set_id($comment->get_value("commentLinkID"));
      $c->select();
      $is_a_reply_comment = true;
      if ($token->select_token_by_entity_and_action("comment",$c->get_id(),"add_comment_from_email")) {
        $hash = $token->get_value("tokenHash");
      }
    }

    if (!$hash) {
      if ($token->select_token_by_entity_and_action("comment",$comment->get_id(),"add_comment_from_email")) {
        $hash = $token->get_value("tokenHash");
      } else {
        $hash = $comment->make_token_add_comment_from_email();
      }
    }

    list($successful_recipients,$messageid) = $comment->send_emails($emailRecipients,$email_receive,$hash,$is_a_reply_comment);

    // Append success to end of the comment
    if ($successful_recipients) {
      $append_comment_text = "Email sent to: ".$successful_recipients;
      $message_good.= $append_comment_text;
      $comment->set_value("commentEmailMessageID",$messageid);
      $comment->set_value("commentEmailRecipients",$successful_recipients);
    }

    $comment->skip_modified_fields = true;
    $comment->updateSearchIndexLater = true;
    $comment->save();
  }

  function attach_timeSheet($commentID, $entityID, $options) {
    // Begin buffering output to halt anything being sent to the web browser.
    ob_start();
    $t = new timeSheetPrint();
    $ops = query_string_to_array($options);

    $t->get_printable_timeSheet_file($entityID,$ops["timeSheetPrintMode"],$ops["printDesc"],$ops["format"]);

    // Capture the output into $str
    $str = (string)ob_get_clean();

    $suffix = ".html";
    $ops["format"] != "html" and $suffix = ".pdf";

    $timeSheetPrintOptions = config::get_config_item("timeSheetPrintOptions");
    $dir = ATTACHMENTS_DIR."comment".DIRECTORY_SEPARATOR.$commentID;
    if (!is_dir($dir)) {
      mkdir($dir, 0777);
    }
    $file = $dir.DIRECTORY_SEPARATOR."timeSheet_".$entityID.$suffix;
    file_put_contents($file,$str);
  }

  function attach_invoice($commentID,$entityID,$verbose) {
    // Begin buffering output to halt anything being sent to the web browser.
    ob_start();
    $invoice = new invoice();
    $invoice->set_id($entityID);
    $invoice->select();
    $invoice->generate_invoice_file($verbose,true);

    // Capture the output into $str
    $str = (string)ob_get_clean();

    $suffix = ".pdf";

    $dir = ATTACHMENTS_DIR."comment".DIRECTORY_SEPARATOR.$commentID;
    if (!is_dir($dir)) {
      mkdir($dir, 0777);
    }
    $file = $dir.DIRECTORY_SEPARATOR."invoice_".$_REQUEST["entityID"].$suffix;
    file_put_contents($file,$str);
  }

  function attach_tasks($commentID, $entityID, $options) {

    $c = new comment();
    $c->set_id($commentID);
    $c->select();
    $projectID = $c->get_project_id();

    if ($projectID) {
      // Begin buffering output to halt anything being sent to the web browser.
      ob_start();
      $t = new taskListPrint();
        
      $defaults = array("showAssigned"=>true
                       ,"showDate1"=>true
                       ,"showDate2"=>true
                       ,"showDate3"=>true
                       ,"showDate4"=>true
                       ,"showDate5"=>true
                       ,"showPercent"=>true
                       ,"showStatus"=>true
                       ,"taskView"=>"prioritised"
                       ,"projectID"=>$projectID
                       ,"format"=>$options
                       );

      if ($options == "pdf_plus" || $options == "html_plus") {
        $defaults["showTimes"] = true;
      }

      $t->get_printable_file($defaults);

      // Capture the output into $str
      $str = (string)ob_get_clean();

      $suffix = ".html";
      $options != "html" && $options != "html_plus" and $suffix = ".pdf";

      $dir = ATTACHMENTS_DIR."comment".DIRECTORY_SEPARATOR.$commentID;
      if (!is_dir($dir)) {
        mkdir($dir, 0777);
      }
      $file = $dir.DIRECTORY_SEPARATOR."taskList_".$entityID.$suffix;
      file_put_contents($file,$str);
    }
  }

  function move_attachment($entity, $entityID) {
    move_attachment($entity, $entityID);
  }


}



?>
