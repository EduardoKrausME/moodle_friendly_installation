<?php

use app\Auth;

require_once __DIR__ . "/app/bootstrap.php";
Auth::logout();
redirect_to("/login.php");
