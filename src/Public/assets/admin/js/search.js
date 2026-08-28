/*
 * Client renderer for the structured global-search contract (?action=search).
 * The server returns structured `item.data` (see docs/adr/search-json-contract.md);
 * this turns each item into a result card and wires the self-describing actions.
 * Consumed by the Select2 quick-search box in common.js (templateResult).
 */

/* ── action dispatch ─────────────────────────────────────────────── */

// Each action is self-describing; map its `kind` to the existing global helpers.
function searchActionOnClick(a) {
  switch (a.kind) {
    case 'navigate':    return `navigate('${a.target}')`;
    case 'api':         return `searchAPI('${a.entity}', ${a.id}, '${a.sub}')`;
    case 'fingerprint': return `modalFingerprint(${a.id}, '${a.context}')`;
    case 'credits':     return `addCredits(${a.id})`;
    default:            return '';
  }
}

function renderActions(actions) {
  if (!actions || !actions.length) return '';
  const btns = actions.map(a => {
    const disabled = a.enabled === false ? ' disabled' : '';
    const title = a.title ? ` title="${a.title}"` : '';
    const onclick = disabled ? '' : ` onclick="${searchActionOnClick(a)}"`;
    return `<button type="button"${title} class="btn btn-xs waves-effect waves-light no-border tooltip"${disabled}${onclick}><i class="mdi ${a.icon}"></i></button>`;
  }).join('');
  return `<li class="dropdown notification-list"><a class="mr-0 waves-effect pd-left pd-right"><span class="pro-user-name text-white ml-1"><i class="fe-sliders text-white"></i> &nbsp; <div class="btn-group bg-animate-info">${btns}</div></span></a></li>`;
}

function menuItem(icon, inner) {
  return `<li class="dropdown notification-list"><a class="mr-0 waves-effect pd-left pd-right"><span class="pro-user-name text-white ml-1">${icon ? `<i class="${icon} text-white"></i> &nbsp; ` : ''}${inner}</span></a></li>`;
}

function badge(variant, text) {
  return `<button type="button" class="btn bg-animate-${variant} btn-xs waves-effect waves-light no-border">${text}</button>`;
}

/* ── status / rating helpers ─────────────────────────────────────── */

function renderStatus(s) {
  if (!s) return '';
  if (s.kind === 'uptime')   return badge('info', s.text);
  if (s.kind === 'progress') return badge('primary', s.percent + '% DONE');
  return badge(s.variant, s.label); // kind: 'status'
}

function renderStars(rating) {
  if (!rating) return '';
  let out = '';
  for (let i = 0; i < rating.stars_full; i++) out += "<i class='mdi mdi-star'></i>";
  if (rating.half) out += "<i class='mdi mdi-star-half'></i>";
  for (let i = 0; i < rating.empty; i++) out += "<i class='mdi mdi-star-outline'></i>";
  const year = rating.year ? `<strong>${rating.year}</strong> &nbsp;` : '';
  return `<br><span style='font-size:11px;'>${year}${out}</span>`;
}

function lastActive(la) {
  if (!la) return "Last Active<br/><small class='text-lighter'>Never</small>";
  if (la.online) {
    return `<a class='text-white' href='javascript:void(0);' onClick="navigate('stream_view?id=${la.stream_id}')">${la.stream_name}</a>`
         + `<br/><small class='text-lighter'>Online: ${la.online_for}</small>`;
  }
  return `Last Active<br/><small class='text-lighter'>${la.date || 'Never'}</small>`;
}

/* ── card templates ──────────────────────────────────────────────── */

function card(image, body, menu) {
  return `<div class="card-search text-white">${image}<div class="card-body"><div class="media align-items-center">${body}</div></div>`
       + `<div class="card-body action-block"><div class="media align-items-center align-center">`
       + `<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">${menu}</ul></div></div></div>`;
}

function renderStreamCard(d) {
  const title = d.title_link
    ? `<span style='cursor:pointer;' onClick="navigate('${d.title_link}')">${d.title}</span>${d.rating ? renderStars(d.rating) : ''}`
    : `${d.title}${d.rating ? renderStars(d.rating) : ''}`;
  const info = `<h3 class="text-white my-1 text-truncate">${title}</h3>`
             + `<p class="text-white mb-1 text-truncate"><small>${d.category}<br/>${d.server}</small></p>`;

  const image = d.layout === 'vod'
    ? `<div class="search-fade"><div class="search-image" style="background: url('resize?maxw=${d.image.size}&maxh=${d.image.size}&url=${encodeURIComponent(d.image.url)}');"></div></div>`
    : '';
  const body = d.layout === 'vod'
    ? `<div class="col-12"><div>${info}</div></div>`
    : `<div class="col-9"><div>${info}</div></div><div class="col-3"><div class="float-right text-center search-icon"><img src="resize?maxw=96&maxh=96&url=${encodeURIComponent(d.image.url)}" /></div></div>`;

  const menu = menuItem('', badge(d.badge.variant, d.badge.text.toUpperCase()))
    + menuItem('fe-zap', `<button onClick="navigate('${d.connections_link}')" type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">${(d.connections || 0).toLocaleString()}</button>`)
    + menuItem('fe-clock', renderStatus(d.status))
    + renderActions(d.actions);

  return card(image, body, menu);
}

function renderSeriesCard(d) {
  const image = `<div class="search-fade"><div class="search-image" style="background: url('resize?maxw=512&maxh=512&url=${encodeURIComponent(d.image.url)}');"></div></div>`;
  const body = `<div class="col-12"><div><h3 class="text-white my-1 text-truncate">${d.title}${renderStars(d.rating)}</h3>`
             + `<p class="text-white mb-1 text-truncate"><small>${d.category}</small></p></div></div>`;
  const menu = menuItem('', badge(d.badge.variant, d.badge.text))
    + menuItem('', `S &nbsp; ${badge('info', d.seasons.toLocaleString())}`)
    + menuItem('', `E &nbsp; ${badge('info', d.episodes.toLocaleString())}`)
    + renderActions(d.actions);
  return card(image, body, menu);
}

function renderUserCard(d) {
  const col = d.is_reseller ? 'col-9' : 'col-12';
  const owner = d.owner ? `<span class="text-white">owner:</span> ${d.owner}` : '';
  const group = d.group ? `<span class="text-white">${d.group}</span><br/>` : '';
  let body = `<div class="${col}"><div><h3 class="text-white my-1 text-truncate">${d.username}</h3>`
           + `<p class="text-lighter mb-1 text-truncate"><small>${group}${owner}</small></p></div></div>`;
  if (d.is_reseller) {
    body += `<div class="col-3"><div class="float-right text-center font-24 search-icon-xl"><i class="mdi mdi-coin text-white"></i><br/>${(d.credits || 0).toLocaleString()}</div></div>`;
  }
  const menu = menuItem('', badge(d.badge.variant, d.badge.text))
    + menuItem('fe-user-check', badge(d.status.variant, d.status.label))
    + menuItem('fe-users', badge('info', d.users_count.toLocaleString()))
    + menuItem('fe-tv', badge('info', d.lines_count.toLocaleString()))
    + renderActions(d.actions);
  return card('', body, menu);
}

function renderLineCard(d) {
  const expires = d.expires ? `<span class="text-white">expires:</span> ${d.expires}<br/>` : '';
  const owner = d.owner ? `<span class="text-white">owner:</span> ${d.owner}` : '';
  const flag = d.device_type
    ? (d.flags.trial ? "<i class='mdi mdi-gavel'></i> " : '')
    : (d.flags.restreamer ? "<i title='Restreamer' class='mdi mdi-swap-horizontal tooltip'></i> "
       : (d.flags.trial ? "<i title='Trial' class='mdi mdi-gavel tooltip'></i> " : ''));
  const label = d.device_type ? d.device_type.toUpperCase() : 'LINE';
  const body = `<div class="col-9"><div><h3 class="text-white my-1 text-truncate">${d.title}</h3>`
             + `<p class="text-lighter mb-1 text-truncate"><small>${expires}${owner}</small></p></div></div>`
             + `<div class="col-3"><div class="float-right text-center search-icon-xl mt-1">${lastActive(d.last_active)}</div></div>`;
  const menu = menuItem('', badge('pink', `${flag}${label}`))
    + menuItem('fe-user-check', badge(d.status.variant, d.status.label))
    + menuItem('fe-zap', badge('info', (d.connections || 0).toLocaleString()))
    + renderActions(d.actions);
  return card('', body, menu);
}

/* ── entry point ─────────────────────────────────────────────────── */

function renderSearchItem(item) {
  if (item.entity === 'no_results') {
    return "<div class='card-search text-white'><div class='card-body text-center'>No Results</div></div>";
  }
  const d = item.data;
  switch (item.entity) {
    case 'stream': case 'movie': case 'channel': case 'radio': case 'episode':
      return renderStreamCard(d);
    case 'series': return renderSeriesCard(d);
    case 'user':   return renderUserCard(d);
    case 'line':   return renderLineCard(d);
    case 'mag': case 'enigma':
      return d ? renderLineCard(d) : '';
    default:       return '';
  }
}
