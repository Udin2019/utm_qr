<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Политика обработки персональных данных · Каргономика</title>
</head>
<body>
<main>
<h1>Политика обработки персональных данных</h1>
<p>Редакция <?php echo esc_html($policy['version']); ?></p>
<p><?php echo esc_html($documents['operator']); ?></p>
<?php foreach (preg_split('/\R\s*\R/u',trim($policy['text'])) as $paragraph): ?>
<p><?php echo nl2br(esc_html($paragraph),false); ?></p>
<?php endforeach; ?>
</main>
</body>
</html>
