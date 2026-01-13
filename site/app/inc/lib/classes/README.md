# Classes PSR-4

Esta pasta é reservada para classes que seguem o padrão PSR-4.

Todas as classes nesta pasta estarão disponíveis sob a namespace `Nexo\`.

## Exemplo

Criar uma classe em `classes/Database.php`:

```php
<?php
namespace Nexo;

class Database {
    public function connect() {
        // código
    }
}
```

Usar a classe:

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

use Nexo\Database;

$db = new Database();
$db->connect();
```
