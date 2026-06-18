# Changelog

本 Fork 相对上游 [onelxzy/emby_signup](https://github.com/onelxzy/emby_signup) 的新增内容。

## [Fork] - 2026-06-18

### 邀请码申请
- 新增公开申请页 `request_invite.php`：用户填邮箱 + 图形验证码（SVG，无需 GD 扩展）提交申请。
- 后台「邀请申请」标签：一键生成并发送邀请码、忽略、删除；列出邮箱/模板/时间/状态。
- 收到新申请时可邮件通知管理员（复用「接收新求片/邀请申请通知」开关）。

### 多模板账号
- 后台「模板管理」标签：新增/删除/启用/停用/改名模板（名称 + Emby 用户 ID）。
- 生成邀请码、用户申请邀请码时可选择模板；注册时按邀请码绑定的模板克隆权限，未绑定回退默认。
- 邮件模板支持 `{template}` 占位符（连同 `{code}`、`{link}`）。
- 移除系统设置里的单一「模板用户 ID」字段，改由模板管理维护。

### 部署
- 新增 GHCR 推送 workflow `.github/workflows/ghcr-publish.yml`（push main / release / 手动触发）。
- 新增 Portainer 用 `docker-compose.yml` 与部署文档 `DEPLOY.md`。
