<?php
require_once("./yudomi.php");

$yudomi = new Yudomi();

// 定期POST
$yudomi->lovecall();
$yudomi->response();