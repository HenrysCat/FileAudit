<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

logout();

header('Location: /login.php');
exit;
