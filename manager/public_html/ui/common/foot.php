    <!-- Bootstrap 5.3.3 JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Alpine.js Controllers - Carregamento Dinâmico -->
    <?php
    // Verificar se existem controllers definidos para esta página
    if (isset($alpineControllers) && is_array($alpineControllers) && count($alpineControllers) > 0) {
        foreach ($alpineControllers as $controller) {
            print('<script src="' . constant('cFrontend') . 'assets/js/alpine/' . $controller . 'Controller.js"></script>' . "\n    ");
        }
    }
    ?>

    <!-- Alpine.js 3.x -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Custom JS -->
    <script src="<?php printf("%s%s", constant("cFrontend"), "assets/js/main.js"); ?>"></script>
</body>

</html>