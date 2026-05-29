<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <script src="/config.js"></script>
    
    <?php
        $css_files = glob(public_path('assets/admin-new/assets/index-*.css'));
        $js_files = glob(public_path('assets/admin-new/assets/index-*.js'));
        $css_url = count($css_files) > 0 ? '/assets/admin-new/assets/' . basename($css_files[0]) : '';
        $js_url = count($js_files) > 0 ? '/assets/admin-new/assets/' . basename($js_files[0]) : '';
    ?>
    
    @if($css_url)
        <link rel="stylesheet" crossorigin href="{{$css_url}}?v={{$version}}">
    @endif
    
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
    
    @if($js_url)
        <script type="module" crossorigin src="{{$js_url}}?v={{$version}}"></script>
    @endif
</body>

</html>
