<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nexobot SaaS Refactoring</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f3f5f7;
            --card: #ffffff;
            --text: #17212b;
            --muted: #5f6b76;
            --border: #d9e0e6;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "IBM Plex Sans", system-ui, sans-serif;
            background: linear-gradient(180deg, #f7faf9 0%, var(--bg) 100%);
            color: var(--text);
        }
        main {
            max-width: 1120px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }
        h1, h2 { margin: 0 0 12px; }
        p { margin: 0; color: var(--muted); }
        .hero, .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.04);
        }
        .hero { margin-bottom: 24px; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .metric {
            background: #f9fbfb;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
        }
        .metric strong {
            display: block;
            font-size: 1.8rem;
            margin-top: 8px;
        }
        .columns {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 24px;
        }
        .list {
            display: grid;
            gap: 12px;
        }
        .list-item {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            background: #fcfdfd;
        }
        code {
            display: block;
            white-space: pre-wrap;
            color: #0f172a;
        }
        .pill {
            display: inline-block;
            margin-bottom: 8px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #d1fae5;
            color: #065f46;
            font-size: 0.8rem;
            font-weight: 600;
        }
        @media (max-width: 860px) {
            .columns { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<main>
    <section class="hero">
        <span class="pill">Laravel Base Ativa</span>
        <h1>Nexobot refatorado para uma base SaaS incremental</h1>
        <p>Esta branch já sobe uma aplicação Laravel própria, mantendo os scripts legados do bot operáveis via Artisan enquanto o domínio é portado por módulos.</p>
    </section>

    <section class="grid">
        <article class="metric">
            <span>Grids ativos</span>
            <strong>{{ $stats['active_grids'] }}</strong>
        </article>
        <article class="metric">
            <span>Ordens abertas</span>
            <strong>{{ $stats['open_orders'] }}</strong>
        </article>
        <article class="metric">
            <span>Trades fechados</span>
            <strong>{{ $stats['closed_trades'] }}</strong>
        </article>
        <article class="metric">
            <span>Usuários ativos</span>
            <strong>{{ $stats['active_users'] }}</strong>
        </article>
    </section>

    <section class="columns">
        <article class="card">
            <h2>Comandos legados disponíveis</h2>
            <p>Use estes comandos dentro do container para continuar operando o bot durante a migração.</p>
            <div class="list" style="margin-top: 16px;">
                @foreach ($legacyCommands as $command)
                    <div class="list-item">
                        <code>{{ $command }}</code>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="card">
            <h2>Últimos eventos de grid</h2>
            <p>Leitura direta do banco legado via Eloquent.</p>
            <div class="list" style="margin-top: 16px;">
                @forelse ($recentGridLogs as $log)
                    <div class="list-item">
                        <strong>{{ $log->event }}</strong>
                        <div>{{ $log->message ?: 'Sem mensagem detalhada.' }}</div>
                        <small>{{ $log->log_type }} · {{ optional($log->created_at)->format('d/m/Y H:i:s') }}</small>
                    </div>
                @empty
                    <div class="list-item">Nenhum log encontrado.</div>
                @endforelse
            </div>
        </article>
    </section>
</main>
</body>
</html>
