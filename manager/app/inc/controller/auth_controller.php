<?php

class auth_controller
{
    public static function check_login()
    {
        if (!isset($_SESSION[constant("cAppKey")]["credential"]["idx"])) {
            return false;
        } else {
            return true;
        }
    }

    public function logout()
    {
        unset($_SESSION[constant("cAppKey")]);
        basic_redir($GLOBALS["login_url"] ?? $GLOBALS["home_url"]);
    }

    public function login($info)
    {
        if (isset($info["post"]["login"]) && isset($info["post"]["password"])) {
            $users = new users_model();
            $users->set_filter(["enabled = 'yes'", " ( '" . ($info["post"]["login"] . "' IN (mail,login) or '" .  $info["post"]["login"]) . "' = cpf ) ", " password = md5( '" . $info["post"]["password"] . "') " ]);
            $users->set_paginate([1]);
            $users->load_data();

            if (isset($users->data[0]["idx"])) {
                $_SESSION[constant("cAppKey")] = ["credential" => current($users->data)];
                $users->set_filter(["idx = '" .  $_SESSION[constant("cAppKey")]["credential"]["idx"]  . "' "]);
                $users->populate(["last_login" => date("Y-m-d H:i:s")]);
                $users->save();
            } else {
                $_SESSION["messages_app"]["danger"] = ["Login e/ou Senha informados não conferem"];
            }
        } else {
            $_SESSION["messages_app"]["danger"] = ["Login e/ou Senha são obrigatórios para realizar o login"];
        }

        basic_redir($GLOBALS["home_url"]);
        exit();
    }

    public function display($info)
    {
        // Carregar bundle único de controllers Alpine
        $alpineControllers = ['auth'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/page/login.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}
