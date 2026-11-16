<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Генератор UTM и QR · Каргономика</title>
    <?php wp_head(); ?>
</head>
<body class="cargo-standalone">
<?php (new \CargoUTM\Workspace())->render($guest); ?>
<?php wp_footer(); ?>
</body>
</html>
