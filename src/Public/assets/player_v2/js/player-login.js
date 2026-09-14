/**
 * Web Player V2 — Multi-Mode Login & Saved Accounts Controller
 *
 * Manages Tab 1 (Subscriber Credentials), Tab 2 (Native Activation Code),
 * and Tab 3 (Saved Accounts on this Device with 1-Click Quick Login).
 */

'use strict';

window.PlayerLogin = (function () {
  const STORAGE_KEY = 'xc_player_v2_accounts';

  /**
   * Safe HTML Escaping.
   */
  const escapeHtml = (str) => {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };

  /**
   * Retrieve saved accounts from localStorage.
   */
  const getSavedAccounts = () => {
    try {
      const data = localStorage.getItem(STORAGE_KEY);
      const list = data ? JSON.parse(data) : [];
      return Array.isArray(list) ? list : [];
    } catch (e) {
      console.error('Failed to parse saved accounts:', e);
      return [];
    }
  };

  /**
   * Save or update an account in localStorage.
   */
  const saveAccount = (account) => {
    if (!account) return;
    const identifier = (account.server || account.activation_code || account.username || '').trim();
    if (!identifier) return;

    let accounts = getSavedAccounts();

    // Deduplicate: remove any account matching same server+user, activation code, or username
    accounts = accounts.filter((a) => {
      if (account.server && a.server) {
        if (a.server.toLowerCase() === account.server.toLowerCase() && (a.username || '').toLowerCase() === (account.username || '').toLowerCase()) {
          return false;
        }
      }
      if (account.activation_code && a.activation_code) {
        if (a.activation_code.toUpperCase() === account.activation_code.toUpperCase()) {
          return false;
        }
      }
      if (!account.server && !account.activation_code && account.username && a.username && !a.server) {
        if (a.username.toLowerCase() === account.username.toLowerCase()) {
          return false;
        }
      }
      return true;
    });

    const isCode = account.type === 'code' || !!account.activation_code;
    const isExternal = account.type === 'external_xc' || !!account.server;

    accounts.unshift({
      id: account.id || null,
      server: account.server || '',
      username: account.username || '',
      password: account.password || '',
      name: isExternal
        ? (account.name || (account.server + ' (' + account.username + ')'))
        : isCode
          ? (account.activation_code || account.name || 'Activation Code')
          : (account.name || account.username),
      activation_code: account.activation_code || '',
      package_name: account.package_name || (isExternal ? 'Xtream Codes' : ''),
      exp_date: account.exp_date || 0,
      exp_date_formatted: account.exp_date_formatted || '',
      type: isExternal ? 'external_xc' : (isCode ? 'code' : 'credentials'),
      added_at: account.added_at || Math.floor(Date.now() / 1000)
    });

    // Cap saved accounts at 20
    if (accounts.length > 20) {
      accounts = accounts.slice(0, 20);
    }

    localStorage.setItem(STORAGE_KEY, JSON.stringify(accounts));
    renderSavedAccounts();
  };

  /**
   * Remove an account by identifier.
   */
  const removeAccount = (identifier) => {
    if (!identifier) return;
    let accounts = getSavedAccounts();
    accounts = accounts.filter((a) => {
      const matchServer = a.server && a.server.toLowerCase() === identifier.toLowerCase();
      const matchCode = a.activation_code && a.activation_code.toUpperCase() === identifier.toUpperCase();
      const matchUser = a.username && a.username.toLowerCase() === identifier.toLowerCase();
      const matchId = a.id && a.id === identifier;
      return !matchServer && !matchCode && !matchUser && !matchId;
    });
    localStorage.setItem(STORAGE_KEY, JSON.stringify(accounts));
    renderSavedAccounts();
  };

  /**
   * Clear all saved accounts.
   */
  const clearAllAccounts = () => {
    if (confirm('Are you sure you want to remove all saved accounts from this device?')) {
      localStorage.removeItem(STORAGE_KEY);
      renderSavedAccounts();
    }
  };

  /**
   * Display status alert message in the auth panel.
   */
  const showAlert = (message, type = 'danger') => {
    const container = document.getElementById('login-alert-container');
    if (!container) return;

    const icon = type === 'success' ? 'bx-check-circle' : 'bx-error-circle';
    container.innerHTML = `
      <div class="alert alert-${type} alert-dismissible d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="icon-base bx ${icon} fs-4 flex-shrink-0"></i>
        <div class="flex-grow-1">${escapeHtml(message)}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    `;
    container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  };

  const clearAlert = () => {
    const container = document.getElementById('login-alert-container');
    if (container) container.innerHTML = '';
  };

  /**
   * Render Tab 3: Saved Accounts list.
   */
  const renderSavedAccounts = () => {
    const container = document.getElementById('saved-accounts-list');
    const badge = document.getElementById('saved-count-badge');
    const clearBtn = document.getElementById('btn-clear-all-saved');

    const accounts = getSavedAccounts();
    const count = accounts.length;

    if (badge) {
      badge.textContent = count;
      badge.className = count > 0 ? 'badge bg-primary ms-1' : 'badge bg-label-secondary ms-1';
    }

    if (clearBtn) {
      clearBtn.style.display = count > 0 ? 'inline-block' : 'none';
    }

    if (!container) return;

    if (count === 0) {
      container.innerHTML = `
        <div class="text-center py-6 px-3">
          <div class="avatar avatar-xl bg-label-secondary rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center">
            <i class="icon-base bx bx-user-x fs-2 text-secondary"></i>
          </div>
          <h6 class="mb-1 fw-semibold">No Saved Accounts</h6>
          <p class="text-body-secondary small mb-4">
            Accounts and activation codes you sign into will appear here for fast 1-click access.
          </p>
          <div class="d-flex justify-content-center gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-primary" id="btn-switch-to-account-tab">
              <i class="icon-base bx bx-user me-1"></i> Subscriber
            </button>
            <button type="button" class="btn btn-sm btn-outline-warning" id="btn-switch-to-code-tab">
              <i class="icon-base bx bx-key me-1"></i> Active Code
            </button>
            <button type="button" class="btn btn-sm btn-outline-info" id="btn-switch-to-xtream-tab">
              <i class="icon-base bx bx-server me-1"></i> Xtream API
            </button>
          </div>
        </div>
      `;

      const switchBtn = document.getElementById('btn-switch-to-account-tab');
      if (switchBtn) {
        switchBtn.addEventListener('click', () => {
          const tabBtn = document.querySelector('[data-bs-target="#tab-account"]');
          if (tabBtn && window.bootstrap && window.bootstrap.Tab) {
            new bootstrap.Tab(tabBtn).show();
          }
        });
      }

      const switchCodeBtn = document.getElementById('btn-switch-to-code-tab');
      if (switchCodeBtn) {
        switchCodeBtn.addEventListener('click', () => {
          const tabBtn = document.querySelector('[data-bs-target="#tab-code"]');
          if (tabBtn && window.bootstrap && window.bootstrap.Tab) {
            new bootstrap.Tab(tabBtn).show();
          }
        });
      }

      const switchXtreamBtn = document.getElementById('btn-switch-to-xtream-tab');
      if (switchXtreamBtn) {
        switchXtreamBtn.addEventListener('click', () => {
          const tabBtn = document.querySelector('[data-bs-target="#tab-xtream"]');
          if (tabBtn && window.bootstrap && window.bootstrap.Tab) {
            new bootstrap.Tab(tabBtn).show();
          }
        });
      }
      return;
    }

    const nowSec = Math.floor(Date.now() / 1000);

    let html = '<div class="d-flex flex-column gap-3">';
    accounts.forEach((acc) => {
      const isCode = acc.type === 'code' || !!acc.activation_code;
      const isExternal = acc.type === 'external_xc' || !!acc.server;
      const isExpired = acc.exp_date > 0 && acc.exp_date <= nowSec;

      let statusBadge = '';
      if (isExpired) {
        statusBadge = '<span class="badge bg-label-danger rounded-pill">Expired</span>';
      } else if (isExternal) {
        statusBadge = '<span class="badge bg-label-info rounded-pill"><i class="icon-base bx bx-server me-1"></i>Xtream API</span>';
      } else if (isCode) {
        statusBadge = '<span class="badge bg-label-warning rounded-pill"><i class="icon-base bx bx-key me-1"></i>Active Code</span>';
      } else {
        statusBadge = '<span class="badge bg-label-success rounded-pill">Subscriber</span>';
      }

      const avatarHtml = isExternal
        ? `<div class="saved-account-avatar bg-label-info text-info"><i class="icon-base bx bx-server fs-4"></i></div>`
        : isCode
          ? `<div class="saved-account-avatar bg-label-warning text-warning"><i class="icon-base bx bx-key fs-4"></i></div>`
          : `<div class="saved-account-avatar bg-label-primary text-primary">${escapeHtml((acc.name || acc.username).substring(0, 2).toUpperCase())}</div>`;

      const titleText = isCode
        ? (acc.activation_code || acc.name || acc.username)
        : isExternal
          ? (acc.name || (acc.server + ' (' + acc.username + ')'))
          : (acc.name || acc.username);

      const subtitleText = isExternal
        ? `${escapeHtml(acc.server || '')} &bull; ${acc.exp_date_formatted ? escapeHtml(acc.exp_date_formatted) : 'Active'}`
        : isCode
          ? `${acc.package_name ? `${escapeHtml(acc.package_name)} &bull; ` : ''}${acc.exp_date_formatted ? escapeHtml(acc.exp_date_formatted) : 'Active'}`
          : `${acc.exp_date_formatted ? escapeHtml(acc.exp_date_formatted) : 'Active'}`;

      html += `
        <div class="saved-account-card d-flex align-items-center justify-content-between p-3 rounded">
          <div class="d-flex align-items-center gap-3 overflow-hidden me-2">
            ${avatarHtml}
            <div class="text-truncate">
              <h6 class="mb-0 fw-semibold text-truncate ${isCode ? 'font-monospace' : ''}">${escapeHtml(titleText)}</h6>
              <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                ${statusBadge}
                <small class="text-body-secondary text-truncate">${subtitleText}</small>
              </div>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2 flex-shrink-0">
            <button
              type="button"
              class="btn btn-sm btn-primary btn-quick-login shadow-sm"
              data-username="${escapeHtml(acc.username || '')}"
              data-password="${escapeHtml(acc.password || '')}"
              data-code="${escapeHtml(acc.activation_code || '')}"
              data-server="${escapeHtml(acc.server || '')}"
              data-type="${escapeHtml(acc.type || 'credentials')}"
              title="Quick Sign In">
              <i class="icon-base bx bx-log-in me-1"></i> Sign In
            </button>
            <button
              type="button"
              class="btn btn-sm btn-icon btn-label-secondary btn-remove-saved"
              data-identifier="${escapeHtml(acc.server || acc.activation_code || acc.username)}"
              title="Remove from device">
              <i class="icon-base bx bx-trash icon-xs"></i>
            </button>
          </div>
        </div>
      `;
    });
    html += '</div>';

    container.innerHTML = html;

    // Attach Quick Login handlers
    container.querySelectorAll('.btn-quick-login').forEach((btn) => {
      btn.addEventListener('click', () => {
        const u = btn.getAttribute('data-username');
        const p = btn.getAttribute('data-password');
        const c = btn.getAttribute('data-code');
        const s = btn.getAttribute('data-server');
        const t = btn.getAttribute('data-type');
        performQuickLogin(btn, u, p, c, s, t);
      });
    });

    // Attach Remove handlers
    container.querySelectorAll('.btn-remove-saved').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-identifier');
        if (confirm(`Remove "${id}" from this device?`)) {
          removeAccount(id);
        }
      });
    });
  };

  /**
   * Execute 1-click Quick Login from Tab 4.
   */
  const performQuickLogin = async (btn, username, password, code, server, type) => {
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
    clearAlert();

    const formAction = document.getElementById('formAuthentication')?.getAttribute('action') || 'login';

    const payload = {};
    if (type === 'external_xc' || (server && server.trim())) {
      payload.action = 'login_external_xc';
      payload.server = server.trim();
      payload.username = username;
      payload.password = password;
    } else if (type === 'code' || (code && code.trim())) {
      payload.action = 'activate_code';
      payload.activation_code = code.trim();
    } else {
      payload.username = username;
      payload.password = password;
    }

    try {
      const res = await fetch(formAction, {
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
        if (window.clearUserClientData) {
          window.clearUserClientData();
        }
        if (data.account) {
          saveAccount(data.account);
        }
        showAlert(data.message || 'Login successful! Redirecting...', 'success');
        setTimeout(() => {
          window.location.href = data.redirect || 'index';
        }, 500);
      } else {
        showAlert(data.message || 'Login failed. Please verify credentials, code, or server host.');
        btn.disabled = false;
        btn.innerHTML = origHtml;
      }
    } catch (err) {
      console.error('Quick login error:', err);
      showAlert('Network error while connecting to authentication service.');
      btn.disabled = false;
      btn.innerHTML = origHtml;
    }
  };

  /**
   * Initialize Tab 1: Username & Password submission.
   */
  const initAccountForm = () => {
    const form = document.getElementById('formAuthentication');
    const submitBtn = document.getElementById('btn-submit-account');

    if (!form) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const usernameInput = document.getElementById('username');
      const passwordInput = document.getElementById('password');
      const rememberCheckbox = document.getElementById('remember-me');

      const username = usernameInput ? usernameInput.value.trim() : '';
      const password = passwordInput ? passwordInput.value : '';

      if (!username || !password) {
        showAlert('Please enter both username and password.');
        return;
      }

      const origBtnHtml = submitBtn ? submitBtn.innerHTML : 'Sign In';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Signing In...';
      }

      const formAction = form.getAttribute('action') || 'login';

      try {
        const res = await fetch(formAction, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            username: username,
            password: password
          })
        });

        const data = await res.json();

        if (res.ok && data.success) {
          if (window.clearUserClientData) {
            window.clearUserClientData();
          }
          if (!rememberCheckbox || rememberCheckbox.checked) {
            if (data.account) {
              saveAccount(data.account);
            }
          }
          showAlert(data.message || 'Signed in successfully! Loading player...', 'success');
          setTimeout(() => {
            window.location.href = data.redirect || 'index';
          }, 500);
        } else {
          showAlert(data.message || 'Invalid username or password.');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
          }
        }
      } catch (err) {
        console.error('Login request failed:', err);
        showAlert('Network or server error during sign in.');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = origBtnHtml;
        }
      }
    });
  };

  /**
   * Initialize Tab 2: Activation Code submission.
   */
  const initCodeForm = () => {
    const form = document.getElementById('formActivationCode');
    const submitBtn = document.getElementById('btn-submit-code');
    const codeInput = document.getElementById('activation_code');
    const pasteBtn = document.getElementById('btn-paste-code');

    // Auto-uppercase and format code input
    if (codeInput) {
      codeInput.addEventListener('input', () => {
        codeInput.value = codeInput.value.toUpperCase().replace(/\s+/g, '');
      });
    }

    // Paste from clipboard handler
    if (pasteBtn && codeInput) {
      pasteBtn.addEventListener('click', async () => {
        try {
          const text = await navigator.clipboard.readText();
          if (text) {
            codeInput.value = text.trim().toUpperCase().replace(/\s+/g, '');
            codeInput.focus();
          }
        } catch (err) {
          console.warn('Clipboard read permission denied:', err);
        }
      });
    }

    if (!form) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const code = codeInput ? codeInput.value.trim().toUpperCase() : '';
      if (!code) {
        showAlert('Please enter an activation code.');
        return;
      }

      const origBtnHtml = submitBtn ? submitBtn.innerHTML : 'Activate & Sign In';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Activating Code...';
      }

      const rememberCheckbox = document.getElementById('remember-code');
      const shouldRemember = !rememberCheckbox || rememberCheckbox.checked;

      const formAction = document.getElementById('formAuthentication')?.getAttribute('action') || 'login';

      try {
        const res = await fetch(formAction, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'activate_code',
            activation_code: code
          })
        });

        const data = await res.json();

        if (res.ok && data.success) {
          if (window.clearUserClientData) {
            window.clearUserClientData();
          }
          if (shouldRemember && data.account) {
            saveAccount(data.account);
          }
          showAlert(data.message || 'Activation successful! Enjoy streaming...', 'success');
          setTimeout(() => {
            window.location.href = data.redirect || 'index';
          }, 600);
        } else {
          showAlert(data.message || 'Invalid or expired activation code.');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
          }
        }
      } catch (err) {
        console.error('Activation request failed:', err);
        showAlert('Network error while validating activation code.');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = origBtnHtml;
        }
      }
    });
  };

  /**
   * Parse any Xtream / M3U / Playlist URL into server, username, and password components.
   */
  const parsePlaylistUrl = (rawUrl) => {
    if (!rawUrl) return null;
    let urlStr = String(rawUrl).trim();
    if (!urlStr) return null;

    // Normalize: prepend http:// if scheme is missing
    if (!/^https?:\/\//i.test(urlStr)) {
      urlStr = 'http://' + urlStr;
    }

    try {
      const urlObj = new URL(urlStr);
      const server = `${urlObj.protocol}//${urlObj.host}`;

      let username = '';
      let password = '';

      // 1. Basic Auth credentials in URL (e.g. http://user:pass@host:port)
      if (urlObj.username) {
        username = decodeURIComponent(urlObj.username);
      }
      if (urlObj.password) {
        password = decodeURIComponent(urlObj.password);
      }

      // 2. Query Parameters (e.g. ?username=...&password=... or ?user=...&pass=...)
      if (!username && urlObj.searchParams) {
        username = urlObj.searchParams.get('username') || urlObj.searchParams.get('user') || urlObj.searchParams.get('u') || '';
      }
      if (!password && urlObj.searchParams) {
        password = urlObj.searchParams.get('password') || urlObj.searchParams.get('pass') || urlObj.searchParams.get('p') || '';
      }

      // 3. Stream Path Credentials (e.g. /live/username/password/123.ts or /series/... or /movie/...)
      if ((!username || !password) && urlObj.pathname) {
        const pathMatch = urlObj.pathname.match(/\/(?:live|movie|series)\/([^\/]+)\/([^\/]+)\//i);
        if (pathMatch) {
          if (!username) username = decodeURIComponent(pathMatch[1]);
          if (!password) password = decodeURIComponent(pathMatch[2]);
        }
      }

      username = (username || '').trim();
      password = (password || '').trim();

      if (!username || !password) {
        return null;
      }

      return {
        server: server,
        username: username,
        password: password,
        rawUrl: urlStr
      };
    } catch (e) {
      // Fallback regex if standard URL parser fails
      const hostMatch = urlStr.match(/^(https?:\/\/[^\/\?]+)/i);
      if (!hostMatch) return null;
      const server = hostMatch[1];
      const userMatch = urlStr.match(/[?&](?:username|user|u)=([^&#]+)/i);
      const passMatch = urlStr.match(/[?&](?:password|pass|p)=([^&#]+)/i);
      if (userMatch && passMatch) {
        return {
          server: server,
          username: decodeURIComponent(userMatch[1]),
          password: decodeURIComponent(passMatch[1]),
          rawUrl: urlStr
        };
      }
      return null;
    }
  };

  /**
   * Initialize Tab 3: External Xtream Codes Server submission.
   */
  const initXtreamForm = () => {
    const form = document.getElementById('formXtreamServer');
    const submitBtn = document.getElementById('btn-submit-xtream');
    const serverInput = document.getElementById('xtream-server');
    const usernameInput = document.getElementById('xtream-username');
    const passwordInput = document.getElementById('xtream-password');
    const rememberCheckbox = document.getElementById('remember-xtream');

    if (!form) return;

    // Auto-detect and parse playlist URL pasted into server input
    if (serverInput) {
      const detectPlaylistInServer = () => {
        const val = serverInput.value.trim();
        const parsed = parsePlaylistUrl(val);
        if (parsed) {
          serverInput.value = parsed.server;
          if (usernameInput) usernameInput.value = parsed.username;
          if (passwordInput) passwordInput.value = parsed.password;
          showAlert('Extracted Host, Username & Password from playlist URL!', 'info');
        }
      };
      serverInput.addEventListener('change', detectPlaylistInServer);
      serverInput.addEventListener('paste', () => setTimeout(detectPlaylistInServer, 50));
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const server = serverInput ? serverInput.value.trim() : '';
      const username = usernameInput ? usernameInput.value.trim() : '';
      const password = passwordInput ? passwordInput.value : '';

      if (!server || !username || !password) {
        showAlert('Please fill in Server Host & Port, Username, and Password.');
        return;
      }

      const origBtnHtml = submitBtn ? submitBtn.innerHTML : 'Connect & Launch Player';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Connecting to Server...';
      }

      const shouldRemember = !rememberCheckbox || rememberCheckbox.checked;
      const formAction = document.getElementById('formAuthentication')?.getAttribute('action') || 'login';

      try {
        const res = await fetch(formAction, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'login_external_xc',
            server: server,
            username: username,
            password: password
          })
        });

        const data = await res.json();

        if (res.ok && data.success) {
          if (window.clearUserClientData) {
            window.clearUserClientData();
          }
          if (shouldRemember && data.account) {
            saveAccount(data.account);
          }
          showAlert(data.message || 'Connected to Xtream server! Loading player...', 'success');
          setTimeout(() => {
            window.location.href = data.redirect || 'index';
          }, 500);
        } else {
          showAlert(data.message || 'Failed to connect to Xtream server. Please check credentials or host.');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
          }
        }
      } catch (err) {
        console.error('External Xtream login failed:', err);
        showAlert('Network or timeout error while contacting external server.');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = origBtnHtml;
        }
      }
    });
  };

  /**
   * Initialize Tab 4: M3U / Playlist URL Extraction & Login.
   */
  const initPlaylistForm = () => {
    const form = document.getElementById('formPlaylistUrl');
    const submitBtn = document.getElementById('btn-submit-playlist');
    const urlInput = document.getElementById('playlist-url');
    const pasteBtn = document.getElementById('btn-paste-playlist');
    const parsedBox = document.getElementById('playlist-parsed-box');
    const parsedServer = document.getElementById('parsed-server');
    const parsedUsername = document.getElementById('parsed-username');
    const parsedPassword = document.getElementById('parsed-password');
    const rememberCheckbox = document.getElementById('remember-playlist');

    if (!form) return;

    const handleUrlChange = () => {
      const val = urlInput ? urlInput.value.trim() : '';
      const parsed = parsePlaylistUrl(val);

      if (parsed && parsedBox) {
        parsedBox.style.display = 'block';
        if (parsedServer) parsedServer.textContent = parsed.server;
        if (parsedUsername) parsedUsername.textContent = parsed.username;
        if (parsedPassword) parsedPassword.textContent = '••••••••';
      } else if (parsedBox) {
        parsedBox.style.display = 'none';
      }
      return parsed;
    };

    if (urlInput) {
      urlInput.addEventListener('input', handleUrlChange);
      urlInput.addEventListener('change', handleUrlChange);
    }

    if (pasteBtn && urlInput) {
      pasteBtn.addEventListener('click', async () => {
        try {
          const text = await navigator.clipboard.readText();
          if (text) {
            urlInput.value = text.trim();
            handleUrlChange();
            urlInput.focus();
          }
        } catch (err) {
          console.warn('Clipboard read permission denied:', err);
        }
      });
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const rawUrl = urlInput ? urlInput.value.trim() : '';
      if (!rawUrl) {
        showAlert('Please paste or enter your M3U or Playlist URL.');
        return;
      }

      const parsed = parsePlaylistUrl(rawUrl);
      if (!parsed) {
        showAlert('Could not extract Server, Username, or Password from this URL. Please verify your playlist link.');
        return;
      }

      const origBtnHtml = submitBtn ? submitBtn.innerHTML : 'Parse & Launch Player';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Parsing & Connecting...';
      }

      const shouldRemember = !rememberCheckbox || rememberCheckbox.checked;
      const formAction = document.getElementById('formAuthentication')?.getAttribute('action') || 'login';

      try {
        const res = await fetch(formAction, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            action: 'login_playlist_url',
            playlist_url: rawUrl,
            server: parsed.server,
            username: parsed.username,
            password: parsed.password
          })
        });

        const data = await res.json();

        if (res.ok && data.success) {
          if (window.clearUserClientData) {
            window.clearUserClientData();
          }
          if (shouldRemember && data.account) {
            saveAccount(data.account);
          }
          showAlert(data.message || 'Connected to playlist server! Loading player...', 'success');
          setTimeout(() => {
            window.location.href = data.redirect || 'index';
          }, 500);
        } else {
          showAlert(data.message || 'Failed to authenticate with playlist credentials.');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
          }
        }
      } catch (err) {
        console.error('Playlist login failed:', err);
        showAlert('Network or timeout error while contacting playlist server.');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = origBtnHtml;
        }
      }
    });
  };

  /**
   * Password show/hide toggle.
   */
  const initPasswordToggle = () => {
    document.querySelectorAll('.form-password-toggle .input-group-text').forEach((toggle) => {
      toggle.addEventListener('click', () => {
        const input = toggle.closest('.input-group')?.querySelector('input');
        const icon = toggle.querySelector('i');
        if (!input) return;

        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';

        if (icon) {
          if (isPassword) {
            icon.classList.remove('bx-hide');
            icon.classList.add('bx-show');
          } else {
            icon.classList.remove('bx-show');
            icon.classList.add('bx-hide');
          }
        }
      });
    });
  };

  /**
   * Auto-select Tab: if saved accounts exist and user hasn't explicitly set another tab,
   * show saved accounts tab so the user can easily log back in.
   */
  const checkInitialTab = () => {
    const accounts = getSavedAccounts();
    const badge = document.getElementById('saved-count-badge');
    if (badge) {
      badge.textContent = accounts.length;
      badge.className = accounts.length > 0 ? 'badge bg-primary ms-1' : 'badge bg-label-secondary ms-1';
    }

    // Clear all button handler
    const clearBtn = document.getElementById('btn-clear-all-saved');
    if (clearBtn) {
      clearBtn.addEventListener('click', clearAllAccounts);
    }

    // Auto-switch to Saved tab if accounts are stored on this device
    if (accounts.length > 0) {
      const savedTabBtn = document.querySelector('[data-bs-target="#tab-saved"]');
      if (savedTabBtn && window.bootstrap && window.bootstrap.Tab) {
        new bootstrap.Tab(savedTabBtn).show();
      }
    }
  };

  const init = () => {
    initPasswordToggle();
    initAccountForm();
    initCodeForm();
    initXtreamForm();
    initPlaylistForm();
    renderSavedAccounts();
    checkInitialTab();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return {
    init,
    saveAccount,
    getSavedAccounts,
    removeAccount,
    clearAllAccounts,
    renderSavedAccounts
  };
})();
