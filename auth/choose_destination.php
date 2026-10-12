<?php
require_once __DIR__ . '/../config/config.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    redirectByRole();
}

$pendingSelection = $_SESSION['pending_role_selection'] ?? null;

if (!$pendingSelection) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$selectionAge = time() - (int)($pendingSelection['created_at'] ?? 0);
if ($selectionAge > ROLE_SELECTION_TIMEOUT) {
    unset($_SESSION['pending_role_selection']);
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$roleOptions = array_values(array_filter($pendingSelection['options'] ?? [], static function ($option) {
    return !empty($option['role']) && isset($option['user_id']);
}));

if (empty($roleOptions)) {
    unset($_SESSION['pending_role_selection']);
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$maskedEmail = $pendingSelection['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Destination – La Elena Fishyalan</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --gold:        #c9a84c;
            --gold-dim:    rgba(201,168,76,0.18);
            --gold-border: rgba(201,168,76,0.30);
            --bg:          #0b0b0b;
            --surface:     #141414;
            --surface-2:   #1c1c1c;
            --line:        rgba(255,255,255,0.07);
            --text:        #f0ead8;
            --muted:       #7a7567;
            --radius-sm:   10px;
            --radius-md:   16px;
            --radius-lg:   22px;
            --ease:        cubic-bezier(0.22, 1, 0.36, 1);
        }

        html, body {
            height: 100%;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: clamp(16px, 4vw, 40px);
        }

        /* Subtle background texture */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 60% 40% at 50% 0%, rgba(201,168,76,0.07) 0%, transparent 70%),
                radial-gradient(ellipse 40% 30% at 80% 90%, rgba(201,168,76,0.04) 0%, transparent 60%);
            pointer-events: none;
            z-index: 0;
        }

        /* ── Card shell ── */
        .shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            overflow: hidden;
            animation: rise 0.55s var(--ease) both;
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Top gold line */
        .shell::before {
            content: "";
            display: block;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            opacity: 0.6;
        }

        /* ── Hero ── */
        .hero {
            padding: clamp(20px, 5vw, 32px) clamp(20px, 5vw, 32px) clamp(16px, 4vw, 24px);
            text-align: center;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 14px;
            opacity: 0.85;
        }

        .brand {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(22px, 5vw, 28px);
            font-weight: 600;
            color: var(--text);
            letter-spacing: 0.03em;
            margin-bottom: 6px;
            line-height: 1.1;
        }

        .brand em {
            font-style: normal;
            color: var(--gold);
        }

        .subtitle {
            font-size: clamp(12px, 2.5vw, 13px);
            color: var(--muted);
            line-height: 1.55;
            margin-bottom: 16px;
            font-weight: 300;
        }

        .email-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 12px;
            color: var(--muted);
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 5px 13px;
            font-weight: 400;
        }

        .email-pill i { font-size: 11px; opacity: 0.7; }

        /* ── Divider ── */
        .divider {
            height: 1px;
            background: var(--line);
            margin: 0 clamp(20px, 5vw, 32px);
        }

        /* ── Role cards ── */
        .cards {
            padding: clamp(14px, 3vw, 20px) clamp(16px, 4vw, 24px);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .role-card {
            display: flex;
            align-items: center;
            gap: 14px;
            background: transparent;
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: clamp(12px, 3vw, 16px) clamp(14px, 3vw, 18px);
            cursor: pointer;
            text-align: left;
            width: 100%;
            color: var(--text);
            transition: border-color 0.22s var(--ease),
                        background   0.22s var(--ease),
                        transform    0.22s var(--ease);
            position: relative;
            overflow: hidden;
        }

        /* Hover shimmer */
        .role-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 50%, rgba(201,168,76,0.06), transparent 60%);
            opacity: 0;
            transition: opacity 0.25s;
        }

        .role-card:hover {
            border-color: var(--gold-border);
            background: var(--surface-2);
            transform: translateY(-1px);
        }

        .role-card:hover::before { opacity: 1; }

        .role-card:focus-visible {
            outline: 2px solid var(--gold);
            outline-offset: 2px;
        }

        .role-card.selected {
            border-color: var(--gold);
            background: var(--gold-dim);
        }

        .role-card[disabled] {
            opacity: 0.55;
            cursor: progress;
            pointer-events: none;
        }

        /* Icon */
        .icon-wrap {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--gold-border);
            background: var(--gold-dim);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
            font-size: 15px;
            transition: background 0.22s, border-color 0.22s;
        }

        .role-card:hover .icon-wrap,
        .role-card.selected .icon-wrap {
            background: rgba(201,168,76,0.25);
            border-color: var(--gold);
        }

        /* Text block */
        .role-info { flex: 1; min-width: 0; }

        .role-label {
            font-size: clamp(13px, 3vw, 14px);
            font-weight: 500;
            color: var(--text);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .role-name {
            font-size: clamp(11px, 2.5vw, 12px);
            color: var(--muted);
            font-weight: 300;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Badge */
        .role-badge {
            flex-shrink: 0;
            font-size: 10px;
            letter-spacing: 0.10em;
            text-transform: uppercase;
            color: var(--gold);
            background: var(--gold-dim);
            border: 1px solid var(--gold-border);
            border-radius: 999px;
            padding: 3px 9px;
            font-weight: 500;
        }

        /* Arrow */
        .arrow {
            flex-shrink: 0;
            color: var(--muted);
            font-size: 13px;
            transition: transform 0.22s var(--ease), color 0.22s;
        }

        .role-card:hover .arrow,
        .role-card.selected .arrow {
            transform: translateX(3px);
            color: var(--gold);
        }

        /* ── Loading ── */
        .loading-note {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--muted);
            font-size: 12px;
            padding: 0 24px 12px;
        }

        .loading-note.active { display: flex; }

        .spinner {
            width: 14px;
            height: 14px;
            border: 1.5px solid var(--line);
            border-top-color: var(--gold);
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Footer ── */
        .footer {
            padding: clamp(12px, 3vw, 16px) clamp(20px, 5vw, 32px) clamp(16px, 4vw, 22px);
            text-align: center;
            font-size: 12px;
            color: var(--muted);
            border-top: 1px solid var(--line);
            font-weight: 300;
        }

        .footer a {
            color: var(--gold);
            text-decoration: none;
            opacity: 0.9;
            transition: opacity 0.18s;
        }

        .footer a:hover { opacity: 1; text-decoration: underline; }

        /* ── Stagger animation for cards ── */
        .role-card:nth-child(1) { animation: cardIn 0.4s 0.15s var(--ease) both; }
        .role-card:nth-child(2) { animation: cardIn 0.4s 0.22s var(--ease) both; }
        .role-card:nth-child(3) { animation: cardIn 0.4s 0.29s var(--ease) both; }

        @keyframes cardIn {
            from { opacity: 0; transform: translateX(-8px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        /* ── Responsive tweaks ── */
        @media (max-width: 360px) {
            .role-badge { display: none; }
        }

        @media (min-width: 640px) {
            .shell { box-shadow: 0 32px 80px rgba(0,0,0,0.55); }
        }

        /* Tall phones — ensure shell never overflows */
        @media (max-height: 600px) {
            body { align-items: flex-start; padding-top: 16px; padding-bottom: 16px; }
        }
    </style>
</head>
<body>
    <main class="shell" role="main" aria-label="Role selection">

        <section class="hero">
            <div class="eyebrow">
                <i class="fas fa-compass" aria-hidden="true"></i>
                Choose your destination
            </div>
            <div class="brand">La Elena Fishyalan</div>
            <p class="subtitle">Select the role you want to use for this session.</p>

            <?php if ($maskedEmail !== ''): ?>
                <div class="email-pill">
                    <i class="far fa-envelope" aria-hidden="true"></i>
                    <?php echo htmlspecialchars($maskedEmail); ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="divider" role="separator"></div>

        <section class="cards" aria-label="Available roles">
            <?php foreach ($roleOptions as $option): ?>
                <button
                    type="button"
                    class="role-card"
                    data-role="<?php echo htmlspecialchars($option['role']); ?>"
                    data-user-id="<?php echo (int)$option['user_id']; ?>"
                    aria-label="Continue as <?php echo htmlspecialchars($option['label'] ?? ucfirst($option['role'])); ?>"
                >
                    <span class="icon-wrap" aria-hidden="true">
                        <i class="fas <?php echo htmlspecialchars($option['icon'] ?? 'fa-user-circle'); ?>"></i>
                    </span>
                    <div class="role-info">
                        <div class="role-label"><?php echo htmlspecialchars($option['label'] ?? ucfirst($option['role'])); ?></div>
                        <div class="role-name">
                            <?php echo htmlspecialchars($option['display_name'] ?? 'Account'); ?>
                            <?php if (!empty($option['description'])): ?>
                                &middot; <?php echo htmlspecialchars($option['description']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="role-badge" aria-hidden="true"><?php echo htmlspecialchars(ucfirst($option['role'])); ?></span>
                    <i class="fas fa-arrow-right arrow" aria-hidden="true"></i>
                </button>
            <?php endforeach; ?>
        </section>

        <div class="loading-note" id="loadingNote" aria-live="polite">
            <div class="spinner" aria-hidden="true"></div>
            Preparing your workspace…
        </div>

        <div class="footer">
            Need a different sign-in?
            <a href="<?php echo htmlspecialchars(app_url('auth/logout.php')); ?>">Return to login</a>
        </div>

    </main>

    <script>
        const cards      = document.querySelectorAll('.role-card');
        const loadingNote = document.getElementById('loadingNote');

        async function submitSelection(card) {
            const role   = card.dataset.role;
            const userId = Number(card.dataset.userId);

            cards.forEach(c => {
                c.disabled = true;
                c.classList.toggle('selected', c === card);
            });
            loadingNote.classList.add('active');

            try {
                const response = await fetch('select_destination.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ role, user_id: userId })
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to complete role selection.');
                }

                window.location.href = data.redirect;

            } catch (error) {
                cards.forEach(c => {
                    c.disabled = false;
                    c.classList.remove('selected');
                });
                loadingNote.classList.remove('active');

                Swal.fire({
                    icon: 'error',
                    title: 'Selection failed',
                    text: error.message || 'Please choose your role again.',
                    confirmButtonColor: '#c9a84c',
                    background: '#141414',
                    color: '#f0ead8'
                });
            }
        }

        cards.forEach(card => {
            card.addEventListener('click', () => submitSelection(card));
        });
    </script>
</body>
</html>