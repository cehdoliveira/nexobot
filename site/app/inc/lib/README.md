# Composer - Instruções de Uso

Este projeto usa o Composer instalado dentro da pasta `site/app/inc/lib/`.

## Comandos Básicos

### Instalar dependências
```bash
# Entrar no container
docker exec -it apache_nexo bash

# Navegar até a pasta lib
cd /var/www/nexo/app/inc/lib

# Instalar dependências
composer install
```

### Adicionar um novo pacote
```bash
composer require nome-do-pacote/pacote
```

### Atualizar dependências
```bash
composer update
```

### Autoload
O autoload do Composer está configurado em `composer.json` e inclui:
- Autoload PSR-4 para a namespace `Nexo\` (pasta `classes/`)
- Carregamento automático dos arquivos principais da lib

### Usar no código PHP
Adicione no início dos seus arquivos PHP (onde necessário):

```php
require_once(__DIR__ . '/vendor/autoload.php');
```

## Estrutura
```
site/app/inc/lib/
├── composer.json       # Configuração do Composer
├── .gitignore         # Ignora vendor/ e outros arquivos
├── vendor/            # Dependências (gerado pelo composer install)
├── classes/           # Classes PSR-4 (namespace Nexo\)
└── (outros arquivos existentes)
```

## Após rebuild do Docker
Sempre que reconstruir o container Docker, execute:
```bash
docker exec -it apache_nexo bash
cd /var/www/nexo/app/inc/lib
composer install
```
