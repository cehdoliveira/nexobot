</head>

<body>
    <header>
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom">
            <div class="container">
                <a class="navbar-brand" href="<?php echo $GLOBALS['home_url']; ?>">Nexo</a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item"><a class="nav-link" href="#">Home</a></li>
                        <li class="nav-item d-none d-lg-block"><a class="nav-link" href="#sobre">Sobre</a></li>
                        <li class="nav-item d-none d-lg-block"><a class="nav-link" href="#servicos">Serviços</a></li>
                        <li class="nav-item d-none d-lg-block"><a class="nav-link" href="#contato">Contato</a></li>
                    </ul>

                    <div class="d-flex align-items-center">
                        <?php if (function_exists('auth_controller') && auth_controller::check_login()) { ?>
                            <?php $user = $_SESSION[constant("cAppKey")]["credential"]; ?>
                            <div class="dropdown">
                                <a class="btn btn-outline-primary dropdown-toggle" href="#" role="button" id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
                                    <?php echo htmlspecialchars($user['name'] ?? $user['login'] ?? 'Usuário'); ?>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                                    <li><a class="dropdown-item" href="<?php echo $GLOBALS['home_url']; ?>">Meu Painel</a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                    <li><a class="dropdown-item" href="<?php echo $GLOBALS['logout_url']; ?>">Sair</a></li>
                                </ul>
                            </div>
                        <?php } else { ?>
                            <a class="btn btn-link text-white me-2" href="<?php echo $GLOBALS['login_url']; ?>">Entrar</a>
                            <a class="btn btn-primary" href="<?php echo $GLOBALS['register_url']; ?>">Cadastre-se</a>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </nav>
    </header>

    <main id="mainContent" class="flex-shrink-0">