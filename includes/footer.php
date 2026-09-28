</div><!-- end .main -->

<!-- ── Corner toast notifications ──────────────────────────────────────────
     Usage from anywhere: showToast('Task added successfully!', 'success');
     Types: 'success' | 'error' | 'info'                                    -->
<div id="toastContainer" style="position:fixed;top:20px;right:20px;z-index:9999;
     display:flex;flex-direction:column;gap:10px;pointer-events:none;max-width:320px"></div>
<style>
.pt-toast{
    pointer-events:auto;
    display:flex;align-items:center;gap:10px;
    background:var(--card-bg,#26262f);border:1px solid var(--border,#34343f);
    color:var(--text-main,#ececf2);
    border-radius:12px;padding:12px 16px;font-size:13px;font-weight:500;
    box-shadow:0 8px 24px rgba(0,0,0,.25);
    opacity:0;transform:translateX(24px);
    transition:opacity .25s ease,transform .25s ease;
}
.pt-toast.show{opacity:1;transform:translateX(0)}
.pt-toast i{font-size:16px;flex-shrink:0}
.pt-toast-success{border-color:rgba(76,175,130,.35)}
.pt-toast-success i{color:#4caf82}
.pt-toast-error{border-color:rgba(217,83,79,.35)}
.pt-toast-error i{color:#d9534f}
.pt-toast-info{border-color:rgba(124,106,247,.35)}
.pt-toast-info i{color:#7c6af7}
.pt-toast-close{margin-left:auto;background:none;border:none;color:var(--text-muted,#9a9aa5);
    cursor:pointer;font-size:14px;line-height:1;padding:0 0 0 8px;flex-shrink:0}
.pt-toast-close:hover{color:var(--text-main,#ececf2)}
</style>
<script>
function showToast(message, type, duration) {
    type = type || 'success';
    duration = duration || 3200;
    var icons = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill', info: 'bi-info-circle-fill' };
    var container = document.getElementById('toastContainer');
    if (!container) return;

    var el = document.createElement('div');
    el.className = 'pt-toast pt-toast-' + type;
    el.innerHTML = '<i class="bi ' + (icons[type] || icons.success) + '"></i>'
        + '<span></span>'
        + '<button class="pt-toast-close" aria-label="Dismiss">&times;</button>';
    el.querySelector('span').textContent = message; // textContent — avoids HTML injection

    function dismiss() {
        el.classList.remove('show');
        setTimeout(function () { el.remove(); }, 250);
    }
    el.querySelector('.pt-toast-close').addEventListener('click', dismiss);

    container.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('show'); });
    setTimeout(dismiss, duration);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</body>
</html>
