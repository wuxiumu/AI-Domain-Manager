'use strict';

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

const state = {
    accounts: [],          // 账号列表（含脱敏密钥）
    tab: 'dashboard',
    expiring: '',          // 列表页到期过滤（'' | expired | 7 | 15 | 30）
    csrf: '',
};

const START_OF_TODAY = new Date();
START_OF_TODAY.setHours(0, 0, 0, 0);

/* ---------- API ---------- */
async function api(path, opts = {}) {
    const cache = typeof window !== 'undefined' ? window.WorkspaceCache : null;
    const method = (opts.method || 'GET').toUpperCase();
    const fetcher = async () => {
        const {refresh, ...requestOpts} = opts;
        const res = await fetch(path, {
            ...requestOpts,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf, ...(opts.headers || {}) },
        });
        const data = await res.json().catch(() => { throw new Error('服务返回了无法读取的结果，请重试'); });
        if (res.status === 401 && typeof document !== 'undefined' && typeof showLogin === 'function'
            && !document.querySelector('#app-view')?.classList.contains('hidden')) showLogin();
        if (!res.ok || data?.ok === false) {
            const error = new Error(data.error || ('请求失败 HTTP ' + res.status));
            error.status = res.status;
            error.code = data.code;
            error.retryAfter = Number(data.retry_after || res.headers?.get('Retry-After')) || 0;
            throw error;
        }
        if (method !== 'GET' || (path.includes('/records') && data.cached === false)) cache?.clear();
        return data;
    };
    const privateResponse = path.startsWith('/api/calendar') || path.startsWith('/api/maintenance') || path.startsWith('/api/login') || path.startsWith('/api/dns/changes') || ['/api/me', '/api/env-check', '/api/setup/status', '/api/settings', '/api/tokens'].some(endpoint => path.split('?')[0] === endpoint);
    return method === 'GET' && !privateResponse
        ? (cache ? cache.read(path, fetcher, opts.refresh || path.includes('refresh=1')) : fetcher()) : fetcher();
}

/* ---------- 工具 ---------- */
function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function providerInfo(pid) {
    const acc = state.accounts.find((a) => a.id === pid);
    if (acc) {
        return { name: acc.name, cls: badgeClass(acc.provider) };
    }
    if (pid.startsWith('aliyun')) return { name: pid, cls: 'aliyun' };
    if (pid.startsWith('tencent')) return { name: pid, cls: 'tencent' };
    if (pid.startsWith('godaddy')) return { name: pid, cls: 'godaddy' };
    return { name: pid, cls: '' };
}

function badgeClass(provider) {
    return provider === 'aliyun' ? 'aliyun'
        : provider === 'tencent' ? 'tencent'
        : provider === 'godaddy' ? 'godaddy' : '';
}

function expireBadge(exp) {
    if (!exp) return '<span class="muted">—</span>';
    const days = Math.floor((new Date(exp + 'T00:00:00') - START_OF_TODAY) / 86400000);
    if (days < 0) return `<span class="badge b-red">已过期 ${-days} 天</span>`;
    if (days <= 7) return `<span class="badge b-red">${days} 天后到期</span>`;
    if (days <= 15) return `<span class="badge b-amber">${days} 天后到期</span>`;
    if (days <= 30) return `<span class="badge b-blue">${days} 天后到期</span>`;
    return `<span class="badge b-gray">${days} 天后到期</span>`;
}
