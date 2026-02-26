</head>

<body>
    <header>
        <nav class="navbar navbar-dark nexo-navbar">
            <div class="container-fluid px-3 px-md-4">
                <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo $GLOBALS['home_url']; ?>">
                    <i class="bi bi-robot" style="font-size: 1.2rem;"></i>
                    <span class="fw-semibold" style="font-size: 0.95rem; letter-spacing: -0.01em;">NexoBot</span>
                </a>
                <div class="d-flex align-items-center gap-2">
                    <?php if (auth_controller::check_login()) { ?>
                        <span class="d-none d-sm-inline text-white-50" style="font-size: 0.75rem;">
                            <?php echo htmlspecialchars($_SESSION[constant("cAppKey")]["credential"]["name"] ?? ''); ?>
                        </span>
                        <a class="btn btn-sm btn-outline-light border-0 d-flex align-items-center gap-1" href="<?php echo $GLOBALS['config_url']; ?>"
                           title="Configurações" style="font-size: 0.8rem; padding: 0.25rem 0.5rem;">
                            <i class="bi bi-gear"></i>
                            <span class="d-none d-md-inline">Config</span>
                        </a>
                        <a class="btn btn-sm btn-outline-danger border-0 d-flex align-items-center gap-1" href="<?php echo $GLOBALS['logout_url']; ?>"
                           title="Sair" style="font-size: 0.8rem; padding: 0.25rem 0.5rem;">
                            <i class="bi bi-box-arrow-right"></i>
                            <span class="d-none d-md-inline">Sair</span>
                        </a>
                    <?php } else { ?>
                        <a class="btn btn-sm btn-outline-light border-0" href="<?php echo $GLOBALS['login_url']; ?>">
                            <i class="bi bi-box-arrow-in-right"></i> Entrar
                        </a>
                    <?php } ?>
                </div>
            </div>
        </nav>
    </header>

    <main id="mainContent" class="flex-shrink-0">