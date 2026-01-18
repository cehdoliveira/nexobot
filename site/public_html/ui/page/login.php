<div class="container d-flex align-items-center justify-content-center" style="min-height: calc(100vh - 140px);">
    <div class="row w-100">
        <div class="col-12 col-sm-10 col-md-8 col-lg-5 mx-auto">
            <!-- Card de Login -->
            <div class="card shadow-sm border-0">
                <div class="card-body p-3 p-md-5">
                    <!-- Logo/Título -->
                    <div class="text-center mb-4">
                        <i class="bi bi-person-circle text-primary" style="font-size: 4rem;"></i>
                        <h3 class="mt-3 mb-2">Bem-vindo!!</h3>
                        <p class="text-muted">Faça login para continuar</p>
                    </div>

                    <!-- Formulário de Login com Alpine.js -->
                    <form x-data="loginController" @submit.prevent="handleSubmit" method="POST" action="<?php print($GLOBALS["login_url"]); ?>">
                        <!-- Campo Login -->
                        <div class="mb-3">
                            <label for="login" class="form-label">
                                <i class="bi bi-person"></i> Login / E-mail / CPF
                            </label>
                            <input
                                type="text"
                                class="form-control form-control-lg"
                                id="login"
                                name="login"
                                placeholder="Digite seu login, e-mail ou CPF"
                                x-model="formData.login"
                                :class="{'is-invalid': errors.login}"
                                required
                                autofocus>
                            <div class="invalid-feedback" x-show="errors.login" x-text="errors.login"></div>
                        </div>

                        <!-- Campo Senha -->
                        <div class="mb-4">
                            <label for="password" class="form-label">
                                <i class="bi bi-lock"></i> Senha
                            </label>
                            <div class="input-group">
                                <input
                                    :type="showPassword ? 'text' : 'password'"
                                    class="form-control form-control-lg"
                                    id="password"
                                    name="password"
                                    placeholder="Digite sua senha"
                                    x-model="formData.password"
                                    :class="{'is-invalid': errors.password}"
                                    required>
                                <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    :title="showPassword ? 'Ocultar senha' : 'Mostrar senha'">
                                    <i :class="showPassword ? 'bi-eye-slash' : 'bi-eye'"></i>
                                </button>
                                <div class="invalid-feedback" x-show="errors.password" x-text="errors.password"></div>
                            </div>
                        </div>

                        <!-- Lembrar-me e Esqueci Senha -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="remember"
                                    x-model="formData.remember">
                                <label class="form-check-label" for="remember">
                                    Lembrar-me
                                </label>
                            </div>
                            <a href="<?php print($GLOBALS["password_url"]); ?>" class="text-decoration-none small">
                                Esqueci minha senha
                            </a>
                        </div>

                        <!-- Botão Submit -->
                        <div class="d-grid gap-2">
                            <button
                                type="submit"
                                class="btn btn-primary btn-lg"
                                :disabled="isSubmitting">
                                <span x-show="!isSubmitting">
                                    <i class="bi bi-box-arrow-in-right"></i> Entrar
                                </span>
                                <span x-show="isSubmitting">
                                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                    Entrando...
                                </span>
                            </button>
                        </div>
                    </form>

                    <!-- Mensagens de Erro do Backend -->
                    <?php if (isset($_SESSION["messages_app"]["danger"])) { ?>
                        <div class="alert alert-danger mt-3 mb-0" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php
                            print(implode('<br>', $_SESSION["messages_app"]["danger"]));
                            unset($_SESSION["messages_app"]["danger"]);
                            ?>
                        </div>
                    <?php } ?>

                    <?php if (isset($_SESSION["messages_app"]["success"])) { ?>
                        <div class="alert alert-success mt-3 mb-0" role="alert">
                            <i class="bi bi-check-circle-fill"></i>
                            <?php
                            print(implode('<br>', $_SESSION["messages_app"]["success"]));
                            unset($_SESSION["messages_app"]["success"]);
                            ?>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <!-- Informações Adicionais -->
            <div class="text-center mt-4">
                <p class="text-muted small">
                    <i class="bi bi-shield-check"></i> Suas informações estão protegidas
                </p>
                <!-- <p class="mt-2">
                    <a href="<?php //print($GLOBALS["register_url"]); ?>" class="text-decoration-none">Não possui conta? Cadastre-se</a>
                </p> -->
            </div>
        </div>
    </div>
</div>