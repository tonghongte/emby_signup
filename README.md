# Emby Signup 邀请注册与求片管理系统

[![PHP Version](https://img.shields.io/badge/PHP-8.0+-blue.svg?style=flat-square&logo=php)](https://www.php.net/)
[![SQLite Version](https://img.shields.io/badge/SQLite-3-green.svg?style=flat-square&logo=sqlite)](https://www.sqlite.org/)
[![License](https://img.shields.io/badge/License-MIT-purple.svg?style=flat-square)](LICENSE)

> 💡 本项目由 Gemini 辅助完成。

一个基于 PHP + SQLite 的 Emby 媒体服务器自助注册与求片管理系统。允许管理员发放一次性**邀请码**，用户可通过 Web 页面自主创建新账号。系统会自动复制预设模板用户的配置和权限，并核销邀请码。

> ⚠️ **安全警告**：本程序涉及 Emby 核心 API 操作及数据库读写。**请务必配置好目录权限，并禁止公网直接访问配置文件目录**，谨防 Token 或数据库泄露。

---

## ✨ 功能特性

- **🛡️ 邀请码机制**：新增 SQLite 数据库支持，实现邀请码的生成、验证与自动核销（一次性使用）。
- **🎬 求片功能**：[新增] 用户可调用 TMDB 进行影片搜索并发出求片申请。
- **📧 邮件与站内信**：[新增] 集成原生 Socket SMTP 服务，不仅支持一键发送邀请邮件，更支持在求片审核后自动发送站内信和邮件通知用户。
- **👮 统一后台**：[新增] 管理员可一键审批求片，支持后台可视化管理邀请码，以及直接封禁或彻底删除 Emby 异常用户。
- **📂 配置分离**：配置文件、数据库与前端完全隔离，支持在后台管理面板中可视化直接修改和保存系统所有配置项。
- **📝 自动开户**：通过 Emby API 自动完成用户创建，并支持注册成功后自动执行登录，无缝跳转到个人中心。
- **©️ 权限克隆**：完美复制模板用户的配置（包括媒体库访问权限、播放设置等）。
- **🔐 安全校验**：支持密码强度校验（长度、数字、字母组合），且全接口强制绑定 CSRF 跨站防御。
- **💻 简洁界面**：暗黑拟物风格响应式设计，适配 PC 与移动端，支持激活链接自动填充邀请码。

---

## 📂 目录结构

建议的生产环境部署结构如下：

```text
emby_signup/
├── config/                 # 存放配置和 SQLite 数据库 (建议禁止外部直接访问)
│   ├── config.php          # 环境变量及系统全局配置文件
│   ├── database.php        # 自动环境修复兼容代理
│   ├── email_template.txt  # 邀请邮件正文文本模版
│   └── invite_codes.sqlite # SQLite 数据库文件 (自动生成)
├── src/                    # 业务类库与核心组件层 (外部无法直接访问)
│   ├── Auth.php            # 安全拦截器、Session 会话及 CSRF 验证
│   ├── ConfigHelper.php    # 配置文件修改与自动兼容注入器
│   ├── Database.php        # SQLite3 操作类 (WAL 模式支持)
│   ├── EmbyApi.php         # Emby API 交互及事务处理核心
│   └── Mailer.php          # Socket SMTP 原生发信套接字驱动
└── public/                 # Web 服务器的物理 DocumentRoot 根目录
    ├── assets/             # 全局静态资源 (CSS / JS)
    ├── index.php           # 门户引导重定向
    ├── login.php           # 系统登录页
    ├── register.php        # 激活注册页
    ├── user.php            # 用户求片门户
    ├── admin.php           # 管理员后台
    ├── api_user.php        # 用户侧 API 接口
    └── api_tmdb.php        # TMDB 搜索代理接口
```

---

## 🔧 直接部署

1. **环境准备**：确保服务器安装了 PHP 8.0+ 以及 SQLite3 扩展。
2. **下载源码**：下载本项目至服务器。
3. **配置与权限设置**：
   为防止任意代码执行 (RCE) 或配置文件泄露等隐患，强烈建议采用**最小权限原则**配置目录权限：
   - 将 Nginx 或 Apache 的网站根目录 (DocumentRoot) 指向 `./public` 目录，**禁止**将父级目录暴露给公网。
   - **写权限 (仅 config)**：PHP 运行进程（如 `www-data`）**仅**需要对 `./config` 目录有写入 (`r+w`) 权限（用于写入 SQLite 数据库和保存可视化配置）。
   - **只读权限 (主目录)**：本项目其余所有目录及文件（包括 `public` 目录下的 PHP 文件）对 PHP 运行进程应设置为**只读 (`r`)**。
     ```bash
     # 示例权限配置 (Linux)
     chown -R root:root /var/www/emby_signup
     chmod -R 755 /var/www/emby_signup
     # 仅给予 config 目录给 Web 进程(例如 www-data)的写入权限
     chown -R www-data:www-data /var/www/emby_signup/config
     ```
4. **初始化**：
   直接访问 `/admin.php`（默认用户名：`admin`，密码：`password`），在后台 **「系统设置」** 页面直接可视化保存并填入 Emby API、模板用户 ID、SMTP 账号等参数即可，无需手动修改任何代码。

---

## 🐳 Docker 部署 

### 方式一：零配置启动 (推荐 ⭐️)

本项目支持**零配置直接启动**。你无需在命令行里逐一输入繁琐的环境变量，启动后可以直接在网页后台系统设置面板里可视化配置，极为方便。

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

如果更倾向于通过环境变量进行部署，也可以使用 `.env` 配置文件来批量传参，避免在 `docker run` 命令中写出超长的一堆 `-e` 配置。

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

## 📝 部署贴士

* 📌 **模板账号权限**：请务必在 Emby 控制台中事先规划并新建好用于克隆权限的**模板用户**！新注册的用户会完美克隆该模板账号的所有媒体库读取与播放权限。
* 🔒 **默认安全**：默认的 `.htaccess` 已经设置了 `Require all denied`，即便没有严格限制 DocumentRoot，外部也无法直接通过 URL 访问你的 `config` 文件夹或下载 SQLite 数据库文件。
* 🔑 **密码修改**：生产部署就绪后，请务必立刻修改默认的 `admin` / `password` 后台账户密码。
* 🛠️ **环境诊断**：配置就绪后，可根据需要删除 `public/check.php` 文件以防外部探知系统内部环境。

---

