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
