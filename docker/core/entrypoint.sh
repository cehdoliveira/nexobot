#!/bin/bash
set -e

ENABLE_CRON="${ENABLE_CRON:-false}"

echo "Executando composer install nas pastas lib..."

# Instalar dependências do composer para site
# Altere o caminho: /var/www/driftex para /var/www/<NOME_APP>
if [ -f "/var/www/driftex/site/app/inc/lib/composer.json" ]; then
    echo "Instalando dependências do site..."
    cd /var/www/driftex/site/app/inc/lib
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

echo "Composer install concluído!"

# Instalar crontab e iniciar cron apenas no container app
if [ "$ENABLE_CRON" = "true" ]; then
    if [ -f "/etc/cron.txt" ]; then
        echo "Instalando crontab..."
        crontab /etc/cron.txt || true
    fi

    echo "Iniciando cron..."
    service cron start || cron || true
else
    echo "Cron desabilitado para este container."
fi

if [ "$#" -gt 0 ]; then
    echo "Executando comando customizado: $*"
    exec "$@"
fi

# Iniciar o Apache
exec apache2-foreground
