<div class="nexo-page-wrapper">
    <div class="container-fluid px-3 px-md-4 py-4" style="max-width: 1100px;">

        <?php
        $stored = $configData['stored'] ?? [];
        $active = $configData['active'] ?? [];
        $flash = $configData['flash'] ?? null;
        $currentMode = $active['mode'] ?? 'dev';
        ?>

        <!-- Page Header -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 mb-4">
            <div>
                <h1 class="fs-5 fw-bold mb-0" style="color: var(--dash-text);">
                    <i class="bi bi-gear"></i> Configurações
                </h1>
                <p class="mb-0 small" style="color: var(--dash-text-muted);">
                    Ambiente e credenciais de API da Binance
                </p>
            </div>
            <a class="nexo-btn-outline d-flex align-items-center gap-1" href="<?php echo $GLOBALS['home_url']; ?>">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
        </div>

        <!-- Flash Message -->
        <?php if ($flash): ?>
            <div class="nexo-alert <?php echo ($flash['success'] ?? false) ? 'nexo-alert-success' : 'nexo-alert-danger'; ?> mb-4">
                <i class="bi <?php echo ($flash['success'] ?? false) ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?>"></i>
                <?php echo htmlspecialchars($flash['message'] ?? ''); ?>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <!-- Main Form -->
            <div class="col-12 col-lg-8">
                <div class="dash-card">
                    <div class="card-header-custom">
                        <h6><i class="bi bi-hdd-network"></i> Ambiente Binance</h6>
                        <span class="badge <?php echo $currentMode === 'prod' ? 'bg-danger' : 'bg-secondary'; ?>" style="font-size: 0.65rem;">
                            <?php echo $currentMode === 'prod' ? 'PRODUÇÃO' : 'DEV'; ?>
                        </span>
                    </div>
                    <div class="card-body-custom">
                        <form method="post" action="<?php echo $GLOBALS['config_url']; ?>">

                            <!-- Environment Selector -->
                            <div class="mb-4">
                                <label class="nexo-form-label fw-semibold">Selecione o ambiente</label>
                                <div class="nexo-env-selector">
                                    <label class="nexo-env-option <?php echo ($stored['mode'] ?? 'dev') === 'dev' ? 'active' : ''; ?>">
                                        <input type="radio" name="mode" value="dev"
                                            <?php echo ($stored['mode'] ?? 'dev') === 'dev' ? 'checked' : ''; ?>
                                            onchange="toggleCredentialFields(); this.closest('.nexo-env-selector').querySelectorAll('.nexo-env-option').forEach(e => e.classList.remove('active')); this.closest('.nexo-env-option').classList.add('active');">
                                        <i class="bi bi-shield-check"></i>
                                        <div>
                                            <span class="d-block fw-semibold" style="font-size: 0.85rem;">Testnet</span>
                                            <small style="color: var(--dash-text-muted); font-size: 0.7rem;">Ambiente de desenvolvimento</small>
                                        </div>
                                    </label>
                                    <label class="nexo-env-option <?php echo ($stored['mode'] ?? '') === 'prod' ? 'active' : ''; ?>">
                                        <input type="radio" name="mode" value="prod"
                                            <?php echo ($stored['mode'] ?? '') === 'prod' ? 'checked' : ''; ?>
                                            onchange="toggleCredentialFields(); this.closest('.nexo-env-selector').querySelectorAll('.nexo-env-option').forEach(e => e.classList.remove('active')); this.closest('.nexo-env-option').classList.add('active');">
                                        <i class="bi bi-lightning-charge-fill" style="color: var(--dash-danger);"></i>
                                        <div>
                                            <span class="d-block fw-semibold" style="font-size: 0.85rem;">Produção</span>
                                            <small style="color: var(--dash-text-muted); font-size: 0.7rem;">API Binance real</small>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <hr style="border-color: var(--dash-border);">

                            <!-- Dev Credentials -->
                            <div id="devCredentials" class="mb-4" style="display: <?php echo ($stored['mode'] ?? 'dev') === 'dev' ? 'block' : 'none'; ?>">
                                <h6 class="d-flex align-items-center gap-2 mb-3" style="color: var(--dash-info); font-size: 0.85rem;">
                                    <i class="bi bi-shield-check"></i> Credenciais Testnet
                                </h6>

                                <div class="mb-3">
                                    <label for="devApiKey" class="nexo-form-label">API Key</label>
                                    <input type="text" class="nexo-form-input font-mono" id="devApiKey" name="dev_api_key"
                                        placeholder="Insira a API Key de Testnet"
                                        value="<?php echo htmlspecialchars($stored['dev_api_key'] ?? ''); ?>">
                                    <small style="color: var(--dash-text-muted); font-size: 0.7rem;">Se vazio, usará as chaves do kernel.</small>
                                </div>

                                <div class="mb-3">
                                    <label for="devApiSecret" class="nexo-form-label">API Secret</label>
                                    <input type="password" class="nexo-form-input" id="devApiSecret" name="dev_api_secret"
                                        placeholder="Insira o API Secret de Testnet" value="">
                                    <small style="color: var(--dash-text-muted); font-size: 0.7rem;">Deixe em branco para manter o atual.</small>
                                </div>
                            </div>

                            <!-- Prod Credentials -->
                            <div id="prodCredentials" class="mb-4" style="display: <?php echo ($stored['mode'] ?? '') === 'prod' ? 'block' : 'none'; ?>">
                                <h6 class="d-flex align-items-center gap-2 mb-3" style="color: var(--dash-danger); font-size: 0.85rem;">
                                    <i class="bi bi-exclamation-triangle-fill"></i> Credenciais Produção
                                </h6>

                                <div class="nexo-alert nexo-alert-warning mb-3" style="font-size: 0.8rem;">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    Credenciais de produção realizam trades reais. Use com máxima segurança!
                                </div>

                                <div class="mb-3">
                                    <label for="prodApiKey" class="nexo-form-label">API Key</label>
                                    <input type="text" class="nexo-form-input font-mono" id="prodApiKey" name="prod_api_key"
                                        placeholder="Insira a API Key de produção"
                                        value="<?php echo htmlspecialchars($stored['prod_api_key'] ?? ''); ?>">
                                    <small style="color: var(--dash-text-muted); font-size: 0.7rem;">Chave gerada na sua conta Binance.</small>
                                </div>

                                <div class="mb-3">
                                    <label for="prodApiSecret" class="nexo-form-label">API Secret</label>
                                    <input type="password" class="nexo-form-input" id="prodApiSecret" name="prod_api_secret"
                                        placeholder="Insira o API Secret de produção" value="">
                                    <small style="color: var(--dash-text-muted); font-size: 0.7rem;">Deixe em branco para manter o atual.</small>
                                </div>
                            </div>

                            <button type="submit" class="nexo-btn-primary d-flex align-items-center gap-2">
                                <i class="bi bi-save"></i> Salvar configurações
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Status Sidebar -->
            <div class="col-12 col-lg-4">
                <div class="dash-card">
                    <div class="card-header-custom">
                        <h6><i class="bi bi-info-circle"></i> Status Atual</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="mb-3">
                            <div class="nexo-form-label mb-1">Ambiente ativo</div>
                            <span class="badge <?php echo $currentMode === 'prod' ? 'bg-danger' : 'bg-secondary'; ?>" style="font-size: 0.75rem; padding: 0.35rem 0.65rem;">
                                <?php echo $currentMode === 'prod' ? 'Produção' : 'Desenvolvimento'; ?>
                            </span>
                        </div>

                        <hr style="border-color: var(--dash-border);">

                        <div class="mb-3">
                            <div class="nexo-form-label mb-1">Endpoint REST</div>
                            <code class="d-block small" style="color: var(--dash-info); word-break: break-all; font-size: 0.7rem;">
                                <?php echo htmlspecialchars($active['restBaseUrl'] ?? 'Não configurado'); ?>
                            </code>
                        </div>

                        <div class="mb-0">
                            <div class="nexo-form-label mb-1">Chave em uso</div>
                            <span class="font-mono" style="color: var(--dash-text-muted); font-size: 0.8rem;">
                                •••<?php echo substr($active['apiKey'] ?? '', -6); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Quick Help -->
                <div class="dash-card mt-3">
                    <div class="card-body-custom">
                        <h6 class="mb-2" style="font-size: 0.8rem; color: var(--dash-text);">
                            <i class="bi bi-question-circle"></i> Ajuda rápida
                        </h6>
                        <ul class="mb-0" style="font-size: 0.75rem; color: var(--dash-text-muted); padding-left: 1.25rem; line-height: 1.8;">
                            <li><strong>Testnet:</strong> Para testes sem risco financeiro</li>
                            <li><strong>Produção:</strong> Opera com dinheiro real</li>
                            <li>API Keys podem ser geradas na <a href="https://www.binance.com/en/my/settings/api-management" target="_blank" style="color: var(--dash-info);">Binance</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleCredentialFields() {
        const devCreds = document.getElementById('devCredentials');
        const prodCreds = document.getElementById('prodCredentials');
        const mode = document.querySelector('input[name="mode"]:checked').value;

        if (mode === 'prod') {
            devCreds.style.display = 'none';
            prodCreds.style.display = 'block';
        } else {
            devCreds.style.display = 'block';
            prodCreds.style.display = 'none';
        }
    }
</script>