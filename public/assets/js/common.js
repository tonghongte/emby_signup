/**
 * 全局共享的现代前端交互逻辑库
 */

/**
 * 弹出高颜值 Toast 提示卡片
 * @param {string} msg 提示消息内容
 * @param {string} type 提示类型 ('success' | 'error' | 'info')
 */
function displayToast(msg, type = 'info') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        container.innerHTML = `<div class="toast-card" id="status-toast"></div>`;
        document.body.appendChild(container);
    }
    
    const toast = document.getElementById('status-toast');
    let icon = 'ℹ️';
    if (type === 'success') {
        icon = '✅';
    } else if (type === 'error') {
        icon = '❌';
    } else {
        if (msg.includes('成功') || msg.includes('已')) {
            icon = '✅';
        } else if (msg.includes('失败') || msg.includes('错误') || msg.includes('出错') || msg.includes('一致') || msg.includes('无效') || msg.includes('为空') || msg.includes('不能为空')) {
            icon = '❌';
        }
    }
    
    toast.innerHTML = `<span style="font-size: 16px;">${icon}</span> <span>${msg}</span>`;
    container.classList.add('show');
    
    // 3秒后自动淡出
    if (window.toastTimeout) clearTimeout(window.toastTimeout);
    window.toastTimeout = setTimeout(() => {
        container.classList.remove('show');
    }, 3000);
}

/**
 * 弹出磨砂玻璃自定义二次确认框
 * @param {string} title 确认框标题
 * @param {string} message 确认框正文内容
 * @param {function} onConfirm 确定回调函数
 */
function showConfirm(title, message, onConfirm) {
    let overlay = document.getElementById('confirm-modal-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'confirm-modal-overlay';
        overlay.className = 'confirm-overlay';
        overlay.innerHTML = `
            <div class="confirm-box">
                <div class="confirm-title" id="confirm-modal-title"></div>
                <div class="confirm-text" id="confirm-modal-text"></div>
                <div class="confirm-buttons">
                    <button class="confirm-btn confirm-btn-cancel" id="confirm-modal-cancel-btn">取消</button>
                    <button class="confirm-btn confirm-btn-ok" id="confirm-modal-ok-btn">确定</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
    }
    
    const titleEl = document.getElementById('confirm-modal-title');
    const textEl = document.getElementById('confirm-modal-text');
    const cancelBtn = document.getElementById('confirm-modal-cancel-btn');
    const okBtn = document.getElementById('confirm-modal-ok-btn');
    
    titleEl.innerText = title;
    textEl.innerText = message;
    overlay.style.display = 'flex';
    
    const cleanup = () => {
        overlay.style.display = 'none';
        // 移除所有侦听器以防止事件累积
        const newCancel = cancelBtn.cloneNode(true);
        const newOk = okBtn.cloneNode(true);
        cancelBtn.parentNode.replaceChild(newCancel, cancelBtn);
        okBtn.parentNode.replaceChild(newOk, okBtn);
    };
    
    document.getElementById('confirm-modal-cancel-btn').onclick = cleanup;
    document.getElementById('confirm-modal-ok-btn').onclick = () => {
        cleanup();
        if (typeof onConfirm === 'function') onConfirm();
    };
}

/**
 * 邀请链接/文本的一键复制助手
 * @param {HTMLElement} btn 触发事件的按钮元素
 * @param {string} text 需要复制的文字
 */
async function copyInviteLink(btn, text) {
    try {
        await navigator.clipboard.writeText(text);
        
        // 动态变更按钮样式与气泡显示
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '✅';
        btn.style.color = '#52B54B';
        displayToast('邀请链接已成功复制到剪贴板！', 'success');
        
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.style.color = '';
        }, 2000);
    } catch (err) {
        displayToast('复制失败，请手动选择复制。', 'error');
    }
}
