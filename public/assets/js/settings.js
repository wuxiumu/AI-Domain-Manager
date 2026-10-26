'use strict';

window.WorkspaceSettings = (() => {
    let settings = null;
    let generation = 0;
    const providerFields = {
        aliyun: [['access_key_id', 'AccessKey ID'], ['access_key_secret', 'AccessKey Secret']],
        tencent: [['secret_id', 'SecretId'], ['secret_key', 'SecretKey']],
        godaddy: [['api_key', 'API Key'], ['api_secret', 'API Secret']],
    };
    const providerNames = {aliyun: '阿里云', tencent: '腾讯云', godaddy: 'GoDaddy'};

    function errorFor(form, message = '') {
        const error = form.querySelector('.error-text');
        error.textContent = message;
        error.classList.toggle('hidden', !message);
    }

    async function load() {
        const revision = ++generation;
        if (document.querySelector('[data-settings-tab="maintenance"]')?.getAttribute('aria-selected') === 'true') window.WorkspaceMaintenance?.load();
        $('#settings-error').classList.add('hidden');
        try {
            const [result, authorization] = await Promise.all([api('/api/settings'), api('/api/tokens')]);
            if (revision !== generation) return;
            settings = result;
            $('#settings-user').value = result.admin_user;
            $('#settings-admin').textContent = result.admin_user;
            $('#settings-timeout').value = result.http.timeout;
            $('#settings-connect-timeout').value = result.http.connect_timeout;
            $('#settings-proxy-state').textContent = result.http.proxy_configured ? '当前代理：' + result.http.proxy_label + '（凭据不回显）' : '当前使用直接连接';
            $('#api-token-scopes').innerHTML = Object.entries(authorization.scopes).map(([scope, label]) => `<label class="settings-checkbox"><input type="checkbox" name="api_scope" value="${escapeHtml(scope)}" ${['assets:read', 'dns:read'].includes(scope) ? 'checked' : ''}><span>${escapeHtml(label)}<small>${escapeHtml(scope)}</small></span></label>`).join('');
            renderTokens(authorization.tokens);
            $('#api-curl-example').textContent = 'curl "' + location.origin + '/api/health" \\\n  -H "Authorization: Bearer $WORKSPACE_API_TOKEN"';
        } catch (error) { $('#settings-error').textContent = error.message; $('#settings-error').classList.remove('hidden'); }
    }

    function renderTokens(tokens) {
        $('#api-token-list').innerHTML = tokens.length ? tokens.map(token => `<article class="api-token-row"><div><strong>${escapeHtml(token.name)}</strong><small>${escapeHtml(token.prefix)}… · ${token.invalidated ? '登录信息已更新，此密钥已失效' : token.expired ? '已过期' : '有效至 ' + new Date(token.expires_at).toLocaleDateString()}</small><div class="api-token-tags">${token.scopes.map(scope => `<span class="badge">${escapeHtml(scope)}</span>`).join('')}</div></div><button class="btn btn-sm btn-link-danger" data-revoke-token="${escapeHtml(token.id)}">撤销</button></article>`).join('') : '<div class="settings-empty"><strong>还没有 API 密钥</strong><p>创建后可交给 AI 工具读取资产；需要操作时再勾选对应权限。</p></div>';
    }

    function hideToken() {
        $('#api-token-value').textContent = '';
        $('#api-token-once').classList.add('hidden');
        $('#api-token-password').value = '';
    }

    document.querySelectorAll('[data-settings-tab]').forEach(button => button.addEventListener('click', () => {
        document.querySelectorAll('[data-settings-tab]').forEach(tab => tab.setAttribute('aria-selected', String(tab === button)));
        document.querySelectorAll('[data-settings-panel]').forEach(panel => panel.classList.toggle('hidden', panel.dataset.settingsPanel !== button.dataset.settingsTab));
    }));

    $('#settings-http-form').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget, button = form.querySelector('button[type="submit"]');
        errorFor(form); button.disabled = true;
        try {
            await api('/api/settings/http', {method: 'PUT', body: JSON.stringify({proxy: $('#settings-proxy').value.trim(), clear_proxy: $('#settings-clear-proxy').checked,
                timeout: Number($('#settings-timeout').value), connect_timeout: Number($('#settings-connect-timeout').value)})});
            $('#settings-proxy').value = ''; $('#settings-clear-proxy').checked = false;
            toast('网络配置已保存'); await load();
        } catch (error) { errorFor(form, error.message); }
        finally { button.disabled = false; }
    });

    $('#settings-auth-form').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget, button = form.querySelector('button[type="submit"]');
        errorFor(form);
        if ($('#settings-new-password').value !== $('#settings-confirm-password').value) { errorFor(form, '两次输入的新密码不一致'); return; }
        button.disabled = true;
        try {
            const nextUser = $('#settings-user').value.trim();
            await api('/api/auth/credentials', {method: 'PUT', body: JSON.stringify({current_password: $('#settings-current-password').value,
                user: nextUser, password: $('#settings-new-password').value})});
            form.reset(); hideToken(); showLogin(); $('#login-user').value = nextUser; toast('登录信息已更新，请使用新账号密码登录');
        } catch (error) { errorFor(form, error.message); }
        finally { button.disabled = false; }
    });

    $('#settings-token-form').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget, button = form.querySelector('button[type="submit"]');
        const tokenPasswordForSubmit = $('#api-token-password').value;
        errorFor(form); hideToken(); button.disabled = true;
        try {
            const result = await api('/api/tokens', {method: 'POST', body: JSON.stringify({name: $('#api-token-name').value.trim(),
                current_password: tokenPasswordForSubmit, expires_in_days: Number($('#api-token-days').value),
                scopes: [...form.querySelectorAll('[name="api_scope"]:checked')].map(input => input.value)})});
            $('#api-token-value').textContent = result.token;
            $('#api-token-once').classList.remove('hidden');
            const list = await api('/api/tokens'); renderTokens(list.tokens);
            toast('API 密钥已创建，请保存本次显示的值');
        } catch (error) { errorFor(form, error.message); }
        finally { button.disabled = false; $('#api-token-password').value = ''; }
    });

    $('#api-token-copy').addEventListener('click', async () => {
        try { await navigator.clipboard.writeText($('#api-token-value').textContent); toast('密钥已复制'); }
        catch { toast('请选中密钥文本并复制', 'info'); }
    });
    $('#api-token-dismiss').addEventListener('click', hideToken);
    $('#api-token-list').addEventListener('click', async event => {
        const button = event.target.closest('[data-revoke-token]'); if (!button) return;
        if (!await confirmDialog({title: '撤销 API 密钥', message: '使用此密钥的 AI 工具将无法继续调用，可创建新密钥重新授权。', danger: true, confirmText: '撤销密钥'})) return;
        button.disabled = true;
        try { await api('/api/tokens/' + encodeURIComponent(button.dataset.revokeToken), {method: 'DELETE'}); hideToken(); await load(); toast('密钥已撤销'); }
        catch (error) { toast(error.message, 'error'); button.disabled = false; }
    });

    async function openAccount(id = null) {
        try {
            settings = await api('/api/settings');
            const account = id ? settings.accounts.find(item => item.id === id) : null;
            if (id && !account) throw new Error('云账号不存在，请刷新列表');
            let provider = account?.provider || 'aliyun';
            const overlay = document.createElement('div'); overlay.className = 'modal-overlay';
            overlay.innerHTML = `<section class="modal account-config-modal" role="dialog" aria-modal="true" aria-label="${account ? '编辑云账号' : '添加云账号'}"><div class="modal-head"><h3>${account ? '编辑云账号' : '添加云账号'}</h3><button class="modal-close" aria-label="关闭云账号配置">×</button></div><form class="settings-form"><div class="modal-body"><label>账号名称<input name="name" required maxlength="100" value="${escapeHtml(account?.name || '')}" placeholder="如：阿里云 · 生产账号"></label><label>云商类型</label><div class="domain-filter-tabs" role="group" aria-label="云商类型">${Object.entries(providerNames).map(([value, name]) => `<button type="button" class="domain-filter-tab" data-account-provider="${value}" data-color="${value}" aria-pressed="${value === provider}" ${account ? 'disabled' : ''}>${name}</button>`).join('')}</div><div class="account-credential-fields"></div><p class="form-help">${account ? '密钥留空会保留已保存的值。停用仅停止云端操作，资产台账与缓存会保留。' : '凭据保存到服务器的私有配置文件，后续仅显示配置状态。'}</p><label>备注<textarea name="note" rows="3" maxlength="2000" placeholder="账号用途或其他说明">${escapeHtml(account?.note || '')}</textarea></label><label class="account-state"><span>启用此云账号</span><input class="account-switch-input" type="checkbox" role="switch" name="enabled" ${account?.enabled === false ? '' : 'checked'}></label><p class="error-text hidden" role="alert"></p></div><div class="modal-foot"><button type="button" class="btn account-cancel">取消</button><button type="submit" class="btn btn-primary">保存配置</button></div></form></section>`;
            (document.fullscreenElement || document.body).appendChild(overlay);
            const form = overlay.querySelector('form');
            const fields = () => {
                overlay.querySelector('.account-credential-fields').innerHTML = providerFields[provider].map(([key, label]) => `<label>${label}<input name="${key}" type="password" autocomplete="new-password" spellcheck="false" ${account ? '' : 'required'} placeholder="${account?.credentials[key]?.configured ? '已配置，留空保留' : '输入 ' + label}"></label>`).join('');
                overlay.querySelectorAll('[data-account-provider]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.accountProvider === provider)));
            };
            fields();
            const close = () => { form.reset(); overlay.remove(); };
            overlay.querySelector('.modal-close').onclick = close;
            overlay.querySelector('.account-cancel').onclick = close;
            overlay.addEventListener('click', event => { if (event.target === overlay) close(); const button = event.target.closest('[data-account-provider]'); if (button && !account) { provider = button.dataset.accountProvider; fields(); } });
            overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
            form.addEventListener('submit', async event => {
                event.preventDefault(); const button = form.querySelector('[type="submit"]'); button.disabled = true; errorFor(form);
                const body = {provider, name: form.elements.name.value.trim(), enabled: form.elements.enabled.checked, note: form.elements.note.value.trim()};
                providerFields[provider].forEach(([key]) => { body[key] = form.elements[key].value; });
                try {
                    await api('/api/accounts' + (account ? '/' + encodeURIComponent(account.id) : ''), {method: account ? 'PUT' : 'POST', body: JSON.stringify(body)});
                    close(); await loadKeys(); toast('云账号配置已保存，可测试连接或同步域名');
                } catch (error) { errorFor(form, error.message); button.disabled = false; }
            });
            form.elements.name.focus();
        } catch (error) { toast(error.message, 'error'); }
    }

    $('#account-add').addEventListener('click', () => openAccount());
    document.addEventListener('click', event => { const button = event.target.closest('[data-account-edit]'); if (button) openAccount(button.dataset.accountEdit); });
    window.addEventListener('hashchange', () => { if (!location.hash.startsWith('#settings')) hideToken(); });
    function leave() {
        generation++;
        window.WorkspaceMaintenance?.leave();
        hideToken();
        $('#settings-auth-form').reset();
        $('#settings-proxy').value = '';
    }
    return {load, openAccount, hideToken, leave};
})();
