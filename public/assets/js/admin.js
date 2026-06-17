/**
 * 管理后台客户端交互逻辑
 */

function getCsrfToken() {
    return window.AppConfig ? window.AppConfig.csrfToken : '';
}

function getEmailTemplate() {
    return window.AppConfig ? window.AppConfig.emailTemplate : '';
}

function openEmailModal(code, link) {
    const modal = document.getElementById('email-modal');
    const bodyInput = document.getElementById('email_body');
    const emailInput = document.getElementById('email_to');
    let content = getEmailTemplate().replace(/{code}/g, code).replace(/{link}/g, link);
    bodyInput.value = content; 
    emailInput.value = ''; 
    modal.style.display = 'flex'; 
    emailInput.focus();
}

function closeEmailModal() { 
    document.getElementById('email-modal').style.display = 'none'; 
}

async function sendEmail() {
    const email = document.getElementById('email_to').value;
    const body = document.getElementById('email_body').value;
    const btn = document.getElementById('btn-send-mail');
    
    if (!email) { 
        displayToast('请输入邮箱地址', 'error'); 
        return; 
    }

    const original = btn.innerText; 
    btn.disabled = true;
    btn.innerText = '发送中...';

    try {
        const formData = new URLSearchParams();
        formData.append('ajax', '1');
        formData.append('csrf_token', getCsrfToken());
        formData.append('action', 'send_email');
        formData.append('email', email);
        formData.append('body', body);

        const response = await fetch("admin.php", {
            method: "POST", 
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: formData.toString()
        });
        
        if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
        const data = await response.json();
        
        displayToast(data.message, data.status);
        if (data.status === 'success') {
            closeEmailModal();
        }
    } catch (error) {
        console.error('Email sending error:', error);
        displayToast('网络请求失败', 'error');
    } finally {
        btn.disabled = false; 
        btn.innerText = original;
    }
}

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}

function switchTab(index) {
    document.querySelectorAll('.tab-btn').forEach((btn, i) => {
        if(i < 5) btn.classList.toggle('active', i === index); // 5 tabs
    });
    document.querySelectorAll('.tab-content').forEach((content, i) => {
        content.classList.toggle('active', i === index);
    });
    history.replaceState(null, null, '#tab-' + index);
}

document.addEventListener("DOMContentLoaded", () => {
    if (window.innerWidth <= 768) {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) sidebar.classList.add('collapsed');
    }
    const hash = location.hash;
    if (hash && hash.startsWith('#tab-')) {
        const idx = parseInt(hash.replace('#tab-', ''));
        if (!isNaN(idx) && idx >= 0 && idx < 5) {
            switchTab(idx);
        }
    }
});

function copyInviteLink(btn, text) {
    const original = btn.innerHTML;
    const temp = document.createElement('textarea');
    temp.value = text; temp.style.position = 'fixed'; temp.style.left = '-9999px';
    document.body.appendChild(temp); temp.select();
    
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { console.error(e); }
    document.body.removeChild(temp);

    if (ok) {
        btn.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        btn.classList.add('success');
        setTimeout(() => { btn.innerHTML = original; btn.classList.remove('success'); }, 1500); 
    } else {
        displayToast('复制失败，请手动复制'); 
    }
}

async function ajaxAction(action, data = {}) {
    const formData = new URLSearchParams();
    formData.append('ajax', '1');
    formData.append('csrf_token', getCsrfToken());
    formData.append('action', action);
    for (const key in data) formData.append(key, data[key]);

    try {
        const res = await fetch("admin.php", {
            method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: formData.toString()
        });
        const result = await res.json();
        displayToast(result.message);
        if (result.status === 'success') {
            if (action === 'save_settings') {
                setTimeout(() => window.location.replace(window.location.href), 1000);
            } else {
                pollAdminData();
            }
        }
    } catch (error) {
        displayToast('网络请求失败');
    }
}

function saveSettings() {
    const form = document.getElementById('settings-form');
    const formData = new FormData(form);
    const dataObj = {};
    formData.forEach((value, key) => dataObj[key] = value);
    
    // Checkboxes might be missing if unchecked, enforce false
    if(!dataObj.enable_admin_email) dataObj.enable_admin_email = '0';
    if(!dataObj.enable_user_email) dataObj.enable_user_email = '0';
    if(!dataObj.enable_autoban) dataObj.enable_autoban = '0';
    if(!dataObj.enable_autodel_req) dataObj.enable_autodel_req = '0';
    
    ajaxAction('save_settings', dataObj);
}

async function pollAdminData() {
    try {
        const formData = new URLSearchParams();
        formData.append('ajax', '1');
        formData.append('csrf_token', getCsrfToken());
        formData.append('action', 'poll_data');

        const response = await fetch("admin.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: formData.toString()
        });
        const data = await response.json();
        if (data.status === 'success') {
            const inviteTbody = document.querySelector('#tab-0 table tbody');
            if (inviteTbody) inviteTbody.innerHTML = data.invite_codes_html;
            
            const reqTbody = document.querySelector('#tab-1 table tbody');
            if (reqTbody) reqTbody.innerHTML = data.requests_html;
            
            const userTbody = document.querySelector('#tab-2 table tbody');
            if (userTbody) userTbody.innerHTML = data.users_html;

            const inviteReqTbody = document.querySelector('#tab-3 table tbody');
            if (inviteReqTbody) inviteReqTbody.innerHTML = data.invite_requests_html;

            const inviteTitle = document.querySelector('#tab-0 .section-title');
            if (inviteTitle) inviteTitle.innerText = `邀请码 (${data.invite_codes_count})`;

            const userTitle = document.querySelector('#tab-2 .section-title');
            if (userTitle) userTitle.innerText = `用户列表 (${data.users_count})`;

            const inviteReqTitle = document.querySelector('#tab-3 .section-title');
            if (inviteReqTitle) inviteReqTitle.innerText = `邀请申请 (${data.invite_requests_count})`;
        }
    } catch (e) {
        console.error("Polling error", e);
    }
}

// 每 8 秒进行一次增量轮询以保持数据的绝对一致与实时同步
setInterval(pollAdminData, 8000);

window.onclick = function(event) {
    const emailOverlay = document.getElementById('email-modal');
    if (event.target === emailOverlay) {
        closeEmailModal();
    }
}
