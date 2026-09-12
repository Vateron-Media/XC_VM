/**
 * Web Player V2 — Subscriber Profile & Settings Controller
 *
 * Handles credential toggles, 1-click clipboard copies, live M3U/EPG playlist
 * generation, bouquet re-ordering, and API connection snippets.
 */

'use strict';

window.PlayerProfile = (function () {
  const init = () => {
    initProgressBars();
    initCredentialToggles();
    initCopyButtons();
    initPlaylistGenerator();
    initBouquetReorder();
    initProfileSync();
    initAccountSwitcher();
  };

  /**
   * Initialize dynamic progress bars from data-progress-percent.
   */
  const initProgressBars = () => {
    document.querySelectorAll('[data-progress-percent]').forEach((bar) => {
      const pct = bar.getAttribute('data-progress-percent') || '0';
      bar.style.width = pct + '%';
    });
  };

  /**
   * Toggle password masking and visibility.
   */
  const initCredentialToggles = () => {
    const toggleBtn = document.getElementById('btn-toggle-password');
    const pwdInput = document.getElementById('profile-password-input');
    const eyeIcon = document.getElementById('icon-toggle-password');

    if (!toggleBtn || !pwdInput) return;

    toggleBtn.addEventListener('click', () => {
      const isPassword = pwdInput.type === 'password';
      pwdInput.type = isPassword ? 'text' : 'password';
      if (eyeIcon) {
        eyeIcon.className = isPassword
          ? 'icon-base bx bx-hide icon-md text-primary'
          : 'icon-base bx bx-show icon-md';
      }
    });
  };

  /**
   * Generic 1-click clipboard copy with visual feedback.
   */
  const initCopyButtons = () => {
    document.querySelectorAll('[data-copy-target]').forEach((btn) => {
      btn.addEventListener('click', async (e) => {
        e.preventDefault();
        const targetId = btn.getAttribute('data-copy-target');
        const targetEl = document.getElementById(targetId);
        if (!targetEl) return;

        const valToCopy = targetEl.value !== undefined ? targetEl.value : targetEl.textContent.trim();
        if (!valToCopy) return;

        try {
          await navigator.clipboard.writeText(valToCopy);

          const origHtml = btn.innerHTML;
          btn.innerHTML = '<i class="icon-base bx bx-check icon-sm text-success me-1"></i> Copied!';
          btn.classList.add('btn-success');
          btn.classList.remove('btn-outline-primary', 'btn-outline-secondary', 'btn-primary');

          setTimeout(() => {
            btn.innerHTML = origHtml;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-primary');
          }, 2000);
        } catch (err) {
          console.error('Clipboard copy failed:', err);
        }
      });
    });
  };

  /**
   * Live M3U Playlist & EPG Link Generator.
   */
  const initPlaylistGenerator = () => {
    const deviceSelect = document.getElementById('playlist-device-select');
    const outputSelect = document.getElementById('playlist-output-select');
    const urlInput = document.getElementById('playlist-generated-url');
    const downloadBtn = document.getElementById('playlist-download-btn');
    const epgUrlInput = document.getElementById('epg-generated-url');

    if (!deviceSelect || !urlInput) return;

    const updatePlaylistUrl = () => {
      const serverBase = (urlInput.getAttribute('data-server-base') || window.location.origin).replace(/\/+$/, '');
      const username = urlInput.getAttribute('data-username') || '';
      const password = urlInput.getAttribute('data-password') || '';
      const deviceFormat = deviceSelect.value || 'm3u_plus';
      const outputType = outputSelect ? outputSelect.value : '';

      let link = '';
      if (deviceFormat === 'm3u_plus_hls') {
        link = `${serverBase}/get.php?username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}&type=m3u_plus&output=hls`;
      } else if (deviceFormat === 'm3u_plus_ts') {
        link = `${serverBase}/get.php?username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}&type=m3u_plus&output=ts`;
      } else if (deviceFormat === 'm3u_standard') {
        link = `${serverBase}/get.php?username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}&type=m3u`;
      } else {
        link = `${serverBase}/playlist/${encodeURIComponent(username)}/${encodeURIComponent(password)}/${deviceFormat}`;
      }

      if (outputType) {
        const sep = link.includes('?') ? '&' : '?';
        link += `${sep}key=${encodeURIComponent(outputType)}`;
      }

      urlInput.value = link;

      if (downloadBtn) {
        downloadBtn.href = link;
      }

      if (epgUrlInput) {
        epgUrlInput.value = `${serverBase}/xmltv.php?username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}`;
      }
    };

    deviceSelect.addEventListener('change', updatePlaylistUrl);
    if (outputSelect) {
      outputSelect.addEventListener('change', updatePlaylistUrl);
    }

    updatePlaylistUrl();
  };

  /**
   * Bouquet Re-ordering manager (Move Up, Move Down, Sort A-Z, Save).
   */
  const initBouquetReorder = () => {
    const listContainer = document.getElementById('bouquet-sort-container');
    const moveUpBtn = document.getElementById('btn-bouquet-up');
    const moveDownBtn = document.getElementById('btn-bouquet-down');
    const sortAzBtn = document.getElementById('btn-bouquet-az');
    const saveBtn = document.getElementById('btn-save-bouquets');
    const toastEl = document.getElementById('sync-toast');
    const toastBody = document.getElementById('sync-toast-body');

    if (!listContainer) return;

    let selectedItem = null;

    // Item selection handler
    listContainer.addEventListener('click', (e) => {
      const item = e.target.closest('.bouquet-sort-item');
      if (!item) return;

      listContainer.querySelectorAll('.bouquet-sort-item').forEach((el) => {
        el.classList.remove('active');
      });

      selectedItem = item;
      item.classList.add('active');
    });

    // Move Up
    if (moveUpBtn) {
      moveUpBtn.addEventListener('click', () => {
        if (!selectedItem) return;
        const prev = selectedItem.previousElementSibling;
        if (prev) {
          listContainer.insertBefore(selectedItem, prev);
          selectedItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      });
    }

    // Move Down
    if (moveDownBtn) {
      moveDownBtn.addEventListener('click', () => {
        if (!selectedItem) return;
        const next = selectedItem.nextElementSibling;
        if (next) {
          listContainer.insertBefore(next, selectedItem);
          selectedItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      });
    }

    // Sort A-Z
    if (sortAzBtn) {
      sortAzBtn.addEventListener('click', () => {
        const items = Array.from(listContainer.querySelectorAll('.bouquet-sort-item'));
        items.sort((a, b) => {
          const nameA = (a.getAttribute('data-bouquet-name') || '').toLowerCase();
          const nameB = (b.getAttribute('data-bouquet-name') || '').toLowerCase();
          return nameA.localeCompare(nameB);
        });
        items.forEach((el) => listContainer.appendChild(el));
      });
    }

    // Save Bouquet Order via AJAX
    if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        const bouquetIds = Array.from(listContainer.querySelectorAll('.bouquet-sort-item')).map((el) => {
          return parseInt(el.getAttribute('data-bouquet-id'), 10);
        }).filter((id) => !isNaN(id) && id > 0);

        const origHtml = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Saving...';

        const saveUrl = saveBtn.getAttribute('data-save-url') || 'profile';

        try {
          const res = await fetch(saveUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            body: JSON.stringify({ bouquet_order: bouquetIds })
          });

          const data = await res.json();

          if (res.ok && data.status === 'success') {
            if (toastBody) {
              toastBody.textContent = data.message || 'Bouquet order saved successfully!';
            }
            if (toastEl && window.bootstrap && window.bootstrap.Toast) {
              const toastInstance = new bootstrap.Toast(toastEl, { delay: 3500 });
              toastInstance.show();
            }
          } else {
            alert(data.message || 'Failed to save bouquet order.');
          }
        } catch (err) {
          console.error('Failed to save bouquet order:', err);
          alert('Network error while saving bouquet order.');
        } finally {
          saveBtn.disabled = false;
          saveBtn.innerHTML = origHtml;
        }
      });
    }
  };

  /**
   * Fast sync action button inside profile header.
   */
  const initProfileSync = () => {
    const profileSyncBtn = document.getElementById('btn-profile-sync');
    if (!profileSyncBtn) return;

    profileSyncBtn.addEventListener('click', (e) => {
      e.preventDefault();
      const navRefreshBtn = document.getElementById('nav-refresh-data');
      if (navRefreshBtn) {
        navRefreshBtn.click();
      } else {
        window.location.reload();
      }
    });
  };

  /**
   * Account Switcher & Saved Profiles Management.
   */
  const initAccountSwitcher = () => {
    const STORAGE_KEY = 'xc_player_v2_accounts';

    const escapeHtml = (str) => {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    };

    const getSavedAccounts = () => {
      try {
        const data = localStorage.getItem(STORAGE_KEY);
        const list = data ? JSON.parse(data) : [];
        return Array.isArray(list) ? list : [];
      } catch (e) {
        console.error('Failed to parse accounts:', e);
        return [];
      }
    };

    const saveAccount = (account) => {
      if (!account || !account.username) return;
      let accounts = getSavedAccounts();
      accounts = accounts.filter((a) => a.username.toLowerCase() !== account.username.toLowerCase());
      accounts.unshift(account);
      if (accounts.length > 20) accounts = accounts.slice(0, 20);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(accounts));
      renderAccountsList();
    };

    const removeAccount = (username) => {
      let accounts = getSavedAccounts();
      accounts = accounts.filter((a) => a.username.toLowerCase() !== username.toLowerCase());
      localStorage.setItem(STORAGE_KEY, JSON.stringify(accounts));
      renderAccountsList();
    };

    // Auto-sync current active account into localStorage
    if (window.CURRENT_SUBSCRIBER && window.CURRENT_SUBSCRIBER.username) {
      let accounts = getSavedAccounts();
      const existing = accounts.find((a) => a.username.toLowerCase() === window.CURRENT_SUBSCRIBER.username.toLowerCase());
      if (existing) {
        existing.id = window.CURRENT_SUBSCRIBER.id;
        existing.exp_date = window.CURRENT_SUBSCRIBER.exp_date;
        existing.exp_date_formatted = window.CURRENT_SUBSCRIBER.exp_date_formatted;
        if (window.CURRENT_SUBSCRIBER.password) {
          existing.password = window.CURRENT_SUBSCRIBER.password;
        }
        localStorage.setItem(STORAGE_KEY, JSON.stringify(accounts));
      } else {
        saveAccount(window.CURRENT_SUBSCRIBER);
      }
    }

    const renderAccountsList = () => {
      const container = document.getElementById('profile-saved-accounts-list');
      const badge = document.getElementById('profile-accounts-count-badge');
      const clearBtn = document.getElementById('btn-profile-clear-accounts');
      const accounts = getSavedAccounts();
      const count = accounts.length;

      if (badge) {
        badge.textContent = count;
      }

      if (clearBtn) {
        clearBtn.style.display = count > 1 ? 'inline-block' : 'none';
        clearBtn.onclick = () => {
          if (confirm('Are you sure you want to remove all other saved accounts from this device?')) {
            const currentU = window.CURRENT_SUBSCRIBER?.username?.toLowerCase();
            const kept = accounts.filter((a) => a.username.toLowerCase() === currentU);
            localStorage.setItem(STORAGE_KEY, JSON.stringify(kept));
            renderAccountsList();
          }
        };
      }

      if (!container) return;

      if (count === 0) {
        container.innerHTML = `
          <div class="col-12 text-center py-5">
            <div class="avatar avatar-lg bg-label-secondary rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center">
              <i class="icon-base bx bx-user-x fs-2"></i>
            </div>
            <p class="text-body-secondary mb-0">No other saved accounts on this device.</p>
          </div>
        `;
        return;
      }

      const currentUsername = (window.CURRENT_SUBSCRIBER?.username || '').toLowerCase();
      const nowSec = Math.floor(Date.now() / 1000);

      let html = '';
      accounts.forEach((acc) => {
        const isCurrent = acc.username.toLowerCase() === currentUsername;
        const initials = (acc.name || acc.username).substring(0, 2).toUpperCase();
        const isExpired = acc.exp_date > 0 && acc.exp_date <= nowSec;
        const isCode = acc.type === 'code' || !!acc.activation_code;

        let statusBadge = '';
        if (isCurrent) {
          statusBadge = '<span class="badge bg-label-success rounded-pill"><span class="badge-dot bg-success me-1"></span>Active Now</span>';
        } else if (isExpired) {
          statusBadge = '<span class="badge bg-label-danger rounded-pill">Expired</span>';
        } else if (isCode) {
          statusBadge = '<span class="badge bg-label-info rounded-pill"><i class="icon-base bx bx-key me-1"></i>Code</span>';
        } else {
          statusBadge = '<span class="badge bg-label-primary rounded-pill">Saved</span>';
        }

        html += `
          <div class="col-12 col-md-6 col-xl-4">
            <div class="saved-account-card h-100 ${isCurrent ? 'is-active-account' : ''}">
              <div class="d-flex align-items-start justify-content-between mb-3">
                <div class="d-flex align-items-center gap-3">
                  <div class="saved-account-avatar ${isCurrent ? 'bg-primary text-white' : 'bg-label-primary text-primary'}">
                    ${escapeHtml(initials)}
                  </div>
                  <div>
                    <h6 class="mb-0 fw-bold text-heading text-truncate" style="max-width: 150px;">
                      ${escapeHtml(acc.name || acc.username)}
                    </h6>
                    <small class="text-body-secondary">${isCode ? 'Smart Code' : 'Subscriber'}</small>
                  </div>
                </div>
                ${statusBadge}
              </div>

              <div class="mb-3 small text-body-secondary">
                <div class="d-flex align-items-center justify-content-between py-1 border-bottom">
                  <span>Expires:</span>
                  <span class="fw-medium text-heading">${acc.exp_date_formatted ? escapeHtml(acc.exp_date_formatted) : 'Unlimited'}</span>
                </div>
                <div class="d-flex align-items-center justify-content-between py-1">
                  <span>Username:</span>
                  <span class="fw-medium text-heading font-monospace text-truncate" style="max-width: 140px;">${escapeHtml(acc.username)}</span>
                </div>
              </div>

              <div class="d-flex align-items-center justify-content-between pt-2 border-top gap-2">
                ${
                  isCurrent
                    ? `<span class="badge bg-label-success py-2 w-100 text-center"><i class="icon-base bx bx-check me-1"></i> Currently Active</span>`
                    : `<button
                        type="button"
                        class="btn btn-sm btn-primary w-100 btn-switch-account"
                        data-username="${escapeHtml(acc.username)}"
                        data-password="${escapeHtml(acc.password)}"
                        data-code="${escapeHtml(acc.activation_code || '')}">
                        <i class="icon-base bx bx-sync me-1"></i> Switch Account
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-icon btn-label-secondary btn-remove-profile-account"
                        data-username="${escapeHtml(acc.username)}"
                        title="Remove from device">
                        <i class="icon-base bx bx-trash icon-xs"></i>
                      </button>`
                }
              </div>
            </div>
          </div>
        `;
      });

      container.innerHTML = html;

      // Attach Switch Button handlers
      container.querySelectorAll('.btn-switch-account').forEach((btn) => {
        btn.addEventListener('click', () => {
          const u = btn.getAttribute('data-username');
          const p = btn.getAttribute('data-password');
          const c = btn.getAttribute('data-code');
          performAccountSwitch(btn, u, p, c);
        });
      });

      // Attach Remove Button handlers
      container.querySelectorAll('.btn-remove-profile-account').forEach((btn) => {
        btn.addEventListener('click', () => {
          const u = btn.getAttribute('data-username');
          if (confirm(`Remove account "${u}" from this device?`)) {
            removeAccount(u);
          }
        });
      });
    };

    /**
     * Perform switch by logging into target account via AJAX and reloading.
     */
    const performAccountSwitch = async (btn, username, password, code) => {
      const origHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Switching...';

      const loginEndpoint = 'login';
      const payload = {};
      if (code && !password) {
        payload.action = 'activate_code';
        payload.activation_code = code;
      } else {
        payload.username = username;
        payload.password = password;
      }

      try {
        const res = await fetch(loginEndpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (res.ok && data.success) {
          if (data.account) {
            saveAccount(data.account);
          }
          btn.innerHTML = '<i class="icon-base bx bx-check me-1"></i> Success!';
          btn.classList.remove('btn-primary');
          btn.classList.add('btn-success');
          setTimeout(() => {
            window.location.reload();
          }, 400);
        } else {
          alert(data.message || 'Failed to switch account. Line credentials may have expired or changed.');
          btn.disabled = false;
          btn.innerHTML = origHtml;
        }
      } catch (err) {
        console.error('Account switch failed:', err);
        alert('Network error while switching account.');
        btn.disabled = false;
        btn.innerHTML = origHtml;
      }
    };

    // Header Button: jump to Switch Accounts tab
    const headerSwitchBtn = document.getElementById('btn-header-switch-account');
    if (headerSwitchBtn) {
      headerSwitchBtn.addEventListener('click', () => {
        const tabNav = document.getElementById('tab-nav-accounts');
        if (tabNav && window.bootstrap && window.bootstrap.Tab) {
          new bootstrap.Tab(tabNav).show();
          tabNav.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    }

    // Modal: Add Account Form Handlers
    const formModalAccount = document.getElementById('formModalAddAccount');
    const formModalCode = document.getElementById('formModalAddCode');
    const modalAlertContainer = document.getElementById('modal-account-alert');
    const modalCodeInput = document.getElementById('modal-input-code');
    const modalPasteBtn = document.getElementById('btn-modal-paste-code');

    const showModalAlert = (msg, type = 'danger') => {
      if (!modalAlertContainer) return;
      modalAlertContainer.innerHTML = `
        <div class="alert alert-${type} alert-dismissible mb-3 p-2 d-flex align-items-center gap-2" role="alert">
          <i class="icon-base bx ${type === 'success' ? 'bx-check-circle' : 'bx-error-circle'} fs-5"></i>
          <div class="small flex-grow-1">${escapeHtml(msg)}</div>
          <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
        </div>
      `;
    };

    if (modalCodeInput) {
      modalCodeInput.addEventListener('input', () => {
        modalCodeInput.value = modalCodeInput.value.toUpperCase().replace(/\s+/g, '');
      });
    }

    if (modalPasteBtn && modalCodeInput) {
      modalPasteBtn.addEventListener('click', async () => {
        try {
          const text = await navigator.clipboard.readText();
          if (text) {
            modalCodeInput.value = text.trim().toUpperCase().replace(/\s+/g, '');
            modalCodeInput.focus();
          }
        } catch (e) {}
      });
    }

    // Submit Modal Form: Username / Password
    if (formModalAccount) {
      formModalAccount.addEventListener('submit', async (e) => {
        e.preventDefault();
        const u = document.getElementById('modal-input-username')?.value.trim();
        const p = document.getElementById('modal-input-password')?.value;
        const btn = document.getElementById('btn-modal-submit-account');

        if (!u || !p) {
          showModalAlert('Please fill in both username and password.');
          return;
        }

        const origBtn = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Adding...';

        try {
          const res = await fetch('login', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            body: JSON.stringify({ username: u, password: p })
          });
          const data = await res.json();
          if (res.ok && data.success) {
            if (data.account) saveAccount(data.account);
            showModalAlert('Account added! Switching session...', 'success');
            setTimeout(() => { window.location.reload(); }, 500);
          } else {
            showModalAlert(data.message || 'Invalid username or password.');
            btn.disabled = false;
            btn.innerHTML = origBtn;
          }
        } catch (err) {
          showModalAlert('Network error adding account.');
          btn.disabled = false;
          btn.innerHTML = origBtn;
        }
      });
    }

    // Submit Modal Form: Activation Code
    if (formModalCode) {
      formModalCode.addEventListener('submit', async (e) => {
        e.preventDefault();
        const c = modalCodeInput?.value.trim().toUpperCase();
        const btn = document.getElementById('btn-modal-submit-code');

        if (!c) {
          showModalAlert('Please enter an activation code.');
          return;
        }

        const origBtn = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Activating...';

        try {
          const res = await fetch('login', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            body: JSON.stringify({ action: 'activate_code', activation_code: c })
          });
          const data = await res.json();
          if (res.ok && data.success) {
            if (data.account) saveAccount(data.account);
            showModalAlert('Code activated! Switching session...', 'success');
            setTimeout(() => { window.location.reload(); }, 500);
          } else {
            showModalAlert(data.message || 'Invalid or expired code.');
            btn.disabled = false;
            btn.innerHTML = origBtn;
          }
        } catch (err) {
          showModalAlert('Network error activating code.');
          btn.disabled = false;
          btn.innerHTML = origBtn;
        }
      });
    }

    renderAccountsList();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return { init };
})();
