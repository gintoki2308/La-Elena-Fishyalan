<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is already logged in and redirect to appropriate dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (isset($_SESSION['user_role'])) {
        switch ($_SESSION['user_role']) {
            case 'admin':
                header('Location: ../adminSide/admin-dashboard.php');
                exit;
            case 'staff':
                header('Location: ../staffSide/staff-dashboard.php');
                exit;
            case 'customer':
                header('Location: ../customerSide/customer-dashboard.php');
                exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            /* Exact variables from login.php */
            --gold-light: #FCF6BA;
            --gold-main: #D4AF37;
            --gold-dark: #BF953F;
            --bg-deep: #050505;
            --bg-radial: radial-gradient(circle at 50% 0%, #1a1a1a 0%, #050505 80%);
            --card-bg: rgba(20, 20, 20, 0.7);
            --text-main: #f5f5f5;
            --text-muted: #a3a3a3;
            --border-gold: rgba(212, 175, 55, 0.3);
            --gold-gradient: linear-gradient(to right, #BF953F, #FCF6BA, #B38728, #FBF5B7, #AA771C);
            --input-bg: rgba(0, 0, 0, 0.4);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background: var(--bg-radial);
            background-attachment: fixed;
            color: var(--text-main);
            min-height: 100vh;
            margin: 0;
            padding: 40px 28px;
            display: block;
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

        .register-container {
            width: 100%;
            max-width: none;
            background: rgba(255, 253, 248, 0.14);
            backdrop-filter: blur(2px);
            border: none;
            border-radius: 0;
            box-shadow: none;
            padding: 24px 4vw 0;
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            margin: 0 auto;
        }

        #registerForm {
            width: 100%;
            max-width: 820px;
            margin: 0 auto;
        }

        .register-container::before,
        .register-container::after {
            content: none;
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

        .register-footer {
            text-align: center;
            padding: 16px 0;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 44px;
            padding: 10px 16px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 5px;
            background: rgba(12, 28, 34, 0.35);
            color: #f7f3ee;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        .back-link:hover {
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.6);
            color: #fff;
        }

        .back-link:focus-visible {
            outline: 3px solid #5daeb1;
            outline-offset: 3px;
        }

        .register-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .register-header h2 {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        .register-header p {
            font-size: 14px;
            font-style: italic;
            font-family: 'Playfair Display', serif;
            color: var(--text-muted);
            margin: 0;
        }

        .form-row {
            display: flex;
            gap: 16px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .address-help {
            margin: 7px 0 0;
            color: rgba(255, 255, 255, 0.78);
            font-size: 11px;
            line-height: 1.45;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i.input-icon {
            position: absolute;
            left: 16px;
            color: #666;
            font-size: 16px;
            transition: color 0.3s ease;
        }

        .input-wrapper .toggle-password {
            position: absolute;
            right: 16px;
            color: #666;
            font-size: 16px;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .input-wrapper .toggle-password:hover {
            color: var(--gold-main);
        }

        .form-control {
            width: 100%;
            padding: 14px 48px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            font-size: 14px;
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
            color: #555;
            font-weight: 400;
        }

        .btn-gold {
            width: 100%;
            padding: 15px;
            background: var(--gold-gradient);
            color: #000;
            border: none;
            border-radius: 4px;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 24px 0 16px 0;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.5);
        }

        .btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px rgba(212, 175, 55, 0.2);
        }

        .signin-link {
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }

        .signin-link a {
            color: var(--gold-main);
            text-decoration: none;
            font-weight: 500;
            margin-left: 4px;
            transition: all 0.3s ease;
        }

        .signin-link a:hover {
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
            border: 1px solid rgba(212, 175, 55, 0.2) !important;
        }

        .swal2-title {
            color: #D4AF37 !important;
            font-family: 'Playfair Display', serif !important;
        }

        .swal2-html-container {
            color: #a3a3a3 !important;
        }

        /* Coastal registration theme */
        :root {
            --gold-main: #e56b4f;
            --gold-light: #f7b486;
            --gold-dark: #d8573d;
            --gold-gradient: linear-gradient(90deg, #e56b4f, #f08462);
            --card-bg: rgba(255, 253, 248, 0.94);
            --input-bg: #eaf2ff;
            --text-main: #18333b;
            --text-muted: #63777a;
            --border-gold: rgba(20, 61, 74, 0.14);
        }

        body {
            background:
                linear-gradient(90deg, rgba(5, 25, 31, 0.82) 0%, rgba(10, 38, 46, 0.7) 45%, rgba(12, 25, 31, 0.56) 100%),
                url('../images/site/bg_image.png') center 54% / cover no-repeat;
        }

        .register-container {
            width: 100%;
            max-width: none;
            background: rgba(12, 28, 34, 0.28);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            box-shadow: 0 18px 45px rgba(2, 18, 22, 0.24);
            padding: 18px 4vw 0;
            backdrop-filter: blur(2px);
        }

        #registerForm {
            max-width: 820px;
        }

        .register-container::before,
        .register-container::after {
            border-color: transparent;
        }

        .metallic-text {
            background: none;
            color: #f7f3ee;
            -webkit-text-fill-color: #f7f3ee;
            animation: none;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.28);
        }

        .form-group label {
            color: rgba(255, 255, 255, 0.9);
            letter-spacing: 0.12em;
        }

        .form-control {
            background: rgba(233, 246, 250, 0.88);
            border-color: rgba(20, 61, 74, 0.2);
            color: #17353d;
            border-radius: 5px;
        }

        .form-control:focus {
            background: rgba(255, 255, 255, 0.96);
            color: #17353d;
            border-color: #5daeb1;
            box-shadow: 0 0 0 3px rgba(93, 174, 177, 0.17);
        }

        .form-control::placeholder {
            color: #647d82;
        }

        .input-wrapper i.input-icon,
        .input-wrapper .toggle-password {
            color: #355a64;
        }

        .input-wrapper .toggle-password:hover {
            color: #d8573d;
        }

        .btn-gold {
            background: #ed7058;
            color: #fff;
            border-radius: 5px;
            box-shadow: 0 10px 20px rgba(237, 112, 88, 0.2);
        }

        .btn-gold:hover {
            background: #df624b;
            box-shadow: 0 15px 25px rgba(237, 112, 88, 0.28);
        }

        .signin-link {
            color: rgba(255, 255, 255, 0.9);
        }

        .signin-link a {
            color: #ffd0c3;
        }

        .signin-link a:hover {
            color: #fff;
            text-shadow: none;
        }

        .swal2-popup {
            background: #fffdf8 !important;
            color: #18333b !important;
            border-color: rgba(20, 61, 74, 0.12) !important;
        }

        .swal2-title {
            color: #143d4a !important;
        }

        .swal2-html-container {
            color: #63777a !important;
        }

        @media (max-width: 480px) {
            .register-container {
                padding: 24px 0;
                border: none;
                box-shadow: none;
            }

            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>

<body>
    <div class="register-container">
        <div class="register-header">
            <h2 class="metallic-text">La Elena Fishyalan</h2>
        </div>

        <form id="registerForm">
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <div class="input-wrapper">
                        <input type="text" class="form-control" id="first_name" name="first_name"
                            placeholder="First name" required minlength="2" maxlength="50" pattern="[A-Za-z]+" title="Use letters only">
                        <i class="far fa-user input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <div class="input-wrapper">
                        <input type="text" class="form-control" id="last_name" name="last_name"
                            placeholder="Last name" required minlength="2" maxlength="50" pattern="[A-Za-z]+" title="Use letters only">
                        <i class="far fa-user input-icon"></i>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrapper">
                    <input type="email" class="form-control" id="email" name="email"
                        placeholder="Enter your email" required>
                    <i class="far fa-envelope input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <div class="input-wrapper">
                    <input type="tel" class="form-control" id="phone" name="phone"
                        placeholder="Enter your phone number" required>
                    <i class="fas fa-phone input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="purok">Purok/Sitio</label>
                <div class="input-wrapper">
                    <input type="text" class="form-control" id="purok" name="purok"
                        placeholder="Enter purok or sitio" required maxlength="100" autocomplete="address-line1"
                        aria-describedby="addressSuggestionHelp">
                    <i class="fas fa-map-pin input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="barangay">Barangay</label>
                <div class="input-wrapper">
                    <input type="text" class="form-control" id="barangay" name="barangay"
                        placeholder="Choose or enter barangay" required maxlength="100" autocomplete="address-line2"
                        list="barangaySuggestions" aria-describedby="addressSuggestionHelp">
                    <i class="fas fa-map-marker-alt input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="municipality">Municipality/City</label>
                <div class="input-wrapper">
                    <input type="text" class="form-control" id="municipality" name="municipality"
                        placeholder="Choose municipality or city" required maxlength="100" autocomplete="address-level2"
                        list="municipalitySuggestions" aria-describedby="addressSuggestionHelp">
                    <i class="fas fa-location-dot input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="province">Province</label>
                <div class="input-wrapper">
                    <input type="text" class="form-control" id="province" name="province"
                        placeholder="Choose a province" required maxlength="100" autocomplete="address-level1"
                        list="provinceSuggestions" aria-describedby="addressSuggestionHelp">
                    <i class="fas fa-landmark input-icon"></i>
                </div>
                <p class="address-help" id="addressSuggestionHelp" aria-live="polite">Choose a province first, then a municipality/city and barangay. Purok/Sitio can be entered manually.</p>
            </div>

            <datalist id="provinceSuggestions"></datalist>
            <datalist id="municipalitySuggestions"></datalist>
            <datalist id="barangaySuggestions"></datalist>

            <div class="form-group">
                <label for="password">Passcode</label>
                <div class="input-wrapper">
                    <input type="password" class="form-control" id="password" name="password"
                        placeholder="Enter a passcode (min 6 chars)" required minlength="6">
                    <i class="fas fa-lock input-icon"></i>
                    <i class="far fa-eye toggle-password" id="togglePassword1" title="Show password"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Passcode</label>
                <div class="input-wrapper">
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                        placeholder="Confirm your passcode" required minlength="6">
                    <i class="fas fa-lock input-icon"></i>
                    <i class="far fa-eye toggle-password" id="togglePassword2" title="Show password"></i>
                </div>
            </div>

            <div class="loading-spinner" id="loadingSpinner">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted" style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Creating Account...</p>
            </div>

            <button type="submit" class="btn-gold">
                Create Account <i class="fas fa-arrow-right ms-2" style="font-size: 0.8rem;"></i>
            </button>
        </form>

        <div class="signin-link">
            Already have an account? <a href="login.php">Sign In</a>
        </div>
        <div class="register-footer">
            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
                Back to Sign In
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const registerForm = document.getElementById('registerForm');
        const loadingSpinner = document.getElementById('loadingSpinner');

        // Password Visibility Toggles
        function setupPasswordToggle(toggleId, inputId) {
            const toggle = document.getElementById(toggleId);
            const input = document.getElementById(inputId);

            toggle.addEventListener('click', function() {
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
            });
        }

        setupPasswordToggle('togglePassword1', 'password');
        setupPasswordToggle('togglePassword2', 'confirm_password');

        // Keep name fields limited to alphabetic characters, including pasted text.
        ['first_name', 'last_name'].forEach((inputId) => {
            document.getElementById(inputId).addEventListener('input', (event) => {
                event.target.value = event.target.value.replace(/[^A-Za-z]/g, '');
            });
        });

        // Location suggestions use the Philippine Standard Geographic Code (PSGC)
        // hierarchy: province -> municipality/city -> barangay. They remain normal
        // text inputs, so a customer can still enter an address when offline.
        const placeApi = 'https://psgc.cloud/api/v1';
        const placeInputs = {
            province: document.getElementById('province'),
            municipality: document.getElementById('municipality'),
            barangay: document.getElementById('barangay')
        };
        const placeLists = {
            province: document.getElementById('provinceSuggestions'),
            municipality: document.getElementById('municipalitySuggestions'),
            barangay: document.getElementById('barangaySuggestions')
        };
        const addressSuggestionHelp = document.getElementById('addressSuggestionHelp');
        let provinces = [];
        let municipalities = [];

        function listItems(payload) {
            return Array.isArray(payload) ? payload : (payload.data || []);
        }

        function setSuggestions(list, places) {
            list.replaceChildren(...places.map((place) => {
                const option = document.createElement('option');
                option.value = place.name;
                return option;
            }));
        }

        function findPlace(places, name) {
            const normalizedName = name.trim().toLocaleLowerCase();
            return places.find((place) => place.name.toLocaleLowerCase() === normalizedName);
        }

        async function getPlaces(path) {
            const response = await fetch(`${placeApi}${path}`);
            if (!response.ok) throw new Error('Place suggestions are unavailable.');
            return listItems(await response.json());
        }

        async function loadProvinces() {
            try {
                provinces = await getPlaces('/provinces?per_page=200');
                setSuggestions(placeLists.province, provinces);
                addressSuggestionHelp.textContent = 'Start by choosing a province. We will suggest matching municipalities/cities and barangays.';
            } catch (error) {
                addressSuggestionHelp.textContent = 'Place suggestions are currently unavailable. You can still enter your address manually.';
            }
        }

        placeInputs.province.addEventListener('change', async () => {
            const province = findPlace(provinces, placeInputs.province.value);
            municipalities = [];
            placeInputs.municipality.value = '';
            placeInputs.barangay.value = '';
            setSuggestions(placeLists.municipality, []);
            setSuggestions(placeLists.barangay, []);

            if (!province) return;

            addressSuggestionHelp.textContent = 'Loading municipalities and cities for the selected province...';
            try {
                municipalities = await getPlaces(`/cities-municipalities?province_code=${encodeURIComponent(province.code)}&per_page=200`);
                setSuggestions(placeLists.municipality, municipalities);
                addressSuggestionHelp.textContent = 'Choose a municipality or city next.';
            } catch (error) {
                addressSuggestionHelp.textContent = 'Municipality suggestions are unavailable. You can still enter the address manually.';
            }
        });

        placeInputs.municipality.addEventListener('change', async () => {
            const municipality = findPlace(municipalities, placeInputs.municipality.value);
            placeInputs.barangay.value = '';
            setSuggestions(placeLists.barangay, []);

            if (!municipality) return;

            addressSuggestionHelp.textContent = 'Loading barangays for the selected municipality or city...';
            try {
                const barangays = await getPlaces(`/cities-municipalities/${encodeURIComponent(municipality.code)}/barangays?per_page=500`);
                setSuggestions(placeLists.barangay, barangays);
                addressSuggestionHelp.textContent = 'Choose a barangay, then enter your purok or sitio.';
            } catch (error) {
                addressSuggestionHelp.textContent = 'Barangay suggestions are unavailable. You can still enter the address manually.';
            }
        });

        loadProvinces();

        registerForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const firstName = document.getElementById('first_name').value.trim();
            const lastName = document.getElementById('last_name').value.trim();
            const email = document.getElementById('email').value.trim().toLowerCase();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const phone = document.getElementById('phone').value.trim();
            const purok = document.getElementById('purok').value.trim();
            const barangay = document.getElementById('barangay').value.trim();
            const municipality = document.getElementById('municipality').value.trim();
            const province = document.getElementById('province').value.trim();
            const address = [purok, barangay, municipality, province].filter(Boolean).join(', ');

            if (!firstName || !lastName || !email || !password || !purok || !barangay || !municipality || !province) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: 'Please fill in all required fields.',
                    confirmButtonColor: '#D4AF37', // Updated to match login gold
                    background: '#1a1a1a', // Updated to match login background
                    color: '#fff'
                });
                return;
            }

            if (!/^[A-Za-z]+$/.test(firstName) || !/^[A-Za-z]+$/.test(lastName)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Name',
                    text: 'First name and last name must contain letters only.',
                    confirmButtonColor: '#D4AF37',
                    background: '#1a1a1a',
                    color: '#fff'
                });
                return;
            }

            if (password !== confirmPassword) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Passcodes Do Not Match',
                    text: 'Please make sure your passcodes match.',
                    confirmButtonColor: '#D4AF37',
                    background: '#1a1a1a',
                    color: '#fff'
                });
                return;
            }

            loadingSpinner.classList.add('active');

            try {
                const response = await fetch('register_process.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        first_name: firstName,
                        last_name: lastName,
                        email: email,
                        password: password,
                        phone_number: phone,
                        purok: purok,
                        barangay: barangay,
                        municipality: municipality,
                        province: province,
                        address: address
                    })
                });

                const data = await response.json();

                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Check Your Email',
                        text: data.message || 'We sent you a verification code.',
                        timer: 2000,
                        showConfirmButton: false,
                        iconColor: '#D4AF37',
                        background: '#1a1a1a',
                        color: '#fff'
                    }).then(() => {
                        window.location.href = data.redirect || ('verify_account.php?email=' + encodeURIComponent(email));
                    });
                } else {
                    const alertOptions = {
                        icon: 'error',
                        title: 'Registration Failed',
                        text: data.message || 'An error occurred.',
                        confirmButtonColor: '#D4AF37',
                        background: '#1a1a1a',
                        color: '#fff'
                    };

                    if (data.redirect && data.action_label) {
                        alertOptions.showCancelButton = true;
                        alertOptions.confirmButtonText = data.action_label;
                        alertOptions.cancelButtonText = 'Stay Here';
                    }

                    Swal.fire(alertOptions).then((result) => {
                        if (data.redirect && data.action_label && result.isConfirmed) {
                            window.location.href = data.redirect;
                        }
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Unable to connect to the server.',
                    confirmButtonColor: '#D4AF37',
                    background: '#1a1a1a',
                    color: '#fff'
                });
            } finally {
                loadingSpinner.classList.remove('active');
            }
        });
    </script>
    <script src="../assets/js/phone-validator.js"></script>
</body>

</html>
