#!/bin/bash
set -e

ENABLE_CRON="${ENABLE_CRON:-false}"

# Instalar dependências do Composer (apenas se não existir vendor)
if [ -f "/var/www/driftex/site/app/inc/lib/composer.json" ]; then
    if [ ! -f "/var/www/driftex/site/app/inc/lib/vendor/autoload.php" ]; then
        echo "Instalando dependências do site (Composer)..."
        cd /var/www/driftex/site/app/inc/lib
        composer install --no-interaction --prefer-dist --optimize-autoloader
        echo "Composer install concluído."
    else
        echo "Dependências já instaladas (vendor encontrado)."
    fi
fi

# Resetar locks de processamento órfãos (container reiniciou sem finalizar o cron)
if [ -n "${MYSQL_HOST:-}" ] || [ -f "/var/www/driftex/site/app/inc/kernel.php" ]; then
    php -r "
        @require '/var/www/driftex/site/app/inc/main.php';
        if (class_exists('local_pdo')) {
            try {
                \$pdo = (new local_pdo())->getPdo();
                \$affected = \$pdo->exec(\"UPDATE grids SET is_processing='no' WHERE is_processing='yes'\");
                if (\$affected > 0) echo \"[entrypoint] \$affected lock(s) orfao(s) liberado(s).\n\";
            } catch (Exception \$e) {
                echo '[entrypoint] Aviso: nao foi possivel resetar locks: ' . \$e->getMessage() . \"\n\";
            }
        }
    " || true
fi

# Instalar crontab e iniciar cron apenas no container app
if [ "$ENABLE_CRON" = "true" ]; then
    if [ -f "/etc/cron.txt" ]; then
        echo "Instalando crontab de produção..."
        crontab /etc/cron.txt || true
    fi

    echo "Iniciando cron (produção)..."
    service cron start || cron || true
else
    echo "Cron desabilitado para este container."
fi

if [ "$#" -gt 0 ]; then
    echo "Executando comando customizado: $*"
    exec "$@"
fi

# Iniciar o Apache em primeiro plano
exec apache2-foreground
