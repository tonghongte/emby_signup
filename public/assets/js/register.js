/**
 * 注册页面客户端交互逻辑
 */

function checkFirstVisit() {
    if (!localStorage.getItem('emby_notice_shown')) {
        showUserNotice();
        localStorage.setItem('emby_notice_shown', 'true');
    }
}

function showUserNotice() {
    const notice = document.getElementById('userNotice');
    if (notice) notice.style.display = 'flex';
}

function hideUserNotice() {
    const notice = document.getElementById('userNotice');
    if (notice) {
        notice.style.opacity = '0';
        setTimeout(() => notice.style.display = 'none', 300);
    }
}

function showMessage() {
    const messageBox = document.getElementById('message');
    const messageContent = document.getElementById('msg-text');
    
    if (messageBox && messageContent && messageContent.innerText.trim() !== '') {
        messageBox.style.display = 'flex'; 
        
        setTimeout(function() {
            hideMessage();
        }, 3000);
    }
}

function hideMessage() {
    const messageBox = document.getElementById('message');
    const messageContent = document.getElementById('msg-text');
    if (!messageBox) return;
    
    messageBox.style.opacity = '0';
    setTimeout(() => {
        messageBox.style.display = 'none';
        messageBox.style.opacity = '1';
    }, 300);

    if (messageContent && messageContent.innerText.trim() === '注册完成！') {
        window.location.href = 'user.php';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    checkFirstVisit();
    showMessage();

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                if (overlay.id === 'message') hideMessage();
            }
        });
    });
});
