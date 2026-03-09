<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="stylesheet" href="css/bootstrap-reboot.min.css">
	<link rel="stylesheet" href="css/bootstrap-grid.min.css">
	<link rel="stylesheet" href="css/default-skin.css">
	<link rel="stylesheet" href="css/main.css">
	<link rel="shortcut icon" href="img/favicon.ico">
	<title><?php echo SettingsManager::getAll()['server_name']; ?></title>
</head>
<body class="body" style="padding-bottom: 0 !important;">
	<div class="sign">
		<div class="container">
			<div class="row">
				<div class="col-12">
					<div class="sign__content">
						<form action="./login" class="sign__form" method="post">
							<span class="sign__logo">
                                <img src="images/logo.png" alt="" height="80px">
                            </span>
							<div class="sign__group">
								<input type="text" name="username" class="sign__input" placeholder="Username">
							</div>
							<div class="sign__group">
								<input type="password" name="password" class="sign__input" placeholder="Password">
							</div>
                            <?php if (isset($_STATUS)): ?>
                            <div class="alert alert-danger">
                                <?php echo $rErrors[$_STATUS]; ?>
                            </div>
                            <?php endif; ?>
                            <button class="sign__btn" type="submit">LOGIN</button>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
	<script src="js/jquery-3.5.1.min.js"></script>
	<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>
