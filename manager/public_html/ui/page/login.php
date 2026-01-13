<div class="auth-hero">
    <div class="container">
        <div class="row w-100">
            <div class="col-12 col-sm-10 col-md-8 col-lg-5 mx-auto">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-3 p-md-4" x-data="loginController">
                        <div class="text-center mb-3">
                            <i class="bi bi-person-circle text-primary" style="font-size: 3.2rem;"></i>
                            <h4 class="mt-2 mb-1">Área Administrativa</h4>
                            <p class="text-muted small">Faça login para continuar</p>
                        </div>

                        <form method="POST" action="<?php printf("%s%s", $GLOBALS["home_url"], "login"); ?>" @submit.prevent="handleSubmit">
                            <div class="mb-3">
                                <label for="login" class="form-label">Login / E-mail / CPF</label>
                                <input x-model="formData.login" type="text" class="form-control form-control-lg" id="login" name="login" required autofocus>
                            </div>

                            <div class="mb-4">
                                <label for="password" class="form-label">Senha</label>
                                <input x-model="formData.password" type="password" class="form-control form-control-lg" id="password" name="password" required>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">Entrar</button>
                            </div>
                        </form>

                        <?php if (isset($_SESSION["messages_app"]["danger"])) { ?>
                            <div class="alert alert-danger mt-3 mb-0" role="alert">
                                <?php
                                print(implode('<br>', $_SESSION["messages_app"]["danger"]));
                                unset($_SESSION["messages_app"]["danger"]);
                                ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>