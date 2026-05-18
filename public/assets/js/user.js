/**
 * 用户中心客户端交互逻辑
 */

let currentRequestData = null;

async function searchTMDB() {
    const query = document.getElementById('search-input').value.trim();
    if (!query) return;
    
    const resultsDiv = document.getElementById('search-results');
    const loadingDiv = document.getElementById('loading');
    const errorDiv = document.getElementById('error-msg');
    
    resultsDiv.innerHTML = '';
    errorDiv.style.display = 'none';
    loadingDiv.style.display = 'block';

    try {
        const response = await fetch(`api_tmdb.php?q=${encodeURIComponent(query)}`);
        const data = await response.json();
        
        if (data.error) throw new Error(data.error);

        loadingDiv.style.display = 'none';
        
        if (!data.results || data.results.length === 0) {
            resultsDiv.innerHTML = '<div style="grid-column: 1/-1; text-align:center; color:rgba(255,255,255,0.3);">未找到相关结果</div>';
            return;
        }

        data.results.forEach(item => {
            if (item.media_type !== 'movie' && item.media_type !== 'tv') return;
            
            const title = item.title || item.name;
            const date = item.release_date || item.first_air_date || '';
            const year = date ? date.substring(0, 4) : '';
            const poster = item.poster_path ? `https://image.tmdb.org/t/p/w200${item.poster_path}` : '';
            const posterDisplay = poster || 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIzMDAiIGhlaWdodD0iNDUwIj48cmVjdCB3aWR0aD0iMzAwIiBoZWlnaHQ9IjQ1MCIgZmlsbD0iIzIyMiIvPjwvc3ZnPg==';

            const card = document.createElement('div');
            card.className = 'poster-card';
            card.innerHTML = `
                <img src="${posterDisplay}" alt="${title}" loading="lazy">
                <div class="poster-info">
                    <div class="poster-title">${title}</div>
                    <div class="poster-year">${year} ${item.media_type === 'tv' ? '(剧集)' : ''}</div>
                </div>
            `;
            
            card.onclick = () => {
                currentRequestData = {
                    tmdb_id: item.id,
                    title: title + (year ? ` (${year})` : ''),
                    poster_url: poster,
                    media_type: item.media_type
                };
                document.getElementById('confirm-title').innerText = currentRequestData.title;
                document.getElementById('confirm-poster').src = posterDisplay;
                document.getElementById('confirm-date-val').innerText = date || '未知日期';
                document.getElementById('confirm-rating-val').innerText = item.vote_average ? parseFloat(item.vote_average).toFixed(1) : '暂无评分';
                document.getElementById('confirm-overview').innerText = item.overview || '暂无简介内容。';
                document.getElementById('confirm-modal').style.display = 'flex';
            };
            
            resultsDiv.appendChild(card);
        });

    } catch (err) {
        loadingDiv.style.display = 'none';
        errorDiv.innerText = err.message;
        errorDiv.style.display = 'block';
    }
}

function getCsrfToken() {
    return window.AppConfig ? window.AppConfig.csrfToken : '';
}

document.addEventListener('DOMContentLoaded', () => {
    const submitBtn = document.getElementById('submit-req-btn');
    if (submitBtn) {
        submitBtn.onclick = async () => {
            if (!currentRequestData) return;
            const btn = document.getElementById('submit-req-btn');
            btn.disabled = true;
            btn.innerText = '提交中...';

            try {
                const formData = new URLSearchParams();
                formData.append('action', 'submit_request');
                formData.append('csrf_token', getCsrfToken());
                formData.append('tmdb_id', currentRequestData.tmdb_id);
                formData.append('title', currentRequestData.title);
                formData.append('poster_url', currentRequestData.poster_url);
                formData.append('media_type', currentRequestData.media_type);

                const response = await fetch('api_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                
                const data = await response.json();
                displayToast(data.message);
                if (data.status === 'success') {
                    pollUserData();
                }
            } catch (e) {
                displayToast('提交出错');
            } finally {
                btn.disabled = false;
                btn.innerText = '确认求片';
                document.getElementById('confirm-modal').style.display = 'none';
            }
        };
    }
});

async function clearNotifications() {
    showConfirm('清空通知', '确定要清空您所有的站内通知吗？此操作不可恢复。', async () => {
        try {
            const formData = new URLSearchParams();
            formData.append('action', 'clear_notifications');
            formData.append('csrf_token', getCsrfToken());
            
            const response = await fetch('api_user.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            });
            const data = await response.json();
            displayToast(data.message);
            if (data.status === 'success') {
                const notifList = document.querySelector('.notification-list');
                if (notifList) {
                    notifList.innerHTML = '<div style="text-align:center; color:rgba(255,255,255,0.3); padding: 20px;">暂无通知</div>';
                }
                const badge = document.getElementById('notif-badge');
                if (badge) {
                    badge.style.display = 'none';
                    badge.innerText = '0';
                }
            }
        } catch (e) {
            displayToast('操作失败，网络错误');
        }
    });
}

function openNotifications() {
    document.getElementById('notif-modal').style.display = 'flex';
    const badge = document.getElementById('notif-badge');
    if (badge && badge.style.display !== 'none') {
        const formData = new URLSearchParams();
        formData.append('action', 'mark_read');
        formData.append('csrf_token', getCsrfToken());
        
        fetch('api_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        });
        badge.style.display = 'none';
        document.querySelectorAll('.notif-unread').forEach(el => el.classList.remove('notif-unread'));
    }
}

function closeNotifications() {
    document.getElementById('notif-modal').style.display = 'none';
}

async function saveEmailPref(enabled) {
    try {
        const formData = new URLSearchParams();
        formData.append('action', 'save_email_pref');
        formData.append('csrf_token', getCsrfToken());
        formData.append('enabled', enabled ? '1' : '0');

        const response = await fetch('api_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        });
        const data = await response.json();
        if (data.status !== 'success') {
            displayToast('保存设置失败');
        } else {
            displayToast('设置已保存');
        }
    } catch (e) {
        displayToast('网络错误');
    }
}

async function pollUserData() {
    try {
        const formData = new URLSearchParams();
        formData.append('action', 'poll_data');
        formData.append('csrf_token', getCsrfToken());
        
        const response = await fetch('api_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        });
        const data = await response.json();
        
        if (data.status === 'success') {
            document.getElementById('my-requests').innerHTML = data.requests_html;
            
            const notifList = document.querySelector('.notification-list');
            if (notifList) notifList.innerHTML = data.notifications_html;
            
            const badge = document.getElementById('notif-badge');
            if (badge) {
                badge.innerText = data.unread_count;
                badge.style.display = data.unread_count > 0 ? 'block' : 'none';
            }
        }
    } catch (e) {
        console.error("Polling error", e);
    }
}

// 每 8 秒进行一次数据轮询以支持实时同步
setInterval(pollUserData, 8000);

window.onclick = function(event) {
    if (event.target == document.getElementById('notif-modal')) closeNotifications();
    if (event.target == document.getElementById('confirm-modal')) document.getElementById('confirm-modal').style.display = 'none';
}
