<!DOCTYPE html>
<html lang="zh-CN">
  <head>
    <script>
      window.settings = {
        title: '{{$title}}',
        theme: '{{$theme}}',
        version: '{{$version}}',
        description: '{{$description}}',
        logo: '{{$logo}}',
        theme_config: {!! json_encode($theme_config ?? []) !!}
      };
    </script>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <!-- 工业级客户端防缓存策略 -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>{{!empty($theme_config['site_name']) ? $theme_config['site_name'] : (!empty($title) ? $title : '控制台')}}</title>
    <link rel="icon" href="{{!empty($theme_config['site_logo']) ? $theme_config['site_logo'] : (!empty($logo) ? $logo : '/theme/'.$theme.'/favicon.svg')}}" />
    <!-- 引入运行时外部配置 (兼容 v2board 后台直出与静态托管，defer 异步非阻塞) -->
    <script defer src="/theme/{{$theme}}/config.js"></script>
    <script type="module" crossorigin src="/theme/v2nexus/static/js/index.CJ1r94DF.js"></script>
    <link rel="modulepreload" crossorigin href="/theme/v2nexus/static/js/vendor-core.DKF0hvqw.js">
    <link rel="modulepreload" crossorigin href="/theme/v2nexus/static/js/vendor-icons.BMba0cHf.js">
    <link rel="modulepreload" crossorigin href="/theme/v2nexus/static/js/vendor-utils.B0W_wSPZ.js">
    <link rel="stylesheet" crossorigin href="/theme/v2nexus/static/css/index.BjzyVpje.css">
  </head>
  <body class="bg-nexus-50 text-nexus-900 dark:bg-nexus-950 dark:text-nexus-100 transition-colors duration-200 antialiased selection:bg-blue-500 selection:text-white">
    <div id="app"></div>
  
    {!! $theme_config['custom_html'] ?? '' !!}
  </body>
</html>
