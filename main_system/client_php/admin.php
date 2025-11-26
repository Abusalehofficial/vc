<?php


if (($admin["admin_type"] == 2 || $admin["admin_type"] == 3 || $admin["admin_type"] == 4)
  && $_SESSION["msmbilisim_adminlogin"] && $admin["client_type"] == 2
) :
  if (!route(1)) {
    $route[1] = "index";
  }
  
  

  if (!file_exists(admin_controller(route(1)))) {
    include FILES_BASE . '/admin/controller/404.php';
    exit();
    $route[1] = "index";
  }
  require admin_controller(route(1));
else :

  $route[1] = "login";
  require admin_controller(route(1));
endif;
