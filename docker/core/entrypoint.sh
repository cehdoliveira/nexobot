#!/bin/bash
set -e

echo "Executando composer install nas pastas lib..."

# Instalar dependências do composer para site
if [ -f "/var/www/nexobot/site/app/inc/lib/composer.json" ]; then
    echo "Instalando dependências do site..."
    cd /var/www/nexobot/site/app/inc/lib
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

echo "Composer install concluído!"

# Instalar crontab, se disponível, e iniciar cron
if [ -f "/etc/cron.txt" ]; then
    echo "Instalando crontab..."
    crontab /etc/cron.txt || true
fi

echo "Iniciando cron..."
service cron start || cron || true

# Iniciar o Apache
exec apache2-foreground
