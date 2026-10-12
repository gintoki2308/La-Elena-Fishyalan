// Customer Menu JavaScript
// Handles modal interactions and favorites

let dishData = {};
let currentDishId = null;
let favoritesList = [];

document.addEventListener('DOMContentLoaded', function() {
    initializeMenuPage();
});

function initializeMenuPage() {
    populateDishData();
    syncFavoritesListFromCards();
    setupCardClicks();
    setupModalListeners();
    setupFilters();
}

/**
 * Add click handlers for dish image panel to open modal
 */
function setupCardClicks() {
    if (document.body._menuCardClicksBound) return;
    document.body._menuCardClicksBound = true;

    document.addEventListener('click', function (event) {
        const imageBox = event.target.closest('.dish-image-container');
        if (!imageBox) return;

        const dishId = imageBox.getAttribute('data-dish-id');
        if (!dishId) return;

        event.preventDefault();
        event.stopPropagation();
        showDishModal(dishId);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const imageBox = event.target.closest('.dish-image-container');
        if (!imageBox) return;

        const dishId = imageBox.getAttribute('data-dish-id');
        if (!dishId) return;

        event.preventDefault();
        showDishModal(dishId);
    });
}

/**
 * Populate dish data from DOM
 */
function populateDishData() {
    dishData = {};
    const dishCards = document.querySelectorAll('.dish-card');
    dishCards.forEach(card => {
        const dishId = card.getAttribute('data-dish-id');
        const dishName = card.querySelector('.dish-name').textContent.replace(/\s+/g, ' ').trim();
        const dishPrice = card.querySelector('.dish-price').textContent.trim();
        const dishDesc = card.querySelector('.dish-description').textContent.trim();
        const dishImage = card.querySelector('.dish-image').src;
        const promoBadgeStack = card.querySelector('.dish-image-container .promo-badge-stack');
        const stockQty = getCardStockQuantity(card);
        
        dishData[dishId] = {
            id: dishId,
            name: dishName,
            price: dishPrice,
            description: dishDesc,
            image: dishImage,
            promoBadges: promoBadgeStack ? promoBadgeStack.innerHTML : '',
            stockQuantity: stockQty,
            isFavorite: false
        };
    });
}

function getCardStockQuantity(card) {
    const dataStock = parseInt(card.getAttribute('data-stock') || '', 10);
    if (!Number.isNaN(dataStock)) {
        return Math.max(0, dataStock);
    }

    return 0;
}

function syncFavoritesListFromCards() {
    favoritesList = Array.from(document.querySelectorAll('.btn-favorite-ajax'))
        .filter(btn => {
            const icon = btn.querySelector('i');
            return icon && icon.classList.contains('fas');
        })
        .map(btn => parseInt(btn.getAttribute('data-item-id'), 10))
        .filter(Number.isInteger);
}

/**
 * Setup modal event listeners
 */
function setupModalListeners() {
    const dishModal = document.getElementById('dishModal');
    const modalQuantity = document.getElementById('modalQuantity');
    
    if (dishModal && !dishModal._menuModalBound) {
        dishModal._menuModalBound = true;

        dishModal.addEventListener('show.bs.modal', function(event) {
            // Set current dish from the trigger element (if available)
            if (event.relatedTarget) {
                const clickedCard = event.relatedTarget.closest('.dish-card');
                if (clickedCard) {
                    currentDishId = clickedCard.getAttribute('data-dish-id');
                }
            }
            updateModalContent();
        });
        
        dishModal.addEventListener('hidden.bs.modal', function() {
            // Reset quantity when modal closes
            modalQuantity.value = 1;
            modalQuantity.min = 1;
            modalQuantity.removeAttribute('max');
        });
    }

    if (modalQuantity && !modalQuantity._stockBound) {
        modalQuantity._stockBound = true;
        modalQuantity.addEventListener('input', function() {
            const maxQty = parseInt(this.getAttribute('max') || '', 10);
            if (!Number.isNaN(maxQty) && this.value !== '') {
                if (parseInt(this.value, 10) > maxQty) this.value = maxQty;
                if (maxQty > 0 && parseInt(this.value, 10) < 1) this.value = 1;
            }
        });
    }
    
}

/**
 * Show dish modal with specific dish
 */
function showDishModal(dishId) {
    currentDishId = dishId;
    const dishModal = new bootstrap.Modal(document.getElementById('dishModal'));
    dishModal.show();
}

/**
 * Update modal content with current dish data
 */
function updateModalContent() {
    if (!currentDishId || !dishData[currentDishId]) return;
    
    const dish = dishData[currentDishId];
    
    // Update text content
    document.getElementById('modalDishName').textContent = dish.name;
    document.getElementById('modalDishPromo').innerHTML = dish.promoBadges || '';
    document.getElementById('modalDishPrice').textContent = dish.price;
    document.getElementById('modalDishDescription').textContent = dish.description;
    
    // Update form values
    document.getElementById('modalItemId').value = dish.id;
    document.getElementById('modalQuantity').value = 1;
    updateModalStockState(dish);
    
    // Update image
    updateModalImage(dish.image);
    
    // Update favorites button
    updateFavoritesButton(dish.id);
    
    // Add smooth animations
    animateModalContent();
}

function updateModalStockState(dish) {
    const quantityInput = document.getElementById('modalQuantity');
    const stockNote = document.getElementById('modalStockNote');
    const addButton = document.getElementById('modalAddToCartBtn');

    if (!quantityInput || !stockNote || !addButton) return;

    const cartQty = parseInt((window.menuCartQuantities || {})[dish.id] || '0', 10) || 0;
    const totalStock = Math.max(0, parseInt(dish.stockQuantity || 0, 10) || 0);
    const remainingStock = Math.max(0, totalStock - cartQty);

    if (remainingStock <= 0) {
        quantityInput.value = 0;
        quantityInput.min = 0;
        quantityInput.max = 0;
        quantityInput.disabled = true;
        addButton.disabled = true;
        addButton.innerHTML = '<i class="fas fa-ban me-2"></i> Unavailable';
        stockNote.textContent = totalStock > 0
            ? 'This item is already in your cart.'
            : 'This item is currently unavailable.';
        return;
    }

    quantityInput.disabled = false;
    quantityInput.min = 1;
    quantityInput.max = remainingStock;
    quantityInput.value = Math.min(Math.max(1, parseInt(quantityInput.value || '1', 10) || 1), remainingStock);
    addButton.disabled = false;
    addButton.innerHTML = '<i class="fas fa-plus-circle me-2"></i> Add to Order';
    stockNote.textContent = cartQty > 0
        ? 'This item is already in your cart and can be added again.'
        : 'This item is available to add to your order.';
}

window.refreshCurrentModalStockState = function() {
    if (!currentDishId || !dishData[currentDishId]) return;
    updateModalStockState(dishData[currentDishId]);
};

window.setDishStockQuantity = function(dishId, stockQty) {
    if (!dishId) return;

    const normalizedStock = Math.max(0, parseInt(stockQty || 0, 10) || 0);

    if (dishData[dishId]) {
        dishData[dishId].stockQuantity = normalizedStock;
    }

    document.querySelectorAll(`.dish-card[data-dish-id="${dishId}"]`).forEach(card => {
        card.setAttribute('data-stock', normalizedStock);
    });

    if (currentDishId === String(dishId) || currentDishId === dishId) {
        window.refreshCurrentModalStockState();
    }
};

/**
 * Update modal image display
 */
function updateModalImage(imageUrl) {
    const modalImage = document.getElementById('modalDishImage');
    const modalPlaceholder = document.getElementById('modalPlaceholder');
    
    if (imageUrl && !imageUrl.includes('placeholder')) {
        modalImage.src = imageUrl;
        modalImage.style.display = 'block';
        modalPlaceholder.style.display = 'none';
        
        // Add fade-in animation
        modalImage.style.opacity = '0';
        setTimeout(() => {
            modalImage.style.transition = 'opacity 0.3s ease';
            modalImage.style.opacity = '1';
        }, 10);
    } else {
        modalImage.style.display = 'none';
        modalPlaceholder.style.display = 'flex';
    }
}

/**
 * Update favorites button state and handler
 */
function updateFavoritesButton(dishId) {
    const favBtn = document.getElementById('favoritesBtn');
    const favBtnText = document.getElementById('favBtnText');
    const favBtnIcon = document.getElementById('favBtnIcon');
    const modalFavCount = document.getElementById('modalFavCount');
    if (!favBtn || !favBtnText || !favBtnIcon) return;

    const isFavorite = favoritesList.includes(parseInt(dishId));
    const cardCount = document.getElementById(`fav-count-${dishId}`);
    favBtn.dataset.itemId = dishId;
    
    if (isFavorite) {
        favBtn.style.backgroundColor = '#c82333';
        favBtnText.textContent = 'Remove from Favorites';
        favBtnIcon.classList.replace('far', 'fas');
    } else {
        favBtn.style.backgroundColor = 'rgba(156, 125, 74, 0.3)';
        favBtnText.textContent = 'Add to Favorites';
        favBtnIcon.classList.replace('fas', 'far');
    }

    if (modalFavCount) {
        modalFavCount.textContent = cardCount ? `(${cardCount.textContent.trim()})` : '';
    }
    
    const newFavBtn = favBtn.cloneNode(true);
    favBtn.parentNode.replaceChild(newFavBtn, favBtn);
    
    newFavBtn.addEventListener('click', function() {
        toggleFavorite(dishId);
    });
}

/**
 * Toggle favorite status
 */
function toggleFavorite(dishId) {
    const favBtn = document.getElementById('favoritesBtn');
    if (favBtn) favBtn.disabled = true;
    
    fetch('toggle_favorite.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `item_id=${encodeURIComponent(dishId)}`
    }).then(response => response.json())
    .then(data => {
        if (favBtn) favBtn.disabled = false;

        if (data.success) {
            const index = favoritesList.indexOf(parseInt(dishId));
            if (data.action === 'removed' && index > -1) {
                favoritesList.splice(index, 1);
            } else if (data.action === 'added' && index === -1) {
                favoritesList.push(parseInt(dishId));
            }

            const navBadge = document.getElementById('nav-fav-count');
            if (navBadge && typeof data.user_total !== 'undefined') {
                navBadge.textContent = data.user_total;
            }

            updateCardFavoriteStar(dishId, data);
            updateFavoritesButton(dishId);
        } else if (data.redirect) {
            window.location.href = data.redirect;
        } else {
            alert(data.error || 'Error updating favorites. Please try again.');
        }
    }).catch(error => {
        if (favBtn) favBtn.disabled = false;
        console.error('Error toggling favorite:', error);
        alert('Error updating favorites. Please try again.');
    });
}

/**
 * Update favorite star on card
 */
function updateCardFavoriteStar(dishId, data = {}) {
    const isFavorite = favoritesList.includes(parseInt(dishId));
    document.querySelectorAll(`.btn-favorite-ajax[data-item-id="${dishId}"]`).forEach(favBtn => {
        const icon = favBtn.querySelector('i');
        const countSpan = favBtn.querySelector(`#fav-count-${dishId}`);

        favBtn.style.backgroundColor = isFavorite ? '#c82333' : 'rgba(156, 125, 74, 0.3)';
        if (icon) {
            icon.classList.toggle('fas', isFavorite);
            icon.classList.toggle('far', !isFavorite);
        }
        if (countSpan && typeof data.new_count !== 'undefined') {
            countSpan.textContent = data.new_count;
        }
    });
}


/**
 * Show success message
 */
function showSuccessMessage(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-success alert-dismissible fade show';
    alertDiv.style.position = 'fixed';
    alertDiv.style.top = '100px';
    alertDiv.style.right = '20px';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.minWidth = '300px';
    alertDiv.innerHTML = `
        <i class="fas fa-check-circle me-2"></i> ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.remove();
    }, 3000);
}

/**
 * Animate modal content on show
 */
function animateModalContent() {
    const modalImage = document.getElementById('modalDishImage');
    const modalDetails = document.querySelector('.modal-dish-details');
    
    if (modalImage.style.display !== 'none') {
        modalImage.style.transform = 'scale(0.95)';
        modalImage.style.transition = 'transform 0.3s ease';
        setTimeout(() => {
            modalImage.style.transform = 'scale(1)';
        }, 10);
    }
    
    if (modalDetails) {
        modalDetails.style.animation = 'slideInRight 0.4s ease';
    }
}

/**
 * Setup filter functionality
 */
function setupFilters() {
    const filterButtons = document.querySelectorAll('.filter-btn');
    
    filterButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            // Let the link handle navigation
            window.location.href = this.href;
        });
    });
}

// Add slide-in animation
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
`;
document.head.appendChild(style);

window.reinitializeMenuCards = function() {
    populateDishData();
    syncFavoritesListFromCards();
    setupCardClicks();
};
