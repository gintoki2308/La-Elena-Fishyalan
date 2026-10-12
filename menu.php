<?php
require_once 'config/config.php';
require_once 'config/Database.php';

$database = new Database();
$db = $database->connect();

// Get filter parameters
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query for food items
$query = "SELECT * FROM menu_items WHERE is_available = TRUE";
if ($category_filter !== 'all') {
    $query .= " AND category = :category";
}
if (!empty($search_query)) {
    $query .= " AND (item_name LIKE :search1 OR description LIKE :search2)";
}
$query .= " ORDER BY category, item_name ASC";

$stmt = $db->prepare($query);
if ($category_filter !== 'all') {
    $stmt->bindParam(':category', $category_filter);
}
if (!empty($search_query)) {
    $search_term = '%' . $search_query . '%';
    $stmt->bindParam(':search1', $search_term);
    $stmt->bindParam(':search2', $search_term);
}
$stmt->execute();
$menu_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all food categories
$cat_query = "SELECT DISTINCT category FROM menu_items WHERE is_available = TRUE ORDER BY category ASC";
$cat_stmt = $db->prepare($cat_query);
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

// Group items by category
$grouped_items = [];
foreach ($menu_items as $item) {
    $grouped_items[$item['category']][] = $item;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La Elena Fishyalan - Menu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .menu-item-card {
            background: #1a1a1a;
            border: 1px solid #d4af37;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .menu-item-card:hover {
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.4);
            transform: translateY(-5px);
        }
        .item-price {
            background: #d4af37;
            color: #1a1a1a;
            font-weight: bold;
            font-size: 1.3rem;
            padding: 8px 15px;
            border-radius: 5px;
            display: inline-block;
        }
        .item-title {
            color: #d4af37;
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            margin-bottom: 10px;
        }
        .item-description {
            color: #f0f0f0;
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 15px;
        }
        .category-section {
            margin-bottom: 50px;
        }
        .category-title {
            color: #d4af37;
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            text-align: center;
            margin-top: 40px;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #d4af37;
        }
        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 40px;
        }
        .filter-btn {
            padding: 10px 20px;
            border: 2px solid #d4af37;
            background: transparent;
            color: #d4af37;
            cursor: pointer;
            border-radius: 5px;
            transition: all 0.3s ease;
            font-weight: bold;
        }
        .filter-btn:hover,
        .filter-btn.active {
            background: #d4af37;
            color: #1a1a1a;
        }
        .search-container {
            max-width: 600px;
            margin: 0 auto 30px auto;
        }
        .search-box {
            background: #1a1a1a;
            border: 2px solid #d4af37;
            border-radius: 5px;
            display: flex;
            padding: 5px 10px;
        }
        .search-box input {
            background: transparent;
            border: none;
            color: #f0f0f0;
            width: 100%;
            padding: 5px 10px;
        }
        .search-box input:focus {
            outline: none;
        }
        .search-box button {
            background: #d4af37;
            border: none;
            color: #1a1a1a;
            font-weight: bold;
            padding: 5px 15px;
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .search-box button:hover {
            background: #f0d555;
        }
    </style>
</head>
<body class="bg-dark">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.html"><i class="fas fa-utensils me-2"></i>La Elena Fishyalan</a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.html">Home</a></li>
                    <li class="nav-item"><a class="nav-link active" href="menu.php">Menu</a></li>
                    <li class="nav-item"><a class="nav-link" href="search-menu.php"><i class="fas fa-search me-1"></i> Search</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.html#about">Our Story</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.html#contact">Contact</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="hero-section" id="menu">
        <div class="container">
            <h1 class="hero-title mb-4">Our Culinary Masterpieces</h1>
            <p class="lead mb-5 text-uppercase hero-subtitle">A carefully curated selection of timeless classics and modern innovations</p>
        </div>
    </header>

    <div class="container py-5">
        <!-- Search Bar -->
        <div class="search-container">
            <form method="GET" action="menu.php" class="search-box">
                <?php if ($category_filter !== 'all'): ?>
                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($category_filter); ?>">
                <?php endif; ?>
                <input type="text" name="search" placeholder="Search our menu..." value="<?php echo htmlspecialchars($search_query); ?>">
                <button type="submit"><i class="fas fa-search me-2"></i>Search</button>
            </form>
        </div>

        <!-- Filter Buttons -->
        <div class="filter-buttons">
            <a href="menu.php" class="filter-btn <?php echo $category_filter === 'all' ? 'active' : ''; ?>">
                All Dishes
            </a>
            <?php foreach ($categories as $cat): ?>
                <a href="menu.php?category=<?php echo urlencode($cat); ?>" 
                   class="filter-btn <?php echo $category_filter === $cat ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Menu Items by Category -->
        <?php if ($category_filter !== 'all'): ?>
            <!-- Single Category View -->
            <div class="category-section">
                <h2 class="category-title"><?php echo htmlspecialchars($category_filter); ?></h2>
                <div class="row">
                    <?php if (isset($grouped_items[$category_filter])): ?>
                        <?php foreach ($grouped_items[$category_filter] as $item): ?>
                            <div class="col-lg-6">
                                <div class="menu-item-card">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <?php $img_path = ltrim($item['image_url'], '/'); ?>
                                        <div class="mb-3" style="height: 200px; overflow: hidden; border-radius: 8px;">
                                            <img src="<?php echo htmlspecialchars($img_path); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                        </div>
                                    <?php endif; ?>
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="flex-grow-1">
                                            <h4 class="item-title"><?php echo htmlspecialchars($item['item_name']); ?></h4>
                                        </div>
                                        <span class="item-price">₱<?php echo number_format($item['price'], 2); ?></span>
                                    </div>
                                    <p class="item-description">
                                        <?php echo htmlspecialchars($item['description']); ?>
                                    </p>
                                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#itemModal" 
                                            data-item='<?php echo json_encode($item); ?>'>
                                        <i class="fas fa-eye me-2"></i> View Details
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>No items available in this category.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- All Categories View -->
            <?php foreach ($grouped_items as $category => $items): ?>
                <div class="category-section">
                    <h2 class="category-title"><?php echo htmlspecialchars($category); ?></h2>
                    <div class="row">
                        <?php foreach ($items as $item): ?>
                            <div class="col-lg-6">
                                <div class="menu-item-card">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <?php $img_path = ltrim($item['image_url'], '/'); ?>
                                        <div class="mb-3" style="height: 200px; overflow: hidden; border-radius: 8px;">
                                            <img src="<?php echo htmlspecialchars($img_path); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                        </div>
                                    <?php endif; ?>
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="flex-grow-1">
                                            <h4 class="item-title"><?php echo htmlspecialchars($item['item_name']); ?></h4>
                                        </div>
                                        <span class="item-price">₱<?php echo number_format($item['price'], 2); ?></span>
                                    </div>
                                    <p class="item-description">
                                        <?php echo htmlspecialchars($item['description']); ?>
                                    </p>
                                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#itemModal" 
                                            data-item='<?php echo json_encode($item); ?>'>
                                        <i class="fas fa-eye me-2"></i> View Details
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (empty($menu_items)): ?>
            <div class="alert alert-warning text-center mt-5">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No menu items available at the moment.
            </div>
        <?php endif; ?>
    </div>

    <!-- Item Details Modal -->
    <div class="modal fade" id="itemModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark border-warning">
                <div class="modal-header border-warning">
                    <h5 class="modal-title text-warning" id="itemName"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-light">
                    <div id="itemImageContainer" class="mb-3 text-center" style="display: none;">
                        <img id="itemImage" src="" alt="" style="max-height: 300px; max-width: 100%; border-radius: 8px; object-fit: cover;">
                    </div>
                    <h3 class="text-warning mb-3" id="itemPrice"></h3>
                    <p id="itemDescription"></p>
                    <button type="button" class="btn btn-warning w-100">
                        <i class="fas fa-plus-circle me-2"></i> Add to Order
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark border-top border-secondary-subtle py-5" id="contact">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4 mb-md-0">
                    <h5 class="text-primary-gold mb-3"><i class="fas fa-map-marker-alt me-2"></i>Location</h5>
                    <p class="mb-2">Libertad Bajo</p>
                    <p class="mb-2">Sinacaban,Mis.Occ</p>
                </div>
                <div class="col-md-4 mb-4 mb-md-0">
                    <h5 class="text-primary-gold mb-3"><i class="fas fa-clock me-2"></i>Hours of Operation</h5>
                    <p class="mb-2">8:00 PM - 09:00 PM</p>
                </div>
                <div class="col-md-4">
                    <h5 class="text-primary-gold mb-3"><i class="fas fa-phone me-2"></i>Contact</h5>
                    <p class="mb-2">Phone: (+63) 997 235 7035</p>
                    <p class="mb-3">Email: <a href="mailto:signarfermar@gmail.com" class="text-primary-gold">signarfermar@gmail.com</a></p>
                    <div class="social-icons mt-3">
                        <a href="https://www.instagram.com" class="text-primary-gold me-3" aria-label="Instagram"><i class="fab fa-instagram fa-lg" aria-hidden="true"></i></a>
                        <a href="https://www.facebook.com/johncine.martil.3/" class="text-primary-gold me-3" aria-label="Facebook"><i class="fab fa-facebook fa-lg" aria-hidden="true"></i></a>
                        <a href="https://x.com/" class="text-primary-gold" aria-label="X"><i class="fab fa-twitter fa-lg" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
            <div class="text-center mt-4 pt-3 border-top border-secondary">
                <small>&copy; 2025 La Elena Fishyalan. All Rights Reserved. |
                    <a href="index.html" class="text-primary-gold">Food Menu</a> |
                    <a href="search.html" class="text-primary-gold">Search</a> |
                    <a href="#" class="text-primary-gold">Privacy Policy</a>
                </small>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle modal with item data
        const itemModal = document.getElementById('itemModal');
        itemModal.addEventListener('show.bs.modal', function (e) {
            const button = e.relatedTarget;
            const item = JSON.parse(button.getAttribute('data-item'));
            
            document.getElementById('itemName').textContent = item.item_name;
            document.getElementById('itemPrice').textContent = '₱' + parseFloat(item.price).toFixed(2);
            document.getElementById('itemDescription').textContent = item.description;
            
            const imgContainer = document.getElementById('itemImageContainer');
            const imgEl = document.getElementById('itemImage');
            if (item.image_url) {
                imgEl.src = item.image_url;
                imgEl.alt = item.item_name;
                imgContainer.style.display = 'block';
            } else {
                imgContainer.style.display = 'none';
                imgEl.src = '';
            }
        });
    </script>
</body>
</html>
