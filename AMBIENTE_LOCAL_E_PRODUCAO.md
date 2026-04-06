# PROMPT MESTRE - ATUALIZACAO DE FRAMEWORK LEGADO (php:7.2-apache -> php:8.4-apache)

Voce e um agente de engenharia responsavel por modernizar um framework PHP legado que hoje roda em php:7.2-apache, sem Redis, sem Kafka, sem padrao moderno de configuracao e sem separacao robusta entre ambiente local e producao.

Seu objetivo e entregar uma atualizacao completa, segura e validada, mantendo compatibilidade funcional da aplicacao, e elevando o stack para o padrao alvo descrito abaixo.

Nao trabalhe com suposicoes silenciosas: valide, registre evidencias, implemente mudancas necessarias, execute verificacoes, e entregue relatorio final objetivo.

Importante: ignore qualquer integracao Binance ou equivalente de exchange. Ela nao faz parte do escopo desta atualizacao.

---

## 1) Missao principal

Atualizar o framework legado para um baseline moderno, com foco em:

1. Atualizacao de runtime para PHP 8.4+ com Apache.
2. Introducao de Redis para cache (e opcionalmente sessao).
3. Introducao de Kafka para fila assincrona de emails.
4. Uso de PHPMailer para envio SMTP no worker consumidor.
5. Substituicao de configuracao sensivel em kernel.php por .env.
6. Estruturacao e validacao de ambiente Local (core) e Producao (prod).
7. Preservacao da arquitetura MVC existente e fluxo funcional atual.

---

## 2) Estado alvo obrigatorio

Ao final, o projeto deve estar alinhado com este estado alvo:

1. Linguagem e runtime
- PHP 8.4+ em container Apache.
- Composer ativo para dependencias e autoload.

2. Servicos de infraestrutura
- MySQL 8.0.
- Redis 7.2+.
- Kafka (broker) com topico de emails.
- Kafka UI no ambiente local (opcional em producao).

3. Email assincrono
- Producer publica mensagens no Kafka.
- Worker CLI consome Kafka e envia email via PHPMailer SMTP.

4. Configuracao
- Sem dependencia de constantes sensiveis hardcoded em kernel.php.
- Configuracao central via .env.
- Arquivo .env.example completo e atualizado.

5. Ambientes
- Local com docker compose all-in-one (app + mysql + redis + kafka + kafka_ui).
- Producao com stack app + redis + worker, com mysql/kafka/traefik externos quando aplicavel.

---

## 3) Regras de atuacao do agente

1. Validar antes de alterar
- Mapear arquivos existentes e comparar com o estado alvo.
- Detectar lacunas, inconsistencias e riscos de regressao.

2. Corrigir com menor impacto estrutural
- Preservar convencoes do projeto.
- Evitar refatoracao desnecessaria fora do escopo.

3. Fazer alteracoes idempotentes
- Mudancas devem ser reaplicaveis sem quebrar o ambiente.

4. Nao expor segredos
- Nunca commitar credenciais reais.
- Segredos devem ficar em .env local/prod e placeholders em .env.example.

5. Ignorar escopo proibido
- Nao incluir, alterar, ou depender de Binance.

---

## 4) Checklist tecnico obrigatorio de validacao

Valide cada item, marque resultado e aplique ajuste se necessario.

### 4.1 Containers e infraestrutura

1. Docker local
- Existe compose local com servicos: apache, mysql, redis, kafka, kafka_ui.
- Volumes de dados e logs estao montados corretamente.
- Rede e conectividade entre containers validada.

2. Docker producao
- Existe compose de deploy com servicos: app, redis, email_worker_site.
- Rede externa para integracao com stack de infraestrutura (mysql, kafka, traefik) validada.
- Healthchecks configurados.

3. Dockerfile core
- Base modernizada para php:8.4-apache.
- Extensoes minimas: pdo, pdo_mysql, redis, rdkafka.
- Composer instalado.
- Cron instalado e inicializado no entrypoint.

4. Dockerfile prod
- Base modernizada para php:8.4-apache.
- Extensoes minimas: mysqli, pdo, pdo_mysql, zip, gd, redis, rdkafka.
- php.ini de producao aplicado.
- hardening de Apache aplicado.

### 4.2 Aplicacao e bootstrap

1. Bootstrap principal
- main.php nao depende de segredo hardcoded.
- Carregamento de autoload e rotas permanece funcional.

2. Scripts CLI
- Worker Kafka executa em modo CLI sem dependencia de contexto HTTP real.
- runner de migrations executa sem erros de caminho.

3. Cache
- Classe de cache Redis disponivel e funcional.
- Se Redis indisponivel, fallback controlado sem derrubar aplicacao.

4. Migrations
- Runner garante tabela de log de migrations.
- Ordem de execucao alfabetica e registro de sucesso/falha.

### 4.3 Mensageria e email

1. Producer Kafka
- Publica payload JSON valido no topico de email.
- Possui timeout e tratamento de erro.

2. Worker Kafka
- Consome mensagens do topico configurado.
- Trata erros de parse e envio.
- Faz logging claro de sucesso/falha.

3. SMTP
- PHPMailer configurado por variaveis de ambiente.
- Remetente, host, porta, usuario e senha vindos de .env.

### 4.4 Configuracao por ambiente (.env)

1. Arquivos obrigatorios
- Criar .env.example completo.
- Garantir .env no .gitignore.

2. Chaves minimas obrigatorias
- APP_ENV, APP_DEBUG, APP_URL, APP_NAME, APP_KEY
- DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
- REDIS_ENABLED, REDIS_HOST, REDIS_PORT, REDIS_PREFIX, REDIS_DATABASE, REDIS_DEFAULT_TTL
- KAFKA_ENABLED, KAFKA_HOST, KAFKA_PORT, KAFKA_TOPIC_EMAIL, KAFKA_CONSUMER_GROUP
- MAIL_FROM_NAME, MAIL_FROM_EMAIL, MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS, MAIL_ENCRYPTION
- SESSION_LIFETIME, SESSION_USE_REDIS
- UPLOAD_DIR, UPLOAD_MAX_SIZE, UPLOAD_ALLOWED_TYPES
- LOG_DIR, LOG_LEVEL

3. Carregamento de .env
- Adicionar/validar dependencia vlucas/phpdotenv (ou equivalente robusto).
- Inicializar dotenv no bootstrap inicial da aplicacao e scripts CLI.
- Garantir fallback seguro para valores ausentes (com erro explicito em settings criticos).

4. Substituicao de kernel.php
- kernel.php deve deixar de ser arquivo de segredo.
- Se permanecer, deve virar adapter sem credenciais, lendo apenas variaveis de ambiente.
- Remover constantes sensiveis hardcoded.

---

## 5) Plano de migracao recomendado (ordem de execucao)

1. Inventario inicial
- Listar arquivos de infraestrutura, bootstrap, configuracao e scripts CLI.

2. Upgrade de runtime
- Atualizar imagens e extensoes PHP para 8.4.
- Ajustar incompatibilidades de sintaxe/API entre PHP 7.2 e 8.4.

3. Introduzir .env
- Criar .env.example.
- Incluir loader dotenv no bootstrap.
- Migrar parametros de kernel.php para env vars.

4. Integrar Redis
- Configurar host/porta/prefix/database/ttl por .env.
- Validar operacao de cache e fallback.

5. Integrar Kafka + worker de email
- Configurar producer e worker por .env.
- Validar publicacao e consumo.

6. Revisar cron e operacao
- Confirmar jobs essenciais (verify_entry, migrations quando aplicavel).
- Confirmar rotinas em local e producao.

7. Revisar seguranca e observabilidade
- Revisar php.ini prod, cookies, logs, opcache e healthchecks.

8. Validacao final ponta a ponta
- Subir stack local.
- Rodar testes de conexao (MySQL, Redis, Kafka).
- Rodar teste de envio de email assincrono.
- Rodar migration runner.

---

## 6) Regras de qualidade para alteracoes de codigo

1. Compatibilidade
- Nao quebrar rotas existentes nem contrato publico de classes criticas sem justificativa.

2. Tratamento de erro
- Toda integracao externa deve ter tratamento de erro e log minimamente util.

3. Clareza
- Comentarios curtos apenas em trechos complexos.

4. Seguranca
- Nao vazar stack traces para usuario final em producao.
- Garantir display_errors desativado em producao.

5. Configurabilidade
- Valores de ambiente nao devem ficar espalhados em varias fontes contraditorias.

---

## 7) Criterios de aceite (Definition of Done)

A tarefa so pode ser considerada concluida se todos os itens abaixo estiverem satisfeitos.

1. Runtime
- Projeto sobe em PHP 8.4+ sem erro fatal de bootstrap.

2. Configuracao
- .env.example existe, esta completo, e cobre todos os parametros criticos.
- Segredos nao estao hardcoded no repositorio.

3. Infra local
- docker compose local sobe app, mysql, redis, kafka e kafka_ui com healthcheck aceitavel.

4. Infra producao
- compose de deploy representa app + redis + worker, com dependencias externas claramente declaradas.

5. Redis
- Leitura/escrita de cache validada.

6. Kafka + Email
- Producer publica no topico correto.
- Worker consome e dispara envio SMTP.

7. Migrations
- runner funciona e registra execucao em migrations_log.

8. Relatorio
- Relatorio final contem:
  - arquivos alterados
  - decisoes tecnicas tomadas
  - pendencias encontradas
  - riscos residuais
  - comandos de verificacao executados

---

## 8) Formato de saida esperado do agente

Ao terminar, responda em 4 blocos objetivos:

1. Resumo executivo
- O que foi atualizado e por que.

2. Matriz validar/corrigir
- Lista item a item do checklist com status:
  - validado sem mudanca
  - corrigido
  - pendente com justificativa

3. Evidencias tecnicas
- Arquivos alterados e principais diffs conceituais.
- Comandos de teste executados e resultado resumido.

4. Proximos passos
- O que falta para hardening final de producao (se houver).

---

## 9) Prompt de execucao direta para o agente

Use o texto abaixo como comando operacional:

"Atualize este framework legado baseado em php:7.2-apache para o estado alvo moderno em php:8.4-apache, com Redis para cache, Kafka para fila de emails assincronos, PHPMailer no worker consumidor e configuracao central por .env no lugar de kernel.php com segredos hardcoded. Valide toda a infraestrutura local e de producao, aplique correcoes necessarias, preserve compatibilidade funcional do MVC existente, ignore integracoes Binance, e entregue relatorio final com checklist completo de validacao, alteracoes feitas, evidencias de teste e pendencias." 

