<?php

 


unset($_SESSION["msmbilisim_userid"]);
unset($_SESSION["msmbilisim_userpass"]);
unset($_SESSION["msmbilisim_userlogin"]);
unset($_SESSION["popSeen"]);
unset($_SESSION["popCount"]);
setcookie("_user", "", time() - (60 * 60 * 24 * 7), '/', null, null, true);
setcookie("_user_token", "", time() - (60 * 60 * 24 * 7), '/', null, null, true);

// session_destroy();

Header("Location:" . site_url(''));
