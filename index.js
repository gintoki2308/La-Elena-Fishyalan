
// Global variable to store user login status
let currentUser = null;

// Local Bootstrap behavior fallback for offline/XAMPP use
(function initBootstrapFallback() {
    const hasNativeBootstrap = Boolean(window.bootstrap && window.bootstrap.Modal);

    if (hasNativeBootstrap) {
        return;
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.navbar-toggler[data-bs-target]').forEach((button) => {
            button.addEventListener('click', () => {
                const target = document.querySelector(button.getAttribute('data-bs-target'));
                if (!target) return;

                const isOpen = target.classList.toggle('show');
                button.setAttribute('aria-expanded', String(isOpen));
            });
        });
    });

    const modalInstances = new WeakMap();

    class LocalModal {
        constructor(element) {
            this.element = element;
            modalInstances.set(element, this);
        }

        show(relatedTarget = null) {
            if (!this.element) return;

            const showEvent = new Event('show.bs.modal', { bubbles: true, cancelable: true });
            showEvent.relatedTarget = relatedTarget;
            this.element.dispatchEvent(showEvent);

            this.element.classList.add('show');
            this.element.style.display = 'block';
            this.element.removeAttribute('aria-hidden');
            this.element.setAttribute('aria-modal', 'true');
            document.body.classList.add('modal-open');
        }

        hide() {
            if (!this.element) return;

            this.element.classList.remove('show');
            this.element.style.display = 'none';
            this.element.setAttribute('aria-hidden', 'true');
            this.element.removeAttribute('aria-modal');
            document.body.classList.remove('modal-open');
            this.element.dispatchEvent(new Event('hidden.bs.modal', { bubbles: true }));
        }

        static getInstance(element) {
            return modalInstances.get(element) || null;
        }
    }

    window.bootstrap = {
        ...(window.bootstrap || {}),
        Modal: LocalModal
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.modal').forEach((modal) => new LocalModal(modal));

        document.addEventListener('click', (event) => {
            const modalTrigger = event.target.closest('[data-bs-toggle="modal"]');
            if (modalTrigger) {
                event.preventDefault();
                const selector = modalTrigger.getAttribute('data-bs-target');
                const modalElement = selector ? document.querySelector(selector) : null;
                if (modalElement) {
                    (LocalModal.getInstance(modalElement) || new LocalModal(modalElement)).show(modalTrigger);
                }
                return;
            }

            const closeTrigger = event.target.closest('[data-bs-dismiss="modal"], .btn-close');
            if (closeTrigger) {
                const modalElement = closeTrigger.closest('.modal');
                if (modalElement) {
                    (LocalModal.getInstance(modalElement) || new LocalModal(modalElement)).hide();
                }
                return;
            }

            if (event.target.classList && event.target.classList.contains('modal')) {
                (LocalModal.getInstance(event.target) || new LocalModal(event.target)).hide();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                (LocalModal.getInstance(openModal) || new LocalModal(openModal)).hide();
            }
        });
    });
})();

// Check user login status on page load
async function checkLoginStatus() {
    try {
        const response = await fetch('auth/get-login-status.php');
        currentUser = await response.json();
        updateNavbarForUser();
    } catch (error) {
        console.log('Not logged in or error checking status');
    }
}

// Update navbar based on login status
function updateNavbarForUser() {
    const userAuthNav = document.getElementById('userAuthNav');
    const navList = document.querySelector('.navbar-nav');
    
    if (!navList || !userAuthNav) return;

    if (currentUser && currentUser.is_logged_in) {
        const firstName = currentUser.first_name || 'User';
        userAuthNav.innerHTML = `
            <a class="logout-link nav-link" href="auth/logout.php">
                <i class="far fa-user" aria-hidden="true"></i><span>${firstName}</span><i class="fas fa-sign-out-alt" aria-hidden="true"></i>
            </a>
        `;

        // Add dashboard link for logged in users if not present
        if (!document.getElementById('dashboardNavItem')) {
            const dashLink = document.createElement('li');
            dashLink.id = 'dashboardNavItem';
            dashLink.className = 'nav-item';
            const role = currentUser.user_role || 'customer';
            const dashboardUrl = role === 'admin' ? 'adminSide/admin-dashboard.php' : (role === 'staff' ? 'staffSide/staff-dashboard.php' : 'customerSide/customer-dashboard.php');
            dashLink.innerHTML = `<a class="nav-link" href="${dashboardUrl}"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>`;
            navList.insertBefore(dashLink, userAuthNav);
        }
    } else {
        userAuthNav.innerHTML = `
            <a class="nav-link" href="auth/login.php"><i class="fas fa-sign-in-alt" aria-hidden="true"></i><span>Sign In</span></a>
        `;

        const existingDashboard = document.getElementById('dashboardNavItem');
        if (existingDashboard) {
            existingDashboard.remove();
        }
    }
}

// Check login status on page load
checkLoginStatus();

document.addEventListener('DOMContentLoaded', function() {
    // DOM Elements
    const reservationBtn = document.getElementById('reservationBtn');
    const viewWineListBtn = document.getElementById('viewWineList');
    const storyBtn = document.getElementById('storyBtn');
    const filterButtons = document.querySelectorAll('.filter-btn');
    const navLinks = document.querySelectorAll('.nav-link');
    
    // Modal Elements
    const dishModal = document.getElementById('dishModal');
    const modalDishName = document.getElementById('modalDishName');
    const modalDishPrice = document.getElementById('modalDishPrice');
    const modalDishDescription = document.getElementById('modalDishDescription');
    const modalDishImage = document.getElementById('modalDishImage');
    const modalPlaceholder = document.getElementById('modalPlaceholder');
    const modalChefBadge = document.getElementById('modalChefBadge');
    const addToCartBtn = document.getElementById('addToCartBtn');
    const shareDishBtn = document.getElementById('shareDishBtn');
    
    // Dish data for modal will be populated dynamically
    let dishData = {};
    let pendingDishParamHandled = false;

    function resolveMenuAssetUrl(assetPath) {
        if (!assetPath) {
            return 'images/placeholder.png';
        }

        if (/^https?:\/\//i.test(assetPath)) {
            return assetPath;
        }

        return String(assetPath).replace(/^\/+/, '');
    }

    // Start the menu independently of optional homepage interactions.
    fetchMenuItems();

    // Initialize functions
    initHeroHeight();
    initEventListeners();
    initSmoothScrolling();
    initModalHandlers();
    
    // Fetch and render menu items
    async function fetchMenuItems() {
        const container = document.getElementById('dynamicMenuContainer');
        if (!container) return;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch('api/getMenuItems.php', { signal: controller.signal });
            const result = await response.json();

            if (!response.ok || !result.success || !Array.isArray(result.data)) {
                throw new Error('Failed to load menu items');
            }
            renderMenuItems(result.data);
        } catch (error) {
            console.error('Error fetching menu items:', error);
            container.innerHTML = '<div class="text-center" role="alert"><p>We could not load the food menu. Please try again.</p><button type="button" class="luxury-btn" id="retryMenu">Retry</button> <a href="menu.php" class="luxury-btn-outline">Browse food menu</a></div>';
            document.getElementById('retryMenu').addEventListener('click', () => {
                container.innerHTML = '<p class="text-center" role="status">Loading food menu...</p>';
                fetchMenuItems();
            });
        } finally {
            clearTimeout(timeout);
        }
    }

    function renderMenuItems(items) {
        const container = document.getElementById('dynamicMenuContainer');
        const filtersContainer = document.getElementById('dynamicMenuFilters');
        
        if (!container || !filtersContainer) return;

        if (items.length === 0) {
            filtersContainer.innerHTML = '';
            container.innerHTML = '<p class="text-center" role="status">No food items are available at the moment.</p>';
            return;
        }
        
        // Group by category
        const categories = {};
        items.forEach(item => {
            if (!categories[item.category]) {
                categories[item.category] = [];
            }
            categories[item.category].push(item);
            
            // Populate dishData for modal
            dishData[item.item_id] = {
                name: item.item_name,
                price: "₱" + parseFloat(item.price).toFixed(2),
                description: item.description,
                isChefChoice: false, 
                imageSrc: resolveMenuAssetUrl(item.image_url)
            };
        });
        
        container.innerHTML = '';
        
        // Generate filters
        filtersContainer.innerHTML = '<button class="filter-btn active" data-filter="all">All Dishes</button>';
        Object.keys(categories).forEach(cat => {
            const safeCat = cat.toLowerCase().replace(/[^a-z0-9]/g, '');
            filtersContainer.innerHTML += `<button class="filter-btn" data-filter="${safeCat}">${cat}</button>`;
        });
        
        // Generate categories and cards
        Object.keys(categories).forEach(cat => {
            const safeCat = cat.toLowerCase().replace(/[^a-z0-9]/g, '');
            
            let html = `
                <div class="menu-category">
                    <h3 class="menu-category-title text-center">${cat}</h3>
                    <div class="row g-3">
            `;
            
            categories[cat].forEach(item => {
                const imageUrl = resolveMenuAssetUrl(item.image_url);
                html += `
                        <div class="col-12 col-md-6 col-lg-4" data-category="${safeCat}">
                            <div class="dish-card" data-dish-id="${item.item_id}">
                                <div class="dish-image-container" data-bs-toggle="modal" data-bs-target="#dishModal">
                                   <img src="${imageUrl}" alt="${item.item_name}" class="dish-image" onerror="this.src='images/placeholder.png'">
                                   <div class="image-overlay">
                                       <i class="fas fa-expand-alt"></i> View Larger
                                   </div>
                                </div>
                                <div class="dish-content">
                                    <div class="dish-header">
                                        <h4 class="dish-name">${item.item_name}</h4>
                                        <span class="dish-price">₱${parseFloat(item.price).toFixed(2)}</span>
                                    </div>
                                    <p class="dish-description">${item.description}</p>
                                </div>
                            </div>
                        </div>
                `;
            });
            
            html += `
                    </div>
                </div>
            `;
            container.innerHTML += html;
        });
        
        // Re-attach dynamic filter listeners
        const dynamicFilterBtns = document.querySelectorAll('.filter-btn');
        dynamicFilterBtns.forEach(button => {
            button.addEventListener('click', handleFilterClick);
        });

        handleUrlDishParam();
    }

    // Function to initialize hero section height
    function initHeroHeight() {
        adjustHeroHeight();
        window.addEventListener('resize', adjustHeroHeight);
    }

    // Function to adjust hero section height for mobile
    function adjustHeroHeight() {
        const heroSection = document.querySelector('.hero-section');
        if (window.innerWidth < 768) {
            heroSection.style.height = '85vh';
        } else {
            heroSection.style.height = '100vh';
        }
    }

    // Function to initialize all event listeners
    function initEventListeners() {
        // Reservation button
        reservationBtn.addEventListener('click', handleReservationClick);
        
        // Wine list button
        if (viewWineListBtn) {
            viewWineListBtn.addEventListener('click', handleWineListClick);
        }
        
        // Story button
        if (storyBtn) {
            storyBtn.addEventListener('click', handleStoryClick);
        }
        
        // Menu filter buttons
        filterButtons.forEach(button => {
            button.addEventListener('click', handleFilterClick);
        });
        
        // Navigation active state
        window.addEventListener('scroll', updateActiveNavLink);
    }

    // Function to initialize modal handlers
    function initModalHandlers() {
        // Bootstrap modal event listener
        if (dishModal) {
            dishModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget; // Button that triggered the modal
                const dishCard = button ? button.closest('[data-dish-id]') : null;
                
                if (dishCard) {
                    const dishId = dishCard.getAttribute('data-dish-id');
                    if (dishId) {
                        showDishModal(parseInt(dishId));
                    }
                }
            });
        }
        
        // Handle add to cart button
        if (addToCartBtn) {
            addToCartBtn.addEventListener('click', handleAddToCart);
        }
        
        // Handle share dish button
        if (shareDishBtn) {
            shareDishBtn.addEventListener('click', handleShareDish);
        }
    }

    // Function to show dish modal with details
   window.showDishModal = function(dishId) {
        const dish = dishData[dishId];
        if (!dish) return;
        
        // Update modal content
        modalDishName.textContent = dish.name;
        modalDishPrice.textContent = dish.price || "View Only";
        modalDishDescription.textContent = dish.description;
        
        // Show/hide chef badge
        if (modalChefBadge) {
            if (dish.isChefChoice) {
                modalChefBadge.style.display = 'inline-block';
            } else {
                modalChefBadge.style.display = 'none';
            }
        }
        
        // Update image
        if (modalDishImage && modalPlaceholder) {
            if (dish.imageSrc) {
                modalDishImage.src = dish.imageSrc;
                modalDishImage.alt = dish.name;
                modalDishImage.style.display = 'block';
                modalPlaceholder.style.display = 'none';
            } else {
                modalDishImage.style.display = 'none';
                modalPlaceholder.style.display = 'flex';
            }
        }
        
        // Store current dish ID for cart/share functionality
        if (addToCartBtn) addToCartBtn.dataset.dishId = dishId;
        if (shareDishBtn) shareDishBtn.dataset.dishId = dishId;
    }

    // Function to handle add to cart
    function handleAddToCart() {
        // Check if user is logged in
        if (!currentUser || !currentUser.is_logged_in) {
            alert('Please log in to place an order.');
            window.location.href = 'auth/login.php?redirect=' + encodeURIComponent(window.location.href);
            return;
        }
        
        const dishId = this.dataset.dishId;
        const dish = dishData[dishId];
        
        if (dish) {
            if (dishId === "21") {
                alert("This is our restaurant interior. You can't add it to your order, but we hope you'll visit us soon!");
            } else {
                alert(`Added "${dish.name}" to your order!`);
                
                // Close modal after a short delay
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(dishModal);
                    if (modal) modal.hide();
                }, 1000);
            }
        }
    }

    // Function to handle share dish
    function handleShareDish() {
        const dishId = this.dataset.dishId;
        const dish = dishData[dishId];
        
        if (dish) {
            const shareText = dishId === "21" 
                ? `Check out the beautiful interior of La Elena Fishyalan! ${dish.description}`
                : `Check out "${dish.name}" from La Elena Fishyalan! ${dish.description}`;
            
            // Use Web Share API if available
            if (navigator.share) {
                navigator.share({
                    title: dish.name,
                    text: shareText,
                    url: window.location.href
                });
            } else {
                // Fallback: copy to clipboard
                navigator.clipboard.writeText(shareText).then(() => {
                    alert('Dish details copied to clipboard!');
                });
            }
        }
    }

    // Function to handle reservation button click
    async function handleReservationClick(e) {
        e.preventDefault();
        if (!currentUser) {
            await checkLoginStatus();
        }
        const role = currentUser && currentUser.user_role;
        window.location.href = role === 'admin'
            ? 'adminSide/reservationsCrud/viewReservations.php'
            : role === 'staff'
                ? 'staffSide/viewReservations.php'
                : 'customerSide/reservations.php';
    }

    // Function to handle wine list button click
    function handleWineListClick() {
        alert('Full wine list would display here. Contact our sommelier for pairing recommendations.');
    }

    // Function to handle story button click
    function handleStoryClick() {
        alert('Our full story page would open here, detailing our history since 1952.');
    }

    // Function to handle menu filter clicks
    function handleFilterClick() {
        // Remove active class from all buttons
        const currentFilterBtns = document.querySelectorAll('.filter-btn');
        currentFilterBtns.forEach(btn => btn.classList.remove('active'));
        
        // Add active class to clicked button
        this.classList.add('active');
        
        const filterValue = this.getAttribute('data-filter');
        
        // Show/hide dish cards based on filter
        const categorySections = document.querySelectorAll('.menu-category');
        categorySections.forEach(section => {
            const items = section.querySelectorAll('.col-12');
            if (filterValue === 'all') {
                section.style.display = 'block';
                items.forEach(item => {
                    item.style.display = 'block';
                    item.style.opacity = '1';
                });
            } else {
                let hasMatch = false;
                items.forEach(item => {
                    if (item.getAttribute('data-category') === filterValue) {
                        item.style.display = 'block';
                        item.style.opacity = '1';
                        hasMatch = true;
                    } else {
                        item.style.display = 'none';
                        item.style.opacity = '0';
                    }
                });
                section.style.display = hasMatch ? 'block' : 'none';
            }
        });
    }

    // Function to initialize smooth scrolling
    function initSmoothScrolling() {
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                if(targetId === '#') return;
                
                scrollToSection(targetId);
                updateNavLinks(this);
                closeMobileNavbar();
            });
        });
    }

    // Function to scroll to a section
    function scrollToSection(sectionId) {
        const targetElement = document.querySelector(sectionId);
        if (targetElement) {
            targetElement.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    }

    // Function to update navigation links
    function updateNavLinks(clickedLink) {
        navLinks.forEach(link => {
            link.classList.remove('active');
        });
        clickedLink.classList.add('active');
    }

    // Function to update active nav link on scroll
    function updateActiveNavLink() {
        let current = '';
        const sections = document.querySelectorAll('section, header');
        
        sections.forEach(section => {
            const sectionTop = section.offsetTop;
            const sectionHeight = section.clientHeight;
            if (scrollY >= (sectionTop - 150)) {
                current = section.getAttribute('id');
            }
        });

        navLinks.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === `#${current}`) {
                link.classList.add('active');
            }
        });
    }

    // Function to close mobile navbar
    function closeMobileNavbar() {
        const navbarToggler = document.querySelector('.navbar-toggler');
        const navbarCollapse = document.querySelector('.navbar-collapse');
        if(navbarCollapse && navbarCollapse.classList.contains('show')) {
            navbarToggler.click();
        }
    }

    // If the page was opened with a `dish` URL parameter, scroll/highlight and open modal
    function handleUrlDishParam() {
        if (pendingDishParamHandled) {
            return;
        }

        try {
            const params = new URLSearchParams(window.location.search);
            const dishParam = params.get('dish');
            if (!dishParam) {
                return;
            }

            const idNum = parseInt(dishParam, 10);
            if (Number.isNaN(idNum) || !dishData[idNum]) {
                return;
            }

            pendingDishParamHandled = true;
            const el = document.querySelector(`[data-dish-id="${dishParam}"]`);
            if (el) {
                setTimeout(() => {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    el.classList.add('highlighted');
                    setTimeout(() => el.classList.remove('highlighted'), 2200);
                }, 300);
            }

            if (typeof window.showDishModal === 'function') {
                window.showDishModal(idNum);
                const modal = new bootstrap.Modal(dishModal);
                modal.show();
            }
        } catch (e) {
            console.error('Error handling dish URL param', e);
        }
    }

});

/* ============================================================
   FLOATING HERO PARTICLES
   ============================================================ */
(function initParticles() {
    const container = document.getElementById('heroParticles');
    if (!container) return;
    const count = 18;
    for (let i = 0; i < count; i++) {
        const p = document.createElement('div');
        p.className = 'hero-particle';
        p.style.left = Math.random() * 100 + '%';
        p.style.width  = (Math.random() * 3 + 1) + 'px';
        p.style.height = p.style.width;
        p.style.animationDuration  = (Math.random() * 10 + 8) + 's';
        p.style.animationDelay     = (Math.random() * 8)  + 's';
        p.style.opacity = Math.random() * 0.6;
        container.appendChild(p);
    }
})();

/* ============================================================
   FADE-UP INTERSECTION OBSERVER
   ============================================================ */
(function initFadeUp() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, i) => {
            if (entry.isIntersecting) {
                setTimeout(() => {
                    entry.target.classList.add('visible');
                }, i * 120);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });

    document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));
})();

/* ============================================================
   ANIMATED STATS COUNTER
   ============================================================ */
(function initStatsCounter() {
    const statNumbers = document.querySelectorAll('.stat-number');
    if (!statNumbers.length) return;

    const easeOut = (t) => 1 - Math.pow(1 - t, 3);

    const animateCounter = (el) => {
        const target = parseInt(el.dataset.target, 10);
        const duration = 1800;
        const start = performance.now();

        const update = (now) => {
            const elapsed = now - start;
            const progress = Math.min(elapsed / duration, 1);
            const value = Math.floor(easeOut(progress) * target);
            el.textContent = value >= 1000 ? value.toLocaleString() : value;
            if (progress < 1) requestAnimationFrame(update);
            else el.textContent = target >= 1000 ? target.toLocaleString() : target;
        };
        requestAnimationFrame(update);
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateCounter(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });

    statNumbers.forEach(el => observer.observe(el));
})();

// Live customer reviews are loaded by feedback.js.
