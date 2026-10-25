<section id="view-settings" class="tab-view hidden" aria-labelledby="settings-title">
    <div class="page-head"><div><p class="eyebrow">PRODUCT SETTINGS</p><h1 id="settings-title">产品设置<span class="heading-dot">.</span></h1><p class="muted">维护工作台登录信息、网络连接与 AI 调用权限。</p></div><a class="btn" href="/api/openapi.json" target="_blank" rel="noopener">查看 OpenAPI</a></div>
    <div class="settings-tabs domain-filter-tabs" role="tablist" aria-label="产品设置分类"><button class="domain-filter-tab" role="tab" data-settings-tab="general" aria-selected="true">工作台配置</button><button class="domain-filter-tab" role="tab" data-settings-tab="api" aria-selected="false" data-color="tencent">AI API</button></div>
    <p id="settings-error" class="error-text hidden" role="alert"></p>
    <div data-settings-panel="general" class="settings-grid">
        <section class="panel settings-card"><div class="panel-title"><h2>网络与云接口</h2><span class="badge">HTTP</span></div><p class="muted">用于云账号连接与域名同步。健康探测保留各自的超时策略。</p>
            <form id="settings-http-form" class="settings-form">
                <label>代理地址<input id="settings-proxy" type="password" autocomplete="new-password" placeholder="留空保留现有配置，支持 http / https / socks5"></label><p id="settings-proxy-state" class="form-help"></p>
                <label class="settings-checkbox"><input id="settings-clear-proxy" type="checkbox">清除现有代理，改为直接连接</label>
                <div class="settings-form-row"><label>请求超时（秒）<input id="settings-timeout" type="number" min="5" max="120" value="25" required></label><label>连接超时（秒）<input id="settings-connect-timeout" type="number" min="1" max="30" value="10" required></label></div>
                <p class="error-text hidden" role="alert"></p><button class="btn btn-primary" type="submit">保存网络配置</button>
            </form>
        </section>
        <section class="panel settings-card"><div class="panel-title"><h2>登录账号与密码</h2><span id="settings-admin" class="badge"></span></div><p class="muted">更新后需重新登录，其他会话和旧 API 密钥也会失效。</p>
            <form id="settings-auth-form" class="settings-form" autocomplete="off">
                <label>当前密码<input id="settings-current-password" type="password" required autocomplete="current-password"></label>
                <label>管理员账号<input id="settings-user" required minlength="3" maxlength="64" autocomplete="username"></label>
                <label>新密码<input id="settings-new-password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" placeholder="12–72 字节"></label>
                <label>确认新密码<input id="settings-confirm-password" type="password" required autocomplete="new-password"></label>
                <p class="error-text hidden" role="alert"></p><button class="btn btn-primary" type="submit">更新登录信息</button>
            </form>
        </section>
    </div>
    <div data-settings-panel="api" class="hidden">
        <section class="panel api-intro"><div><p class="eyebrow">AI READY</p><h2>让 AI 读懂资产，也能按权限操作。</h2><p class="muted">支持标准 OpenAPI、能力发现和 Bearer Token。密码与云密钥不会通过查询接口返回。</p></div><div class="api-entry-links"><a class="btn btn-sm" href="/api/openapi.json" target="_blank" rel="noopener">OpenAPI 3.1</a><a class="btn btn-sm" href="/.well-known/asset-workspace.json" target="_blank" rel="noopener">能力发现</a><a class="btn btn-sm" href="/llms.txt" target="_blank" rel="noopener">AI 使用说明</a></div></section>
        <div class="settings-grid">
            <section class="panel settings-card"><div class="panel-title"><h2>创建 API 密钥</h2><span class="badge">BEARER</span></div><form id="settings-token-form" class="settings-form" autocomplete="off">
                <div class="settings-form-row"><label>用途名称<input id="api-token-name" required maxlength="100" placeholder="如：AI 资产巡检"></label><label>有效天数<input id="api-token-days" type="number" min="1" max="365" value="90" required></label></div>
                <fieldset class="api-scopes"><legend>授权范围</legend><div id="api-token-scopes"></div></fieldset>
                <label>当前管理员密码<input id="api-token-password" type="password" required autocomplete="current-password"></label>
                <p class="error-text hidden" role="alert"></p><button class="btn btn-primary" type="submit">创建密钥</button>
            </form></section>
            <section class="panel settings-card"><div class="panel-title"><h2>调用方式</h2><span class="badge">JSON API</span></div><p class="muted">将接口描述导入支持 OpenAPI 的 AI 工具，认证方式选 Bearer Token。也可通过账号密码登录 API，保留 Cookie 并携带 CSRF Token 写入。</p><pre class="api-code" id="api-curl-example"></pre><p class="form-help">查询资产使用 <code>GET /api/health</code>；解析记录用云账号 ID 和域名定位。DNS 修改、健康检查、同步有独立权限。</p><div id="api-token-once" class="api-token-once hidden" role="status"><strong>请保存新密钥，离开后无法再次查看。</strong><code id="api-token-value"></code><button id="api-token-copy" type="button" class="btn btn-sm">复制密钥</button><button id="api-token-dismiss" type="button" class="btn btn-sm">已保存，隐藏密钥</button></div></section>
        </div>
        <section class="panel settings-card"><div class="panel-title"><h2>已授权的 API 密钥</h2><span class="muted">可随时撤销</span></div><div id="api-token-list"></div></section>
    </div>
</section>
