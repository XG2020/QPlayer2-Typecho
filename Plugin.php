<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once 'libs/Config.php';
require_once 'libs/cache/Cache.php';

use QPlayer\Cache\Cache;
use QPlayer\Config;
use QPlayer\General_Config;

/**
 * 一款简洁小巧的 HTML5 底部悬浮音乐播放器
 *
 * @package QPlayer2
 * @author MoeShin&XG.GM
 * @version 2.1.0
 * @link https://github.com/XG2020/QPlayer2-Typecho
 */
class QPlayer2_Plugin extends Typecho_Widget implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        if (!extension_loaded('curl')) {
            throw new Typecho_Plugin_Exception(_t('缺少 cURL 拓展'));
        }
        if (!(extension_loaded('openssl') || extension_loaded('mcrypt'))) {
            throw new Typecho_Plugin_Exception(_t('缺少 openssl 或 mcrypt 拓展'));
        }
        Helper::addAction('QPlayer2', 'QPlayer2_Action');
        Typecho_Plugin::factory('Widget_Archive')->footer = array('QPlayer2_Plugin', 'footer');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Exception
     */
    public static function deactivate() {
        Helper::removeAction('QPlayer2');
        $config = Config::getConfig();
        $cacheType = $config->cacheType;
        if ($cacheType != 'none') {
            Cache::UninstallWithConfig($config);
        }
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $action = Typecho_Common::url('action/QPlayer2', Helper::options()->index);
        $general = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'general',
            array(
                'cdn' => _t('使用 jsDelivr CDN 免费加速 js、css 文件'),
                'isRotate' => _t('旋转封面'),
                'isShuffle' => _t('随机播放'),
                'isAutoplay' => _t('自动播放'),
                'isPauseOtherWhenPlay' => _t('在播放时暂停其他媒体'),
                'isPauseWhenOtherPlay' => _t('在其他媒体播放时暂停')
            ),
            array(
                'cdn',
                'isRotate',
                'isShuffle',
                'isPauseOtherWhenPlay',
                'isPauseWhenOtherPlay'
            ),
            _t('常规')
        );
        $form->addInput($general->multiMode());
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'color',
            null,
            '#EE1122',
            _t('主题颜色'),
            _t('默认：<span style="color: #EE1122">#EE1122</span>')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'bitrate',
            array(
                '128' => _t('流畅品质 128K'),
                '192' => _t('清晰品质 192K'),
                '320' => _t('高品质 320K')
            ),
            '320',
            _t('默认音质')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'list',
            null,
            '[{
    "name": "Nightglow",
    "artist": "蔡健雅",
    "audio": "https://cdn.jsdelivr.net/gh/moeshin/QPlayer-res/Nightglow.mp3",
    "cover": "https://cdn.jsdelivr.net/gh/moeshin/QPlayer-res/Nightglow.jpg",
    "lrc": "https://cdn.jsdelivr.net/gh/moeshin/QPlayer-res/Nightglow.lrc"
},
{
    "name": "やわらかな光",
    "artist": "やまだ豊",
    "audio": "https://cdn.jsdelivr.net/gh/moeshin/QPlayer-res/やわらかな光.mp3",
    "cover": "https://cdn.jsdelivr.net/gh/moeshin/QPlayer-res/やわらかな光.jpg"
}]',
            _t('歌曲列表'),
            _t('
支持 JSON 数组，也兼容单个 JSON 对象；具体属性请看 <a href="https://github.com/moeshin/QPlayer2#list-item">这里</a><br>
推荐写成数组，例如：<br>
<code>[{"server": "netease", "type": "playlist", "id": "3136952023"}]</code><br>
也兼容直接写单个对象，例如：私人雷达<br>
<code>{"server": "netease", "type": "playlist", "id": "3136952023"}</code><br>
来引入第三方资源，此功能基于 <a href="https://github.com/metowolf/Meting">Meting</a><br>
<code>server</code>：netease、tencent、baidu、xiami、kugou<br>
<code>type</code>：playlist、song、album、artist<br>
（附：<a target="_blank" href="https://github.com/moeshin/netease-music-dynamic-playlist">网易云动态歌单整理</a>）
')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'cookie',
            null,
            '',
            _t('网易云音乐 Cookie'),
            _t('
如果您是网易云音乐的会员或者使用私人雷达，可以将您的 cookie 的 <code>MUSIC_U</code>
填入此处来获取云盘等付费资源，听歌将不会计入下载次数<br>
<strong>如果不知道这是什么意思，忽略即可</strong>
')
        ));
        /* 内部保活时间戳也会写入插件配置；补一个隐藏字段，避免后台设置页回填时找不到对应 input。 */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Hidden(
            'cookieRefreshedAt',
            null,
            '0'
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'cacheType',
            array(
                'none' => _t('无'),
                'database' => _t('数据库'),
                'memcached' => _t('Memcached'),
                'redis' => _t('Redis')
            ),
            'none',
            _t('缓存类型'),
            _t('缓存歌曲解析信息，降低服务器压力')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'cacheHost',
            null,
            '',
            _t('缓存地址'),
            _t('若使用数据库缓存，请忽略此项。默认：127.0.0.1')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'cachePort',
            null,
            '',
            _t('缓存端口'),
            _t('若使用数据库缓存，请忽略此项。默认，Memcached：11211；Redis：6379')
        ));
        $item = new Typecho_Widget_Helper_Layout();
        $form->addItem($item
            ->html('<a target="_blank" href="' . $action . '?do=flush">
<button type="button" class="btn primary">' . _t('清除缓存') . '</button></a>'));
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 处理配置信息
     *
     * @access public
     * @param array $settings 配置值
     * @param boolean $isInit 是否为初始化
     * @return void
     * @throws Exception
     * @noinspection PhpUnused
     */
    public static function configHandle($settings, $isInit)
    {
        /** @var QPlayer\Cache\Cache $cache */
        if (!$isInit) {
            $config = Config::getConfig();
            $cacheTypeNow = $settings['cacheType'];
            $cacheArgs = array(
                $cacheTypeNow,
                $settings['cacheHost'],
                $settings['cachePort']
            );
            $cacheTypeLast = $config->cacheType;
            $cacheBuild = array('QPlayer\Cache\Cache', 'Build');
            $isNotNoneNow = $cacheTypeNow != 'none';
            if ($cacheTypeNow != $cacheTypeLast) {
                if ($isNotNoneNow) {
                    $cache = call_user_func_array($cacheBuild, $cacheArgs);
                    $cache->install();
                    $cache->test();
                }
                if ($cacheTypeLast != 'none') {
                    Cache::UninstallWithConfig($config);
                }
            } elseif ($isNotNoneNow && $cacheTypeNow != 'database' && self::compareCacheConfig($settings, $config)) {
                $cache = call_user_func_array($cacheBuild, $cacheArgs);
                $cache->test();
            }
        }
        Helper::configPlugin('QPlayer2', $settings);
    }

    private static function compareCacheConfig($now, $last)
    {
        $keys = array('cacheHost', 'cachePort');
        $length = count($keys);
        for ($i = 0; $i < $length; ++$i) {
            $key = $keys[$i];
            if ($now[$key] != $last->$key) {
                return true;
            }
        }
        return false;
    }

    public static function footer()
    {
        require_once 'libs/General_Config.php';
        $config = Config::getConfig();
        $general = new General_Config($config);
        if ($general->getBool('cdn')) {
            /* 指向自建 fork 仓库 XG2020/QPlayer2-Typecho（含本地已做的 js/css 修改）。
               该仓库暂无 release tag，只有 main 分支，故用 @main 引用；
               改动 assets 后需到 https://purge.jsdelivr.net/gh/XG2020/QPlayer2-Typecho@main/ 刷新缓存，
               或打 tag 后把 @main 换成 @版本号获得永久缓存 */
            $prefix = 'https://cdn.jsdelivr.net/gh/XG2020/QPlayer2-Typecho@main/assets';
        } else {
            $prefix = Typecho_Common::url('QPlayer2/assets', Helper::options()->pluginUrl);
        }
        echo '<script src="' . $prefix . '/QPlayer.min.js"></script>';
        echo '<script src="' . $prefix . '/QPlayer-plugin.js"></script>';
        echo '<link rel="stylesheet" href="' . $prefix . '/QPlayer.min.css">';
?>
<script>
(function () {
    var q = window.QPlayer;
    q.isRotate = <?php echo $general->getBoolString('isRotate'); ?>;
    q.isShuffle = <?php echo $general->getBoolString('isShuffle'); ?>;
    q.isAutoplay = <?php echo $general->getBoolString('isAutoplay'); ?>;
    q.isPauseOtherWhenPlay = <?php echo $general->getBoolString('isPauseOtherWhenPlay'); ?>;
    q.isPauseWhenOtherPlay = <?php echo $general->getBoolString('isPauseWhenOtherPlay'); ?>;
    q.$(function () {
        var q = QPlayer;
        var plugin = q.plugin;
        var list = <?php $config->list(); ?>;
        if (list && !Array.isArray(list)) {
            list = [list];
        }
        plugin.api = "<?php echo Typecho_Common::url('action/QPlayer2', Helper::options()->index); ?>";
        plugin.setList(list || []);
        q.setColor("<?php $config->color(); ?>");
    });
    /* 网易云 cookie 保活：不依赖播放行为，任何页面访问都异步 ping 一次 keepalive。
       前端 6h 节流，服务端 CookieKeeper 再按 24h 限频，对页面性能无影响 */
    try {
        var kaKey = 'QPlayer2KeepAlive';
        var kaLast = +localStorage.getItem(kaKey) || 0;
        if (Date.now() - kaLast > 216e5) {
            localStorage.setItem(kaKey, Date.now());
            var kaUrl = "<?php echo Typecho_Common::url('action/QPlayer2', Helper::options()->index); ?>?do=keepalive";
            if (navigator.sendBeacon) navigator.sendBeacon(kaUrl);
            else fetch(kaUrl, {method: 'POST', keepalive: true}).catch(function () {});
        }
    } catch (e) {}
})();
</script>
<?php
    }
}
