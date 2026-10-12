<?php
require_once 'config/config.php';
require_once 'config/Database.php';

$database = new Database();
$db = $database->connect();

$search_query = isset($_GET['q']) ? $_GET['q'] : '';
$search_results = [];
$result_count = 0;

if (!empty($search_query)) {
    // Search in all menu items
    $query = "SELECT * FROM menu_items WHERE (item_name LIKE :search OR description LIKE :search) AND is_available = TRUE ORDER BY category, item_name ASC";
    $stmt = $db->prepare($query);
    $search_term = '%' . $search_query . '%';
    $stmt->bindParam(':search', $search_term);
    $stmt->execute();
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result_count = count($search_results);
}

// Get all items for suggestions
$all_query = "SELECT DISTINCT item_name FROM menu_items WHERE is_available = TRUE ORDER BY item_name ASC LIMIT 20";
$all_stmt = $db->prepare($all_query);
$all_stmt->execute();
$suggestions = $all_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La Elena Fishyalan - Search Menu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .search-container {
            background: linear-gradient(135deg, #1a1a1a 0%, #2a2a2a 100%);
            padding: 60px 0;
            margin-bottom: 40px;
            border-bottom: 2px solid #d4af37;
        }

        .search-box {
            background: #1a1a1a;
            border: 2px solid #d4af37;
            border-radius: 10px;
            padding: 20px;
        }

        .search-box input {
            border: none;
            background: transparent;
            color: #f0f0f0;
            font-size: 1.2rem;
        }

        .search-box input::placeholder {
            color: #808080;
        }

        .search-box input:focus {
            outline: none;
            box-shadow: none;
        }

        .search-btn {
            background: #d4af37;
            color: #1a1a1a;
            border: none;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .search-btn:hover {
            background: #f0d555;
            transform: scale(1.05);
        }

        .result-card {
            background: #1a1a1a;
            border: 1px solid #d4af37;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .result-card:hover {
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.4);
            transform: translateX(5px);
        }

        .result-title {
            color: #d4af37;
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            margin-bottom: 10px;
        }

        .result-category {
            display: inline-block;
            background: #404040;
            color: #d4af37;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
            margin-bottom: 10px;
        }

        .result-price {
            background: #d4af37;
            color: #1a1a1a;
            font-weight: bold;
            padding: 8px 15px;
            border-radius: 5px;
            display: inline-block;
            float: right;
        }

        .result-description {
            color: #f0f0f0;
            font-size: 0.95rem;
            line-height: 1.5;
            clear: both;
            margin-top: 10px;
        }

        .no-results {
            text-align: center;
            padding: 60px 20px;
        }

        .no-results i {
            color: #d4af37;
            margin-bottom: 20px;
        }

        .suggestion-tags {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 30px;
        }

        .suggestion-tag {
            background: #404040;
            color: #d4af37;
            padding: 8px 15px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #d4af37;
        }

        .suggestion-tag:hover {
            background: #d4af37;
            color: #1a1a1a;
        }

        .results-header {
            color: #d4af37;
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #d4af37;
        }

        .result-count {
            color: #d4af37;
            font-size: 1.1rem;
            margin-bottom: 20px;
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
                    <li class="nav-item"><a class="nav-link" href="menu.php">Menu</a></li>
                    <li class="nav-item"><a class="nav-link active" href="search-menu.php"><i class="fas fa-search me-1"></i> Search</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.html#about">Our Story</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.html#contact">Contact</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Search Section -->
    <div class="search-container">
        <div class="container">
            <h1 class="text-center text-primary-gold mb-4" style="font-family: 'Playfair Display', serif; font-size: 2.5rem;">
                <i class="fas fa-search me-2"></i>Search Our Menu
            </h1>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <form method="GET" class="search-box">
                        <div class="input-group">
                            <input type="text" name="q" class="form-control" placeholder="Search for dishes, drinks, ingredients..."
                                value="<?php echo htmlspecialchars($search_query); ?>" autofocus>
                            <button class="btn search-btn" type="submit">
                                <i class="fas fa-search me-2"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Results Section -->
    <div class="container py-5">
        <?php if (!empty($search_query)): ?>
            <?php if ($result_count > 0): ?>
                <h2 class="results-header">
                    <i class="fas fa-list me-2"></i>Search Results
                </h2>
                <p class="result-count">
                    Found <strong><?php echo $result_count; ?></strong>
                    <?php echo $result_count === 1 ? 'item' : 'items'; ?> matching "<strong><?php echo htmlspecialchars($search_query); ?></strong>"
                </p>

                <div class="row">
                    <?php foreach ($search_results as $item): ?>
                        <div class="col-lg-6">
                            <div class="result-card">
                                <span class="result-category"><?php echo htmlspecialchars($item['category']); ?></span>
                                <span class="result-price">₱<?php echo number_format($item['price'], 2); ?></span>
                                <h4 class="result-title"><?php echo htmlspecialchars($item['item_name']); ?></h4>
                                <p class="result-description">
                                    <?php echo htmlspecialchars($item['description']); ?>
                                </p>
                                <button class="btn btn-warning btn-sm mt-3" data-bs-toggle="modal" data-bs-target="#itemModal"
                                    data-item='<?php echo json_encode($item); ?>'>
                                    <i class="fas fa-eye me-2"></i> View Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-search fa-4x"></i>
                    <h3 class="text-light mt-4 mb-3">No Items Found</h3>
                    <p class="text-secondary mb-5">
                        We couldn't find any items matching "<strong><?php echo htmlspecialchars($search_query); ?></strong>"
                    </p>

                    <p class="text-secondary mb-0">Try a different keyword or browse the full menu.</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <!-- No Search Yet -->
            <div class="no-results">
                <i class="fas fa-utensils fa-4x text-primary-gold"></i>
                <h3 class="text-light mt-4 mb-3">Start Your Search</h3>
                <p class="text-secondary mb-5">
                    Use the search box above to find your favorite dishes and drinks
                </p>

                <h5 class="text-primary-gold mb-3">Popular Items:</h5>
                <div class="suggestion-tags">
                    <?php foreach (array_slice($suggestions, 0, 6) as $suggestion): ?>
                        <a href="search-menu.php?q=<?php echo urlencode($suggestion); ?>" class="suggestion-tag text-decoration-none">
                            <?php echo htmlspecialchars($suggestion); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
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
                    <p class="text-secondary mb-3" id="itemCategory"></p>
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
        itemModal.addEventListener('show.bs.modal', function(e) {
            const button = e.relatedTarget;
            const item = JSON.parse(button.getAttribute('data-item'));

            document.getElementById('itemName').textContent = item.item_name;
            document.getElementById('itemCategory').textContent = 'Category: ' + item.category;
            document.getElementById('itemPrice').textContent = '₱' + parseFloat(item.price).toFixed(2);
            document.getElementById('itemDescription').textContent = item.description;
        });
    </script>
</body>

</html>
