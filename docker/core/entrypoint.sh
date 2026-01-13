#!/bin/bash
set -e

echo "Executando composer install nas pastas lib..."

# Instalar dependências do composer para manager
if [ -f "/var/www/nexo/manager/app/inc/lib/composer.json" ]; then
    echo "Instalando dependências do manager..."
    cd /var/www/nexo/manager/app/inc/lib
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Instalar dependências do composer para site
if [ -f "/var/www/nexo/site/app/inc/lib/composer.json" ]; then
    echo "Instalando dependências do site..."
    cd /var/www/nexo/site/app/inc/lib
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

echo "Composer install concluído!"

# Iniciar o Apache
exec apache2-foreground
