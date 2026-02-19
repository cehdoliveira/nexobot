<?php

/**
 * Front Controller Principal
 * PHP 8.3+ com PDO e MySQL 8.0
 * 
 * Este arquivo é o ponto de entrada da aplicação
 * Gerencia sessões, rotas e despacho de requisições
 */

// ob_start() ANTES de qualquer output garante que header() e Set-Cookie
// funcionem mesmo que algum include gere bytes acidentais (espaços, BOM, etc.)
ob_start();

// Iniciar sessão com configurações seguras para PHP 8.4
// cookie_secure: força envio do cookie apenas sobre HTTPS (alinhado ao php.ini)
// cookie_samesite Lax: permite cookies em redirects GET de topo (pós-login)
// use_only_cookies: impede que o session_id seja passado via URL
// use_strict_mode REMOVIDO: conflita com session_write_close() explícito no phpredis —
//   sessões ficam como "não inicializadas" e são rejeitadas na próxima requisição.
//   Proteção contra session fixation é feita via session_regenerate_id(true) no login.
session_start([
	'cookie_httponly'  => true,
	'cookie_secure'    => true,
	'cookie_samesite'  => 'Lax',
	'use_only_cookies' => true,
]);

// Configurações de erro — controladas pelo php.ini em produção
// ini_set('display_errors', 1) foi REMOVIDO: em produção erros não devem ser exibidos

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
$authGuard = fn() => auth_controller::check_login();
$dispatcher->add_route("GET", "/(index(\.json|\.xml|\.html)).*?", "function:basic_redir", null, $home_url);

// Login (público)
$dispatcher->add_route("GET", "/login(\.json|\.xml|\.html)?", "auth_controller:display", null, $params);
$dispatcher->add_route("POST", "/login(\.json|\.xml|\.html)?", "auth_controller:login", null, $params);

// Rotas de cadastro
// $dispatcher->add_route("GET", "/cadastro(\.json|\.xml|\.html)?", "auth_controller:display_register", null, $params);
// $dispatcher->add_route("POST", "/cadastro(\.json|\.xml|\.html)?", "auth_controller:register", null, $params);

// Logout
$dispatcher->add_route("GET", "/sair", "auth_controller:logout", null, $params);

// Dashboard (home protegida - requer login)
$dispatcher->add_route("GET", "/?", "site_controller:dashboard", $authGuard, $params);
$dispatcher->add_route("POST", "/?", "site_controller:dashboard", $authGuard, $params);

// Rotas protegidas
$dispatcher->add_route("GET", "/config(\.json|\.xml|\.html)?", "config_controller:display", $authGuard, $params);
$dispatcher->add_route("POST", "/config(\.json|\.xml|\.html)?", "config_controller:update", $authGuard, $params);
$dispatcher->add_route("GET", "/setup", "setup_controller:display", $authGuard, $params);

// Executar dispatcher e tratar falhas
if (!$dispatcher->exec()) {
	basic_redir($home_url);
}
