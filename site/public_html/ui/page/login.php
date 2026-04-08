<div class="nexo-page-wrapper">
    <div class="nexo-page-centered">
        <div class="nexo-login-container">

            <!-- Login Card -->
            <div class="dash-card">
                <div class="card-body-custom p-4 p-md-5">

                    <!-- Logo -->
                    <div class="text-center mb-4">
                        <div class="nexo-login-icon">
                            <i class="bi bi-robot"></i>
                        </div>
                        <h4 class="fw-bold mt-3 mb-1" style="color: var(--dash-text);">Driftex</h4>
                        <p class="small" style="color: var(--dash-text-muted);">Faça login para acessar o painel</p>
                    </div>

                    <!-- Formulário de Login com Alpine.js -->
                    <form x-data="loginController" @submit.prevent="handleSubmit" method="POST" action="<?php print($GLOBALS["login_url"]); ?>">

                        <!-- Campo Login -->
                        <div class="mb-3">
                            <label for="login" class="nexo-form-label">
                                <i class="bi bi-person"></i> Login / E-mail
                            </label>
                            <input
                                type="text"
                                class="nexo-form-input"
                                id="login"
                                name="login"
                                placeholder="Digite seu login ou e-mail"
                                x-model="formData.login"
                                :class="{'is-invalid': errors.login}"
                                required
                                autofocus>
                            <div class="invalid-feedback" x-show="errors.login" x-text="errors.login"></div>
                        </div>

                        <!-- Campo Senha -->
                        <div class="mb-4">
                            <label for="password" class="nexo-form-label">
                                <i class="bi bi-lock"></i> Senha
                            </label>
                            <div class="nexo-input-group">
                                <input
                                    :type="showPassword ? 'text' : 'password'"
                                    class="nexo-form-input"
                                    id="password"
                                    name="password"
                                    placeholder="Digite sua senha"
                                    x-model="formData.password"
                                    :class="{'is-invalid': errors.password}"
                                    required>
                                <button
                                    class="nexo-input-addon"
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    :title="showPassword ? 'Ocultar senha' : 'Mostrar senha'">
                                    <i :class="showPassword ? 'bi-eye-slash' : 'bi-eye'"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback" x-show="errors.password" x-text="errors.password" style="display:block;"></div>
                        </div>

                        <!-- Botão Submit -->
                        <button
                            type="submit"
                            class="nexo-btn-primary w-100"
                            :disabled="isSubmitting">
                            <span x-show="!isSubmitting">
                                <i class="bi bi-box-arrow-in-right"></i> Entrar
                            </span>
                            <span x-show="isSubmitting">
                                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                Entrando...
                            </span>
                        </button>
                    </form>

                    <!-- Mensagens de Erro do Backend -->
                    <?php if (isset($_SESSION["messages_app"]["danger"])) { ?>
                        <div class="nexo-alert nexo-alert-danger mt-3">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php
                            print(implode('<br>', $_SESSION["messages_app"]["danger"]));
                            unset($_SESSION["messages_app"]["danger"]);
                            ?>
                        </div>
                    <?php } ?>

                    <?php if (isset($_SESSION["messages_app"]["success"])) { ?>
                        <div class="nexo-alert nexo-alert-success mt-3">
                            <i class="bi bi-check-circle-fill"></i>
                            <?php
                            print(implode('<br>', $_SESSION["messages_app"]["success"]));
                            unset($_SESSION["messages_app"]["success"]);
                            ?>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <!-- Security Note -->
            <div class="text-center mt-3">
                <small style="color: var(--dash-text-secondary); font-size: 0.7rem;">
                    <i class="bi bi-shield-check"></i> Conexão segura
                </small>
            </div>
        </div>
    </div>
</div>