</head>

<body>
    <header>
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom">
            <div class="container">
                <a class="navbar-brand" href="<?php echo $GLOBALS['home_url']; ?>">NexoBot</a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="mainNav">
                    <div class="d-flex align-items-center ms-auto">
                        <?php if (auth_controller::check_login()) { ?>
                            <a class="btn btn-outline-danger" href="<?php echo $GLOBALS['logout_url']; ?>"><i class="bi bi-box-arrow-in-right"></i> Sair</a>
                        <?php } else { ?>
                            <a class="btn btn-outline-light" href="<?php echo $GLOBALS['login_url']; ?>"><i class="bi bi-box-arrow-in-right"></i> Entrar</a>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </nav>
    </header>

    <main id="mainContent" class="flex-shrink-0">