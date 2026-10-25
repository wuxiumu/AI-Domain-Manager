<section id="setup-view" class="view hidden">
    <div class="setup-shell">
        <div class="setup-intro">
            <span class="brand-mark"><svg class="icon" aria-hidden="true"><use href="#icon-grid"/></svg></span>
            <p class="eyebrow">WELCOME TO YOUR WORKSPACE</p>
            <h1>从这里，管理你的资产。</h1>
            <p class="muted">创建管理员后，即可接入云账号、整理域名与服务器，并让 AI 通过 API 协作。</p>
            <ol class="setup-steps"><li><b>01</b><div><strong>创建管理员</strong><span>设置工作台的登录账号和密码</span></div></li><li><b>02</b><div><strong>连接云账号</strong><span>支持阿里云、腾讯云和 GoDaddy，可稍后添加</span></div></li><li><b>03</b><div><strong>开启 AI 协作</strong><span>在产品设置创建有期限、按权限授权的 API 密钥</span></div></li></ol>
        </div>
        <div class="panel setup-form-panel">
            <h2>首次配置</h2><p class="muted">已有配置会自动沿用，不需要重复初始化。</p>
            <form id="setup-form" class="settings-form">
                <label>初始化凭据<input id="setup-key" required autocomplete="off" spellcheck="false" placeholder="粘贴服务器终端显示的初始化凭据"></label>
                <p class="form-help">首次运行 <code>./start.sh</code> 会显示凭据；也可在服务器运行 <code>php scripts/setup-key.php</code> 获取。</p>
                <label>管理员账号<input id="setup-user" required minlength="3" maxlength="64" autocomplete="username" placeholder="3–64 位字母、数字或 _.@-"></label>
                <label>登录密码<input id="setup-password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" placeholder="12–72 字节"></label>
                <label>确认密码<input id="setup-confirm" type="password" required autocomplete="new-password" placeholder="再次输入登录密码"></label>
                <p id="setup-error" class="error-text hidden" role="alert"></p>
                <button id="setup-submit" class="btn btn-primary" type="submit">创建工作台 <svg class="icon" aria-hidden="true"><use href="#icon-arrow"/></svg></button>
            </form>
        </div>
    </div>
</section>
