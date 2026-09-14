/**
 * Web Player V2 — Catalog & Data Synchronization Manager
 *
 * Handles server-side cache clearing, user bouquet & stream synchronization,
 * visual feedback with spinning animation and notifications, and view refresh.
 */

'use strict';

window.PlayerSync = (function () {
  let isSyncing = false;

  const init = () => {
    const refreshBtn = document.getElementById('nav-refresh-data');
    const refreshIcon = document.getElementById('nav-refresh-icon');
    const toastEl = document.getElementById('sync-toast');
    const toastBody = document.getElementById('sync-toast-body');

    if (!refreshBtn) return;

    let toastInstance = null;
    if (toastEl && window.bootstrap && window.bootstrap.Toast) {
      toastInstance = new bootstrap.Toast(toastEl, { delay: 4500 });
    }

    const showToast = (message, isError = false) => {
      if (toastBody) {
        toastBody.textContent = message;
      }
      if (toastEl) {
        toastEl.classList.remove('bg-primary', 'bg-danger', 'text-white');
        if (isError) {
          toastEl.classList.add('bg-danger', 'text-white');
        } else {
          toastEl.classList.add('bg-primary', 'text-white');
        }
      }
      if (toastInstance) {
        toastInstance.show();
      }
    };

    refreshBtn.addEventListener('click', async (e) => {
      e.preventDefault();
      if (isSyncing) return;

      isSyncing = true;
      if (refreshIcon) {
        refreshIcon.classList.add('spin-anim', 'text-primary');
      }

      showToast('Refreshing and synchronizing latest data...');

      const refreshUrl = refreshBtn.getAttribute('data-refresh-url') || '/webplayer_v2/refresh';

      try {
        const response = await fetch(refreshUrl, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });

        const data = await response.json();

        if (response.ok && data.status === 'success') {
          showToast(data.message || 'Data synchronized successfully! Reloading...');
          setTimeout(() => {
            window.location.reload();
          }, 800);
        } else {
          showToast(data.message || 'Failed to synchronize data.', true);
          if (refreshIcon) {
            refreshIcon.classList.remove('spin-anim', 'text-primary');
          }
          isSyncing = false;
        }
      } catch (err) {
        console.error('Data sync failed:', err);
        showToast('Network error occurred while synchronizing data.', true);
        if (refreshIcon) {
          refreshIcon.classList.remove('spin-anim', 'text-primary');
        }
        isSyncing = false;
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return {
    init
  };
})();
