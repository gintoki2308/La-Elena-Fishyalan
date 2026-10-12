<?php
require_once __DIR__ . '/../config/config.php';

// Check if user is already logged in and redirect to appropriate dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    // Redirect based on user role
    if (isset($_SESSION['user_role'])) {
        switch ($_SESSION['user_role']) {
            case 'admin':
                header('Location: ' . app_url('adminSide/admin-dashboard.php'));
                exit;
            case 'staff':
                header('Location: ' . app_url('staffSide/staff-dashboard.php'));
                exit;
            case 'customer':
                header('Location: ' . app_url('customerSide/customer-dashboard.php'));
                exit;
            default:
                // If role is unknown, destroy session and show login
                session_destroy();
                break;
        }
    }
}

if (!empty($_SESSION['pending_role_selection'])) {
    $pendingSelection = $_SESSION['pending_role_selection'];
    $selectionAge = time() - (int)($pendingSelection['created_at'] ?? 0);

    if ($selectionAge <= ROLE_SELECTION_TIMEOUT) {
        header('Location: ' . app_url('auth/choose_destination.php'));
        exit;
    }

    unset($_SESSION['pending_role_selection']);
}

// Check for logout message
$logout_message = '';
if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $logout_message = 'You have been successfully logged out.';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gold-light: #FCF6BA;
            --gold-main: #D4AF37;
            --gold-dark: #BF953F;
            --bg-deep: #07171b;
            --bg-radial: radial-gradient(circle at 50% 0%, #10272d 0%, #07171b 80%);
            --card-bg: rgba(8, 23, 27, 0.94);
            --text-main: #f3f1ec;
            --text-muted: #aaadb0;
            --border-gold: rgba(208, 169, 48, 0.44);
            --gold-gradient: linear-gradient(to right, #BF953F, #FCF6BA, #B38728, #FBF5B7, #AA771C);
            --input-bg: rgba(3, 14, 17, 0.8);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background: radial-gradient(circle at 84% 60%, rgba(83, 57, 20, 0.3), transparent 36%),
                linear-gradient(145deg, #0c2228 0%, #07161a 58%, #1b130d 100%);
            color: var(--text-main);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        /* Metallic Text Utility */
        .metallic-text {
            background: var(--gold-gradient);
            background-size: 200% auto;
            color: #000;
            background-clip: text;
            text-fill-color: transparent;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: shine 5s linear infinite;
        }

        @keyframes shine {
            to {
                background-position: 200% center;
            }
        }

        .login-container {
            width: 100%;
            max-width: 550px;
            min-height: 656px;
            background: var(--card-bg);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, 0.025);
            border-radius: 5px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.48), inset 0 1px 0 rgba(255, 255, 255, 0.025);
            padding: 60px 51px 42px;
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        /* Subtle corner accents matching the loyalty page */
        .login-container::before,
        .login-container::after {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            border: 1px solid var(--gold-main);
            opacity: 0.65;
        }

        .login-container::before {
            top: 15px;
            left: 15px;
            border-right: none;
            border-bottom: none;
        }

        .login-container::after {
            bottom: 15px;
            right: 15px;
            border-left: none;
            border-top: none;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .login-header h2 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(31px, 6vw, 40px);
            font-weight: 700;
            margin-bottom: 0;
            letter-spacing: 0.4px;
        }

        .login-header p {
            font-size: 14px;
            font-style: italic;
            font-family: 'Playfair Display', serif;
            color: var(--text-muted);
            margin: 0;
        }

        .logout-message {
            background-color: rgba(212, 175, 55, 0.1);
            color: var(--gold-main);
            border: 1px solid var(--border-gold);
            border-radius: 4px;
            padding: 12px 16px;
            margin-bottom: 24px;
            text-align: center;
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 1px;
            animation: fadeOut 3s forwards;
            animation-delay: 2s;
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                visibility: hidden;
            }
        }

        .form-group {
            margin-bottom: 26px;
        }

        .form-group label {
            display: block;
            margin-bottom: 11px;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 1.35px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i.input-icon {
            position: absolute;
            left: 20px;
            color: #72777a;
            font-size: 19px;
            transition: color 0.3s ease;
        }

        .input-wrapper .toggle-password {
            position: absolute;
            right: 20px;
            color: #72777a;
            font-size: 19px;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .input-wrapper .toggle-password:hover {
            color: var(--gold-main);
        }

        .form-control {
            width: 100%;
            height: 64px;
            padding: 14px 54px 14px 61px;
            border: 1px solid rgba(178, 195, 196, 0.25);
            border-radius: 5px;
            font-size: 17px;
            transition: all 0.3s ease;
            background-color: var(--input-bg);
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
        }

        .form-control:focus {
            border-color: var(--gold-main);
            background-color: rgba(0, 0, 0, 0.6);
            box-shadow: 0 0 10px rgba(212, 175, 55, 0.2);
            outline: none;
            color: white;
        }

        .form-control:focus+i.input-icon {
            color: var(--gold-main);
        }

        .form-control::placeholder {
            color: #74777a;
            font-weight: 400;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 16px;
            color: var(--text-muted);
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--gold-main);
            background-color: var(--input-bg);
        }

        .forgot-link {
            color: var(--gold-main);
            text-decoration: none;
            font-size: 16px;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .forgot-link:hover {
            color: var(--gold-light);
            text-shadow: 0 0 5px rgba(252, 246, 186, 0.5);
        }

        .btn-gold {
            width: 100%;
            padding: 19px 15px;
            background: var(--gold-gradient);
            color: #000;
            border: none;
            border-radius: 5px;
            font-weight: 700;
            font-size: 17px;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 42px 0 28px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.5);
        }

        .btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px rgba(212, 175, 55, 0.2);
        }

        .btn-back-home {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px 16px;
            margin-bottom: 18px;
            border: 1px solid rgba(20, 61, 74, 0.18);
            border-radius: 5px;
            background: #fff;
            color: #18333b;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.25s ease;
        }

        .btn-back-home:hover {
            background: #fef4f0;
            border-color: rgba(229, 107, 79, 0.5);
            color: #d8573d;
            text-decoration: none;
        }

        .divider {
            text-align: center;
            position: relative;
            margin: 28px 0;
        }

        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: calc(50% - 24px);
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .divider::before {
            left: 0;
        }

        .divider::after {
            right: 0;
        }

        .divider span {
            background: var(--bg-deep);
            padding: 0 12px;
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }



        .signup-link {
            text-align: center;
            color: var(--text-muted);
            font-size: 16px;
        }

        .signup-link a {
            color: var(--gold-main);
            text-decoration: none;
            font-weight: 500;
            margin-left: 5px;
            transition: all 0.3s ease;
        }

        .signup-link a:hover {
            color: var(--gold-light);
            text-shadow: 0 0 5px rgba(252, 246, 186, 0.3);
        }

        .loading-spinner {
            display: none;
            text-align: center;
            margin-bottom: 20px;
        }

        .loading-spinner.active {
            display: block;
        }

        .spinner-border {
            width: 2rem;
            height: 2rem;
            color: var(--gold-main);
        }

        /* SweetAlert Customization for Dark Theme */
        .swal2-popup {
            background: #111 !important;
            color: #f5f5f5 !important;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .swal2-title {
            color: #D4AF37 !important;
            font-family: 'Playfair Display', serif !important;
        }

        .swal2-html-container {
            color: #a3a3a3 !important;
        }

        /* Coastal sign-in theme */
        :root {
            --gold-main: #e56b4f;
            --gold-light: #f7b486;
            --gold-gradient: linear-gradient(90deg, #e56b4f, #f08462);
            --card-bg: rgba(255, 253, 248, 0.92);
            --input-bg: #ffffff;
            --text-main: #18333b;
            --text-muted: #63777a;
            --border-gold: rgba(20, 61, 74, 0.17);
        }

        body {
            background:
                linear-gradient(90deg, rgba(5, 25, 31, 0.55), rgba(12, 25, 31, 0.35)),
                url('../images/site/bg_image.png') center center / cover no-repeat;
            background-attachment: fixed;
        }

        .login-container {
            max-width: 460px;
            min-height: 0;
            padding: 46px 40px 36px;
            border-color: rgba(20, 61, 74, .09);
            box-shadow: 0 22px 55px rgba(20, 61, 74, .14);
        }

        .login-header { margin-bottom: 28px; }
        .form-group { margin-bottom: 20px; }
        .btn-gold { padding: 16px 15px; margin: 28px 0 20px; }
        .divider { margin: 20px 0; }

        .metallic-text {
            background: none;
            color: #143d4a;
            -webkit-text-fill-color: #143d4a;
            animation: none;
        }

        .form-control {
            color: #18333b;
            border-color: rgba(20, 61, 74, .18);
        }

        .form-control:focus {
            background: #fff;
            color: #18333b;
            border-color: #5daeb1;
            box-shadow: 0 0 0 3px rgba(93, 174, 177, .17);
        }

        .form-control::placeholder {
            color: #91a0a2;
        }

        .input-wrapper i.input-icon,
        .input-wrapper .toggle-password {
            color: #5d777c;
        }

        .btn-gold {
            color: #fff;
            box-shadow: 0 10px 20px rgba(229, 107, 79, .2);
        }

        .forgot-link,
        .signup-link a {
            color: #d8573d;
        }

        .swal2-popup {
            background: #fffdf8 !important;
            color: #18333b !important;
            border-color: rgba(20, 61, 74, .12);
        }

        .swal2-title {
            color: #143d4a !important;
        }

        .swal2-html-container {
            color: #63777a !important;
        }

        @media (max-width: 480px) {
            body {
                align-items: stretch;
            }

            .login-container {
                min-height: 100vh;
                padding: 62px 32px 40px;
                border: none;
                box-shadow: none;
            }

            .login-header {
                margin-bottom: 38px;
            }

            .form-control {
                font-size: 16px;
            }

            .checkbox-wrapper,
            .forgot-link,
            .signup-link {
                font-size: 14px;
            }

            .role-options {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-header">
            <h2 class="metallic-text">La Elena Fishyalan</h2>
        </div>

        <?php if ($logout_message): ?>
            <div class="logout-message" id="logoutMessage">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($logout_message); ?>
            </div>
        <?php endif; ?>

        <form id="loginForm">
            <div class="form-group">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <input type="email" class="form-control" id="email" name="email"
                        placeholder="Enter your email" required>
                    <i class="far fa-envelope input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Passcode</label>
                <div class="input-wrapper">
                    <input type="password" class="form-control" id="password" name="password"
                        placeholder="Enter your password" required>
                    <i class="fas fa-lock input-icon"></i>
                    <i class="far fa-eye toggle-password" id="togglePassword" title="Show password"></i>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <label class="checkbox-wrapper">
                    <input type="checkbox" id="keep_signed_in">
                    <span>Keep me signed in</span>
                </label>
                <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
            </div>

            <div class="loading-spinner" id="loadingSpinner">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted" style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Authenticating...</p>
            </div>

            <button type="submit" class="btn-gold">
                Sign In <i class="fas fa-arrow-right ms-2" style="font-size: 0.8rem;"></i>
            </button>
        </form>

        <a href="../index.html" class="btn-back-home">
            <i class="fas fa-house"></i> Back to Main Page
        </a>

        <div class="signup-link">
            New to our establishment? <a href="register.php">Create an account</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // DOM Elements
        const loginForm = document.getElementById('loginForm');
        const loadingSpinner = document.getElementById('loadingSpinner');
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        // Password Visibility Toggle
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);

            // Toggle icon classes
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        // Sign In Form Handler
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const email = document.getElementById('email').value.trim();
            const password = passwordInput.value;
            const keepSignedIn = document.getElementById('keep_signed_in').checked;


            if (!email || !password) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: 'Please enter both email and passcode.',
                    confirmButtonColor: '#D4AF37', // Gold matching theme
                    background: '#1a1a1a',
                    color: '#fff'
                });
                return;
            }

            // Show loading spinner
            loadingSpinner.classList.add('active');

            try {
                const response = await fetch('authenticate.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        email: email,
                        password: password,
                        keep_signed_in: keepSignedIn
                    })
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    if (data.requires_role_selection) {
                        Swal.fire({
                            icon: 'info',
                            title: 'Choose Your Account',
                            text: data.message || 'Select which role you want to use for this session.',
                            timer: 1800,
                            showConfirmButton: false,
                            iconColor: '#D4AF37',
                            background: '#1a1a1a',
                            color: '#fff'
                        }).then(() => {
                            window.location.href = data.redirect || 'choose_destination.php';
                        });
                    } else {
                        Swal.fire({
                            icon: 'success',
                            title: 'Welcome Back',
                            text: data.message || 'Authentication successful.',
                            timer: 1500,
                            showConfirmButton: false,
                            iconColor: '#D4AF37', // Gold matching theme
                            background: '#1a1a1a',
                            color: '#fff'
                        }).then(() => {
                            window.location.href = data.redirect || 'dashboard.php';
                        });
                    }
                } else {
                    const alertOptions = {
                        icon: data.requires_verification ? 'info' : 'error',
                        title: data.requires_verification ? 'Verification Required' : 'Access Denied',
                        text: data.message || 'Invalid credentials.',
                        confirmButtonColor: '#D4AF37',
                        background: '#1a1a1a',
                        color: '#fff'
                    };

                    if (data.requires_verification && data.redirect) {
                        alertOptions.showCancelButton = true;
                        alertOptions.confirmButtonText = data.action_label || 'Verify Now';
                        alertOptions.cancelButtonText = 'Stay Here';
                    }

                    Swal.fire(alertOptions).then((result) => {
                        if (data.requires_verification && data.redirect && result.isConfirmed) {
                            window.location.href = data.redirect;
                        }
                    });
                }
            } catch (error) {
                console.error('Login Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Unable to reach the server. Please try again.',
                    confirmButtonColor: '#D4AF37',
                    background: '#1a1a1a',
                    color: '#fff'
                });
            } finally {
                loadingSpinner.classList.remove('active');
            }
        });

        // Pre-fill email from URL parameter
        const urlParams = new URLSearchParams(window.location.search);
        const emailParam = urlParams.get('email');
        if (emailParam) {
            document.getElementById('email').value = emailParam;
            document.getElementById('password').focus();
        }

        // Auto-hide logout message after animation
        setTimeout(() => {
            const logoutMessage = document.getElementById('logoutMessage');
            if (logoutMessage) {
                logoutMessage.style.display = 'none';
            }
        }, 5000);
    </script>
</body>

</html>
