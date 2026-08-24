<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Fon Banking is an educational mobile banking platform backed by a secure Laravel API.">
    <title>Fon Banking</title>
    <style>
        :root {
            color-scheme: dark;
            --ink: #f4f5ef;
            --muted: #a8aea5;
            --line: rgba(255, 255, 255, 0.12);
            --accent: #b9f36b;
            --unavailable: #ff6b6b;
            --panel: rgba(20, 25, 23, 0.82);
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            color: var(--ink);
            background:
                radial-gradient(circle at 80% 15%, rgba(93, 137, 78, 0.24), transparent 28rem),
                linear-gradient(145deg, #101513 0%, #080a09 72%);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body::before {
            position: fixed;
            inset: 0;
            pointer-events: none;
            content: "";
            opacity: 0.16;
            background-image: linear-gradient(var(--line) 1px, transparent 1px), linear-gradient(90deg, var(--line) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(to bottom, black, transparent 78%);
        }

        .shell {
            position: relative;
            display: grid;
            min-height: 100vh;
            grid-template-rows: auto 1fr auto;
            width: min(1120px, calc(100% - 40px));
            margin: 0 auto;
        }

        header,
        footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 28px 0;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .mark {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            border: 1px solid rgba(185, 243, 107, 0.4);
            border-radius: 50%;
            color: var(--accent);
            font-family: Georgia, serif;
            font-size: 17px;
        }

        .status {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px;
        }

        .status::before {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 16px var(--accent);
            content: "";
        }

        .status--unavailable::before {
            background: var(--unavailable);
            box-shadow: 0 0 16px var(--unavailable);
        }

        .activation-codes {
            display: flex;
            flex-direction: column;
            justify-items: flex-start;
            gap: 10px;
            margin-top: 10px;
        }

        main {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.6fr);
            gap: clamp(48px, 9vw, 120px);
            align-items: center;
            padding: 72px 0 96px;
        }

        .eyebrow {
            margin: 0 0 22px;
            color: var(--accent);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        h1 {
            max-width: 760px;
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(56px, 9vw, 108px);
            font-weight: 400;
            letter-spacing: -0.055em;
            line-height: 0.88;
        }

        h1 span {
            color: var(--accent);
            font-style: italic;
        }

        .intro {
            max-width: 620px;
            margin: 36px 0 0;
            color: var(--muted);
            font-size: clamp(17px, 2vw, 20px);
            line-height: 1.65;
        }

        .actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            max-width: 620px;
            margin-top: 34px;
        }

        .action {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            min-height: 54px;
            padding: 0 18px;
            border: 1px solid var(--line);
            color: var(--ink);
            background: rgba(255, 255, 255, 0.025);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px;
            letter-spacing: 0.04em;
            text-decoration: none;
            transition: border-color 160ms ease, color 160ms ease, background 160ms ease, transform 160ms ease;
        }

        .action::after {
            color: var(--accent);
            content: "↗";
            font-size: 16px;
        }

        .action--internal::after {
            content: "→";
        }

        .action:hover,
        .action:focus-visible {
            border-color: rgba(185, 243, 107, 0.58);
            color: var(--accent);
            background: rgba(185, 243, 107, 0.07);
            outline: none;
            transform: translateY(-2px);
        }

        .panel {
            padding: 28px;
            border: 1px solid var(--line);
            border-radius: 3px;
            background: var(--panel);
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.28);
            backdrop-filter: blur(14px);
        }

        .panel-label {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .features {
            display: grid;
            gap: 0;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .features li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            border-top: 1px solid var(--line);
            font-size: 14px;
        }

        .features li::after {
            color: var(--accent);
            content: "01";
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 10px;
        }

        .features li:nth-child(2)::after { content: "02"; }
        .features li:nth-child(3)::after { content: "03"; }
        .features li:nth-child(4)::after { content: "04"; }

        .stack {
            margin: 24px 0 0;
            color: var(--muted);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 11px;
            line-height: 1.7;
        }

        footer {
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 12px;
        }

        footer strong {
            color: var(--ink);
            font-weight: 500;
        }

        .statuses{
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        @media (max-width: 760px) {
            .shell {
                width: min(100% - 32px, 560px);
            }

            main {
                grid-template-columns: 1fr;
                gap: 48px;
                padding: 64px 0 72px;
            }

            h1 {
                font-size: clamp(52px, 18vw, 82px);
            }

            footer {
                align-items: flex-start;
                gap: 12px;
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header>
            <div class="brand">
                <span class="mark">F</span>
                Fon Banking
            </div>
            <div class="statuses">
                <div class="status">API operational</div>
                <div class="activation-codes">
                    @forelse($activationCodes as $activationCode)
                        <div class="status {{ $activationCode->isValid() ? '' : 'status--unavailable' }}">
                            {{ $activationCode->user?->first_name ?? 'Unknown user' }}
                        </div>
                    @empty
                        <div class="status status--unavailable">No activation codes</div>
                    @endforelse
                </div>
            </div>
        </header>

        <main>
            <section>
                <p class="eyebrow">Mobile banking, reimagined for learning</p>
                <h1>Banking in your <span>pocket.</span></h1>
                <p class="intro">
                    Fon Banking is an educational mobile banking platform. Its secure REST API powers account access, card management, transfers, and transaction history for the companion mobile application.
                </p>
                <nav class="actions" aria-label="Project resources">
                    <a class="action" href="https://github.com/Nenad005/fon-banking-backend" target="_blank" rel="noopener noreferrer">Backend GitHub</a>
                    <a class="action" href="https://github.com/Nenad005/fon-banking-frontend" target="_blank" rel="noopener noreferrer">Frontend GitHub</a>
                    <a class="action action--internal" href="/api/documentation">API documentation</a>
                    <a class="action action--internal" href="/laravel-erd/fon-banking">Database diagram</a>
                </nav>
            </section>

            <aside class="panel">
                <p class="panel-label">Core capabilities</p>
                <ul class="features">
                    <li>Secure activation and PIN access</li>
                    <li>Accounts and payment cards</li>
                    <li>Peer-to-peer transfers</li>
                    <li>Transaction history</li>
                </ul>
                <p class="stack">Laravel {{ app()->version() }}<br>Sanctum token authentication<br>Versioned REST API</p>
            </aside>
        </main>

        <footer>
            <span><strong>Fon Banking</strong> / Faculty project</span>
            <span>Built for demonstration and education</span>
        </footer>
    </div>
</body>
</html>
