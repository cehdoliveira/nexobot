#!/bin/bash
set -e

# Instalar dependências do Composer (apenas se não existir vendor)
if [ -f "/var/www/nexobot/site/app/inc/lib/composer.json" ]; then
    if [ ! -f "/var/www/nexobot/site/app/inc/lib/vendor/autoload.php" ]; then
        echo "Instalando dependências do site (Composer)..."
        cd /var/www/nexobot/site/app/inc/lib
        composer install --no-interaction --prefer-dist --optimize-autoloader
        echo "Composer install concluído."
    else
        echo "Dependências já instaladas (vendor encontrado)."
    fi
fi

# Instalar crontab, se disponível, e iniciar cron
if [ -f "/etc/cron.txt" ]; then
    echo "Instalando crontab de produção..."
    crontab /etc/cron.txt || true
fi

echo "Iniciando cron (produção)..."
service cron start || cron || true

# Iniciar o Apache em primeiro plano
exec apache2-foreground
