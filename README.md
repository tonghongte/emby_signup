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

## 🔧 直接部署 (直接部署遵循“最小权限原则”)

1. **环境准备**：确保服务器安装了 PHP 7.4+ 以及 SQLite3 扩展。
2. **下载源码**：下载本项目至服务器。
3. **配置与权限设置 (🔒 最小权限原则)**：
   为防止任意代码执行 (RCE) 或越权写入等安全隐患，强烈建议采用**最小权限原则**配置目录权限：
   - 将 Nginx 或 Apache 的网站根目录 (DocumentRoot) 指向 `./public` 目录，**禁止**将父级目录暴露给公网。
   - **写权限 (仅 config)**：PHP 进程（如 `www-data`）**仅**需要对 `./config` 目录有写入 (`r+w`) 权限（用于写入 SQLite 数据库和保存可视化配置）。
   - **只读权限 (主目录)**：本项目其余所有目录及文件（包括 `public` 目录下的 php 文件）对 PHP 进程应设置为**只读 (`r`)**。
     ```bash
     # 示例权限配置 (Linux)
     chown -R root:root /var/www/emby_signup
     chmod -R 755 /var/www/emby_signup
     # 仅给予 config 目录给 Web 用户(例如 www-data)的写入权限
     chown -R www-data:www-data /var/www/emby_signup/config
     ```
4. **初始化**：
   你可以直接访问 `/admin.php`（默认用户名：`admin`，密码：`password`），然后在后台管理面板的 **「系统设置」** 页面中直接可视化地完成 Emby API、SMTP 邮件等各项设置，无需手动编辑任何代码。

---

## 🐳 Docker 部署 

### 方式一：零配置启动 (推荐 ⭐️)

本项目已支持**零配置直接启动**。你无需在命令行里逐一输入繁琐的环境变量，启动后可以直接在网页的系统设置面板里可视化配置，极为方便。

1. **创建本地映射目录**（用于持久化保存数据库和配置）
   ```bash
   mkdir -p config
   ```
2. **直接运行容器**
   ```bash
   docker run -d \
     --name emby-signup \
     --restart always \
     -p 8888:80 \
     -v $(pwd)/config:/var/www/html/config \
     onelxzy/emby_signup:latest
   ```
3. **访问并配置**
   直接访问 `http://IP:8888/admin.php`，使用默认账号（用户名 `admin`，密码 `password`）登录，在 **「系统设置」** 页面直接进行可视化配置保存。

---

### 方式二：使用 `.env` 配置文件启动

如果你更倾向于通过传统的环境变量进行部署，也可以使用 `.env` 配置文件来优雅地批量传参，避免在 `docker run` 命令中写出超长的一堆 `-e` 配置。

1. **下载或创建 `.env` 配置文件**
   复制项目根目录下的 `.env.example` 并重命名为 `.env`，填入你的配置信息：
   ```bash
   cp .env.example .env
   # 编辑 .env 填入你的 Emby API / SMTP 配置
   nano .env
   ```
2. **通过 `--env-file` 运行容器**
   ```bash
   docker run -d \
     --name emby-signup \
     --restart always \
     -p 8888:80 \
     -v $(pwd)/config:/var/www/html/config \
     --env-file .env \
     onelxzy/emby_signup:latest
   ```

---

### 💡 部署贴士：
* 📌 **模板账号权限**：请务必在 Emby 控制台中事先规划好用于克隆权限的**模板用户**！新注册的用户会完美克隆该模板账号的所有媒体库读取与播放权限。
* 🔒 **默认安全**：默认的 `.htaccess` 已经设置了 `Require all denied`，即便你将整站暴露，外部也无法直接通过 URL 访问你的 `config` 文件夹或下载 SQLite 数据库文件。

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
