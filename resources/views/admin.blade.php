<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <script src="/config.js"></script>
    
    <link rel="stylesheet" crossorigin href="/assets/admin-new/assets/element-plus.css?v={{$version}}">
    <link rel="stylesheet" crossorigin href="/assets/admin-new/assets/index.css?v={{$version}}">
    
    <script>
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$version}}',
            background_url: '{{$background_url}}',
            logo: '{{$logo}}',
            secure_path: '{{$secure_path}}'
        }
    </script>
</head>

<body>
    <div id="app"></div>
    <script type="module" crossorigin src="/assets/admin-new/assets/index.js?v={{$version}}"></script>
</body>

</html>
