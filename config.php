<?php
include 'mysql.class.php';

$config = array();
$config['host'] = 'localhost';
$config['user'] = 'root';
$config['pass'] = 'root';
$config['table'] = 'example';

$DB = new DB($config);

session_start();

?>