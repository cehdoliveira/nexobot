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
        basic_redir($GLOBALS["login_url"]);
    }

    public function login($info)
    {
        if (isset($info["post"]["login"]) && isset($info["post"]["password"])) {
            $users = new users_model();
            $users->set_filter(["enabled = 'yes'", " ( '" . ($info["post"]["login"] . "' IN (mail,login) or '" .  $info["post"]["login"]) . "' = cpf ) ", " password = md5( '" . $info["post"]["password"] . "') "]);
            $users->set_paginate([1]);
            $users->load_data();

            if (isset($users->data[0]["idx"])) {
                // $users->attach(array("profiles"), false, null, array("idx", "name", "adm", "slug"));
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

    public function display_register($info)
    {
        // Definir controllers Alpine.js necessários para esta página
        $alpineControllers = ['registerController'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/register.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function register($info)
    {
        if (!isset($info["post"])) {
            $_SESSION["messages_app"]["danger"] = ["Dados de cadastro inválidos"];
            basic_redir($GLOBALS["register_url"]);
            exit();
        }

        // Validações básicas
        $required = ['name', 'mail', 'password', 'login'];
        foreach ($required as $r) {
            if (!isset($info["post"][$r]) || trim($info["post"][$r]) === "") {
                $_SESSION["messages_app"]["danger"] = ["Campo $r é obrigatório"];
                basic_redir($GLOBALS["register_url"]);
                exit();
            }
        }

        // Preparar CPF limpo para comparação
        $info["post"]['cpf'] = isset($info["post"]['cpf']) ? sanitize_string($info["post"]['cpf'], true) : '';

        // Verificar existência por email/login/cpf
        $users = new users_model();
        $users->set_filter([" active = 'yes' ", " ( mail = '" . $info["post"]['mail'] . "' OR login = '" . $info["post"]['login'] . "' OR cpf = '" . $info["post"]['cpf'] . "' ) "]);
        $users->set_paginate([1]);
        $users->load_data();

        if (isset($users->data[0]['idx'])) {
            $_SESSION["messages_app"]["danger"] = ["Já existe um usuário com esse e-mail/login/CPF"];
            basic_redir($GLOBALS["register_url"]);
            exit();
        }

        $info["post"]["password"] = md5($info["post"]["password"]);
        $info["post"]['cpf'] = isset($info["post"]['cpf']) ? sanitize_string($info["post"]['cpf'], true) : null;

        // Criar novo usuário
        $newUser = new users_model();
        $newUser->populate($info["post"]);
        $idx = $newUser->save();

        if ($idx > 0) {
            // Enviar email com dados de acesso (assíncrono via Kafka)
            try {
                if (class_exists('EmailProducer')) {
                    $producer = EmailProducer::getInstance();
                    
                    $subject = "Seus dados de acesso";
                    $body = "Olá " . htmlspecialchars($info["post"]['name']) . ",<br><br>Seu cadastro foi realizado com sucesso.<br><br>Login: " . htmlspecialchars($info["post"]['login']) . "<br>Senha: " . htmlspecialchars($info["post"]["password"]) . "<br><br>Atenciosamente,<br>Equipe";
                    
                    $producer->send($info["post"]['mail'], $subject, $body);
                }
            } catch (Exception $e) {
                error_log('Erro ao enfileirar email de cadastro: ' . $e->getMessage());
            }

            $_SESSION["messages_app"]["success"] = ["Cadastro realizado com sucesso. Verifique seu e-mail com os dados de acesso."];
            basic_redir($GLOBALS["login_url"]);
            exit();
        } else {
            $_SESSION["messages_app"]["danger"] = ["Falha ao criar usuário. Tente novamente mais tarde."];
            basic_redir($GLOBALS["register_url"]);
            exit();
        }
    }

    public function display($info)
    {

        // Definir controllers Alpine.js necessários para esta página
        $alpineControllers = ['loginController'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/login.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}
