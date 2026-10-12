(() => {
    const form = document.getElementById('feedbackForm');
    const description = document.getElementById('ratingDescription');
    const comment = document.getElementById('feedbackComment');
    const count = document.getElementById('commentCount');
    const ratings = ['Select a rating', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'];
    function updateRating() {
        const selected = form.querySelector('input[name="rating"]:checked');
        description.textContent = selected ? `${selected.value}/5 — ${ratings[Number(selected.value)]}` : ratings[0];
    }
    function updateCount() {
        count.textContent = `${comment.value.length.toLocaleString('en-US')} / 2,000`;
    }
    form.querySelectorAll('input[name="rating"]').forEach(input => input.addEventListener('change', updateRating));
    comment.addEventListener('input', updateCount);
    updateRating();
    updateCount();

    document.addEventListener('click', event => {
        const link = event.target.closest('.logout-link');
        if (!link || typeof Swal === 'undefined') return;
        event.preventDefault();
        Swal.fire({
            title: 'Ready to leave?',
            text: 'Are you sure you want to log out?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Log out',
            cancelButtonText: 'Stay here',
            confirmButtonColor: '#c65339',
            cancelButtonColor: '#526e73',
            background: '#fffdf8',
            color: '#143d4a'
        }).then(result => {
            if (result.isConfirmed) window.location.href = link.href;
        });
    });
})();
