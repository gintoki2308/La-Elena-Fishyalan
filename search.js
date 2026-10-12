// Global variable to store user login status
let currentUser = null;

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

    if (!userAuthNav) {
        return;
    }

    if (currentUser && currentUser.is_logged_in) {
        const firstName = currentUser.first_name || 'User';
        userAuthNav.innerHTML = `
            <a class="logout-link nav-link" href="auth/logout.php">
                <i class="fas fa-user-circle me-1"></i> ${firstName} <i class="fas fa-sign-out-alt ms-1"></i>
            </a>
        `;
    } else {
        userAuthNav.innerHTML = `
            <a class="nav-link" href="auth/login.php"><i class="fas fa-sign-in-alt me-1"></i> Sign In</a>
        `;
    }
}

// Check login status on page load
checkLoginStatus();

// search.js - Landing page search functionality
class RestaurantSearch {
    constructor() {
        this.searchInput = document.getElementById('searchInput');
        this.searchClear = document.getElementById('searchClear');
        this.searchResultsSection = document.getElementById('searchResults');
        this.searchQueryElement = document.getElementById('searchQuery');
        this.searchResultsCount = document.getElementById('searchResultsCount');
        this.searchResultsGrid = document.getElementById('searchResultsGrid');
        this.noSearchResults = document.getElementById('noSearchResults');
        this.noResultsQuery = document.getElementById('noResultsQuery');

        this.allItems = [];
        this.filteredItems = [];
        this.currentSearch = '';
        this.appBaseUrl = this.getAppBaseUrl();
        this.minSearchLength = 2;
        this.liveSearchDelay = 180;
        this.liveSearchTimeout = null;

        this.init();
    }

    async init() {
        this.setupEventListeners();
        this.setupSuggestionTags();
        await this.loadMenuData();
        this.checkUrlSearch();
    }

    getAppBaseUrl() {
        const currentUrl = new URL(window.location.href);
        const currentDirectory = currentUrl.pathname.replace(/[^/]*$/, '');
        return `${currentUrl.origin}${currentDirectory}`;
    }

    resolveAssetUrl(assetPath) {
        const fallbackUrl = new URL('images/placeholder.png', this.appBaseUrl).toString();

        if (!assetPath) {
            return fallbackUrl;
        }

        if (/^https?:\/\//i.test(assetPath)) {
            return assetPath;
        }

        const cleanPath = String(assetPath).replace(/^\/+/, '');
        return new URL(cleanPath, this.appBaseUrl).toString();
    }

    formatCategory(category) {
        const normalizedCategory = String(category || '').trim().toLowerCase();
        const categories = {
            appetizers: 'Appetizer',
            appetizer: 'Appetizer',
            mains: 'Main Course',
            'main course': 'Main Course',
            desserts: 'Dessert',
            dessert: 'Dessert'
        };

        return categories[normalizedCategory] || String(category || 'Menu Item');
    }

    formatPrice(price) {
        const numericPrice = Number.parseFloat(price);
        return Number.isFinite(numericPrice) ? `₱${numericPrice.toFixed(2)}` : '₱0.00';
    }

    buildTags(item) {
        const category = String(item.category || '').trim().toLowerCase();
        const nameWords = String(item.item_name || '')
            .toLowerCase()
            .split(/[^a-z0-9]+/)
            .filter(word => word.length > 2);

        const categoryTag = category ? [category.replace(/\s+/g, '-')] : [];
        return [...new Set([...categoryTag, ...nameWords])].slice(0, 6);
    }

    async loadMenuData() {
        try {
            const response = await fetch('api/getMenuItems.php');
            const result = await response.json();

            if (!response.ok || !result.success || !Array.isArray(result.data)) {
                throw new Error(result.message || 'Unable to load menu data');
            }

            this.allItems = result.data
                .filter(item => item && item.item_id && item.item_name)
                .map(item => {
                    return {
                        id: String(item.item_id),
                        name: item.item_name,
                        price: this.formatPrice(item.price),
                        description: item.description || 'No description available.',
                        category: this.formatCategory(item.category),
                        type: 'dish',
                        image: this.resolveAssetUrl(item.image_url),
                        tags: this.buildTags(item),
                        page: 'index.html'
                    };
                });
        } catch (error) {
            console.error('Failed to load menu data for search:', error);
            this.allItems = [];
        }
    }

    setupEventListeners() {
        this.searchInput?.addEventListener('input', (e) => this.handleSearch(e));
        this.searchInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.performSearch();
            }
        });

        this.searchClear?.addEventListener('click', () => this.clearSearch());
    }

    setupSuggestionTags() {
        document.querySelectorAll('.suggestion-tag').forEach(tag => {
            tag.addEventListener('click', (e) => {
                const searchTerm = e.target.getAttribute('data-search');
                if (!searchTerm || !this.searchInput) {
                    return;
                }

                this.searchInput.value = searchTerm;
                this.performSearch();
            });
        });
    }

    handleSearch(e) {
        const query = e.target.value.trim();

        if (this.searchClear) {
            if (query.length > 0) {
                this.searchClear.classList.add('visible');
            } else {
                this.searchClear.classList.remove('visible');
            }
        }

        window.clearTimeout(this.liveSearchTimeout);

        if (!query) {
            this.resetSearchState();
            this.clearUrlSearch();
            return;
        }

        if (query.length < this.minSearchLength) {
            this.resetSearchState({ preserveQuery: true });
            this.clearUrlSearch();
            return;
        }

        this.liveSearchTimeout = window.setTimeout(() => {
            this.performSearch({ shouldScroll: false });
        }, this.liveSearchDelay);
    }

    performSearch(options = {}) {
        const { shouldScroll = true } = options;
        const query = this.searchInput?.value.trim() || '';

        if (query.length < this.minSearchLength) {
            this.resetSearchState({ preserveQuery: Boolean(query) });
            this.clearUrlSearch();
            return;
        }

        this.currentSearch = query;
        this.filterItems(query);
        this.displaySearchResults({ shouldScroll });
        this.updateUrl(query);
    }

    filterItems(query) {
        const normalizedQuery = query.toLowerCase();
        const searchTerms = normalizedQuery.split(' ').filter(term => term.length > 0);

        this.filteredItems = this.allItems.filter(item => {
            const searchableText = [
                item.name,
                item.description,
                item.category,
                item.type,
                item.tags.join(' ')
            ].join(' ').toLowerCase();

            return searchTerms.every(term => searchableText.includes(term));
        });

        this.filteredItems.sort((a, b) => {
            const aNameMatch = a.name.toLowerCase().includes(normalizedQuery);
            const bNameMatch = b.name.toLowerCase().includes(normalizedQuery);

            if (aNameMatch && !bNameMatch) return -1;
            if (!aNameMatch && bNameMatch) return 1;
            return a.name.localeCompare(b.name);
        });
    }

    displaySearchResults(options = {}) {
        const { shouldScroll = true } = options;

        if (this.searchQueryElement) {
            this.searchQueryElement.textContent = this.currentSearch;
        }

        if (this.noResultsQuery) {
            this.noResultsQuery.textContent = this.currentSearch;
        }

        if (this.searchResultsCount) {
            const count = this.filteredItems.length;
            this.searchResultsCount.textContent = `Found ${count} item${count !== 1 ? 's' : ''}`;
        }

        if (this.searchResultsGrid) {
            this.searchResultsGrid.innerHTML = '';
        }

        if (this.filteredItems.length === 0) {
            if (this.searchResultsSection) {
                this.searchResultsSection.style.display = 'block';
            }
            if (this.noSearchResults) {
                this.noSearchResults.style.display = 'block';
            }
            if (this.searchResultsGrid) {
                this.searchResultsGrid.style.display = 'none';
            }

            if (shouldScroll) {
                setTimeout(() => {
                    this.searchResultsSection?.scrollIntoView({ behavior: 'smooth' });
                }, 100);
            }
            return;
        }

        if (this.searchResultsSection) {
            this.searchResultsSection.style.display = 'block';
        }
        if (this.noSearchResults) {
            this.noSearchResults.style.display = 'none';
        }
        if (this.searchResultsGrid) {
            this.searchResultsGrid.style.display = 'grid';
        }

        this.filteredItems.forEach(item => {
            const resultHtml = this.createResultItemHtml(item);
            this.searchResultsGrid?.insertAdjacentHTML('beforeend', resultHtml);
        });

        setTimeout(() => {
            document.querySelectorAll('.search-result-item').forEach((resultItem, index) => {
                resultItem.addEventListener('click', () => this.handleResultClick(this.filteredItems[index]));
            });
        }, 50);

        if (shouldScroll) {
            setTimeout(() => {
                this.searchResultsSection?.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }
    }

    createResultItemHtml(item) {
        const defaultImage = new URL('images/placeholder.png', this.appBaseUrl).toString();
        const imageUrl = item.image || defaultImage;

        return `
            <div class="search-result-item" data-id="${item.id}">
                <div class="search-result-image">
                    <img src="${imageUrl}" alt="${item.name}" onerror="this.src='${defaultImage}'">
                </div>
                <div class="search-result-content">
                    <span class="search-result-type">FOOD</span>
                    <h3 class="search-result-name">${item.name}</h3>
                    <div class="search-result-price">${item.price}</div>
                    <p class="search-result-description">${item.description}</p>
                    <div class="search-result-meta">
                        <span class="search-result-category">${item.category}</span>
                        <button class="search-result-action">View Details</button>
                    </div>
                </div>
            </div>
        `;
    }

    handleResultClick(item) {
        if (!item) {
            return;
        }

        const url = new URL('index.html', window.location.href);
        if (this.currentSearch) {
            url.searchParams.set('search', this.currentSearch);
        }
        url.searchParams.set('dish', item.id);
        url.hash = 'menu';
        window.location.href = url.toString();
    }

    clearSearch() {
        window.clearTimeout(this.liveSearchTimeout);

        if (this.searchInput) {
            this.searchInput.value = '';
        }

        this.searchClear?.classList.remove('visible');
        this.resetSearchState();
        this.clearUrlSearch();
    }

    resetSearchState(options = {}) {
        const { preserveQuery = false } = options;

        this.currentSearch = preserveQuery ? (this.searchInput?.value.trim() || '') : '';
        this.filteredItems = [];

        if (this.searchResultsGrid) {
            this.searchResultsGrid.innerHTML = '';
            this.searchResultsGrid.style.display = 'none';
        }
        if (this.noSearchResults) {
            this.noSearchResults.style.display = 'none';
        }
        if (this.searchQueryElement && !preserveQuery) {
            this.searchQueryElement.textContent = '';
        }
        if (this.noResultsQuery) {
            this.noResultsQuery.textContent = this.currentSearch;
        }
        if (this.searchResultsCount) {
            this.searchResultsCount.textContent = '';
        }

        this.hideSearchResults();
    }

    hideSearchResults() {
        if (this.searchResultsSection) {
            this.searchResultsSection.style.display = 'none';
        }
    }

    updateUrl(query) {
        if (query) {
            const url = new URL(window.location.href);
            url.searchParams.set('search', query);
            window.history.pushState({}, '', url.toString());
        } else {
            this.clearUrlSearch();
        }
    }

    clearUrlSearch() {
        const url = new URL(window.location.href);
        url.searchParams.delete('search');
        window.history.pushState({}, '', url.toString());
    }

    checkUrlSearch() {
        const urlParams = new URLSearchParams(window.location.search);
        const searchParam = urlParams.get('search');

        if (searchParam && this.searchInput) {
            this.searchInput.value = searchParam;
            this.searchClear?.classList.add('visible');
            this.performSearch();
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    window.restaurantSearch = new RestaurantSearch();
});
