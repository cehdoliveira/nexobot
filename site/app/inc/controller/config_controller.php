<?php

class config_controller
{
    public function display($info)
    {
        if (!auth_controller::check_login()) {
            basic_redir($GLOBALS["login_url"]);
        }

        $settings = BinanceConfig::load();
        $active = BinanceConfig::getActiveCredentials();
        $flash = $_SESSION['config_status'] ?? null;
        unset($_SESSION['config_status']);

        $configData = [
            'stored' => $settings,
            'active' => $active,
            'flash' => $flash
        ];

        $alpineControllers = ['config'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/config.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function update($info)
    {
        if (!auth_controller::check_login()) {
            basic_redir($GLOBALS["login_url"]);
        }

        $mode = $info['post']['mode'] ?? 'dev';
        $devKey = trim($info['post']['dev_api_key'] ?? '');
        $devSecret = trim($info['post']['dev_api_secret'] ?? '');
        $prodKey = trim($info['post']['prod_api_key'] ?? '');
        $prodSecret = trim($info['post']['prod_api_secret'] ?? '');

        $saved = BinanceConfig::save($mode, $devKey, $devSecret, $prodKey, $prodSecret);

        $_SESSION['config_status'] = [
            'success' => $saved,
            'mode' => $mode,
            'message' => $saved ? 'Configurações salvas com sucesso.' : 'Não foi possível salvar as configurações. Verifique permissões de escrita.',
        ];

        basic_redir($GLOBALS['config_url']);
    }
}
