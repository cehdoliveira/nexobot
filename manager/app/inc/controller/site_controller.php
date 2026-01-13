<?php

class site_controller
{
    public function display($info)
    {

        if (!auth_controller::check_login()) {
            basic_redir($GLOBALS["login_url"]);
        }

        // Use unified controllers bundle to simplify assets
        $alpineControllers = ['site', 'auth'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/home.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function getUsers($info)
    {
        // Limpar qualquer output anterior
        ob_clean();

        // Definir headers JSON
        header('Content-Type: application/json; charset=utf-8');

        try {
            $users = new users_model();
            $users->load_data();
            $data = $users->data;
            
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit();
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit();
        }
    }
}
