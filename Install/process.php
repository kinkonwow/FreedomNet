<?php
require_once('header.php');
use Core\Libraries\FreedomCore\System\Text as Text;

echo "<pre>";
$Databases = $Installer->getDatabases();
$Installer->processInput(json_decode($Databases, true), $_REQUEST);

header('Location: /Install/database');