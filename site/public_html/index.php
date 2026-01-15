<?php

/**
 * Front Controller Principal
 * PHP 8.3+ com PDO e MySQL 8.0
 * 
 * Este arquivo é o ponto de entrada da aplicação
 * Gerencia sessões, rotas e despacho de requisições
 */

// Iniciar sessão com configurações seguras para PHP 8.4
session_start([
	'cookie_httponly' => true,
	'cookie_samesite' => 'Lax',
	'use_strict_mode' => true
]);

// Configurações de erro (DESENVOLVIMENTO)
// TODO: Desabilitar em produção ou usar variável de ambiente
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Carregar dependências principais
require_once($_SERVER["DOCUMENT_ROOT"] . "/../app/inc/main.php");

// Processar logout
if (isset($_GET["logout"]) && $_GET["logout"] == "yes") {
	unset($_SESSION[constant("cAppKey")]);
	session_regenerate_id(true); // Regenerar ID de sessão por segurança
	basic_redir($GLOBALS["home_url"]);
}

// Parâmetros da requisição (PHP 8.4 compatível)
$params = [
	"sr" => isset($_GET["sr"]) && (int)$_GET["sr"] > 1 ? (int)$_GET["sr"] : 0,
	"format" => ".html",
	"post" => $_POST ?? null,
	"get" => $_GET ?? null,
];

// Flags de ação
$btn_save = isset($_POST["btn_save"]) ? true : null;
$btn_remove = isset($_POST["btn_remove"]) ? true : null;

// Variável legacy (manter compatibilidade)
$strCanal = "";

// Inicializar dispatcher de rotas
$dispatcher = new dispatcher(true);

// Definir rotas da aplicação
$dispatcher->add_route("GET", "/(index(\.json|\.xml|\.html)).*?", "function:basic_redir", null, $home_url);

// Login (público)
$dispatcher->add_route("GET", "/login(\.json|\.xml|\.html)?", "auth_controller:display", null, $params);
$dispatcher->add_route("POST", "/login(\.json|\.xml|\.html)?", "auth_controller:login", null, $params);

// Rotas de cadastro
$dispatcher->add_route("GET", "/cadastro(\.json|\.xml|\.html)?", "auth_controller:display_register", null, $params);
$dispatcher->add_route("POST", "/cadastro(\.json|\.xml|\.html)?", "auth_controller:register", null, $params);

// Logout
$dispatcher->add_route("GET", "/sair", "auth_controller:logout", null, $params);

// Dashboard (home protegida - requer login)
$dispatcher->add_route("GET", "/?", "site_controller:dashboard", null, $params);
$dispatcher->add_route("POST", "/?", "site_controller:dashboard", null, $params);

// Rotas protegidas
if (auth_controller::check_login()) {
	$dispatcher->add_route("GET", "/setup", "setup_controller:display", null, $params);
}

// Executar dispatcher e tratar falhas
if (!$dispatcher->exec()) {
	basic_redir($home_url);
}
