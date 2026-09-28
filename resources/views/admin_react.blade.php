<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <link rel="icon" type="image/png" href="/assets/admin-react/favicon.png" />
    <link rel="stylesheet" crossorigin href="/assets/admin-react/assets/index.css?v=20260929_3">
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
        };
        if (window.location.search.includes('theme=')) {
            window.history.replaceState(null, '', window.location.pathname + window.location.hash);
        }
    </script>
</head>

<body>
    <div id="app"></div>
    <script type="module" crossorigin src="/assets/admin-react/assets/index.js?v=20260929_3"></script>
</body>

</html>
