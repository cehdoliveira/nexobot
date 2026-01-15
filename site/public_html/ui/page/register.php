<div class="container register-page d-flex align-items-start align-items-md-center justify-content-center">
    <div class="row w-100">
        <div class="col-12 col-sm-10 col-md-8 col-lg-6 mx-auto">
            <div class="card shadow-sm border-0">
                <div class="card-body p-3 p-md-5">
                    <div class="text-center mb-4">
                        <i class="bi bi-person-plus text-primary" style="font-size: 3.5rem;"></i>
                        <h3 class="mt-3 mb-2">Crie sua conta</h3>
                        <p class="text-muted">Preencha os dados para realizar o cadastro</p>
                    </div>

                    <form x-data="registerController" @submit.prevent="handleSubmit($event)" method="POST" action="<?php echo $GLOBALS['register_url']; ?>">
                        <div class="mb-3">
                            <label class="form-label">Nome</label>
                            <input id="name" type="text" name="name" x-model="formData.name" :class="{'is-invalid': errors.name}" class="form-control form-control-lg" required>
                            <div class="invalid-feedback" x-text="errors.name" x-show="errors.name"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">E-mail</label>
                            <input id="mail" type="email" name="mail" x-model="formData.mail" :class="{'is-invalid': errors.mail}" class="form-control form-control-lg" required>
                            <div class="invalid-feedback" x-text="errors.mail" x-show="errors.mail"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Login</label>
                            <input id="login" type="text" name="login" x-model="formData.login" :class="{'is-invalid': errors.login}" class="form-control form-control-lg" required>
                            <div class="invalid-feedback" x-text="errors.login" x-show="errors.login"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">CPF</label>
                            <input id="cpf" type="text" name="cpf" x-model="formData.cpf" @input="onCpfInput($event)" maxlength="14" placeholder="000.000.000-00" :class="{'is-invalid': errors.cpf}" class="form-control form-control-lg">
                            <div class="invalid-feedback" x-text="errors.cpf" x-show="errors.cpf"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Telefone</label>
                            <input id="phone" type="text" name="phone" x-model="formData.phone" @input="onPhoneInput($event)" maxlength="15" placeholder="(00) 00000-0000" :class="{'is-invalid': errors.phone}" class="form-control form-control-lg">
                            <div class="invalid-feedback" x-text="errors.phone" x-show="errors.phone"></div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Senha</label>
                            <input id="password" type="password" name="password" x-model="formData.password" :class="{'is-invalid': errors.password}" class="form-control form-control-lg" required>
                            <div class="invalid-feedback" x-text="errors.password" x-show="errors.password"></div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">Cadastrar</button>
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

                    <?php if (isset($_SESSION["messages_app"]["success"])) { ?>
                        <div class="alert alert-success mt-3 mb-0" role="alert">
                            <?php
                            print(implode('<br>', $_SESSION["messages_app"]["success"]));
                            unset($_SESSION["messages_app"]["success"]);
                            ?>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <div class="text-center mt-4">
                <a href="<?php print($GLOBALS["login_url"]); ?>" class="text-decoration-none">Já possui conta? Entrar</a>
            </div>
        </div>
    </div>
</div>