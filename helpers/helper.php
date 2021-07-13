<?php
//Curl Request
function doCurl($url, $method, $authorization, $query, $postFields, &$http_response) {
  $start = microtime(true);
  $curl_options = array(
    CURLOPT_URL => $url . ($query ? '?' . http_build_query($query) : ''),
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FORBID_REUSE => true,
    CURLOPT_TIMEOUT => 60
  );

  $headers = array();
  if ($authorization != NULL) {
    $headers[$authorization] = $authorization;
  } //$authorization != NULL

  $curl_options[$method] = true;
  if ($postFields != NULL) {
    $curl_options[CURLOPT_POSTFIELDS] = $postFields;
  } //$postFields != NULL

  if (sizeof($headers) > 0)
      $curl_options[CURLOPT_HTTPHEADER] = $headers;

  $curl_handle = curl_init();
  curl_setopt_array($curl_handle, $curl_options);
  $curl_result = curl_exec($curl_handle);
  $http_response = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
  //print_r($http_response);
  curl_close($curl_handle);
  $end = microtime(true);
  if (!$curl_result)
      return NULL;
  else if ($http_response >= 400)
      return NULL;
  else
      $result = json_decode($curl_result, true);
      return $result;
}

//Check credentials
function check($db){
  $credentials[0] = 'api';
  $db->query("SELECT * FROM tokens Where clientID = :clientId");
  $db->bind(':clientId', $credentials[0]);
  $row = $db->single();
  $credentials[1] = $row->clientSecret;
  $credentials[2] = $row->username;
  $credentials[3] = $row->password;
  $credentials[4] = $row->refreshToken;
  $credentials[5] = $row->accessToken;
  $credentials[6] = $row->refreshExpire;
  $credentials[7] = $row->accessExpire;

  $date = date("Y-m-d H:i:s", time());
  if($credentials[6] < $date || $credentials[4] == NULL){
    $credentials = refreshToken($credentials, $db);
  } else if($credentials[7] < $date || $credentials[5] == NULL){
    $credentials = refreshAccessToken($credentials, $db);
  }

  return $credentials;
}

//Create access and refresh token
function refreshToken($credentials, $db) {
  $ch = curl_init(APIROOT . "oauth2/token/?grant_type=password&client_id=" . $credentials[0] . "&client_secret=" . $credentials[1] . "&username=" . $credentials[2] . "&password=" . $credentials[3]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $output = curl_exec($ch);
  curl_close ($ch);
  $obj = json_decode($output, true);

  $credentials[4] = $obj['refresh_token'];
  $credentials[5] = $obj['access_token'];
  $refreshTime = date("Y-m-d H:i:s", time()+172800);
  $accessTime = date("Y-m-d H:i:s", time()+3600);

  $db->query('Update tokens Set refreshToken = :refreshToken, accessToken = :accessToken, refreshExpire = :refreshExpire, accessExpire = :accessExpire Where clientId = :clientId');
  $db->bind(':refreshToken', $credentials[4]);
  $db->bind(':accessToken', $credentials[5]);
  $db->bind(':refreshExpire', $refreshTime);
  $db->bind(':accessExpire', $accessTime);
  $db->bind(':clientId', $credentials[0]);
  $db->execute();
  return $credentials;
}

//Refresh access token
function refreshAccessToken($credentials, $db) {
  $url = APIROOT . "oauth2/token/?grant_type=refresh_token&refresh_token=" . $credentials[4] . "&client_id=" . $credentials[0] . "&client_secret=" . $credentials[1];
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $output = curl_exec($ch);
  curl_close ($ch);
  $obj = json_decode($output, true);

  $accessTime = date("Y-m-d H:i:s", time()+3600);
  $credentials[5] = $obj['access_token'];

  $db->query('Update tokens Set accessToken = :accessToken, accessExpire = :accessExpire Where clientId = :clientId');
  $db->bind(':accessToken', $credentials[5]);
  $db->bind(':accessExpire', $accessTime);
  $db->bind(':clientId', $credentials[0]);
  $db->execute();

  return $credentials;
}

?>
