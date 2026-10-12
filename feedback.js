(function initTestimonialsCarousel() {
    const track = document.getElementById('testimonialsTrack');
    if (!track) return;
    const carousel = document.getElementById('testimonialsCarousel');
    const status = document.getElementById('testimonialsStatus');
    const retry = document.getElementById('retryTestimonials');
    const controls = carousel.querySelector('.testimonials-controls');
    const dots = document.getElementById('testimonialDots');
    const previous = document.getElementById('prevTestimonial');
    const next = document.getElementById('nextTestimonial');
    let current = 0;
    let signature = null;
    let loading = false;
    const visibleCount = () => window.innerWidth >= 768 ? 2 : 1;
    const pages = () => Math.ceil(track.children.length / visibleCount());
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    function goTo(page) {
        const total = pages();
        current = total ? (page + total) % total : 0;
        const first = track.children[0];
        const target = track.children[current * visibleCount()];
        const offset = target && first ? target.offsetLeft - first.offsetLeft : 0;
        track.style.transform = `translateX(-${offset}px)`;
        Array.from(track.children).forEach((card, i) => {
            card.setAttribute('aria-hidden', String(i < current * visibleCount() || i >= (current + 1) * visibleCount()));
        });
        Array.from(dots.children).forEach((dot, i) => {
            dot.classList.toggle('active', i === current);
            dot.setAttribute('aria-current', i === current ? 'true' : 'false');
        });
    }
    function layout() {
        controls.hidden = pages() <= 1;
        dots.replaceChildren();
        for (let i = 0; i < pages(); i++) {
            const dot = element('button', 'testimonial-dot');
            dot.type = 'button';
            dot.setAttribute('aria-label', `Review page ${i + 1}`);
            dot.addEventListener('click', () => goTo(i));
            dots.append(dot);
        }
        goTo(Math.min(current, Math.max(0, pages() - 1)));
    }
    function render(reviews) {
        track.replaceChildren();
        reviews.forEach(review => {
            const card = element('article', 'testimonial-card');
            const header = element('div', 'testimonial-header');
            const quote = element('div', 'testimonial-quote', '\u201c');
            quote.setAttribute('aria-hidden', 'true');
            const stars = element('div', 'testimonial-stars', '\u2605'.repeat(review.rating) + '\u2606'.repeat(5 - review.rating));
            stars.setAttribute('aria-label', `${review.rating} out of 5 stars`);
            header.append(stars, quote);
            const author = element('div', 'testimonial-author');
            const avatar = element('div', 'testimonial-avatar', review.initial);
            avatar.setAttribute('aria-hidden', 'true');
            const details = element('div', 'testimonial-details');
            details.append(element('div', 'testimonial-name', review.name), element('div', 'testimonial-role', 'Customer review'));
            const date = element('time', 'testimonial-date', review.date);
            date.dateTime = review.date;
            details.append(date);
            author.append(avatar, details);
            card.append(header, element('p', 'testimonial-text', review.comment), author);
            track.append(card);
        });
        current = 0;
        carousel.hidden = reviews.length === 0;
        layout();
    }
    async function loadReviews() {
        if (loading || document.hidden) return;
        loading = true;
        retry.hidden = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch('api/getFeedback.php', {cache: 'no-store', signal: controller.signal});
            const data = await response.json();
            if (!response.ok || !data.success || !Array.isArray(data.data)) throw new Error('Unavailable');
            const updated = JSON.stringify(data.data);
            if (updated !== signature) {
                render(data.data);
                signature = updated;
            }
            status.textContent = data.data.length ? '' : 'No public reviews yet. Be the first to share your experience!';
            status.hidden = data.data.length > 0;
        } catch (_) {
            status.hidden = false;
            status.textContent = track.children.length
                ? 'Reviews could not be refreshed. Please try again.'
                : 'Guest reviews are temporarily unavailable. Please try again.';
            retry.hidden = false;
        } finally {
            clearTimeout(timeout);
            loading = false;
        }
    }
    previous.addEventListener('click', () => goTo(current - 1));
    next.addEventListener('click', () => goTo(current + 1));
    retry.addEventListener('click', loadReviews);
    window.addEventListener('resize', layout);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) loadReviews(); });
    loadReviews();
    setInterval(loadReviews, 30000);
})();
