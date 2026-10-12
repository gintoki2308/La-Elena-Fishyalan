<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/pagination_helper.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

$error = null;
$success_msg = null;

// handle removal via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'remove_favorite') {
        $itemId = intval($_POST['item_id']);
        if ($itemId) {
            $del = $db->prepare("DELETE FROM favorites WHERE member_id = :mid AND item_id = :iid");
            $del->execute([':mid' => $_SESSION['user_id'], ':iid' => $itemId]);
            $success_msg = "Item removed from your favorites.";
        }
    } elseif ($_POST['action'] === 'add_to_cart') {
        $itemId = intval($_POST['item_id']);
        $qty = max(1, intval($_POST['quantity'] ?? 1));
        
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        
        if (isset($_SESSION['cart'][$itemId])) {
            $_SESSION['cart'][$itemId] += $qty;
        } else {
            $_SESSION['cart'][$itemId] = $qty;
        }
        
        $success_msg = "Item added to your cart!";
    } elseif ($_POST['action'] === 'clear_favorites') {
        $del = $db->prepare("DELETE FROM favorites WHERE member_id = :mid");
        $del->execute([':mid' => $_SESSION['user_id']]);
        $success_msg = "All favorites have been cleared.";
    }
}

// Get favorites with item details
$favorites = [];
$total_value = 0;
try {
    $stmt = $db->prepare("SELECT mi.*, f.created_at as favorited_date 
                          FROM favorites f 
                          JOIN menu_items mi ON f.item_id = mi.item_id 
                          WHERE f.member_id = :mid AND mi.is_available = TRUE 
                          ORDER BY f.created_at DESC");
    $stmt->execute([':mid' => $_SESSION['user_id']]);
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($favorites as $item) {
        $total_value += $item['price'];
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$cart_count = array_sum($_SESSION['cart'] ?? []);
$favorite_count = count($favorites);
$favoritesPagination = paginate_array($favorites, 6);
$favorites = $favoritesPagination['items'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Favorites - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gold: #D4AF37;
            --dark-gold: #B4941F;
            --deep-charcoal: #121212;
            --charcoal: #1E1E1E;
            --light-gray: #E0E0E0;
            --white: #FFFFFF;
            --heart-red: #ff4757;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background-color: var(--deep-charcoal);
            min-height: 100vh;
            color: var(--light-gray);
        }

        /* Navbar Styling */
        .navbar {
            background-color: #1a1a1a;
            padding: 1rem 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
        }

        .navbar-brand {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--gold) !important;
            letter-spacing: 1px;
        }

        .navbar-brand i {
            color: var(--gold);
            margin-right: 10px;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.8) !important;
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            margin: 0 0.2rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            color: var(--gold) !important;
            background: rgba(212, 175, 55, 0.1);
            transform: translateY(-2px);
        }

        .nav-link.active {
            color: var(--gold) !important;
            border-bottom: 2px solid var(--gold);
            border-radius: 0;
            background: transparent;
        }

        .cart-badge {
            background: var(--gold);
            color: var(--deep-charcoal);
            font-weight: 600;
            padding: 0.25rem 0.6rem;
            border-radius: 50px;
            margin-left: 5px;
        }

        /* Hero Section */
        .favorites-hero {
            background: linear-gradient(rgba(18, 18, 18, 0.8), rgba(18, 18, 18, 0.9)), url('https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80');
            background-size: cover;
            background-position: center;
            padding: 4rem 2rem;
            border-radius: 20px;
            margin: 2rem 0;
            color: var(--white);
            text-align: center;
            border: 1px solid rgba(212, 175, 55, 0.2);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            position: relative;
            overflow: hidden;
        }

        .favorites-hero h1 {
            font-family: 'Playfair Display', serif;
            font-size: 3.5rem;
            font-weight: 700;
            color: var(--gold);
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.8);
        }

        .favorites-hero h1 i {
            color: var(--heart-red);
            font-size: 2.5rem;
            vertical-align: middle;
            animation: heartbeat 1.5s ease infinite;
        }

        @keyframes heartbeat {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .favorites-hero p {
            font-size: 1.2rem;
            color: var(--light-gray);
            max-width: 600px;
            margin: 0 auto;
        }

        .favorites-stats {
            display: flex;
            justify-content: center;
            gap: 3rem;
            margin-top: 2rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gold);
        }

        .stat-label {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #aaa;
        }

        /* Favorites Grid */
        .favorites-grid {
            margin-top: 2rem;
        }

        .favorite-card {
            background: var(--charcoal);
            border: 1px solid rgba(212, 175, 55, 0.1);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
            height: 100%;
            position: relative;
        }

        .favorite-card:hover {
            transform: translateY(-10px);
            border-color: var(--gold);
            box-shadow: 0 15px 40px rgba(212, 175, 55, 0.15);
        }

        .favorite-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: var(--heart-red);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            z-index: 2;
            box-shadow: 0 4px 10px rgba(255, 71, 87, 0.3);
        }

        .favorite-image {
            height: 180px;
            background: #151515;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            overflow: hidden;
        }

        .favorite-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.3s ease;
        }

        .favorite-card:hover .favorite-image img {
            transform: scale(1.1);
        }

        .favorite-content {
            padding: 1.5rem;
        }

        .favorite-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .favorite-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 0.3rem;
        }

        .favorite-category {
            color: var(--gold);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .favorite-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gold);
        }

        .favorite-description {
            color: #aaa;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .favorite-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-size: 0.85rem;
            color: #888;
        }

        .favorite-meta i {
            margin-right: 5px;
            color: var(--gold);
        }

        .favorite-actions {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        .favorite-cart-form {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            min-width: 0;
        }

        .favorite-cart-row {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .btn-add-cart {
            flex: 1;
            background: var(--gold);
            border: none;
            color: var(--deep-charcoal);
            padding: 0.6rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-add-cart:hover {
            background: var(--white);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(212, 175, 55, 0.4);
        }

        .btn-remove {
            background: transparent;
            border: 1px solid var(--heart-red);
            color: var(--heart-red);
            padding: 0.6rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            min-height: 44px;
            white-space: nowrap;
        }

        .favorite-remove-form {
            display: flex;
            align-items: flex-end;
        }

        .btn-remove:hover {
            background: var(--heart-red);
            color: white;
        }

        .quantity-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0;
        }

        .quantity-input {
            width: 70px;
            text-align: center;
            background: #2A2A2A;
            color: white;
            border: 1px solid #444;
            border-radius: 6px;
            padding: 0.3rem;
            font-weight: 600;
        }

        .quantity-input:focus {
            outline: none;
            border-color: var(--gold);
        }

        /* Empty State */
        .empty-favorites {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--charcoal);
            border: 1px dashed rgba(212, 175, 55, 0.3);
            border-radius: 20px;
            margin-top: 2rem;
        }

        .empty-favorites i {
            font-size: 5rem;
            color: var(--heart-red);
            margin-bottom: 1.5rem;
            opacity: 0.7;
            animation: heartbeat 2s ease infinite;
        }

        .empty-favorites h3 {
            font-family: 'Playfair Display', serif;
            color: var(--gold);
            margin-bottom: 1rem;
        }

        .empty-favorites p {
            color: #aaa;
            margin-bottom: 2rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        .btn-gold {
            background: var(--gold);
            color: var(--deep-charcoal);
            font-weight: 700;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-gold:hover {
            background: var(--white);
            color: var(--deep-charcoal);
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
        }

        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 2rem 0 1rem;
        }

        .action-bar .text-muted {
            color: #aaa !important;
        }

        .btn-outline-danger {
            background: transparent;
            border: 1px solid var(--heart-red);
            color: var(--heart-red);
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-outline-danger:hover {
            background: var(--heart-red);
            color: white;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 1px solid var(--gold);
            color: var(--gold);
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-outline-secondary:hover {
            background: var(--gold);
            color: var(--deep-charcoal);
        }

        /* Sweet Alert Overrides for Dark Mode */
        .swal2-popup {
            background: var(--charcoal) !important;
            color: var(--white) !important;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }
        .swal2-title, .swal2-html-container {
            color: var(--white) !important;
        }

        @media (max-width: 768px) {
            .navbar-brand { font-size: 1.4rem; }
            .favorites-hero h1 { font-size: 2.2rem; }
            .favorites-stats { flex-direction: column; gap: 1rem; }
            .favorite-actions { flex-direction: column; }
            .favorite-cart-row { flex-direction: column; align-items: stretch; }
            .favorite-remove-form { width: 100%; }
            .action-bar { flex-direction: column; gap: 1rem; }
            .action-bar .btn { width: 100%; }
        }
</style>
    <link rel="stylesheet" href="customer-theme.css">
</head>
<body>
    <?php
    $customerNavPage = 'favorites.php';
    require 'customer-navbar.php';
    ?>

    <div class="container">
        <!-- Hero Section -->
        <div class="favorites-hero">
            <h1>
                <i class="fas fa-heart"></i>
                My Favorites
                <i class="fas fa-heart"></i>
            </h1>
            <p>Your personally curated collection of our finest culinary creations</p>
            
            <?php if (!empty($favorites)): ?>
                <div class="favorites-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $favorite_count; ?></div>
                        <div class="stat-label">Favorite Items</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">₱<?php echo number_format($total_value, 2); ?></div>
                        <div class="stat-label">Total Value</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($favorites)): ?>
            <!-- Action Bar -->
            <div class="action-bar">
                <span class="text-muted">
                    <i class="fas fa-heart me-2" style="color: var(--heart-red);"></i>
                    You have <?php echo $favorite_count; ?> saved favorite<?php echo $favorite_count > 1 ? 's' : ''; ?>
                </span>
                
                <form method="POST" class="d-inline" onsubmit="return confirmClearFavorites(event, this)">
                    <input type="hidden" name="action" value="clear_favorites">
                    <button type="submit" class="btn-outline-danger">
                        <i class="fas fa-trash-alt me-2"></i>Clear All Favorites
                    </button>
                </form>
            </div>

            <!-- Favorites Grid -->
            <div class="row g-4 favorites-grid">
                <?php foreach ($favorites as $item): ?>
                    <?php
                        $imageSrc = !empty($item['image_url'])
                            ? '../' . htmlspecialchars($item['image_url'])
                            : '../images/placeholder.png';
                    ?>
                    <div class="col-lg-6">
                        <div class="favorite-card">
                            <div class="favorite-badge">
                                <i class="fas fa-heart"></i>
                                Favorite
                            </div>
                            
                            <div class="favorite-image">
                                <img
                                    src="<?php echo $imageSrc; ?>"
                                    alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                    onerror="this.onerror=null;this.src='../images/placeholder.png'"
                                >
                            </div>
                            
                            <div class="favorite-content">
                                <div class="favorite-header">
                                    <div>
                                        <h4 class="favorite-title"><?php echo htmlspecialchars($item['item_name']); ?></h4>
                                        <span class="favorite-category"><?php echo htmlspecialchars($item['category'] ?? 'Menu Item'); ?></span>
                                    </div>
                                    <div class="favorite-price">₱<?php echo number_format($item['price'], 2); ?></div>
                                </div>
                                
                                <p class="favorite-description"><?php echo htmlspecialchars($item['description']); ?></p>
                                
                                <?php if (isset($item['favorited_date'])): ?>
                                    <div class="favorite-meta">
                                        <span>
                                            <i class="far fa-clock"></i>
                                            Added <?php echo date('M d, Y', strtotime($item['favorited_date'])); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="favorite-actions">
                                    <form method="POST" class="favorite-cart-form" onsubmit="return confirmAddToCart(event, this, '<?php echo htmlspecialchars($item['item_name']); ?>')">
                                        <input type="hidden" name="action" value="add_to_cart">
                                        <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                        <div class="favorite-cart-row">
                                            <div class="quantity-selector">
                                                <input type="number" name="quantity" class="quantity-input" value="1" min="1" max="10">
                                            </div>
                                            <button type="submit" class="btn-add-cart w-100">
                                                <i class="fas fa-cart-plus me-2"></i>Add to Cart
                                            </button>
                                        </div>
                                    </form>
                                    
                                    <form method="POST" class="favorite-remove-form" onsubmit="return confirmRemoveFavorite(event, this, '<?php echo htmlspecialchars($item['item_name']); ?>')">
                                        <input type="hidden" name="action" value="remove_favorite">
                                        <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                        <button type="submit" class="btn-remove">
                                            <i class="fas fa-trash-alt me-1"></i>Remove
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php echo render_pagination($favoritesPagination); ?>

            <!-- Quick Actions -->
            <div class="row mt-5">
                <div class="col-12">
                    <div class="d-flex gap-3 justify-content-center">
                        <a href="menu.php" class="btn-outline-secondary text-decoration-none">
                            <i class="fas fa-utensils me-2"></i>Browse More Items
                        </a>
                        <a href="cart.php" class="btn-outline-secondary text-decoration-none">
                            <i class="fas fa-shopping-cart me-2"></i>View Cart
                        </a>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Empty State -->
            <div class="empty-favorites">
                <i class="fas fa-heart-broken"></i>
                <h3>No Favorites Yet</h3>
                <p>You haven't added any items to your favorites list. Explore our menu and heart the dishes you love!</p>
                <a href="menu.php" class="btn-gold text-decoration-none" style="display: inline-block; width: auto; padding: 1rem 3rem;">
                    <i class="fas fa-utensils me-2"></i>Explore Menu
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show success message if exists
        <?php if ($success_msg): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: <?php echo json_encode($success_msg); ?>,
            timer: 3000,
            showConfirmButton: true,
            confirmButtonColor: '#667eea',
            background: '#fff',
            iconColor: '#667eea'
        });
        <?php endif; ?>

        <?php if ($error): ?>
        Swal.fire({
            icon: 'error',
            title: 'Oops...',
            text: <?php echo json_encode($error); ?>,
            confirmButtonColor: '#667eea',
            background: '#fff',
            iconColor: '#ff4757'
        });
        <?php endif; ?>

        // Confirm add to cart
        function confirmAddToCart(event, form, itemName) {
            event.preventDefault();
            const quantity = form.querySelector('.quantity-input').value;
            
            Swal.fire({
                title: 'Add to Cart',
                html: `Add <strong>${quantity}</strong> x <strong>${itemName}</strong> to your cart?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, add to cart',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show adding animation
                    Swal.fire({
                        title: 'Adding to Cart',
                        text: 'Please wait...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                            form.submit();
                        }
                    });
                }
            });
            return false;
        }

        // Confirm remove favorite
        function confirmRemoveFavorite(event, form, itemName) {
            event.preventDefault();
            
            Swal.fire({
                title: 'Remove from Favorites',
                html: `Are you sure you want to remove <strong>${itemName}</strong> from your favorites?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, remove it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            return false;
        }

        // Confirm clear all favorites
        function confirmClearFavorites(event, form) {
            event.preventDefault();
            
            Swal.fire({
                title: 'Clear All Favorites',
                html: 'Are you sure you want to remove <strong>all items</strong> from your favorites?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, clear all',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            return false;
        }

        // Quantity input validation
        document.querySelectorAll('.quantity-input').forEach(input => {
            input.addEventListener('change', function() {
                if (this.value < 1) this.value = 1;
                if (this.value > 10) this.value = 10;
            });
        });

        // Heart animation on hover
        document.querySelectorAll('.favorite-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                const heart = this.querySelector('.favorite-badge i');
                if (heart) {
                    heart.style.animation = 'heartbeat 1s ease infinite';
                }
            });
            
            card.addEventListener('mouseleave', function() {
                const heart = this.querySelector('.favorite-badge i');
                if (heart) {
                    heart.style.animation = '';
                }
            });
        });
    </script>
    
    <script>
        document.addEventListener('click', function(e) {
            const link = e.target.closest('.logout-link');
            if (link) {
                e.preventDefault();
                const logoutUrl = link.getAttribute('href');
                Swal.fire({
                    title: 'Ready to Leave?',
                    text: 'Are you sure you want to log out?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#d4af37',
                    cancelButtonColor: '#333',
                    confirmButtonText: 'Yes, log me out',
                    cancelButtonText: 'Cancel',
                    background: '#1e1e1e',
                    color: '#fff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = logoutUrl;
                    }
                });
            }
        });
    </script>
</body>
</html>
