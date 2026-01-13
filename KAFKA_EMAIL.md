# Sistema de Email Assíncrono com Kafka + PHPMailer

## 📋 Visão Geral

O Nexo Framework implementa um sistema de envio de emails totalmente assíncrono usando **Apache Kafka** como message broker e **PHPMailer** para o envio efetivo dos emails. Esta arquitetura garante alta performance, escalabilidade e confiabilidade no processamento de emails.

### 🏗️ Arquitetura

```
┌─────────────────┐      ┌──────────────┐      ┌─────────────────┐
│  Aplicação PHP  │─────▶│  EmailProducer│─────▶│  Kafka Broker  │
│  (Manager/Site) │      │  (Producer)   │      │  (Tópico emails)│
└─────────────────┘      └──────────────┘      └────────┬────────┘
                                                          │
                                                          │
                                                          ▼
                                                ┌─────────────────┐
                                                │  email_worker   │
                                                │  (Consumer)     │
                                                └────────┬────────┘
                                                         │
                                                         ▼
                                                ┌─────────────────┐
                                                │   PHPMailer     │
                                                │   (SMTP Send)   │
                                                └─────────────────┘
```

### ✅ Benefícios

- **Assíncrono**: Não bloqueia a requisição HTTP
- **Escalável**: Adicione múltiplos workers conforme a demanda
- **Confiável**: Kafka garante entrega das mensagens
- **Monitorável**: Logs detalhados de processamento
- **Flexível**: Suporta templates, anexos, CC/BCC

---

## 🔧 Configuração

### 1. Constantes do Kernel

As configurações estão em [manager/app/inc/kernel.php.example](manager/app/inc/kernel.php.example) e [site/app/inc/kernel.php.example](site/app/inc/kernel.php.example):

```php
// Configurações SMTP (PHPMailer)
define("mail_from_name", "Nexo Manager");
define("mail_from_mail", "noreply@manager.nexo.local");
define("mail_from_host", "smtp.gmail.com");
define("mail_from_port", "587");
define("mail_from_user", "seu-email@gmail.com");
define("mail_from_pwd", "sua-senha-app");

// Configurações Kafka
define("KAFKA_HOST", "kafka_nexo");
define("KAFKA_PORT", "9092");
define("KAFKA_TOPIC_EMAIL", "emails");
define("KAFKA_CONSUMER_GROUP", "email-worker-group");
```

### 2. Containers Docker

O [docker/docker-compose.yml](docker/docker-compose.yml) inclui:

```yaml
# Kafka Broker (modo KRaft - sem Zookeeper)
kafka_nexo:
  image: apache/kafka:latest
  ipv4_address: 172.29.0.5
  ports:
    - "9092:9092"  # Porta do broker
    - "9093:9093"  # Porta do controller

# Kafka UI (Interface Web de Gerenciamento)
kafka_ui:
  image: provectuslabs/kafka-ui:latest
  ipv4_address: 172.29.0.6
  ports:
    - "8080:8080"  # Acesso: http://localhost:8080
  depends_on:
    - kafka_nexo
```

**Kafka UI**: Interface web para gerenciar tópicos, visualizar mensagens, monitorar consumers e métricas em tempo real.

### 3. Dependências PHP

Instaladas via Composer em [manager/app/inc/lib/composer.json](manager/app/inc/lib/composer.json):

```json
{
  "require": {
    "phpmailer/phpmailer": "^6.9",
    "enqueue/rdkafka": "^0.10"
  }
}
```

### 4. Inicializar Infraestrutura

```bash
# Rebuild dos containers (necessário na primeira vez)
cd docker
docker-compose down
docker-compose build --no-cache
docker-compose up -d

# Aguardar Kafka inicializar (pode levar ~30s)
docker logs -f kafka_nexo

# Acessar Kafka UI
# Abrir no navegador: http://localhost:8080

# Instalar dependências Composer
docker exec -it apache_nexo bash
cd /var/www/nexo/manager/app/inc/lib
composer install

cd /var/www/nexo/site/app/inc/lib
composer install
exit

# Verificar Kafka funcionando
docker exec -it kafka_nexo /opt/kafka/bin/kafka-topics.sh --list --bootstrap-server localhost:9092

# Criar tópico emails (opcional, é criado automaticamente)
docker exec -it kafka_nexo /opt/kafka/bin/kafka-topics.sh --create \
  --topic emails \
  --bootstrap-server localhost:9092 \
  --partitions 3 \
  --replication-factor 1
```

---

## 📤 Uso do EmailProducer

### Exemplo Básico

```php
<?php
// Obter instância do producer
$emailer = EmailProducer::getInstance();

// Enviar email simples
$emailer->send(
    'usuario@example.com',
    'Bem-vindo ao Nexo',
    '<h1>Olá!</h1><p>Bem-vindo ao nosso sistema.</p>'
);
```

### Email com Múltiplos Destinatários

```php
$emailer->sendEmail(
    ['user1@example.com', 'user2@example.com', 'user3@example.com'],
    'Notificação Importante',
    '<p>Esta é uma notificação para todos os usuários.</p>',
    [
        'cc' => ['gerente@example.com'],
        'bcc' => ['admin@example.com'],
        'isHtml' => true
    ]
);
```

### Email com Template

```php
// Criar template em: manager/public_html/ui/email/welcome.php
$emailer->sendTemplate(
    'novo-usuario@example.com',
    'Bem-vindo!',
    'welcome',
    [
        'nome' => 'João Silva',
        'codigo_ativacao' => 'ABC123'
    ]
);
```

### Email com Anexos

```php
$emailer->sendWithAttachments(
    'cliente@example.com',
    'Relatório Mensal',
    '<p>Segue anexo o relatório solicitado.</p>',
    [
        '/var/www/nexo/_data/upload/relatorio.pdf',
        '/var/www/nexo/_data/upload/grafico.png'
    ]
);
```

### Opções Avançadas

```php
$emailer->sendEmail(
    'vip@example.com',
    'Email Prioritário',
    '<h2>Conteúdo importante</h2>',
    [
        'isHtml' => true,
        'priority' => 'high', // high, normal, low
        'replyTo' => 'suporte@example.com',
        'cc' => ['supervisor@example.com'],
        'bcc' => ['log@example.com'],
        'attachments' => ['/path/to/file.pdf']
    ]
);
```

---

## 🔄 Email Worker (Consumer)

### Executar Worker Manualmente

```bash
# Entrar no container
docker exec -it apache_nexo bash

# Executar worker
cd /var/www/nexo/manager/cgi-bin
php email_worker.php
```

### Logs do Worker

Os logs são salvos em `/var/www/nexo/manager/app/logs/email_worker.log`:

```
[2024-12-24 14:30:15] [INFO] Email Worker iniciado
[2024-12-24 14:30:15] [INFO] Conectado ao Kafka: kafka_nexo:9092
[2024-12-24 14:30:15] [INFO] Consumindo tópico: emails
[2024-12-24 14:30:15] [INFO] Aguardando mensagens...
[2024-12-24 14:32:01] [INFO] Nova mensagem recebida [Offset: 0]
[2024-12-24 14:32:01] [INFO] Assunto: Bem-vindo ao Nexo
[2024-12-24 14:32:01] [INFO] Destinatários: usuario@example.com
[2024-12-24 14:32:03] [INFO] Email enviado com sucesso para: usuario@example.com
[2024-12-24 14:32:03] [INFO] Email processado com sucesso
```

### Executar Worker como Daemon (Supervisor)

Criar arquivo `/etc/supervisor/conf.d/email-worker.conf`:

```ini
[program:nexo-email-worker]
command=/usr/bin/php /var/www/nexo/manager/cgi-bin/email_worker.php
directory=/var/www/nexo/manager/cgi-bin
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/nexo/email-worker.log
environment=HOME="/var/www",USER="www-data"
```

Ativar:

```bash
supervisorctl reread
supervisorctl update
supervisorctl start nexo-email-worker
supervisorctl status
```

### Executar Worker via Systemd

Criar arquivo `/etc/systemd/system/nexo-email-worker.service`:

```ini
[Unit]
Description=Nexo Email Worker
After=network.target docker.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/nexo/manager/cgi-bin
ExecStart=/usr/bin/php /var/www/nexo/manager/cgi-bin/email_worker.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Ativar:

```bash
systemctl daemon-reload
systemctl enable nexo-email-worker
systemctl start nexo-email-worker
systemctl status nexo-email-worker
```

---

## 🧪 Testes

### 1. Teste do Kafka

```bash
# Verificar containers rodando
docker ps | grep -E 'kafka'

# Acessar Kafka UI no navegador
# http://localhost:8080

# Listar tópicos
docker exec -it kafka_nexo /opt/kafka/bin/kafka-topics.sh --list --bootstrap-server localhost:9092

# Criar tópico manualmente (se necessário)
docker exec -it kafka_nexo /opt/kafka/bin/kafka-topics.sh --create \
  --topic emails \
  --bootstrap-server localhost:9092 \
  --partitions 3 \
  --replication-factor 1

# Descrever tópico
docker exec -it kafka_nexo /opt/kafka/bin/kafka-topics.sh --describe \
  --topic emails \
  --bootstrap-server localhost:9092

# Consumir mensagens (teste manual)
docker exec -it kafka_nexo /opt/kafka/bin/kafka-console-consumer.sh \
  --topic emails \
  --from-beginning \
  --bootstrap-server localhost:9092

# Produzir mensagem de teste
docker exec -it kafka_nexo /opt/kafka/bin/kafka-console-producer.sh \
  --topic emails \
  --bootstrap-server localhost:9092
```

### 2. Teste do Producer

Criar arquivo [manager/public_html/test-email.php](manager/public_html/test-email.php):

```php
<?php
require_once __DIR__ . '/../app/inc/kernel.php';
require_once __DIR__ . '/../app/inc/lib/vendor/autoload.php';

header('Content-Type: application/json');

try {
    $emailer = EmailProducer::getInstance();
    
    $result = $emailer->send(
        'teste@example.com',
        'Email de Teste - ' . date('Y-m-d H:i:s'),
        '<h1>Teste de Email</h1><p>Este é um email de teste do sistema Nexo.</p>'
    );
    
    echo json_encode([
        'success' => $result,
        'message' => $result ? 'Email enviado para fila Kafka' : 'Erro ao enviar email',
        'stats' => $emailer->getStats()
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
```

Acessar: `http://manager.nexo.local/test-email.php`

### 3. Teste Completo (Producer + Worker)

```bash
# Terminal 1: Iniciar worker
docker exec -it apache_nexo bash
cd /var/www/nexo/manager/cgi-bin
php email_worker.php

# Terminal 2: Enviar email de teste
curl http://manager.nexo.local/test-email.php

# Verificar logs no Terminal 1
# Deve aparecer: "Email enviado com sucesso"
```

---

## 📊 Monitoramento

### Kafka UI (Interface Web)

Acesse **http://localhost:8080** para visualizar:

- **Topics**: Todos os tópicos, partições e configurações
- **Messages**: Visualizar mensagens em tempo real
- **Consumers**: Grupos de consumidores e offsets
- **Brokers**: Status e métricas dos brokers
- **Schemas**: Schemas Avro/JSON (se configurado)

### Verificar Tópico Kafka (CLI)

```bash
# Informações do tópico
docker exec -it kafka_nexo /opt/kafka/bin/kafka-topics.sh --describe \
  --topic emails \
  --bootstrap-server localhost:9092

# Total de mensagens no tópico
docker exec -it kafka_nexo /opt/kafka/bin/kafka-run-class.sh kafka.tools.GetOffsetShell \
  --broker-list localhost:9092 \
  --topic emails \
  --time -1

# Consumer groups
docker exec -it kafka_nexo /opt/kafka/bin/kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --list
```

### Estatísticas do Producer

```php
$emailer = EmailProducer::getInstance();
$stats = $emailer->getStats();
print_r($stats);

// Output:
// Array (
//     [broker] => kafka_nexo:9092
//     [topic] => emails
//     [connected] => 1
// )
```

### Logs do Sistema

```bash
# Logs do Apache (PHP)
tail -f /var/log/apache2/error.log

# Logs do Worker
tail -f /var/www/nexo/manager/app/logs/email_worker.log

# Logs do Kafka
docker logs -f kafka_nexo
```

---

## 🔥 Troubleshooting

### Problema: Producer não conecta ao Kafka

**Sintoma**: `EmailProducer Error: ...`

**Solução**:
```bash
# Verificar se Kafka está rodando
docker ps | grep kafka_nexo

# Verificar logs do Kafka
docker logs kafka_nexo | tail -50

# Verificar conectividade
docker exec -it apache_nexo ping kafka_nexo

# Reiniciar Kafka
docker-compose restart kafka_nexo

# Aguardar inicialização completa (~30s)
docker logs -f kafka_nexo

# Verificar no Kafka UI
# http://localhost:8080
```

### Problema: Worker não consome mensagens

**Sintoma**: Worker fica aguardando, mas mensagens não são processadas

**Solução**:
```bash
# Verificar grupo de consumidores
docker exec -it kafka_nexo /opt/kafka/bin/kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --describe \
  --group email-worker-group

# Verificar mensagens no tópico via Kafka UI
# http://localhost:8080 > Topics > emails > Messages

# Reset offset (cuidado: reprocessa todas as mensagens)
docker exec -it kafka_nexo /opt/kafka/bin/kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --group email-worker-group \
  --reset-offsets \
  --to-earliest \
  --topic emails \
  --execute
```

### Problema: PHPMailer não envia emails

**Sintoma**: Worker processa mas email não chega

**Solução**:
1. Verificar configurações SMTP no `kernel.php`
2. Testar credenciais Gmail: [https://myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
3. Verificar logs do PHPMailer no `email_worker.log`
4. Testar SMTP manualmente:

```bash
telnet smtp.gmail.com 587
EHLO localhost
QUIT
```

### Problema: Extensão rdkafka não instalada

**Sintoma**: `Class 'RdKafka\Producer' not found`

**Solução**:
```bash
# Rebuild do container
docker-compose down
docker-compose build --no-cache
docker-compose up -d

# Verificar extensão instalada
docker exec -it apache_nexo php -m | grep rdkafka
```

---

## 🚀 Performance & Escalabilidade

### Múltiplos Workers

Para processar mais emails em paralelo:

```bash
# Iniciar 3 workers
docker exec -d apache_nexo php /var/www/nexo/manager/cgi-bin/email_worker.php
docker exec -d apache_nexo php /var/www/nexo/manager/cgi-bin/email_worker.php
docker exec -d apache_nexo php /var/www/nexo/manager/cgi-bin/email_worker.php
```

Kafka distribui automaticamente as mensagens entre os workers do mesmo grupo.

### Particionamento do Tópico

Para aumentar paralelismo:

```bash
# Aumentar número de partições
docker exec -it kafka_nexo /opt/kafka/bin/kafka-topics.sh --alter \
  --topic emails \
  --partitions 6 \
  --bootstrap-server localhost:9092

# Verificar alteração no Kafka UI
# http://localhost:8080 > Topics > emails
```

### Métricas de Performance

- **Throughput**: ~100-500 emails/segundo (depende do SMTP)
- **Latência Producer**: ~5-20ms (envio para Kafka)
- **Latência Total**: ~1-5s (incluindo envio SMTP)
- **Retenção**: 7 dias (configurável no Kafka)

---

## 📝 Exemplos Práticos

### 1. Cadastro de Usuário

```php
// No controller de cadastro
public function register() {
    // ... salvar usuário no banco ...
    
    $emailer = EmailProducer::getInstance();
    $emailer->sendTemplate(
        $user->email,
        'Ative sua conta',
        'activate-account',
        [
            'nome' => $user->nome,
            'link_ativacao' => cFrontend . "/activate?token={$user->token}"
        ]
    );
}
```

### 2. Recuperação de Senha

```php
public function forgotPassword() {
    // ... gerar token ...
    
    $emailer = EmailProducer::getInstance();
    $emailer->sendTemplate(
        $email,
        'Recuperação de Senha',
        'reset-password',
        [
            'nome' => $user->nome,
            'link_reset' => cFrontend . "/reset?token={$token}",
            'validade' => '24 horas'
        ]
    );
}
```

### 3. Notificação de Relatório

```php
public function generateReport($userId) {
    // ... gerar relatório PDF ...
    
    $emailer = EmailProducer::getInstance();
    $emailer->sendWithAttachments(
        $user->email,
        "Relatório Mensal - " . date('m/Y'),
        '<p>Segue anexo seu relatório mensal.</p>',
        [UPLOAD_DIR . "relatorio_{$userId}.pdf"]
    );
}
```

---

## 📚 Referências

- **Apache Kafka**: [https://kafka.apache.org/documentation/](https://kafka.apache.org/documentation/)
- **PHPMailer**: [https://github.com/PHPMailer/PHPMailer](https://github.com/PHPMailer/PHPMailer)
- **RdKafka PHP**: [https://github.com/arnaud-lb/php-rdkafka](https://github.com/arnaud-lb/php-rdkafka)
- **Confluent Platform**: [https://docs.confluent.io/](https://docs.confluent.io/)

---

## 🎯 Próximos Passos

- [ ] Implementar retry policy para emails falhados
- [ ] Criar dead letter queue para emails não processados
- [ ] Adicionar métricas Prometheus/Grafana
- [ ] Implementar rate limiting no SMTP
- [ ] Criar dashboard de monitoramento
- [ ] Adicionar suporte a múltiplos idiomas nos templates
- [ ] Implementar agendamento de emails (envio futuro)

---

**Nexo Framework** - Sistema de Email Assíncrono com Kafka + PHPMailer  
Versão 1.0 - 2024
