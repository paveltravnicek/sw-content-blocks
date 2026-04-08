
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.swcb-carousel__nav');
  if (!btn) return;

  const wrapper = btn.closest('.swcb-carousel');
  if (!wrapper) return;

  const track = wrapper.querySelector('.swcb-carousel__track');
  if (!track) return;

  const amount = Math.max(track.clientWidth * 0.9, 320);
  track.scrollBy({
    left: btn.classList.contains('swcb-carousel__nav--next') ? amount : -amount,
    behavior: 'smooth'
  });
});
