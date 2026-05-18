# Emby Signup 邀请注册系统

![PHP](https://img.shields.io/badge/PHP-7.4+-blue.svg) ![SQLite](https://img.shields.io/badge/SQLite-3-green.svg)

一个基于 PHP + SQLite 的 Emby 媒体服务器自助注册系统。允许管理员发放一次性**邀请码**，用户可通过 Web 页面自主创建新账号。系统会自动复制预设模板用户的配置和权限，并核销邀请码。

> ⚠️ **安全警告**：本程序涉及 Emby 核心 API 操作及数据库读写。**请务必配置好目录权限，并禁止公网直接访问配置文件目录**，谨防 Token 或数据库泄露。

---

## ✨ 功能特性

- **🛡️ 邀请码机制**：新增 SQLite 数据库支持，实现邀请码的生成、验证与自动核销（一次性使用）。
- **🎬 求片与 TMDB 墙**：[新增] 用户通过统一门户登录后，可直达专属个人界面，搜片时无缝调用 TMDB 生成精美海报墙并带简介展示。
- **👮 统一双轨后台**：[新增] 通过 Emby 管理员权限实现后台无缝识别，管理员可直观审核求片，以及封禁/删除异常用户。
- **🚫 自动封禁清理**：[新增] 系统支持对长期未活跃的 Emby 用户进行自动探测和封禁。
- **📧 邮件与站内信**：[新增] 集成 SMTP，不仅支持注册邀请邮件，更支持求片审核结果的站内信与邮件双重异步通知。
- **📂 配置分离与可视化**：所有后台设置全部支持可视化 UI 保存并自动重写配置，配置与前端分离。
- **📝 自动开户**：通过 Emby API 自动完成用户创建。
- **©️ 权限克隆**：完美复制模板用户的配置（包括媒体库访问权限、播放设置等）。
- **💻 响应式界面**：极致的毛玻璃质感、微动画以及兼容移动端的折叠侧边栏设计。

---

## 📂 目录结构

建议的部署结构如下，**请确保 Web 服务器根目录指向 `public` 文件夹**：

```text
/www
├── config/                 # [核心] 存放配置和数据库，严禁外部访问
│   ├── config.php          # 配置文件 (需配置 Emby API 及 SMTP 信息)
│   ├── database.php        # 数据库操作类
│   ├── email_template.txt  # [新增] 邀请邮件内容模板 (可自定义)
│   └── invite_codes.sqlite # SQLite 数据库文件 (自动生成)
└── public/                 # [前端] Web 服务器根目录
    ├── index.php           # 主页面
    ├── register.php        # 注册页面入口
    └── admin.php           # 邀请码管理后台
```

---

## 🔧 直接部署

1. 环境准备：确保服务器安装了 PHP 7.4+ 以及 SQLite3 扩展。
2. 下载源码：下载本项目至服务器。
3. 配置修改：
   
    ●根据注释修改 `./config/config.php`，填入 Emby API 信息及管理员账号。

    ●[新增] 在配置文件中填入您的 SMTP 邮箱服务信息（支持 QQ、Gmail、126 等）。

    ●[新增] 可按需编辑 `./config/email_template.txt` 自定义发送给用户的邮件文案。
4. 权限设置：PHP 需要对 `./config` 目录有写入权限；将 Nginx 或 Apache 的网站根目录指向 `./public` 目录。
## 🐳 Docker 部署 

### 快速启动

1. **下载镜像**
   ```bash
   docker pull onelxzy/emby_signup:latest
   ```

2. **创建配置文件目录**
   在宿主机创建一个目录用于保存配置（例如 `config`），以便重启容器后配置不丢失。
   ```bash
   mkdir -p config
   ```

3. **启动容器**
   运行以下命令（请根据实际情况修改环境变量）：
> **💡 提示**：你也可以**不设置**任何 `-e` 环境变量直接启动。容器启动后会自动在挂载的 `./config` 目录下生成 `config.php`，你可以直接编辑该文件填入配置，然后重启容器即可。
   ```bash
   docker run -d \
     --name emby-signup \
     --restart always \
     -p 8888:80 \
     -v $(pwd)/config:/var/www/html/config \
     -e EMBY_BASE_URL="http://192.168.1.10:8096" \
     -e EMBY_API_TOKEN="你的EmbyAPI密钥" \
     -e EMBY_TEMPLATE_USER_ID="复制权限的模板用户ID" \
     -e SITE_LOGIN_URL="http://192.168.1.10:8096" \
     -e ADMIN_USERNAME="admin" \
     -e ADMIN_PASSWORD="password" \
     -e SMTP_HOST="smtp.qq.com" \
     -e SMTP_PORT="465" \
     -e SMTP_SECURE="ssl" \
     -e SMTP_USERNAME="your_email@qq.com" \
     -e SMTP_PASSWORD="your_smtp_password" \
     -e SMTP_FROM_NAME="Emby Admin" \
     onelxzy/emby_signup:latest
   ```

### 环境变量说明

| 变量名 | 必填 | 默认值 | 说明 |
| :--- | :--- | :--- | :--- |
| `EMBY_BASE_URL` | ✅ | - | Emby 服务器地址 |
| `EMBY_API_TOKEN` | ✅ | - | 在 Emby 控制台 -> 高级 -> API 密钥中生成 |
| `EMBY_TEMPLATE_USER_ID` | ✅ | - | 用于复制权限/配置的模板用户 ID |
| `SITE_LOGIN_URL` | ✅ | - | 注册成功后跳转的登录地址 |
| `ADMIN_USERNAME` | ❌ | `admin` | 后台管理面板用户名 (备用) |
| `ADMIN_PASSWORD` | ❌ | `password` | 后台管理面板密码 (备用) |
| `SMTP_HOST` | ✅ | - | SMTP 服务器 (如 `smtp.qq.com`) |
| `SMTP_PORT` | ❌ | `465` | SMTP 端口 |
| `SMTP_SECURE` | ❌ | `ssl` | 加密方式 (`ssl` 或 `tls`) |
| `SMTP_USERNAME` | ✅ | - | 发信邮箱账号 |
| `SMTP_PASSWORD` | ✅ | - | 邮箱密码或应用专用授权码 |
| `SMTP_FROM_NAME` | ❌ | `Emby Admin` | 邮件发件人显示名称 |
| `EMAIL_SUBJECT` | ❌ | `Emby 媒体服务器邀请函` | 邀请邮件的主题 |
| `TMDB_API_KEY` | ❌ | - | [新增] 用于求片海报墙的 TMDB API 密钥 |
| `TMDB_PROXY` | ❌ | - | [新增] 访问 TMDB 的 HTTP 代理 (如 `tcp://127.0.0.1:7890`) |
| `ENABLE_REQUEST_EMAIL_NOTIFY` | ❌ | `false` | [新增] 是否开启求片处理的邮件通知 |
| `ENABLE_AUTO_BAN` | ❌ | `false` | [新增] 是否开启超时未活动用户自动封禁 |
| `AUTO_BAN_DAYS` | ❌ | `30` | [新增] 自动封禁的无活动天数阈值 |
   
📌 **模板账号权限必须事先设置好！** 新用户会完整继承该用户的 Emby 权限设置，请谨慎选择。

---

## 🌟 页面预览
> 与Emby风格适配的响应式自助注册页面，支持 PC 与移动端

<img width="1897" height="1080" alt="image" src="https://github.com/user-attachments/assets/7c3bed91-bc81-44fa-a40b-d41f10046283" />

> 邀请码后台管理界面

<img width="1920" height="1080" alt="image" src="https://github.com/user-attachments/assets/72f68bad-d079-49c7-9192-4f071220ffe6" />

<img width="1920" height="1080" alt="image" src="https://github.com/user-attachments/assets/8878ad7e-91b3-4a4d-883b-91e7384c505e" />


---

## 🌍 项目地址

[https://github.com/onelxzy/emby_signup](https://github.com/onelxzy/emby_signup)

---

## 🚀 License

MIT License
