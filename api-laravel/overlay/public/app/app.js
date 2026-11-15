'use strict';

/* ClassHub demo UI: plain JavaScript single page app that talks to the Laravel API. */

const API = '/api';

// ---------- small helpers ----------

const $ = (selector) => document.querySelector(selector);

const esc = (value) =>
  String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const safeUrl = (url) => (/^https?:\/\//i.test(url || '') ? url : null);

const idr = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
const money = (n) => idr.format(Number(n) || 0);

const BTN = 'inline-flex items-center justify-center rounded-xl px-5 py-2.5 text-sm font-semibold transition focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60';
const BTN_PRIMARY = `${BTN} bg-indigo-600 text-white shadow-sm hover:bg-indigo-500 focus:ring-indigo-300`;
const BTN_GHOST = `${BTN} border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus:ring-slate-200`;
const INPUT = 'w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200';
const CARD = 'rounded-2xl border border-slate-200 bg-white p-6 shadow-sm';

const GRADIENTS = [
  'from-indigo-500 to-violet-500',
  'from-emerald-500 to-teal-500',
  'from-amber-500 to-orange-500',
  'from-sky-500 to-blue-600',
];
const gradientFor = (id) => GRADIENTS[(Number(id) - 1 + GRADIENTS.length) % GRADIENTS.length];

// ---------- session ----------

const session = { token: null, user: null, after: null };

try {
  session.token = localStorage.getItem('classhub_token');
} catch (e) {
  session.token = null;
}

function saveSession(token, user) {
  session.token = token;
  session.user = user;
  try {
    localStorage.setItem('classhub_token', token);
  } catch (e) { /* storage unavailable: the session lasts until reload */ }
  renderNav();
}

function clearSession() {
  session.token = null;
  session.user = null;
  try {
    localStorage.removeItem('classhub_token');
  } catch (e) { /* ignore */ }
  renderNav();
}

// ---------- API client ----------

class ApiError extends Error {
  constructor(status, message, data) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

function firstError(data) {
  if (!data) return '';
  if (data.errors) {
    const key = Object.keys(data.errors)[0];
    if (key && data.errors[key] && data.errors[key][0]) return data.errors[key][0];
  }
  return data.message || '';
}

async function api(path, { method = 'GET', body, auth = true } = {}) {
  const headers = { Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (auth && session.token) headers.Authorization = `Bearer ${session.token}`;

  let res;
  try {
    res = await fetch(API + path, { method, headers, body: body ? JSON.stringify(body) : undefined });
  } catch (e) {
    throw new ApiError(0, 'Cannot reach the server. Is docker compose running?');
  }

  let data = null;
  try {
    data = await res.json();
  } catch (e) { /* empty or non-JSON body */ }

  if (!res.ok) {
    if (res.status === 401 && auth) clearSession();
    throw new ApiError(res.status, firstError(data) || `Request failed (${res.status})`, data);
  }
  return data;
}

// ---------- UI primitives ----------

function toast(message, kind = 'info') {
  const colors = {
    info: 'bg-slate-900 text-white',
    success: 'bg-emerald-600 text-white',
    error: 'bg-rose-600 text-white',
  };
  const el = document.createElement('div');
  el.className = `max-w-sm rounded-xl px-4 py-3 text-sm shadow-lg ${colors[kind] || colors.info}`;
  el.textContent = message;
  $('#toast').appendChild(el);
  setTimeout(() => el.remove(), 4500);
}

const setView = (html) => {
  $('#view').innerHTML = html;
  window.scrollTo({ top: 0 });
};

const spinner = () =>
  '<div class="flex justify-center py-20"><div class="h-9 w-9 animate-spin rounded-full border-4 border-indigo-200 border-t-indigo-600"></div></div>';

const errorBox = (message) =>
  `<div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">${esc(message)}</div>`;

const warnBox = (message) =>
  `<div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">${esc(message)}</div>`;

function badge(status) {
  const styles = {
    paid: 'bg-emerald-100 text-emerald-700',
    pending: 'bg-amber-100 text-amber-700',
    failed: 'bg-rose-100 text-rose-700',
  };
  return `<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ${styles[status] || 'bg-slate-100 text-slate-600'}">${esc(status)}</span>`;
}

function busy(button, label) {
  const original = button.textContent;
  button.disabled = true;
  button.textContent = label;
  return () => {
    button.disabled = false;
    button.textContent = original;
  };
}

// ---------- navigation ----------

function renderNav() {
  const link = (href, label) =>
    `<a href="${href}" class="rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900">${label}</a>`;

  if (session.token) {
    $('#nav').innerHTML = `
      ${link('#/courses', 'Courses')}
      ${link('#/orders', 'My orders')}
      <span class="mx-2 hidden text-slate-500 sm:inline">${esc(session.user ? session.user.name : '')}</span>
      <button id="logout" class="rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900">Log out</button>`;
    $('#logout').addEventListener('click', logout);
  } else {
    $('#nav').innerHTML = `
      ${link('#/courses', 'Courses')}
      ${link('#/login', 'Log in')}
      <a href="#/register" class="${BTN_PRIMARY} !px-4 !py-2">Sign up</a>`;
  }
}

async function logout() {
  try {
    await api('/auth/logout', { method: 'POST' });
  } catch (e) { /* the token may already be invalid */ }
  clearSession();
  toast('You are logged out.');
  go('#/courses');
}

function go(hash) {
  if (location.hash === hash) route();
  else location.hash = hash;
}

function requireAuth(next) {
  if (session.token) return true;
  session.after = next;
  toast('Please log in first.');
  go('#/login');
  return false;
}

// ---------- router ----------

const routes = [
  [/^\/courses$/, viewCourses],
  [/^\/courses\/(\d+)$/, viewCourse],
  [/^\/checkout\/(\d+)$/, viewCheckout],
  [/^\/learn\/(\d+)$/, viewLearn],
  [/^\/orders$/, viewOrders],
  [/^\/login$/, viewLogin],
  [/^\/register$/, viewRegister],
  [/^\/verify$/, viewVerify],
];

async function route() {
  const raw = (location.hash || '#/courses').slice(1);
  const [path, query] = raw.split('?');
  const params = new URLSearchParams(query || '');

  for (const [pattern, handler] of routes) {
    const match = path.match(pattern);
    if (match) {
      try {
        await handler(...match.slice(1), params);
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) {
          requireAuth(location.hash);
        } else {
          setView(`<div class="mx-auto max-w-lg">${errorBox(e.message || 'Something went wrong.')}</div>`);
        }
      }
      return;
    }
  }
  go('#/courses');
}

// ---------- views: courses ----------

async function viewCourses() {
  setView(spinner());
  const { data } = await api('/courses', { auth: false });

  const cards = data
    .map(
      (c) => `
      <a href="#/courses/${c.id}" class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
        <div class="flex h-32 items-center justify-center bg-gradient-to-br ${gradientFor(c.id)}">
          <span class="text-5xl font-extrabold text-white/90">${esc(c.title.charAt(0))}</span>
        </div>
        <div class="p-5">
          <h3 class="text-lg font-semibold text-slate-900 group-hover:text-indigo-600">${esc(c.title)}</h3>
          <p class="mt-1 line-clamp-2 text-sm text-slate-500">${esc(c.description)}</p>
          <div class="mt-4 flex items-center justify-between">
            <span class="text-base font-bold text-slate-900">${money(c.price)}</span>
            <span class="text-sm font-semibold text-indigo-600">View course</span>
          </div>
        </div>
      </a>`
    )
    .join('');

  setView(`
    <section class="mb-10 rounded-3xl bg-gradient-to-br from-indigo-600 to-violet-600 px-8 py-12 text-white">
      <h1 class="text-3xl font-extrabold sm:text-4xl">Learn at your own pace</h1>
      <p class="mt-3 max-w-xl text-indigo-100">Pick a course, apply a promo code, and get instant access to materials and tryouts.</p>
    </section>
    <h2 class="mb-4 text-xl font-bold text-slate-900">Available courses</h2>
    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">${cards || '<p class="text-slate-500">No courses yet.</p>'}</div>`);
}

async function paidOrderFor(courseId) {
  if (!session.token) return null;
  try {
    const { data } = await api('/orders');
    return data.find((o) => o.course && o.course.id === Number(courseId) && o.status === 'paid') || null;
  } catch (e) {
    return null;
  }
}

async function viewCourse(id) {
  setView(spinner());
  const [{ data: course }, paid] = await Promise.all([api(`/courses/${id}`, { auth: false }), paidOrderFor(id)]);

  const action = paid
    ? `<a href="#/learn/${course.id}" class="${BTN_PRIMARY} w-full">Open course</a>`
    : `<a href="#/checkout/${course.id}" class="${BTN_PRIMARY} w-full">Enroll now</a>`;

  setView(`
    <a href="#/courses" class="text-sm font-medium text-indigo-600 hover:underline">&larr; All courses</a>
    <div class="mt-4 grid gap-8 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <div class="flex h-48 items-center justify-center rounded-3xl bg-gradient-to-br ${gradientFor(course.id)}">
          <span class="text-7xl font-extrabold text-white/90">${esc(course.title.charAt(0))}</span>
        </div>
        <h1 class="mt-6 text-3xl font-extrabold text-slate-900">${esc(course.title)}</h1>
        <p class="mt-3 text-slate-600">${esc(course.description)}</p>
        <h2 class="mt-8 text-lg font-bold text-slate-900">What you get</h2>
        <ul class="mt-3 space-y-2 text-sm text-slate-600">
          <li class="flex gap-2"><span class="text-emerald-500">&#10003;</span> Learning materials (PDF, video, articles)</li>
          <li class="flex gap-2"><span class="text-emerald-500">&#10003;</span> Timed practice tryouts</li>
          <li class="flex gap-2"><span class="text-emerald-500">&#10003;</span> Promo codes accepted at checkout</li>
        </ul>
      </div>
      <aside class="${CARD} h-fit">
        <p class="text-sm text-slate-500">Price</p>
        <p class="mt-1 text-3xl font-extrabold text-slate-900">${money(course.price)}</p>
        <div class="mt-6">${action}</div>
        ${paid ? '<p class="mt-3 text-center text-xs text-emerald-600">You are enrolled in this course.</p>' : ''}
      </aside>
    </div>`);
}

// ---------- views: checkout ----------

async function viewCheckout(id) {
  if (!requireAuth(`#/checkout/${id}`)) return;
  setView(spinner());

  const [{ data: course }, paid] = await Promise.all([api(`/courses/${id}`, { auth: false }), paidOrderFor(id)]);
  if (paid) {
    toast('You are already enrolled in this course.');
    go(`#/learn/${id}`);
    return;
  }

  let applied = null; // { code, original, discount, total }

  const summary = () => {
    const original = applied ? applied.original : course.price;
    const discount = applied ? applied.discount : 0;
    const total = applied ? applied.total : course.price;
    return `
      <dl class="space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-slate-500">Course price</dt><dd>${money(original)}</dd></div>
        <div class="flex justify-between"><dt class="text-slate-500">Discount${applied ? ` (${esc(applied.code)})` : ''}</dt><dd class="text-emerald-600">- ${money(discount)}</dd></div>
        <div class="flex justify-between border-t border-slate-200 pt-3 text-base font-bold"><dt>Total</dt><dd>${money(total)}</dd></div>
      </dl>`;
  };

  setView(`
    <a href="#/courses/${course.id}" class="text-sm font-medium text-indigo-600 hover:underline">&larr; Back to course</a>
    <h1 class="mt-4 text-2xl font-extrabold text-slate-900">Checkout</h1>
    <div class="mt-6 grid gap-8 lg:grid-cols-5">
      <section class="${CARD} lg:col-span-3">
        <div class="flex items-center gap-4">
          <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br ${gradientFor(course.id)} text-2xl font-extrabold text-white">${esc(course.title.charAt(0))}</div>
          <div>
            <h2 class="font-semibold text-slate-900">${esc(course.title)}</h2>
            <p class="text-sm text-slate-500">${money(course.price)}</p>
          </div>
        </div>
        <form id="promo-form" class="mt-6">
          <label for="promo" class="text-sm font-medium text-slate-700">Promo code</label>
          <div class="mt-1 flex gap-2">
            <input id="promo" class="${INPUT} uppercase" placeholder="e.g. WELCOME10" autocomplete="off">
            <button class="${BTN_GHOST}" type="submit">Apply</button>
          </div>
          <p id="promo-msg" class="mt-2 min-h-[1.25rem] text-sm"></p>
          <p class="text-xs text-slate-400">Demo codes: WELCOME10 (10% off), HEMAT25K (Rp 25.000 off)</p>
        </form>
      </section>
      <aside class="${CARD} h-fit lg:col-span-2">
        <h2 class="mb-4 font-semibold text-slate-900">Order summary</h2>
        <div id="summary">${summary()}</div>
        <button id="order-btn" class="${BTN_PRIMARY} mt-6 w-full">Place order</button>
        <div id="order-result" class="mt-4"></div>
      </aside>
    </div>`);

  const refreshSummary = () => ($('#summary').innerHTML = summary());

  $('#promo-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const code = $('#promo').value.trim();
    const msg = $('#promo-msg');
    if (!code) {
      applied = null;
      msg.textContent = '';
      refreshSummary();
      return;
    }
    try {
      const { data } = await api('/promos/validate', { method: 'POST', body: { course_id: course.id, promo_code: code } });
      applied = { code: code.toUpperCase(), ...data };
      msg.className = 'mt-2 min-h-[1.25rem] text-sm text-emerald-600';
      msg.textContent = `Promo applied: you save ${money(data.discount)}.`;
    } catch (e) {
      applied = null;
      msg.className = 'mt-2 min-h-[1.25rem] text-sm text-rose-600';
      msg.textContent = e.message;
    }
    refreshSummary();
  });

  $('#order-btn').addEventListener('click', async (event) => {
    const done = busy(event.currentTarget, 'Placing order...');
    try {
      const body = { course_id: course.id };
      if (applied) body.promo_code = applied.code;
      const { data: order } = await api('/orders', { method: 'POST', body });
      showOrderResult(order, course.id);
      $('#order-btn').classList.add('hidden');
      $('#promo-form').querySelectorAll('input,button').forEach((el) => (el.disabled = true));
    } catch (e) {
      $('#order-result').innerHTML = errorBox(e.message);
      done();
    }
  });
}

function showOrderResult(order, courseId) {
  const box = $('#order-result');

  if (order.status === 'paid') {
    box.innerHTML = `
      <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
        <p class="font-semibold">Order ${esc(order.order_code)} is paid.</p>
        <p class="mt-1">You can start learning now.</p>
      </div>
      <a href="#/learn/${courseId}" class="${BTN_PRIMARY} mt-3 w-full">Open course</a>`;
    return;
  }

  const payUrl = safeUrl(order.redirect_url);
  box.innerHTML = `
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
      <p class="font-semibold">Order ${esc(order.order_code)} is waiting for payment.</p>
      <p class="mt-1">Total: ${money(order.gross_amount)}</p>
    </div>
    ${
      payUrl
        ? `<a href="${esc(payUrl)}" target="_blank" rel="noopener noreferrer" class="${BTN_PRIMARY} mt-3 w-full">Pay with Midtrans (sandbox)</a>`
        : `<p class="mt-3 text-xs text-slate-500">No Midtrans sandbox key is configured, so there is no payment page. In local development you can simulate a successful payment.</p>
           <button id="simulate" class="${BTN_PRIMARY} mt-3 w-full">Simulate payment (local demo)</button>`
    }
    <button id="check-status" class="${BTN_GHOST} mt-3 w-full">I have paid, check status</button>`;

  const simulate = $('#simulate');
  if (simulate) {
    simulate.addEventListener('click', async () => {
      const done = busy(simulate, 'Processing...');
      try {
        const { data } = await api(`/dev/orders/${encodeURIComponent(order.order_code)}/simulate-paid`, { method: 'POST' });
        toast('Payment confirmed.', 'success');
        showOrderResult(data, courseId);
      } catch (e) {
        done();
        toast(e.status === 404 ? 'Payment simulation is only available in local mode.' : e.message, 'error');
      }
    });
  }

  $('#check-status').addEventListener('click', async (event) => {
    const done = busy(event.currentTarget, 'Checking...');
    try {
      const { data } = await api(`/orders/${encodeURIComponent(order.order_code)}`);
      if (data.status === 'paid') {
        toast('Payment confirmed.', 'success');
        showOrderResult(data, courseId);
      } else {
        toast(`Order status: ${data.status}`);
        done();
      }
    } catch (e) {
      done();
      toast(e.message, 'error');
    }
  });
}

// ---------- views: orders ----------

async function viewOrders() {
  if (!requireAuth('#/orders')) return;
  setView(spinner());
  const { data } = await api('/orders');

  const rows = data
    .map((o) => {
      const courseId = o.course ? o.course.id : '';
      let action = '';
      if (o.status === 'paid') {
        action = `<a href="#/learn/${courseId}" class="font-semibold text-indigo-600 hover:underline">Open course</a>`;
      } else if (o.status === 'pending') {
        const payUrl = safeUrl(o.redirect_url);
        action = payUrl
          ? `<a href="${esc(payUrl)}" target="_blank" rel="noopener noreferrer" class="font-semibold text-indigo-600 hover:underline">Pay now</a>`
          : `<button data-simulate="${esc(o.order_code)}" class="font-semibold text-indigo-600 hover:underline">Simulate payment</button>`;
      }
      return `
        <tr class="border-t border-slate-100">
          <td class="px-4 py-3 font-mono text-xs text-slate-600">${esc(o.order_code)}</td>
          <td class="px-4 py-3">${esc(o.course ? o.course.title : '')}</td>
          <td class="px-4 py-3 text-right">${money(o.gross_amount)}</td>
          <td class="px-4 py-3">${badge(o.status)}</td>
          <td class="px-4 py-3 text-right text-sm">${action}</td>
        </tr>`;
    })
    .join('');

  setView(`
    <h1 class="text-2xl font-extrabold text-slate-900">My orders</h1>
    <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
      ${
        data.length
          ? `<table class="w-full text-left text-sm">
              <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Course</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>`
          : '<p class="p-8 text-center text-slate-500">You have no orders yet. <a href="#/courses" class="font-semibold text-indigo-600 hover:underline">Browse courses</a></p>'
      }
    </div>`);

  document.querySelectorAll('[data-simulate]').forEach((btn) =>
    btn.addEventListener('click', async () => {
      const done = busy(btn, 'Processing...');
      try {
        await api(`/dev/orders/${encodeURIComponent(btn.dataset.simulate)}/simulate-paid`, { method: 'POST' });
        toast('Payment confirmed.', 'success');
        viewOrders();
      } catch (e) {
        done();
        toast(e.status === 404 ? 'Payment simulation is only available in local mode.' : e.message, 'error');
      }
    })
  );
}

// ---------- views: learn ----------

async function viewLearn(id) {
  if (!requireAuth(`#/learn/${id}`)) return;
  setView(spinner());

  const { data: course } = await api(`/courses/${id}`, { auth: false });
  const [materials, tryouts] = await Promise.allSettled([api(`/courses/${id}/materials`), api(`/courses/${id}/tryouts`)]);

  // Session expired while loading: send the user back to log in.
  if ([materials, tryouts].some((r) => r.status === 'rejected' && r.reason.status === 401)) {
    requireAuth(`#/learn/${id}`);
    return;
  }

  // Not enrolled: both calls are refused with 403.
  if ([materials, tryouts].every((r) => r.status === 'rejected' && r.reason.status === 403)) {
    setView(`
      <div class="mx-auto max-w-lg ${CARD} text-center">
        <h1 class="text-xl font-bold text-slate-900">${esc(course.title)}</h1>
        <p class="mt-2 text-slate-600">You need to enroll to see the content of this course.</p>
        <a href="#/checkout/${course.id}" class="${BTN_PRIMARY} mt-6">Enroll now</a>
      </div>`);
    return;
  }

  const unavailable = [materials, tryouts].some((r) => r.status === 'rejected' && r.reason.status === 503);

  const section = (title, result, render, emptyText) => {
    let body;
    if (result.status === 'fulfilled') {
      const items = result.value.data;
      body = items.length ? `<ul class="divide-y divide-slate-100">${items.map(render).join('')}</ul>` : `<p class="p-5 text-sm text-slate-500">${emptyText}</p>`;
    } else {
      body = `<p class="p-5 text-sm text-slate-500">${esc(result.reason.status === 503 ? 'Temporarily unavailable.' : result.reason.message)}</p>`;
    }
    return `<section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <h2 class="border-b border-slate-100 bg-slate-50 px-5 py-3 font-semibold text-slate-900">${title}</h2>${body}</section>`;
  };

  const ICONS = { pdf: 'PDF', video: 'VID', article: 'ART' };

  const materialRow = (m) => {
    const url = safeUrl(m.url);
    return `
      <li class="flex items-center gap-4 px-5 py-4">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-xs font-bold text-indigo-600">${ICONS[m.type] || 'DOC'}</span>
        <div class="min-w-0 flex-1">
          <p class="truncate font-medium text-slate-900">${esc(m.title)}</p>
          <p class="text-xs capitalize text-slate-500">${esc(m.type)}</p>
        </div>
        ${url ? `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-indigo-600 hover:underline">Open</a>` : ''}
      </li>`;
  };

  const tryoutRow = (t) => `
    <li class="flex items-center justify-between gap-4 px-5 py-4">
      <div>
        <p class="font-medium text-slate-900">${esc(t.title)}</p>
        <p class="text-xs text-slate-500">${esc(t.duration_minutes)} minutes &middot; ${esc(t.question_count)} questions</p>
      </div>
    </li>`;

  setView(`
    <a href="#/orders" class="text-sm font-medium text-indigo-600 hover:underline">&larr; My orders</a>
    <h1 class="mt-4 text-3xl font-extrabold text-slate-900">${esc(course.title)}</h1>
    <p class="mt-1 text-slate-500">${esc(course.description)}</p>
    ${unavailable ? `<div class="mt-6">${warnBox('Course content is temporarily unavailable. The rest of the platform still works, please try again later.')}</div>` : ''}
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
      ${section('Materials', materials, materialRow, 'No materials yet.')}
      ${section('Tryouts', tryouts, tryoutRow, 'No tryouts yet.')}
    </div>`);
}

// ---------- views: authentication ----------

function authShell(title, subtitle, body) {
  return `
    <div class="mx-auto max-w-md">
      <div class="${CARD}">
        <h1 class="text-2xl font-extrabold text-slate-900">${title}</h1>
        <p class="mt-1 text-sm text-slate-500">${subtitle}</p>
        <div class="mt-6">${body}</div>
      </div>
    </div>`;
}

const field = (id, label, type, extra = '') => `
  <div>
    <label for="${id}" class="text-sm font-medium text-slate-700">${label}</label>
    <input id="${id}" name="${id}" type="${type}" required class="${INPUT} mt-1" ${extra}>
  </div>`;

function viewLogin() {
  setView(
    authShell(
      'Welcome back',
      'Log in to continue learning.',
      `<form id="login-form" class="space-y-4">
        ${field('email', 'Email', 'email', 'autocomplete="email"')}
        ${field('password', 'Password', 'password', 'autocomplete="current-password"')}
        <div id="form-error"></div>
        <button class="${BTN_PRIMARY} w-full" type="submit">Log in</button>
      </form>
      <p class="mt-6 text-center text-sm text-slate-500">New here? <a href="#/register" class="font-semibold text-indigo-600 hover:underline">Create an account</a></p>`
    )
  );

  $('#login-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const done = busy(event.submitter, 'Logging in...');
    const email = $('#email').value.trim();
    try {
      const data = await api('/auth/login', { method: 'POST', auth: false, body: { email, password: $('#password').value } });
      saveSession(data.token, data.user);
      toast(`Welcome, ${data.user.name}!`, 'success');
      const next = session.after || '#/courses';
      session.after = null;
      go(next);
    } catch (e) {
      done();
      if (e.status === 403) {
        toast('Please verify your email first.');
        go(`#/verify?email=${encodeURIComponent(email)}`);
      } else {
        $('#form-error').innerHTML = errorBox(e.message);
      }
    }
  });
}

function viewRegister() {
  setView(
    authShell(
      'Create your account',
      'We will email you a 6-digit code to verify your address.',
      `<form id="register-form" class="space-y-4">
        ${field('name', 'Full name', 'text', 'autocomplete="name" maxlength="100"')}
        ${field('email', 'Email', 'email', 'autocomplete="email"')}
        ${field('password', 'Password (min. 8 characters)', 'password', 'autocomplete="new-password" minlength="8"')}
        <div id="form-error"></div>
        <button class="${BTN_PRIMARY} w-full" type="submit">Sign up</button>
      </form>
      <p class="mt-6 text-center text-sm text-slate-500">Already have an account? <a href="#/login" class="font-semibold text-indigo-600 hover:underline">Log in</a></p>`
    )
  );

  $('#register-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const done = busy(event.submitter, 'Creating account...');
    const email = $('#email').value.trim();
    try {
      await api('/auth/register', {
        method: 'POST',
        auth: false,
        body: { name: $('#name').value.trim(), email, password: $('#password').value },
      });
      toast('Account created. Check your email for the code.', 'success');
      go(`#/verify?email=${encodeURIComponent(email)}`);
    } catch (e) {
      done();
      $('#form-error').innerHTML = errorBox(e.message);
    }
  });
}

let resendTimer = null;

function viewVerify(params) {
  const email = params.get('email') || '';
  clearInterval(resendTimer);

  setView(
    authShell(
      'Verify your email',
      'Enter the 6-digit code we sent to your email. It is valid for 10 minutes.',
      `<form id="verify-form" class="space-y-4">
        ${field('email', 'Email', 'email', `value="${esc(email)}" autocomplete="email"`)}
        <div>
          <label for="otp" class="text-sm font-medium text-slate-700">Verification code</label>
          <input id="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"
                 class="${INPUT} mt-1 text-center text-2xl font-bold tracking-[0.5em]" placeholder="000000">
        </div>
        <div id="form-error"></div>
        <button class="${BTN_PRIMARY} w-full" type="submit">Verify and continue</button>
      </form>
      <button id="resend" class="${BTN_GHOST} mt-3 w-full">Send a new code</button>
      <div class="mt-6 rounded-xl bg-slate-50 p-4 text-xs text-slate-500">
        <strong class="text-slate-700">Demo setup:</strong> emails are caught by Mailpit, not sent to real inboxes.
        Open <a href="http://localhost:8025" target="_blank" rel="noopener noreferrer" class="font-semibold text-indigo-600 hover:underline">localhost:8025</a> to read the code.
      </div>`
    )
  );

  $('#otp').focus();

  $('#verify-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const done = busy(event.submitter, 'Verifying...');
    try {
      const data = await api('/auth/verify-otp', {
        method: 'POST',
        auth: false,
        body: { email: $('#email').value.trim(), otp: $('#otp').value.trim() },
      });
      saveSession(data.token, data.user);
      toast('Email verified. Welcome!', 'success');
      const next = session.after || '#/courses';
      session.after = null;
      go(next);
    } catch (e) {
      done();
      $('#form-error').innerHTML = errorBox(e.message);
    }
  });

  const resend = $('#resend');
  resend.addEventListener('click', async () => {
    const address = $('#email').value.trim();
    if (!address) {
      $('#form-error').innerHTML = errorBox('Enter your email first.');
      return;
    }
    try {
      await api('/auth/resend-otp', { method: 'POST', auth: false, body: { email: address } });
      toast('A new code was sent if the account needs verification.', 'success');
    } catch (e) {
      toast(e.message, 'error');
    }
    let seconds = 30;
    resend.disabled = true;
    resendTimer = setInterval(() => {
      seconds -= 1;
      if (seconds <= 0) {
        clearInterval(resendTimer);
        resend.disabled = false;
        resend.textContent = 'Send a new code';
      } else {
        resend.textContent = `Send a new code (${seconds}s)`;
      }
    }, 1000);
  });
}

// ---------- start ----------

async function start() {
  renderNav();
  if (session.token) {
    try {
      const { user } = await api('/auth/me');
      session.user = user;
    } catch (e) {
      clearSession();
    }
    renderNav();
  }
  window.addEventListener('hashchange', route);
  route();
}

start();
