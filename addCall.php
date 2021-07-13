<?php
  //Get required files
  require_once 'bootstrap.php';
  //Connect to database
  $db = new Database;

  if (date('I', time())) {
    $hour = 4;
  } else {
    $hour = 5;
  }

  $entityBody = file_get_contents('php://input');
  $data = json_decode($entityBody);

  foreach($data as $entry){

    if($entry->remove == "yes"){

      $dialedNum = $num = substr(substr($entry->orig_to_uri, 0, strpos($entry->orig_to_uri, "@")), strlen(substr($entry->orig_to_uri, 0, strpos($entry->orig_to_uri, "@")))-10, 10);
      if($entry->by_domain == ""){
        $domain = $entry->term_domain;
      } else {
        $domain = $entry->by_domain;
      }
      if($entry->by_domain == "" && ($entry->term_domain == "" || $entry->term_domain == "*")){
        $domain = $entry->orig_domain;
      }

      $db->query("SELECT * FROM missedCallSubscription WHERE `number` = :n && domain = :domain");
      $db->bind(':n', $dialedNum);
      $db->bind(':domain', $domain);
      $destRows = $db->single();
      $destCount = $db->rowCount();

      if($destCount > 0 && $destRows->callBack != "callBack"){
        $date5 = strtotime($entry->time_start);
        $subtract = $hour * 60 * 60;
        $date6 = date('Y-m-d H:i:s' , $date5 - $subtract);
        $callBack = $entry->orig_from_name . " " . $date6;
        if($entry->time_answer == "0000-00-00 00:00:00"){
          $duration = 0;
        } else {
          $duration = strtotime($entry->time_answer) - strtotime($entry->time_start);
        }

        $db->query('UPDATE missedCallSubscription Set callBack = :callBack, duration = :duration Where `number` = :n && domain = :domain');
        //Bind Values
        $db->bind(':callBack', $callBack);
        $db->bind(':duration', $duration);
        $db->bind(':n', $dialedNum);
        $db->bind(':domain', $domain);
        //Execute
        $db->execute();
      }

      $db->query("SELECT * FROM missedCallsTracking WHERE `domain` = :domain");
      $db->bind(':domain', $domain);
      $trackingRows = $db->resultSetArray();
      $trackingCount = $db->rowCount();

      if($entry->by_sub == ""){
        $key = array_search($entry->term_sub, array_column($trackingRows, 'ext'));
        $ext = $entry->term_sub;
      } else {
        $key = array_search($entry->by_sub, array_column($trackingRows, 'ext'));
        $ext = $entry->by_sub;
      }

      $num = substr(substr($entry->orig_from_uri, 0, strpos($entry->orig_from_uri, "@")), strlen(substr($entry->orig_from_uri, 0, strpos($entry->orig_from_uri, "@")))-10, 10);

      if($trackingCount > 0 && strlen($num) > 4){

        $date3 = strtotime($entry->time_start);
        $subtract = $hour * 60 * 60;
        $date4 = date('Y-m-d H:i:s' , $date3 - $subtract);

        $db->query("SELECT * FROM missedCallSubscription WHERE `orig_callid` = :orig_callid");
        $db->bind(':orig_callid', $entry->orig_callid);
        $origRows = $db->single();
        $origCount = $db->rowCount();

        if($trackingRows[$key]['type'] == 'queue'){
          $type = "Abandoned";
        } else if($trackingRows[$key]['type'] == 'fwd'){
          $type = "Missed";
        }
        if(($type == "Abandoned" && $entry->term_leg_tag == "") || $type == "Missed"){
          if($origCount > 0){
            if($origRows->ext != $ext){
              if($key !== false){
                //Existing call and matches any tracking but not the original one
                //i.e. call comes into 1 queue that is being tracked and fails over to a forward.  Call needs to be removed from abandoned of old ext and added to missed of new ext
                $db->query('UPDATE missedCallSubscription Set ext = :ext, type = :type, `date` = :d, callBack = :callBack, duration = :duration Where orig_callid = :orig_callid');
                //Bind Values
                $db->bind(':ext', $ext);
                $db->bind(':type', $type);
                $db->bind(':d', $date4);
                $db->bind(':callBack', "N/A");
                $db->bind(':duration', "N/A");
                $db->bind(':orig_callid', $entry->orig_callid);
                //Execute
                $db->execute();
              } else {
                $db->query('DELETE FROM missedCallSubscription WHERE orig_callid = :orig_callid');
                //Bind Values
                $db->bind(':orig_callid', $entry->orig_callid);
                //Execute
                $db->execute();
              }
            }
          } else {
            if($key !== false){
              $db->query("SELECT * FROM missedCallSubscription WHERE `domain` = :domain && `ext` = :ext && `number` = :n");
              $db->bind(':domain', $domain);
              $db->bind(':ext', $ext);
              $db->bind(':n', $num);

              $origRows = $db->single();
              $origCount = $db->rowCount();
              if($origCount > 0){
                //New Call and matches tracking and number already called in once
                $db->query('UPDATE missedCallSubscription Set orig_callid = :orig_callid, `date` = :d, count = :count, callBack = :callBack, duration = :duration Where `ext` = :ext && `number` = :n');
                //Bind Values
                $db->bind(':orig_callid', $entry->orig_callid);
                $db->bind(':d', $date4);
                $db->bind(':count', $origRows->count + 1);
                $db->bind(':callBack', "N/A");
                $db->bind(':duration', "N/A");
                $db->bind(':ext', $ext);
                $db->bind(':n', $num);
                //Execute
                $db->execute();
              } else {
                if($entry->term_uri == "Auto-Attendant"){
                  $type = "Call Back";
                  $db->query('INSERT INTO missedCallSubscription (domain, orig_callid, ext, `number`, type, `date`, count, callBack, duration, `text`) VALUES(:domain, :orig_callid, :ext, :n, :type, :d, :count, :callBack, :duration, :t)');
                  //Bind Values
                  $db->bind(':domain', $domain);
                  $db->bind(':orig_callid', $entry->orig_callid);
                  $db->bind(':ext', $ext);
                  $db->bind(':n', $num);
                  $db->bind(':type', $type);
                  $db->bind(':d', $date4);
                  $db->bind(':count', 1);
                  $db->bind(':callBack', "N/A");
                  $db->bind(':duration', "N/A");
                  $db->bind(':t', strval(json_encode($entry)));
                  //$db->bind(':t', strval($entityBody));
                  //Execute
                  $db->execute();
                } else {
                  //Get access token for API
                  $credentials = check($db);
                  $query = array('object' => 'call', 'action' => "read", 'domain' => $domain, "orig_callid" => $entry->orig_callid, 'format' => "json");
                  $queryCheck = doCurl(APIROOT, CURLOPT_POST, "Authorization: Bearer " . $credentials[5], $query, null, $http_response);

                  if($queryCheck == NULL){
                    $db->query('INSERT INTO missedCallSubscription (domain, orig_callid, ext, `number`, type, `date`, count, callBack, duration, `text`) VALUES(:domain, :orig_callid, :ext, :n, :type, :d, :count, :callBack, :duration, :t)');
                    //Bind Values
                    $db->bind(':domain', $domain);
                    $db->bind(':orig_callid', $entry->orig_callid);
                    $db->bind(':ext', $ext);
                    $db->bind(':n', $num);
                    $db->bind(':type', $type);
                    $db->bind(':d', $date4);
                    $db->bind(':count', 1);
                    $db->bind(':callBack', "N/A");
                    $db->bind(':duration', "N/A");
                    $db->bind(':t', strval(json_encode($entry)));
                    //$db->bind(':t', strval($entityBody));
                    //Execute
                    $db->execute();
                  }
                }
              }
            }
          }
        } else if($origCount > 0 && $entry->term_leg_tag != "") {
          $db->query('DELETE FROM missedCallSubscription WHERE orig_callid = :orig_callid');
          //Bind Values
          $db->bind(':orig_callid', $entry->orig_callid);
          //Execute
          $db->execute();
        }
      }
    }
  }
