<?php
ini_set('display_errors', 1);
//Config
require_once 'helpers/config.php';
require_once("libraries/vendor/autoload.php");
require_once 'helpers/helper.php';

// Autoload Core Libraries
spl_autoload_register(function($className){
  require_once 'libraries/'. $className . '.php';
});
