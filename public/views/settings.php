<section id="view-settings" class="tab-view hidden" aria-labelledby="settings-title">
    <div class="page-head"><div><p class="eyebrow">PRODUCT SETTINGS</p><h1 id="settings-title">产品设置<span class="heading-dot">.</span></h1><p class="muted">管理登录、网络、API 权限、资产备份与交付自检。</p></div><a class="btn" href="/api/openapi.json" target="_blank" rel="noopener">查看 OpenAPI</a></div>
    <div class="settings-tabs domain-filter-tabs" role="tablist" aria-label="产品设置分类"><button class="domain-filter-tab" role="tab" data-settings-tab="general" aria-selected="true">工作台配置</button><button class="domain-filter-tab" role="tab" data-settings-tab="api" aria-selected="false" data-color="tencent">AI API</button><button class="domain-filter-tab" role="tab" data-settings-tab="maintenance" aria-selected="false">数据与交付</button></div>
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
    <div data-settings-panel="maintenance" class="hidden">
        <section class="panel settings-card maintenance-assets">
            <div class="panel-title"><h2>资产备份与迁移</h2><span id="maintenance-version" class="badge"></span></div>
            <p class="muted">JSON 保留域名、手工 IP、备注、网站链接与人工关联，可预览后恢复；CSV 用于交接。文件不含云凭据、管理员密码或 API 密钥，但包含私有资产资料，请妥善保存。</p>
            <div class="maintenance-actions"><button class="btn btn-primary" id="maintenance-backup">创建服务器备份</button><button class="btn" data-asset-export="json">导出 JSON</button><button class="btn" data-asset-export="csv">导出 CSV</button><button class="btn" id="maintenance-refresh">刷新</button></div>
            <p id="maintenance-message" class="form-help" role="status" aria-live="polite"></p>
            <p id="maintenance-error" class="error-text hidden" role="alert"></p>
            <div class="table-wrap maintenance-table"><table><thead><tr><th>备份时间</th><th>域名</th><th>手工 IP</th><th>关联</th><th>大小</th><th>操作</th></tr></thead><tbody id="maintenance-backups"><tr><td colspan="6">尚未加载</td></tr></tbody></table></div>
            <div class="maintenance-import"><label for="maintenance-file">从 JSON 文件恢复 <span class="muted">最大 5 MB</span></label><input id="maintenance-file" type="file" accept=".json,application/json"><button class="btn" id="maintenance-file-preview">预览文件</button></div>
            <p class="form-help">服务器最多保存 30 份备份；恢复前会自动留底一份。换机后未配置的云账号来源会转为手工资产，云凭据需重新配置。恢复会清空检查结果，需要重新检查。</p>
            <section id="maintenance-preview" class="maintenance-preview hidden" aria-labelledby="maintenance-preview-title" tabindex="-1">
                <h3 id="maintenance-preview-title">恢复预览</h3><div id="maintenance-preview-summary"></div><p id="maintenance-preview-warning" class="error-text"></p>
                <p class="form-help" id="maintenance-preview-hash"></p>
                <form id="maintenance-restore-form" class="settings-form" autocomplete="off"><div class="settings-form-row"><label>当前管理员密码<input id="maintenance-password" type="password" required autocomplete="current-password"></label><label>输入“恢复资产”确认<input id="maintenance-confirmation" required autocomplete="off" placeholder="恢复资产"></label></div><p class="error-text hidden" role="alert"></p><div class="maintenance-actions"><button class="btn btn-danger" type="submit">替换当前资产并恢复</button><button class="btn" type="button" id="maintenance-cancel">取消预览</button></div></form>
            </section>
        </section>
        <section class="panel settings-card maintenance-delivery"><div class="panel-title"><h2>交付自检</h2><div class="maintenance-actions"><strong id="maintenance-check-count"></strong><button class="btn btn-sm" id="maintenance-report">导出交付摘要</button></div></div><div id="maintenance-checks" class="maintenance-checks"></div><p class="form-help">自检核对当前环境；实际交付还需在隔离实例完成恢复演练，并确认云账号权限、生产访问与授权范围。</p></section>
        <section class="panel settings-card maintenance-audit"><div class="panel-title"><h2>操作记录</h2><span class="muted">最近 100 条 · 服务器保留 1,000 条</span></div><p class="form-help">记录已通过登录与权限校验的写入请求。处理中表示没有完成记录，需要核实实际结果；记录不含请求正文、密钥或 DNS 修改前后值。</p><div class="table-wrap maintenance-table"><table><thead><tr><th>发起时间</th><th>操作者</th><th>操作</th><th>结果</th></tr></thead><tbody id="maintenance-audit"><tr><td colspan="4">尚未加载</td></tr></tbody></table></div></section>
    </div>
</section>
