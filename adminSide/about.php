<?php
require_once '../config/config.php';
require_once '../config/Database.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$database = new Database();
$db = $database->connect();

// ---------- FETCH CURRENT ADMIN DATA FOR NAVBAR ----------
$stmt = $db->prepare("SELECT * FROM admins WHERE admin_id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

$displayName = $admin['display_name'] ?? ($admin['first_name'] . ' ' . $admin['last_name']);
$avatarSrc = getProfileAvatar($admin['profile_picture'] ?? '', $admin['first_name'] ?? '', $admin['last_name'] ?? '');

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Developer - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin-dashboard.css">
    <style>
        .text-gold {
            color: #d4af37;
        }

        .developer-about {
            max-width: 900px;
            margin: 0 auto;
            font-size: 1.05rem;
            line-height: 1.9;
        }

        .developer-about p {
            color: #18333b !important;
            font-weight: 400;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="admin-dashboard.php">Admin Panel</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarCollapse">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="sidebarCollapse">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <img src="<?php echo $avatarSrc; ?>" alt="Avatar" class="rounded-circle me-2" width="30" height="30" style="object-fit: cover; border: 1px solid var(--gold);">
                        <span><?php echo htmlspecialchars($displayName); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-tie me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="#settings"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="logout-link dropdown-item" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <aside class="col-md-3 col-lg-2 sidebar bg-dark">
                <div class="sidebar-header">
                    <h5 class="text-gold mb-4"><i class="fas fa-chart-line me-2"></i>Dashboard</h5>
                </div>
                <nav class="nav flex-column">
                    <a class="nav-link" href="admin-dashboard.php"><i class="fas fa-home me-2"></i>Overview</a>
                    <a class="nav-link active" href="about.php"><i class="fas fa-code me-2"></i>About</a>
                </nav>
            </aside>

            <main class="col-md-9 col-lg-10 main-content p-4">
                <div class="dashboard-header mb-4">
                    <h1 class="display-6"><i class="fas fa-address-card me-2 text-gold"></i>System Information</h1>
                    <p class="text-gold">Information about the creator of this system.</p>
                </div>

                <div class="card bg-dark border-secondary">
                    <div class="card-body p-4 p-md-5">
                        <div class="developer-about">
                            <h4 class="text-gold mb-3" style="font-family: 'Playfair Display', serif;">About The Developer</h4>
                            <p class="mb-0">
                                This system was developed as a comprehensive web-based restaurant management platform designed to streamline online orders, manage inventory, and provide a seamless dining experience for customers.
                            </p>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
    <script>
        document.addEventListener('click', function(e) {
            const link = e.target.closest('.logout-link');
            if (link) {
                e.preventDefault();
                const targetHref = link.href || link.getAttribute('data-href') || '../auth/login.php';
                Swal.fire({
                    title: 'Ready to leave?',
                    text: 'You will be logged out of your session.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes, logout',
                    background: '#2d2d2d',
                    color: '#fff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        if (link.tagName.toUpperCase() === 'BUTTON' && link.closest('form')) {
                            link.closest('form').submit();
                        } else {
                            window.location.href = targetHref;
                        }
                    }
                });
            }
        });
    </script>
</body>

</html>
