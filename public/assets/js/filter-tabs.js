'use strict';

/** One visual and keyboard convention for compact choices across the workspace. */
window.ChoiceTabs = (() => {
    const limit = 8;
    const entries = new Map();
    const names = {'health-kind':'资产类型','health-status':'健康状态','rel-ip':'IP / 服务器','ip-version':'地址类型','ip-status':'健康状态','dm-provider':'服务商','rec-type':'记录类型'};
    const colorFor = value => ({healthy:'healthy',unhealthy:'unhealthy',stale:'stale',unchecked:'neutral',domain:'tencent',ip:'healthy',IPv4:'tencent',IPv6:'violet',A:'tencent',AAAA:'violet',CNAME:'stale',MX:'healthy',confirmed:'healthy',dns:'tencent',unlinked:'stale'})[value] || (value.startsWith('aliyun')?'aliyun':value.startsWith('tencent')?'tencent':value.startsWith('godaddy')?'godaddy':'neutral');
    function syncSelect(select) {
        if (!select.id) return;
        let entry = entries.get(select);
        if (!entry) {
            const wrapper = document.createElement('div');
            wrapper.className = 'choice-control domain-filter-group' + (select.closest('.form-grid,.rec-form-grid') ? ' form-choice' : '');
            const title = document.createElement('span');title.className='domain-filter-label';title.textContent=names[select.id] || select.getAttribute('aria-label') || '选项';
            const choices = document.createElement('div');choices.className='domain-filter-tabs';choices.setAttribute('role','group');choices.setAttribute('aria-label',title.textContent);
            wrapper.append(title,choices);select.insertAdjacentElement('afterend',wrapper);
            entry={wrapper,choices,signature:''};entries.set(select,entry);
            choices.addEventListener('click', event => {
                const button=event.target.closest('[data-choice-value]');
                if (!button || select.disabled) return;
                event.preventDefault();
                select.value=button.dataset.choiceValue;
                select.dispatchEvent(new Event('change',{bubbles:true}));
                sync();
            });
            select.addEventListener('change',()=>syncSelect(select));
        }
        const options=Array.from(select.options);
        const compact=options.length<=limit;
        select.hidden=compact;entry.wrapper.hidden=!compact;
        select.classList.toggle('choice-native',!compact);
        if (!compact) return;
        const signature=JSON.stringify(options.map(o=>[o.value,o.textContent,o.disabled]));
        if (signature!==entry.signature) {
            const fragment=document.createDocumentFragment();
            options.forEach(option=>{
                const button=document.createElement('button');button.type='button';button.className='domain-filter-tab';button.textContent=option.textContent;button.dataset.choiceValue=option.value;button.dataset.color=colorFor(option.value);fragment.appendChild(button);
            });
            entry.choices.replaceChildren(fragment);entry.signature=signature;
        }
        Array.from(entry.choices.children).forEach((button,index)=>{
            button.setAttribute('aria-pressed',String(button.dataset.choiceValue===select.value));
            button.disabled=select.disabled||options[index].disabled;
        });
    }
    function sync() {
        document.querySelectorAll('select').forEach(syncSelect);
        for (const [select,entry] of entries) { if(!select.isConnected){entry.wrapper.remove();entries.delete(select);} }
        document.querySelectorAll('.rel-quick-tabs button,.rel-view-tabs button').forEach(button=>{
            button.classList.add('domain-filter-tab');
            button.dataset.color=colorFor(button.dataset.relTab || button.dataset.domainView || '');
        });
    }
    let scheduled=false;
    const schedule=()=>{if(scheduled)return;scheduled=true;queueMicrotask(()=>{scheduled=false;sync();});};
    new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true});
    document.addEventListener('click',schedule);
    document.addEventListener('change',schedule);
    sync();
    return {sync,limit};
})();
