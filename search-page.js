// search-page.js — Enhanced Search Page for La Elena Fishyalan

class SearchPage {
    constructor() {
        // Core elements
        this.mainSearchInput    = document.getElementById('mainSearchInput');
        this.mainSearchClear    = document.getElementById('mainSearchClear');
        this.searchQueryEl      = document.getElementById('searchQuery');
        this.resultsTitleLabel = document.getElementById('resultsTitleLabel');
        this.searchResultsCount = document.getElementById('searchResultsCount');
        this.searchResultsGrid  = document.getElementById('searchResultsGrid');
        this.noSearchResults    = document.getElementById('noSearchResults');
        this.searchResultsHeader = document.getElementById('searchResultsHeader');
        this.sortSelect         = document.getElementById('sortSelect');
        this.recentWrap         = document.getElementById('recentSearchesWrap');
        this.recentChips        = document.getElementById('recentSearchesChips');
        this.clearRecentBtn     = document.getElementById('clearRecentBtn');

        // State
        this.allItems      = [];
        this.searchMatches = [];
        this.filteredItems = [];
        this.currentSearch = '';
        this.currentFilter = 'all';
        this.currentSort   = 'relevance';
        this.appBaseUrl    = this.getAppBaseUrl();
        this.loadError     = false;
        this.liveDelay     = 220;
        this.liveTimeout   = null;

        // Chef's pick IDs (optional — set by item name keywords)
        this.chefPicks = ['wagyu', 'foie', 'lobster', 'truffle', 'laelena', 'chateaubriand'];

        this.init();
    }

    async init() {
        this.spawnParticles();
        this.renderRecentSearches();
        this.setupEventListeners();
        this.setupFilters();
        await this.loadMenuData();
        this.checkUrlSearch();
    }

    /* ── Floating gold particles in hero ── */
    spawnParticles() {
        const container = document.getElementById('searchParticles');
        if (!container) return;
        for (let i = 0; i < 18; i++) {
            const p = document.createElement('div');
            p.className = 'hero-particle';
            p.style.cssText = `
                left: ${Math.random() * 100}%;
                width: ${(Math.random() * 2.5 + 0.8)}px;
                height: ${(Math.random() * 2.5 + 0.8)}px;
                animation-duration: ${(Math.random() * 12 + 8)}s;
                animation-delay: ${(Math.random() * 10)}s;
            `;
            container.appendChild(p);
        }
    }

    /* ── Recent searches (localStorage) ── */
    getRecentSearches() {
        try { return JSON.parse(localStorage.getItem('gs_recent') || '[]'); }
        catch { return []; }
    }

    saveRecentSearch(query) {
        let recents = this.getRecentSearches();
        recents = [query, ...recents.filter(r => r.toLowerCase() !== query.toLowerCase())].slice(0, 5);
        localStorage.setItem('gs_recent', JSON.stringify(recents));
        this.renderRecentSearches();
    }

    renderRecentSearches() {
        const recents = this.getRecentSearches();
        if (!this.recentWrap || !this.recentChips) return;

        if (recents.length === 0) {
            this.recentWrap.style.display = 'none';
            return;
        }

        this.recentWrap.style.display = 'flex';
        this.recentChips.innerHTML = recents.map(r =>
            `<button class="recent-chip" data-search="${r}">
                <i class="fas fa-clock" style="font-size:0.65rem;opacity:0.6;"></i>${r}
             </button>`
        ).join('');

        this.recentChips.querySelectorAll('.recent-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                this.mainSearchInput.value = chip.dataset.search;
                this.mainSearchInput.dispatchEvent(new Event('input'));
                this.performSearch();
            });
        });
    }

    /* ── Utility ── */
    getAppBaseUrl() {
        const u = new URL(window.location.href);
        return `${u.origin}${u.pathname.replace(/[^/]*$/, '')}`;
    }

    resolveAssetUrl(path) {
        const fallback = new URL('images/placeholder.png', this.appBaseUrl).toString();
        if (!path) return fallback;
        if (/^https?:\/\//i.test(path)) return path;
        return new URL(String(path).replace(/^\/+/, ''), this.appBaseUrl).toString();
    }

    formatCategory(category) {
        const map = {
            appetizers: 'Appetizer', appetizer: 'Appetizer',
            mains: 'Main Course', 'main course': 'Main Course',
            desserts: 'Dessert', dessert: 'Dessert'
        };
        return map[String(category || '').trim().toLowerCase()] || String(category || 'Menu Item');
    }

    formatPrice(price) {
        const n = parseFloat(price);
        return isFinite(n) ? `₱${n.toFixed(2)}` : '₱0.00';
    }

    parsePrice(priceStr) {
        return parseFloat(String(priceStr).replace(/[^0-9.]/g, '')) || 0;
    }

    buildTags(item) {
        const cat = String(item.category || '').trim().toLowerCase();
        const words = String(item.item_name || '').toLowerCase()
            .split(/[^a-z0-9]+/).filter(w => w.length > 2);
        return [...new Set([cat ? [cat.replace(/\s+/g, '-')] : [], ...words].flat())].slice(0, 6);
    }

    isChefPick(item) {
        const name = item.name.toLowerCase();
        return this.chefPicks.some(k => name.includes(k));
    }

    /* ── Load data ── */
    async loadMenuData() {
        this.loadError = false;
        try {
            const res = await fetch('api/getMenuItems.php');
            const result = await res.json();
            if (!res.ok || !result.success || !Array.isArray(result.data))
                throw new Error(result.message || 'Failed to load menu');

            this.allItems = result.data
                .filter(i => i && i.item_id && i.item_name)
                .map(i => {
                    return {
                        id:          String(i.item_id),
                        name:        i.item_name,
                        price:       this.formatPrice(i.price),
                        priceNum:    parseFloat(i.price) || 0,
                        description: i.description || 'No description available.',
                        category:    this.formatCategory(i.category),
                        type:        'dish',
                        image:       this.resolveAssetUrl(i.image_url),
                        tags:        this.buildTags(i),
                        page:        'index.html'
                    };
                });
        } catch (err) {
            console.error('Search load error:', err);
            this.allItems = [];
            this.loadError = true;
        }
    }

    /* ── Event listeners ── */
    setupEventListeners() {
        this.mainSearchInput?.addEventListener('input', e => this.handleInput(e));
        this.mainSearchInput?.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); this.performSearch(); }
        });
        this.mainSearchInput?.addEventListener('focus', () => this.renderRecentSearches());

        this.mainSearchClear?.addEventListener('click', () => this.clearSearch());

        this.sortSelect?.addEventListener('change', () => {
            this.currentSort = this.sortSelect.value;
            this.applyFilter();
            this.renderResults({ shouldScroll: false });
        });

        // Custom Sort Dropdown Logic
        const customDropdown = document.getElementById('customSortDropdown');
        const customSelected = document.getElementById('customSortSelected');
        const customOptions = document.getElementById('customSortOptions');
        
        if (customSelected && customDropdown && customOptions) {
            customSelected.addEventListener('click', (e) => {
                e.stopPropagation();
                customDropdown.classList.toggle('open');
            });
            
            customOptions.addEventListener('click', (e) => {
                if (e.target.classList.contains('custom-sort-option')) {
                    const value = e.target.getAttribute('data-value');
                    const text = e.target.innerText;
                    
                    customSelected.innerHTML = `${text}`;
                    customOptions.querySelectorAll('.custom-sort-option').forEach(opt => opt.classList.remove('selected'));
                    e.target.classList.add('selected');
                    
                    customDropdown.classList.remove('open');
                    
                    if (this.sortSelect) {
                        this.sortSelect.value = value;
                        this.sortSelect.dispatchEvent(new Event('change'));
                    }
                }
            });
            
            document.addEventListener('click', (e) => {
                if (!customDropdown.contains(e.target)) {
                    customDropdown.classList.remove('open');
                }
            });
        }

        this.clearRecentBtn?.addEventListener('click', () => {
            localStorage.removeItem('gs_recent');
            this.renderRecentSearches();
        });

        // Suggestion tags (no results state)
        document.querySelectorAll('.suggestion-tag').forEach(tag => {
            tag.addEventListener('click', () => {
                this.mainSearchInput.value = tag.dataset.search;
                this.performSearch();
            });
        });
    }

    setupFilters() {
        document.querySelectorAll('.filter-chip').forEach(chip => {
            chip.addEventListener('click', e => {
                document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
                e.target.classList.add('active');
                this.currentFilter = e.target.dataset.filter || 'all';
                this.applyFilter();
                this.renderResults({ shouldScroll: false });
            });
        });
    }

    handleInput(e) {
        const q = e.target.value.trim();
        this.mainSearchClear?.classList.toggle('visible', q.length > 0);
        clearTimeout(this.liveTimeout);

        if (!q) { this.resetState(); this.clearUrl(); return; }

        this.liveTimeout = setTimeout(() => {
            this.performSearch({ shouldScroll: false });
        }, this.liveDelay);
    }

    performSearch(options = {}) {
        const { shouldScroll = true } = options;
        const q = this.mainSearchInput?.value.trim() || '';

        if (!q) {
            this.resetState();
            this.clearUrl();
            return;
        }

        this.currentSearch = q;
        this.saveRecentSearch(q);
        this.filterItems(q);
        this.renderResults({ shouldScroll });
        this.updateUrl(q);
    }

    filterItems(q) {
        const terms = q.toLowerCase().split(' ').filter(t => t.length > 0);

        this.searchMatches = this.allItems.filter(item => {
            const blob = [item.name, item.description, item.category, item.type, item.tags.join(' ')].join(' ').toLowerCase();
            return terms.every(t => blob.includes(t));
        });

        this.applyFilter();
    }

    applyFilter() {
        let items = [...this.searchMatches];

        // Category filter
        if (this.currentFilter !== 'all') {
            items = items.filter(item => {
                switch (this.currentFilter) {
                    case 'food': return item.type === 'dish';
                    case 'dish': return item.type === 'dish';
                    case 'appetizer': return item.category.toLowerCase() === 'appetizer';
                    case 'main': return item.category.toLowerCase() === 'main course';
                    case 'dessert': return item.category.toLowerCase() === 'dessert';
                    default: return true;
                }
            });
        }

        // Sort
        switch (this.currentSort) {
            case 'price-asc':  items.sort((a, b) => a.priceNum - b.priceNum); break;
            case 'price-desc': items.sort((a, b) => b.priceNum - a.priceNum); break;
            case 'name':       items.sort((a, b) => a.name.localeCompare(b.name)); break;
            default:
                items.sort((a, b) => {
                    const aChef = this.isChefPick(a) ? -1 : 0;
                    const bChef = this.isChefPick(b) ? -1 : 0;
                    if (aChef !== bChef) return aChef - bChef;
                    const aName = a.name.toLowerCase().includes(this.currentSearch.toLowerCase());
                    const bName = b.name.toLowerCase().includes(this.currentSearch.toLowerCase());
                    if (aName && !bName) return -1;
                    if (!aName && bName) return 1;
                    return 0;
                });
        }

        this.filteredItems = items;
    }

    /* ── Render ── */
    renderResults(options = {}) {
        const { shouldScroll = true } = options;
        const count = this.filteredItems.length;

        // Update header
        if (this.resultsTitleLabel) this.resultsTitleLabel.textContent = this.currentSearch ? 'Results for' : 'All available food';
        if (this.searchQueryEl) this.searchQueryEl.textContent = this.currentSearch ? `"${this.currentSearch}"` : '';

        const emptyTitle = this.noSearchResults?.querySelector('.no-results-title');
        const emptySubtitle = this.noSearchResults?.querySelector('.no-results-sub');
        if (emptyTitle) {
            emptyTitle.textContent = this.loadError
                ? 'Unable to load the food menu'
                : this.currentSearch ? `Nothing found for "${this.currentSearch}"` : 'No food items available';
        }
        if (emptySubtitle) {
            emptySubtitle.textContent = this.loadError
                ? 'Please refresh the page to try again.'
                : this.currentSearch ? 'Try a different keyword.' : 'Try another filter or check back soon.';
        }

        // Animate count
        if (this.searchResultsCount) this.animateCount(this.searchResultsCount, count);

        if (this.searchResultsHeader) this.searchResultsHeader.style.display = 'block';

        if (count === 0) {
            if (this.searchResultsGrid) { this.searchResultsGrid.innerHTML = ''; this.searchResultsGrid.style.display = 'none'; }
            if (this.noSearchResults) this.noSearchResults.style.display = 'block';
        } else {
            if (this.noSearchResults) this.noSearchResults.style.display = 'none';
            if (this.searchResultsGrid) {
                this.searchResultsGrid.style.display = 'grid';
                this.searchResultsGrid.innerHTML = this.filteredItems
                    .map((item, idx) => this.buildCard(item, idx))
                    .join('');

                // Click handlers
                this.searchResultsGrid.querySelectorAll('.search-result-item').forEach((el, idx) => {
                    el.addEventListener('click', () => this.handleResultClick(this.filteredItems[idx]));
                });
            }
        }

        if (shouldScroll) {
            setTimeout(() => {
                document.getElementById('searchResults')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 150);
        }
    }

    animateCount(el, target) {
        const start = 0;
        const duration = 600;
        const startTime = performance.now();
        const update = (now) => {
            const progress = Math.min((now - startTime) / duration, 1);
            const val = Math.round(progress * target);
            el.textContent = `Found ${val} item${val !== 1 ? 's' : ''}`;
            if (progress < 1) requestAnimationFrame(update);
        };
        requestAnimationFrame(update);
    }

    buildCard(item, idx) {
        const defaultImg = new URL('images/placeholder.png', this.appBaseUrl).toString();
        const chef = this.isChefPick(item);
        const delay = idx * 60;

        return `
        <div class="search-result-item" data-id="${item.id}" style="animation-delay:${delay}ms">
            <div class="search-result-image">
                <img src="${item.image}" alt="${item.name}" onerror="this.src='${defaultImg}'">
                <div class="search-result-badge">
                    <span class="badge-type">${item.type === 'dish' ? '🍴 FOOD' : '🍸 DRINK'}</span>
                    ${chef ? '<span class="badge-chef">⭐ Chef\'s Pick</span>' : ''}
                </div>
            </div>
            <div class="search-result-content">
                <h3 class="search-result-name">${item.name}</h3>
                <div class="search-result-price-row">
                    <span class="search-result-price">${item.price}</span>
                    <span class="search-result-category-chip">${item.category}</span>
                </div>
                <p class="search-result-description">${item.description}</p>
                <div class="search-result-footer">
                    <span class="search-result-tags">${item.tags.slice(0,2).map(t => `#${t}`).join(' ')}</span>
                    <span class="search-result-go">View <i class="fas fa-arrow-right" style="font-size:0.7rem;"></i></span>
                </div>
            </div>
        </div>`;
    }

    handleResultClick(item) {
        if (!item) return;
        const url = new URL('index.html', window.location.href);
        if (this.currentSearch) url.searchParams.set('search', this.currentSearch);
        url.searchParams.set('dish', item.id);
        url.hash = 'menu';
        window.location.href = url.toString();
    }

    clearSearch() {
        clearTimeout(this.liveTimeout);
        if (this.mainSearchInput) this.mainSearchInput.value = '';
        this.mainSearchClear?.classList.remove('visible');
        this.resetState();
        this.clearUrl();
    }

    resetState() {
        this.currentSearch = '';
        this.searchMatches = [...this.allItems];
        this.applyFilter();
        this.renderResults({ shouldScroll: false });
    }

    updateUrl(q) {
        const url = new URL(window.location.href);
        q ? url.searchParams.set('search', q) : url.searchParams.delete('search');
        window.history.pushState({}, '', url.toString());
    }

    clearUrl() {
        const url = new URL(window.location.href);
        url.searchParams.delete('search');
        window.history.pushState({}, '', url.toString());
    }

    checkUrlSearch() {
        const q = new URLSearchParams(window.location.search).get('search');
        if (q) {
            if (this.mainSearchInput) this.mainSearchInput.value = q;
            this.mainSearchClear?.classList.add('visible');
            this.performSearch();
        } else {
            this.resetState();
        }
    }
}

// Input shake animation
const shakeStyle = document.createElement('style');
shakeStyle.textContent = `
@keyframes inputShake {
    0%,100% { transform: translateX(0); }
    20%,60%  { transform: translateX(-6px); }
    40%,80%  { transform: translateX(6px); }
}
.input-shake .search-hero-input-wrap { animation: inputShake 0.5s ease; }
`;
document.head.appendChild(shakeStyle);

document.addEventListener('DOMContentLoaded', () => {
    window.searchPage = new SearchPage();
});
