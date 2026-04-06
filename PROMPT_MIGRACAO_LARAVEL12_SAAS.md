# Prompt de Migração para Novo Repositório Laravel 12

Use este prompt no novo repositório Laravel 12 "limpo" para iniciar o desenvolvimento e a migração do sistema atual.

---

## Contexto

Você está trabalhando em um **novo repositório Laravel 12**, criado do zero, cujo objetivo é substituir um sistema legado em PHP puro que já está **funcional e rodando em produção**.

O sistema legado atual é um **bot de grid trading para Binance**, com:

- autenticação de usuários;
- dashboard web operacional;
- configuração de credenciais Binance;
- execução automática via cron;
- persistência de grids, ordens, trades e logs;
- proteções como stop-loss, trailing stop, sliding grid e lock contra concorrência;
- Redis para cache;
- Kafka para emails assíncronos;
- MySQL como banco principal;
- deploy manual via Docker/Portainer, sem CI/CD por enquanto.

O repositório legado deve ser tratado como **fonte de verdade funcional**.

## Fonte do sistema legado

O sistema legado está neste caminho local:

`/home/cehdoliveira/Projetos/nexobot`

Arquivos mais importantes do legado:

- `/home/cehdoliveira/Projetos/nexobot/site/app/inc/controller/setup_controller.php`
- `/home/cehdoliveira/Projetos/nexobot/site/app/inc/controller/site_controller.php`
- `/home/cehdoliveira/Projetos/nexobot/site/app/inc/controller/auth_controller.php`
- `/home/cehdoliveira/Projetos/nexobot/site/app/inc/controller/config_controller.php`
- `/home/cehdoliveira/Projetos/nexobot/site/cgi-bin/verify_entry.php`
- `/home/cehdoliveira/Projetos/nexobot/site/cgi-bin/sync_grid_orders.php`
- `/home/cehdoliveira/Projetos/nexobot/site/cgi-bin/sync_trades_auto.php`
- `/home/cehdoliveira/Projetos/nexobot/site/cgi-bin/sync_trades_with_binance.php`
- `/home/cehdoliveira/Projetos/nexobot/site/cgi-bin/check_capital.php`
- `/home/cehdoliveira/Projetos/nexobot/site/cgi-bin/check_cron_status.php`
- `/home/cehdoliveira/Projetos/nexobot/migrations`
- `/home/cehdoliveira/Projetos/nexobot/docker`
- `/home/cehdoliveira/Projetos/nexobot/MANUAL_DEPLOY.md`

## Objetivo principal

Migrar o sistema legado para uma arquitetura **Laravel 12 limpa**, sem carregar o código antigo para dentro do novo projeto.

O novo sistema deve ser preparado para evoluir para um **SaaS**, mas a prioridade inicial é:

1. reproduzir o comportamento crítico do bot com segurança;
2. estruturar o domínio corretamente;
3. manter compatibilidade operacional com o modelo de deploy manual via Portainer;
4. reduzir risco de regressão em regras de negócio.

---

## Diretriz principal de engenharia

Não quero uma adaptação superficial do legado.

Quero:

- arquitetura limpa;
- código novo em Laravel 12;
- separação de domínio;
- eliminação de acoplamentos ruins do sistema antigo;
- migração criteriosa das regras de negócio;
- nenhuma dependência do código legado em runtime.

O repositório legado serve apenas para leitura, análise e referência funcional.

---

## Restrições importantes

- Não copiar o sistema antigo “como está”.
- Não embutir o legado dentro do novo projeto.
- Não criar compatibilidade artificial com a antiga arquitetura MVC própria.
- Não portar scripts CGI diretamente; reimplementar como comandos/serviços Laravel.
- Não mudar regras críticas do bot sem evidência clara no código legado.
- Não fazer refactors cosméticos sem ganho real.
- Não inventar comportamento que não exista no sistema atual.

---

## Prioridades

1. Correção funcional
2. Segurança
3. Reprodutibilidade do comportamento do bot
4. Arquitetura limpa
5. Facilidade de deploy no Portainer
6. Preparação para SaaS
7. Performance

---

## Como o novo sistema deve ser pensado

O novo projeto deve ser estruturado como uma aplicação Laravel 12 moderna, com responsabilidades bem separadas.

### Estrutura desejada

Organize o sistema em algo próximo de:

- `app/Domain/Bot`
- `app/Domain/Trading`
- `app/Domain/Risk`
- `app/Domain/Exchange`
- `app/Domain/User`
- `app/Domain/Tenant`
- `app/Application/Services`
- `app/Application/Jobs`
- `app/Application/Actions`
- `app/Infrastructure/Binance`
- `app/Infrastructure/Persistence`
- `app/Console/Commands`
- `app/Http/Controllers`

Se julgar melhor, adapte nomes, mas mantenha a separação clara entre:

- domínio;
- aplicação;
- infraestrutura;
- interface HTTP;
- processos agendados.

---

## Domínio mínimo a ser modelado

Criar entidades equivalentes às principais tabelas e conceitos do legado:

- User
- Profile ou Role
- ExchangeCredential
- Bot
- Grid
- GridLevel ou relação equivalente
- Order
- Trade
- GridLog
- WalletBalanceSnapshot
- AppSetting

Também preparar a base para futura multi-tenancy:

- Tenant
- TenantUser
- Plan ou Subscription

Se multi-tenant ainda não for implementado na primeira etapa, ao menos deixe o modelo pronto para isso.

---

## Regras do negócio que devem ser preservadas

O comportamento do bot no legado deve ser identificado e reimplementado com cuidado, especialmente:

- criação do grid inicial;
- monitoramento periódico;
- consulta de preços e saldo da Binance;
- colocação de ordens BUY e SELL;
- sincronização de status de ordens;
- cálculo de lucro acumulado;
- reaproveitamento de níveis no sliding grid;
- stop-loss global;
- trailing stop;
- registro de logs operacionais;
- prevenção de concorrência/race condition;
- ações manuais via dashboard:
  - encerrar posições;
  - parar bot;
  - reiniciar bot;
  - resetar grid;
  - registrar aporte.

Nada disso deve ser assumido por memória. Sempre validar no código legado.

---

## Requisitos técnicos do novo sistema

### Stack

- Laravel 12
- PHP 8.4+
- MySQL
- Redis
- Queue nativa do Laravel
- Scheduler nativo do Laravel
- Eloquent ORM
- Blade ou Livewire/Inertia apenas se fizer sentido real

### Integrações

- Binance Spot API
- SMTP para email
- Redis

Kafka pode ser reavaliado.

Se o envio de email assíncrono puder ser simplificado para filas nativas do Laravel sem perda importante, prefira isso.

### Segurança

- armazenar credenciais Binance de forma segura;
- nunca deixar secrets hardcoded;
- proteger ações operacionais;
- evitar SQL manual quando Eloquent/Query Builder resolver;
- autenticação robusta;
- substituir padrões inseguros do legado, como `md5` em senhas.

---

## Deploy e operação

O sistema será deployado manualmente via Portainer.

### Modelo operacional desejado

- subir containers/stack manualmente;
- copiar ou clonar o repositório no servidor;
- rodar `composer install`;
- ajustar `.env`;
- fazer `git pull` em futuras atualizações;
- sem pipeline CI/CD por enquanto.

### Exigência

O novo projeto deve ser estruturado para funcionar bem com esse modelo de deploy.

Leve em conta:

- `Dockerfile`
- configuração de Apache/Nginx
- scheduler
- worker de fila
- variáveis de ambiente
- persistência de logs
- comandos de manutenção

Use como referência operacional:

- `/home/cehdoliveira/Projetos/nexobot/MANUAL_DEPLOY.md`

Mas não replique cegamente a estrutura antiga.

---

## Estratégia de execução esperada

Quero que você trabalhe em fases, com baixo risco.

### Fase 1

Fazer a fundação correta do novo sistema:

- configuração base do Laravel;
- organização do domínio;
- models iniciais;
- migrations iniciais;
- infraestrutura Docker/deploy;
- auth base;
- configuração de ambiente;
- base de console commands;
- base do dashboard administrativo.

### Fase 2

Migrar os blocos centrais do bot:

- integração Binance;
- leitura de saldo;
- entidades de grid;
- ordens;
- trades;
- logs;
- engine de execução periódica.

### Fase 3

Migrar regras avançadas:

- stop-loss;
- trailing stop;
- sliding grid;
- locks;
- reconciliação/sincronização.

### Fase 4

Finalizar camada web operacional:

- dashboard;
- configurações;
- ações manuais;
- monitoramento;
- auditoria.

### Fase 5

Preparar evolução SaaS:

- tenancy;
- planos;
- isolamento por cliente;
- billing, se entrar no escopo.

---

## Forma de trabalho esperada

- Investigue primeiro o legado.
- Leia apenas o necessário para cada etapa.
- Ao identificar a regra de negócio com evidência suficiente, implemente no Laravel.
- Faça mudanças pequenas, incrementais e verificáveis.
- Valide sempre com o menor conjunto de testes/comandos que dê confiança real.

Não pare para propor arquitetura abstrata sem materializar código.

---

## Critérios de qualidade

Cada entrega deve:

- compilar/rodar;
- ter estrutura coerente;
- evitar dependência do legado;
- ser consistente com Laravel 12;
- ser preparada para manutenção;
- incluir validação objetiva.

Se algo não puder ser validado localmente, deixar explícito.

---

## Critérios de aceite da migração

O sistema novo será considerado bem encaminhado quando:

- o novo projeto tiver arquitetura Laravel limpa;
- as entidades centrais do bot estiverem modeladas;
- os comandos agendados críticos existirem em Laravel;
- o dashboard básico estiver funcional;
- o deploy manual no Portainer estiver previsto e coerente;
- o comportamento crítico do bot estiver sendo migrado com rastreabilidade em relação ao legado.

---

## O que você deve fazer agora

1. Analisar a estrutura do novo repositório Laravel 12.
2. Ler o sistema legado apenas nos pontos necessários.
3. Definir a arquitetura-alvo do novo sistema com base no domínio real.
4. Implementar a **primeira fatia útil**, começando pela fundação correta:
   - estrutura de domínio;
   - models;
   - migrations;
   - configuração base;
   - deploy base;
   - auth base;
   - comandos iniciais.
5. Entregar progresso concreto em código, não apenas plano.

---

## Observações finais

- O legado funciona em produção, então preserve o comportamento, não a arquitetura.
- Em caso de dúvida entre “copiar rápido” e “estruturar corretamente”, prefira estruturar corretamente, desde que não invente regra de negócio.
- Se houver ambiguidade, consulte o código legado antes de decidir.
- Sempre trate o bot como sistema crítico: qualquer regressão pode afetar operação real.

