# 🚀 Emby Signup 部署指南 (GHCR + Portainer)

本文档说明如何使用 GitHub Container Registry (GHCR) 镜像，通过 Portainer Stack 部署本项目，并绑定自定义域名。

---

## 1. 镜像与 Workflow 说明

仓库内有两条镜像构建流水线（均在「发布 Release」时触发）：

| Workflow 文件 | 推送目标 | 镜像名称 | 标签 | 触发条件 |
| --- | --- | --- | --- | --- |
| `.github/workflows/docker-publish.yml` | Docker Hub | `onelxzy/emby_signup` | `{version}`、`latest` | `release: published` |
| `.github/workflows/ghcr-publish.yml` | **GHCR** | `ghcr.io/<owner>/emby_signup` | `{version}`、`latest` | `release: published` + 手动 `workflow_dispatch` |

- 两者均构建 `linux/amd64` + `linux/arm64` 双架构。
- GHCR Workflow 使用内置 `GITHUB_TOKEN`，无需额外配置 Secret；只需仓库 Settings → Actions 权限允许写 packages。
- `<owner>` 即你的 GitHub 用户名/组织名（本仓库为 `tonghongte`，最终镜像 `ghcr.io/tonghongte/emby_signup`）。

> 发布首个 Release 后，到仓库 **Packages** 页面把该 package 的可见性设为 **Public**，否则 Portainer 拉取私有镜像需要登录凭证。

---

## 2. 环境变量 (Environment Variables)

全部变量**可选**。留空即可启动，启动后在后台「系统设置」可视化填写。
environment 只在「持久化卷里还没有 config.php」时作为默认值生效；一旦在 UI 保存过设置，配置以字面量写入卷中，后续 environment 不再覆盖。

| 变量 | 说明 | 示例 / 默认 |
| --- | --- | --- |
| `EMBY_BASE_URL` | Emby 服务器内网/可达地址，容器需能访问 | `http://192.168.1.10:8096` |
| `EMBY_API_TOKEN` | Emby 后台生成的 API Key | （留空，后台填） |
| `EMBY_TEMPLATE_USER_ID` | 权限克隆模板用户的 ID | （留空，后台填） |
| `SITE_LOGIN_URL` | 注册成功后跳转的 Emby 公网登录页 | `https://emby.example.com` |
| `ADMIN_USERNAME` | 后台登录用户名 | `admin` |
| `ADMIN_PASSWORD` | 后台登录密码（**务必修改**） | `please_change_me` |
| `SMTP_HOST` | 发信 SMTP 服务器 | `smtp.qq.com` |
| `SMTP_PORT` | SMTP 端口 | `465` (SSL) / `587` (TLS) |
| `SMTP_SECURE` | 加密方式 | `ssl` / `tls` / `none` |
| `SMTP_USERNAME` | 发件邮箱账号 | `you@qq.com` |
| `SMTP_PASSWORD` | 邮箱密码或授权码 | （留空，后台填） |
| `SMTP_FROM_NAME` | 发件人显示名 | `Emby Admin` |
| `TMDB_API_KEY` | TMDB 求片海报墙 Key | （可选） |
| `TMDB_PROXY` | TMDB HTTP 代理 | `tcp://127.0.0.1:7890` |
| `TMDB_LANGUAGE` | TMDB 返回语言 | `zh-CN` |
| `ENABLE_AUTO_BAN` | 非活跃用户自动封禁 | `false` |
| `AUTO_BAN_DAYS` | 判定非活跃天数 | `30` |

---

## 3. 通讯埠 (Ports)

- **容器内固定监听 `80`**（基础镜像 `php:8.2-apache`，DocumentRoot 已指向 `public/`）。
- compose 中 `8888:80` 表示「宿主机 8888 → 容器 80」。宿主机端口可任意改，例如 `19280:80`。
- 若前面有反向代理，宿主机端口只需对反代可达，**不必**对公网开放。
- 容器本身不需要额外端口（SQLite 本地文件，SMTP 为出站连接）。

| 端口 | 用途 | 是否必须对公网开放 |
| --- | --- | --- |
| 容器 `80` | Web 服务 (Apache) | 否（由宿主机映射决定） |
| 宿主机 `8888` | 映射端口，供访问或反代回源 | 视部署而定 |

---

## 4. 自定义域名绑定

整体链路：`用户浏览器 → DNS → 反向代理 (TLS) → 容器:8888`

### 4.1 DNS 设置
1. 在域名服务商添加一条 A 记录（IPv6 用 AAAA）：
   - 主机记录：`signup`（即 `signup.example.com`）
   - 记录值：你服务器的公网 IP
2. 等待解析生效（`nslookup signup.example.com` 验证）。
3. 注意：**注册门户域名**与 **Emby 本体域名**通常分开。`SITE_LOGIN_URL` 填 Emby 的公网地址，反代这里代理的是注册门户容器。

### 4.2 反向代理（推荐，自动 HTTPS）

**Caddy（最简单，自动签发证书）** — `Caddyfile`：
```caddy
signup.example.com {
    reverse_proxy 127.0.0.1:8888
}
```

**Nginx** — server 块：
```nginx
server {
    listen 443 ssl http2;
    server_name signup.example.com;

    ssl_certificate     /etc/letsencrypt/live/signup.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/signup.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8888;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;   # 重要：让应用感知 HTTPS
    }
}
# 80 → 443 跳转
server {
    listen 80;
    server_name signup.example.com;
    return 301 https://$host$request_uri;
}
```

**Nginx Proxy Manager（图形化）**：新增 Proxy Host → Domain 填 `signup.example.com`，Forward Hostname/IP 填容器或宿主机 IP，端口 `8888`，SSL 页签申请 Let's Encrypt 证书并开启 Force SSL。

> ⚠️ HTTPS 提示：后台生成的「邀请注册链接」依据请求的 `Host` 与是否 HTTPS 拼接。务必让反代转发 `Host` 头并设置 `X-Forwarded-Proto`，否则生成的链接可能是 `http://`。若仍为 http，可在后台「系统设置」中直接以 https 公网地址手动校正相关配置。

---

## 5. 快速开始 (Quick Start)

> 目标：从 Portainer 贴配置到成功登录后台。

**前置**：一台装好 Docker + Portainer 的服务器；项目已在 GitHub 发布过 Release（GHCR 已生成镜像，且 package 设为 Public）。

1. **进入 Stacks** — Portainer 左侧菜单 → **Stacks** → **Add stack**，命名如 `emby-signup`。

2. **粘贴 Compose** — 选择 **Web editor**，粘贴本仓库 `docker-compose.yml` 内容。
   - 把 `image:` 改成你的命名空间：`ghcr.io/<你的GitHub用户名>/emby_signup:latest`。
   - 按需修改宿主机端口 `8888:80`。

3. **填写环境变量** — 两种方式任选：
   - 直接在 Web editor 的 `environment:` 段改值；或
   - 用 Portainer 下方 **Environment variables** 面板逐条填（适合敏感信息）。
   - 最少先改 `ADMIN_PASSWORD`，其余可留空后台再填。

4. **部署** — 点 **Deploy the stack**，等待容器状态变为 `running`。
   - 拉取私有镜像失败时：Registries 里添加 `ghcr.io` 凭证（用户名 = GitHub 名，密码 = 有 `read:packages` 权限的 PAT），或把 package 设为 Public。

5. **首次访问后台** — 浏览器打开 `http://服务器IP:8888/admin.php`：
   - 默认账号 `admin` / 你设置的密码登录。
   - 进入 **「系统设置」**，填入 Emby Base URL、API Token、模板用户 ID、SMTP 等，保存。

6. **验证功能**：
   - 打开 `http://服务器IP:8888/register.php` → 「没有邀请码？申请一个」测试申请流程。
   - 后台 **「邀请申请」** 标签查看申请，点「发送邀请码」验证 SMTP。
   - 后台 **「邀请码管理」** 生成邀请码 → 用注册页完整走一遍注册。

7. **（可选）绑定域名** — 按第 4 节配置 DNS + 反向代理，之后通过 `https://signup.example.com` 访问。

8. **安全收尾**：
   - 确认已改默认密码。
   - 删除或限制 `public/check.php`（环境诊断工具）防止信息泄露。
   - 确认 `config` 卷正常持久化（重建容器后数据仍在）。

---

完成。门户已上线，可对外发放邀请码。
