# PROMPT MESTRE - FRONTEND BASE (BOOTSTRAP + ALPINEJS) COM TEMPLATE DE NAV/HEADER

Voce e um agente de engenharia frontend/backend (MVC PHP) e deve criar ou ajustar um modulo base de interface para este framework, respeitando o padrao ja existente do projeto.

Objetivo central:
- montar estrutura reutilizavel de layout (head, header/nav, footer, foot)
- implementar area de login e cadastro
- implementar tela pos-login com conteudo minimo "Hello World"
- preservar o comportamento visual de barra superior para usuario logado e deslogado
- manter o conceito de "alterar apenas o arquivo do meio" (conteudo da view), reaproveitando template comum em todas as paginas

Importante:
- seguir o padrao arquitetural atual do projeto
- usar Bootstrap + Alpine.js
- nao criar estrutura paralela desnecessaria
- integrar com o fluxo MVC/rotas/controladores existente

---

## 1) Contexto estrutural obrigatorio (mapa real do projeto)

Use estes arquivos como base de arquitetura existente:

1. Layout comum
- site/public_html/ui/common/head.php
- site/public_html/ui/common/header.php
- site/public_html/ui/common/footer.php
- site/public_html/ui/common/foot.php

2. Views de pagina
- site/public_html/ui/page/login.php
- site/public_html/ui/page/register.php
- site/public_html/ui/page/home.php
- site/public_html/ui/page/dashboard.php

3. Controllers
- site/app/inc/controller/auth_controller.php
- site/app/inc/controller/site_controller.php

4. Rotas e URLs globais
- site/app/inc/urls.php

5. Assets frontend
- site/public_html/assets/css/main.css
- site/public_html/assets/css/dashboard.css
- site/public_html/assets/js/main.js
- site/public_html/assets/js/alpine/

Observacao critica:
O header atual ja possui comportamento condicional logado/deslogado com auth_controller::check_login(). Esse comportamento deve ser preservado e usado como padrao, nao refeito de forma desconectada.

---

## 2) Missao funcional

Criar um template frontend base para novas views, com os seguintes requisitos:

1. Login
- pagina de login funcional no padrao Bootstrap
- comportamento Alpine.js para UX basica (validacao cliente, loading de submit, toggle de senha)
- manter compatibilidade com submit para rota/controlador ja existente

2. Registro
- pagina de cadastro funcional no padrao Bootstrap
- Alpine.js para validacoes simples de formulario
- manter compatibilidade com fluxo de backend atual

3. Pos-login
- pagina protegida (apenas autenticado)
- conteudo central minimo: "Hello World"
- manter header/nav de logado e rodape comum

4. Layout reutilizavel
- cada nova view deve reaproveitar:
  - head.php
  - header.php
  - footer.php
  - foot.php
- o unico arquivo que muda por tela deve ser o arquivo de conteudo da pagina (arquivo "do meio" em ui/page)

---

## 3) Padrao de composicao de view (obrigatorio)

Todo controller que renderiza pagina HTML deve seguir este padrao:

1. incluir head comum
2. incluir header/nav comum
3. incluir view de conteudo especifica (arquivo do meio)
4. incluir footer comum
5. incluir foot comum

Modelo conceitual:
- include ui/common/head.php
- include ui/common/header.php
- include ui/page/<miolo>.php
- include ui/common/footer.php
- include ui/common/foot.php

Nao desviar desse padrao sem justificativa tecnica forte.

---

## 4) Requisitos de estado visual (logado vs deslogado)

A barra superior deve refletir o estado de autenticacao:

1. Usuario deslogado
- exibir acao principal para entrar (botao/atalho login)
- opcional: acesso para cadastro

2. Usuario logado
- exibir nome do usuario quando disponivel
- exibir acao de configuracao (quando existir no fluxo)
- exibir acao de logout

3. Responsividade
- navbar deve funcionar em mobile e desktop
- labels secundarias podem ser ocultadas em telas pequenas, mantendo icones

4. Fonte da verdade
- estado logado/deslogado deve vir do backend (sessao/check_login), nao de estado fake no frontend

---

## 5) Alpine.js no fluxo

Use Alpine.js para interatividade leve, sem transformar a tela em SPA.

1. Login controller (frontend)
- estado do formulario
- validacao basica antes do submit
- loading visual de envio
- exibir/ocultar senha

2. Register controller (frontend)
- validacoes basicas (campos obrigatorios)
- mascara opcional de cpf/telefone (se mantiver padrao atual)
- loading visual no submit

3. Carregamento dinamico
- respeitar estrategia ja existente em foot.php (alpineControllers)
- cada pagina deve declarar apenas os controllers Alpine necessarios

---

## 6) Escopo de implementacao esperado do agente

O agente deve validar, corrigir e, se necessario, criar os seguintes pontos:

1. Header/nav template
- confirmar que header.php e reutilizavel para todas as paginas
- garantir variantes logado/deslogado

2. Login e cadastro
- garantir que login.php e register.php seguem o padrao de composicao
- garantir integracao com auth_controller e rotas existentes

3. Tela Hello World protegida
- criar/ajustar uma view simples de pos-login (ex: ui/page/hello.php)
- garantir que controller bloqueie acesso sem login
- manter template completo (head/header/miolo/footer/foot)

4. Rotas
- garantir URL publica para login/cadastro
- garantir URL protegida para hello pos-login

5. Reuso de template
- demonstrar de forma explicita que para criar nova tela basta trocar o arquivo do meio

---

## 7) Checklist tecnico obrigatorio

Marque cada item como:
- validado sem mudanca
- corrigido
- pendente com justificativa

### 7.1 Estrutura de arquivos
- head.php separado e reutilizavel
- header.php separado e reutilizavel
- footer.php separado e reutilizavel
- foot.php separado e reutilizavel
- views em ui/page independentes do layout global

### 7.2 Autenticacao visual
- navbar muda para estado logado/deslogado
- logout disponivel quando logado
- login disponivel quando deslogado

### 7.3 Fluxo login/registro
- login envia para rota correta
- registro envia para rota correta
- mensagens de retorno renderizam corretamente na tela

### 7.4 Pos-login
- rota protegida ativa
- pagina mostra "Hello World"
- layout completo comum aplicado

### 7.5 Bootstrap e Alpine
- Bootstrap carregado via head/foot padrao
- Alpine carregado via foot padrao
- controllers Alpine por pagina funcionando

### 7.6 Responsividade
- header funcional em mobile
- formularios de login e registro legiveis e usaveis em mobile
- pagina hello com estrutura minima consistente

---

## 8) Convencoes de implementacao

1. Nao duplicar layout
- nao copiar blocos de header/footer para cada view

2. Nao quebrar o padrao MVC
- manter logica de sessao em controller/backend
- manter view focada em apresentacao

3. Nao introduzir dependencias pesadas
- Alpine.js para interacao leve
- Bootstrap para UI padrao

4. Nao reescrever o projeto inteiro
- alterar somente o necessario para o template funcionar com clareza

5. Nomes consistentes
- manter naming alinhado ao projeto (controllers, urls globais, includes)

---

## 9) Criterios de aceite (Definition of Done)

Considerar concluido apenas se:

1. Existe um template claro e reutilizavel de layout comum (head/header/footer/foot).
2. Login e cadastro estao funcionais no padrao visual do projeto.
3. Existe uma tela pos-login com "Hello World" protegida por autenticacao.
4. O header alterna corretamente entre estado logado e deslogado.
5. Fica evidente que novas views alteram somente o arquivo do meio.
6. Rotas e controllers estao coerentes com a arquitetura atual.
7. Resultado final e responsivo e consistente entre desktop e mobile.

---

## 10) Formato do relatorio final do agente

Ao concluir, o agente deve responder em 4 blocos:

1. Resumo executivo
- o que foi validado, criado e corrigido

2. Matriz de validacao
- checklist item a item com status

3. Evidencias
- arquivos alterados/criados
- fluxo de navegacao final (deslogado -> login/registro -> logado -> hello)

4. Proximos passos
- melhorias opcionais de UX/componentizacao para evolucao futura

---

## 11) Prompt de execucao direta para o agente

Use este comando operacional:

"Crie/ajuste no framework um template frontend base com Bootstrap e Alpine.js, mantendo o padrao de layout comum (head, header/nav, footer e foot), com tela de login, tela de cadastro e uma tela protegida pos-login exibindo apenas Hello World. Preserve o comportamento de barra superior para usuario logado e deslogado como ja existe no projeto, mantenha compatibilidade MVC atual e garanta que novas views alterem apenas o arquivo do meio (conteudo da pagina). Valide, corrija e complete o que for necessario, entregando relatorio final com checklist e evidencias."