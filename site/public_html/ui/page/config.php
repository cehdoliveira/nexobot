<div class="container py-4">
    <div class="row mb-3">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="mb-1"><i class="bi bi-gear"></i> Configurações</h2>
                <p class="text-muted mb-0">Defina o ambiente e as credenciais de API da Binance.</p>
            </div>
            <a class="btn btn-outline-secondary" href="<?php echo $GLOBALS['home_url']; ?>">
                <i class="bi bi-arrow-left"></i> Voltar ao Dashboard
            </a>
        </div>
    </div>

    <?php
        $stored = $configData['stored'] ?? [];
        $active = $configData['active'] ?? [];
        $flash = $configData['flash'] ?? null;
        $currentMode = $active['mode'] ?? 'dev';
    ?>

    <?php if ($flash): ?>
        <div class="alert <?php echo ($flash['success'] ?? false) ? 'alert-success' : 'alert-danger'; ?>" role="alert">
            <strong><?php echo ($flash['success'] ?? false) ? 'Sucesso' : 'Atenção'; ?>:</strong>
            <?php echo htmlspecialchars($flash['message'] ?? ''); ?>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-hdd-network"></i> Ambiente Binance</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="<?php echo $GLOBALS['config_url']; ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Selecione o ambiente</label>
                            <div class="d-flex gap-3 flex-wrap">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="mode" id="modeDev" value="dev" <?php echo ($stored['mode'] ?? 'dev') === 'dev' ? 'checked' : ''; ?> onchange="toggleCredentialFields()">
                                    <label class="form-check-label" for="modeDev">
                                        Desenvolvimento (Testnet)
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="mode" id="modeProd" value="prod" <?php echo ($stored['mode'] ?? '') === 'prod' ? 'checked' : ''; ?> onchange="toggleCredentialFields()">
                                    <label class="form-check-label" for="modeProd">
                                        Produção
                                    </label>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2">
                                Você pode usar chaves de teste (Testnet) ou de produção (API Binance real).
                            </small>
                        </div>

                        <hr>

                        <!-- Credenciais Desenvolvimento -->
                        <div id="devCredentials" class="mb-4" style="display: <?php echo ($stored['mode'] ?? 'dev') === 'dev' ? 'block' : 'none'; ?>">
                            <h6 class="text-primary mb-3"><i class="bi bi-shield-check"></i> Credenciais Desenvolvimento (Testnet)</h6>
                            
                            <div class="mb-3">
                                <label for="devApiKey" class="form-label">API Key (Desenvolvimento)</label>
                                <input type="text" class="form-control" id="devApiKey" name="dev_api_key" placeholder="Insira a API Key de Testnet" value="<?php echo htmlspecialchars($stored['dev_api_key'] ?? ''); ?>">
                                <small class="text-muted">Se deixar em branco, usará as chaves configuradas no kernel.</small>
                            </div>

                            <div class="mb-3">
                                <label for="devApiSecret" class="form-label">API Secret (Desenvolvimento)</label>
                                <input type="password" class="form-control" id="devApiSecret" name="dev_api_secret" placeholder="Insira o API Secret de Testnet" value="">
                                <small class="text-muted">Deixe em branco para manter o secreto já salvo.</small>
                            </div>
                        </div>

                        <!-- Credenciais Produção -->
                        <div id="prodCredentials" class="mb-4" style="display: <?php echo ($stored['mode'] ?? '') === 'prod' ? 'block' : 'none'; ?>">
                            <h6 class="text-danger mb-3"><i class="bi bi-exclamation-triangle-fill"></i> Credenciais Produção (API Real)</h6>
                            
                            <div class="alert alert-warning" role="alert">
                                <strong>Atenção:</strong> As credenciais de produção são utilizadas para realizar trades reais. Trate-as com máxima segurança!
                            </div>

                            <div class="mb-3">
                                <label for="prodApiKey" class="form-label">API Key (Produção)</label>
                                <input type="text" class="form-control" id="prodApiKey" name="prod_api_key" placeholder="Insira a API Key de produção" value="<?php echo htmlspecialchars($stored['prod_api_key'] ?? ''); ?>">
                                <small class="text-muted">Chave gerada na sua conta Binance.</small>
                            </div>

                            <div class="mb-3">
                                <label for="prodApiSecret" class="form-label">API Secret (Produção)</label>
                                <input type="password" class="form-control" id="prodApiSecret" name="prod_api_secret" placeholder="Insira o API Secret de produção" value="">
                                <small class="text-muted">Deixe em branco para manter o secreto já salvo.</small>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Salvar configurações
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> Status atual</h6>
                </div>
                <div class="card-body">
                    <p class="mb-2">Ambiente ativo:</p>
                    <span class="badge <?php echo $currentMode === 'prod' ? 'bg-danger' : 'bg-secondary'; ?>">
                        <?php echo strtoupper($currentMode === 'prod' ? 'Produção' : 'Desenvolvimento'); ?>
                    </span>
                    <hr>
                    <p class="mb-1">Endpoint REST:</p>
                    <p class="text-muted small mb-3"><?php echo htmlspecialchars($active['restBaseUrl'] ?? ''); ?></p>
                    <p class="mb-1">Chave em uso:</p>
                    <p class="text-muted small mb-0">***<?php echo substr($active['apiKey'] ?? '', -6); ?></p>
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
