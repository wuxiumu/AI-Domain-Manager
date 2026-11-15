<section id="setup-view" class="view hidden">
    <div class="setup-shell">
        <div class="setup-intro">
            <span class="brand-mark"><svg class="icon" aria-hidden="true"><use href="#icon-grid"/></svg></span>
            <p class="eyebrow">WELCOME TO YOUR WORKSPACE</p>
            <h1>从这里，管理你的资产。</h1>
            <p class="muted">创建管理员后，即可接入云账号、整理域名与服务器，并让 AI 通过 API 协作。</p>
            <ol class="setup-steps"><li><b>01</b><div><strong>检查环境</strong><span>确认 PHP 与扩展、数据目录就绪</span></div></li><li><b>02</b><div><strong>创建管理员</strong><span>设置工作台的登录账号和密码</span></div></li><li><b>03</b><div><strong>连接云账号</strong><span>支持阿里云、腾讯云和 GoDaddy，可稍后添加</span></div></li></ol>
        </div>
        <div class="panel setup-form-panel">
            <!-- 阶段一：环境检查。全部通过才出现创建管理员表单。 -->
            <div id="setup-env-step">
                <h2>环境检查</h2>
                <p class="muted">首次安装先确认运行环境，全部通过后即可创建管理员。</p>
                <ul id="setup-env-list" class="setup-env-list" aria-live="polite">
                    <li class="setup-env-item is-loading"><span class="setup-env-mark">…</span><span class="setup-env-name">正在检查运行环境</span></li>
                </ul>
                <p id="setup-env-error" class="error-text hidden" role="alert"></p>
                <div class="setup-env-actions">
                    <button id="setup-env-retry" type="button" class="btn">重新检查</button>
                    <button id="setup-env-continue" type="button" class="btn btn-primary hidden">开始创建管理员 <svg class="icon" aria-hidden="true"><use href="#icon-arrow"/></svg></button>
                </div>
            </div>
            <!-- 阶段二：创建管理员 -->
            <div id="setup-form-step" class="hidden">
                <h2>创建管理员</h2><p class="muted">环境已就绪，设置管理员账号和密码后自动完成初始化并登录。</p>
                <form id="setup-form" class="settings-form">
                    <label>管理员账号<input id="setup-user" required minlength="3" maxlength="64" autocomplete="username" placeholder="3–64 位字母、数字或 _.@-"></label>
                    <label>登录密码<input id="setup-password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" placeholder="12–72 字节"></label>
                    <p id="setup-error" class="error-text hidden" role="alert"></p>
                    <button id="setup-submit" class="btn btn-primary" type="submit">创建工作台 <svg class="icon" aria-hidden="true"><use href="#icon-arrow"/></svg></button>
                </form>
            </div>
        </div>
    </div>
</section>
