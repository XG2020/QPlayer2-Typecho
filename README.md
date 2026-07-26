# QPlayer2 for Typecho

一款简洁小巧的 HTML5 底部悬浮音乐播放器 Typecho 插件。

本仓库 fork 自 [moeshin/QPlayer2-Typecho](https://github.com/moeshin/QPlayer2-Typecho)，由 [XG.GM](https://www.xggm.top) 维护，包含若干功能增强与样式调整，并作为 jsDelivr CDN 的资源源仓库使用。

## 相比原版的改动

- **网易云 Cookie 自动续期**：新增 `libs/CookieKeeper.php`，每 24 小时自动调用网易云官方接口 `login/token/refresh` 为 `MUSIC_U` 续期，并把新 cookie 持久化写回插件配置。只要博客保持有播放请求，cookie 不再过期，无需手动重新抓取
- **CDN 指向本仓库**：开启「使用 jsDelivr CDN」后，js/css 从 `cdn.jsdelivr.net/gh/XG2020/QPlayer2-Typecho@main/assets` 加载，与本仓库内修改后的资源保持一致（原版指向 moeshin 仓库，无法包含自定义修改）
- **js/css 样式与行为微调**：`assets/` 下的 `QPlayer.min.js`、`QPlayer.min.css`、`QPlayer-plugin.js` 为修改后的版本

## 环境要求

- Typecho 1.x
- PHP 拓展：`curl`、`openssl`（或 `mcrypt`）
- 可选：`bcmath`（网易云 weapi 加密使用随机密钥，无此拓展时降级为固定密钥）

## 安装

1. 下载本仓库，文件夹重命名为 `QPlayer2`
2. 放入 Typecho 的 `usr/plugins/` 目录
3. 后台「控制台 → 插件」中启用 QPlayer2
4. 进入插件设置，按需配置

## 配置说明

| 配置项 | 说明 |
| --- | --- |
| 使用 jsDelivr CDN | 开启后 js/css 走本仓库的 jsDelivr 加速；关闭则加载插件目录本地文件 |
| 旋转封面 / 随机播放 / 自动播放 | 播放器行为开关 |
| 在播放时暂停其他媒体 / 在其他媒体播放时暂停 | 与页面上其他音视频的互斥策略 |
| 主题颜色 | 播放器主色，默认 `#EE1122` |
| 默认音质 | 128K / 192K / 320K |
| 歌曲列表 | JSON 数组，支持直链歌曲与第三方平台资源（基于 [Meting](https://github.com/metowolf/Meting)） |
| 网易云音乐 Cookie | 填入包含 `MUSIC_U` 的 cookie 可播放云盘/会员资源，填入后自动续期接管 |
| 缓存类型 | 无 / 数据库 / Memcached / Redis，缓存歌曲解析结果降低服务器压力 |

### 歌曲列表示例

```json
[
  {
    "name": "歌曲名",
    "artist": "歌手",
    "audio": "https://example.com/song.mp3",
    "cover": "https://example.com/cover.jpg",
    "lrc": "https://example.com/song.lrc"
  },
  { "server": "netease", "type": "playlist", "id": "3136952023" }
]
```

`server` 支持：`netease`、`tencent`、`baidu`、`xiami`、`kugou`；`type` 支持：`playlist`、`song`、`album`、`artist`。

### 网易云 Cookie 自动续期

1. 登录网页版网易云音乐，从浏览器开发者工具中复制 cookie（需包含 `MUSIC_U`，建议连同 `__csrf` 一起）
2. 粘贴到插件设置的「网易云音乐 Cookie」输入框保存
3. 之后插件会在网易云播放请求时自动续期，并把新 cookie 写回配置，无需再人工维护

> 注意：在网易云 App / 网页端主动退出登录会使服务端吊销该 cookie，此时需重新抓取填入。

## 资源更新流程（维护者）

修改 `assets/` 下的 js/css 后：

1. 推送到本仓库 `main` 分支
2. 逐个访问 `https://purge.jsdelivr.net/gh/XG2020/QPlayer2-Typecho@main/assets/<文件名>` 刷新 jsDelivr 缓存（分支引用的边缘缓存最长 12 小时、浏览器缓存 7 天）

或者打 tag 发布 Release，并把 `Plugin.php` 中 CDN 前缀的 `@main` 改为 `@<tag>`，即可获得永久缓存、免 purge。

## 致谢

- [moeshin/QPlayer2-Typecho](https://github.com/moeshin/QPlayer2-Typecho) — 原版插件
- [metowolf/Meting](https://github.com/metowolf/Meting) — 多平台音乐 API 框架

## 许可

遵循原仓库开源许可。
