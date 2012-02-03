<?php

require dirname(__FILE__) . '/I18N.php';
I18n::instance('pt_PT');

?>
<!doctype html>
<html>
	<head>
		<meta charset="utf-8" />
		<title>translate test...</title>
	</head>
	<body>
	<?php echo I18n::translate("I hope it's useful for someone"); ?>
	</body>
</html>