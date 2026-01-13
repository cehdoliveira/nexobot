#!/bin/bash
set -e

# Instalar crontab, se disponível, e iniciar cron
if [ -f "/etc/cron.txt" ]; then
    echo "Instalando crontab de produção..."
    crontab /etc/cron.txt || true
fi

echo "Iniciando cron (produção)..."
service cron start || cron || true

# Iniciar o Apache em primeiro plano
exec apache2-foreground
