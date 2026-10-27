'use strict';

window.WorkspaceCache = (() => {
    let user = null, revision = 0;
    const memory = new Map(), pending = new Map();
    const prefix = 'asset-workspace-v6:';
    const ownedKey = key => /^asset-workspace-v\d+:/.test(key);
    try { Object.keys(sessionStorage).filter(key => ownedKey(key) && !key.startsWith(prefix)).forEach(key => sessionStorage.removeItem(key)); } catch {}
    const keyFor = path => prefix + user + ':' + path;
    function clear(broadcast = true) {
        revision++;
        memory.clear(); pending.clear();
        try {
            Object.keys(sessionStorage).filter(ownedKey).forEach(k => sessionStorage.removeItem(k));
            if (broadcast) localStorage.setItem(prefix + 'invalidate', String(Date.now()) + Math.random());
        } catch {}
        window.dispatchEvent(new CustomEvent('workspace-invalidated'));
    }
    function setUser(value) {
        if (user && user !== value) clear(false);
        user = value;
    }
    async function read(path, fetcher, force = false) {
        if (!user) return fetcher();
        const key = keyFor(path), now = Date.now();
        if (!force) {
            let entry = memory.get(key);
            if (!entry) { try { entry = JSON.parse(sessionStorage.getItem(key)); } catch {} }
            if (entry && entry.expires > now) { memory.set(key, entry); return structuredClone(entry.value); }
            if (pending.has(key)) return structuredClone(await pending.get(key));
        }
        const started = revision;
        const request = fetcher().then(value => {
            if (started === revision) {
                const freshness = path.startsWith('/api/health') || path.startsWith('/api/domains/stats') ? 60000 : 30 * 60 * 1000;
                const entry = {value, expires: now + freshness};
                memory.set(key, entry);
                try { sessionStorage.setItem(key, JSON.stringify(entry)); } catch {}
            }
            return value;
        });
        pending.set(key, request);
        try { return structuredClone(await request); }
        finally { if (pending.get(key) === request) pending.delete(key); }
    }
    window.addEventListener('storage', event => { if (event.key === prefix + 'invalidate') clear(false); });
    return {read, clear, setUser};
})();

window.WorkspaceData = {
    filterDomains(data, params) {
        const today = new Date().toLocaleDateString('en-CA', {timeZone:'Asia/Shanghai'});
        const date = new Date(today + 'T00:00:00+08:00').getTime();
        const rows = data.list.map(d => {
            const health = d.health;
            return health?.checked_at && Date.now() - Date.parse(health.checked_at.replace(' ','T') + '+08:00') >= 86400000 ? {...d,health:{...health,status:'stale'}} : d;
        }).filter(d => {
            if (params.get('provider') && d.provider !== params.get('provider')) return false;
            if (params.has('valid') && (d.valid !== false) !== (params.get('valid') === '1')) return false;
            if (params.get('access') && (d.health?.status || 'unchecked') !== params.get('access')) return false;
            if (params.get('q') && ![d.domain,d.provider_name,d.note].join(' ').toLowerCase().includes(params.get('q').toLowerCase())) return false;
            if (params.get('expiring')) {
                const days = d.expire_at ? Math.floor((Date.parse(d.expire_at + 'T23:59:59+08:00') - date) / 86400000) : null;
                if (d.valid === false || days === null) return false;
                if (params.get('expiring') === 'expired' ? days >= 0 : days < 0 || days > Number(params.get('expiring'))) return false;
            }
            return true;
        });
        const sort = params.get('sort');
        rows.sort((a,b) => sort === 'domain' ? a.domain.localeCompare(b.domain) : sort === 'synced' ? (b.synced_at || '').localeCompare(a.synced_at || '') :
            !a.expire_at ? (!b.expire_at ? a.domain.localeCompare(b.domain) : 1) : !b.expire_at ? -1 : (sort === 'expire_desc' ? -1 : 1) * a.expire_at.localeCompare(b.expire_at));
        return {...data, list: rows, total: rows.length};
    }
};
