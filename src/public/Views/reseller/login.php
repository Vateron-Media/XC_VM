<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <title data-id="login">XC_VM | <?= $language::get('login') ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <link rel="shortcut icon" href="assets/images/favicon.ico">
        <link href="assets/css/icons.css" rel="stylesheet" type="text/css" />
        <?php if (isset($_COOKIE['theme']) && $_COOKIE['theme'] == 1): ?>
        <link href="assets/css/bootstrap.dark.css" rel="stylesheet" type="text/css" />
        <link href="assets/css/app.dark.css" rel="stylesheet" type="text/css" />
        <?php else: ?>
        <link href="assets/css/bootstrap.css" rel="stylesheet" type="text/css" />
        <link href="assets/css/app.css" rel="stylesheet" type="text/css" />
        <?php endif; ?>
        <link href="assets/css/extra.css" rel="stylesheet" type="text/css" />
        <style>
        .g-recaptcha {
            display: inline-block;
        }
        .vertical-center {
            margin: 0;
            position: absolute;
            top: 50%;
            -ms-transform: translateY(-50%);
            transform: translateY(-50%);
            width: 100%;
        }
        </style>
    </head>
    <body class="bg-animate<?php if (isset($_COOKIE['hue']) && strlen($_COOKIE['hue']) > 0 && in_array($_COOKIE['hue'], array_keys($rHues))): ?>-<?= $_COOKIE['hue'] ?><?php endif; ?>">
        <div class="body-full navbar-custom">
            <div class="account-pages vertical-center">
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-md-8 col-lg-6 col-xl-5">
                            <div class="text-center w-75 m-auto">
                                <span><img src="assets/images/logo.png" height="80px" alt=""></span>
                                <p class="text-muted mb-4 mt-3"></p>
                            </div>
                            <?php if (isset($_STATUS) && $_STATUS == STATUS_FAILURE): ?>
                            <div class="alert alert-danger alert-dismissible bg-danger text-white border-0 fade show" role="alert">
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                <?= $language::get('login_message_1') ?>
                            </div>
                            <?php elseif (isset($_STATUS) && $_STATUS == STATUS_INVALID_CODE): ?>
                            <div class="alert alert-danger alert-dismissible bg-danger text-white border-0 fade show" role="alert">
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                <?= $language::get('login_message_2') ?>
                            </div>
                            <?php elseif (isset($_STATUS) && $_STATUS == STATUS_NOT_RESELLER): ?>
                            <div class="alert alert-danger alert-dismissible bg-danger text-white border-0 fade show" role="alert">
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                <?= $language::get('login_message_3') ?>
                            </div>
                            <?php elseif (isset($_STATUS) && $_STATUS == STATUS_DISABLED): ?>
                            <div class="alert alert-danger alert-dismissible bg-danger text-white border-0 fade show" role="alert">
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                <?= $language::get('login_message_4') ?>
                            </div>
                            <?php elseif (isset($_STATUS) && $_STATUS == STATUS_INVALID_CAPTCHA): ?>
                            <div class="alert alert-danger alert-dismissible bg-danger text-white border-0 fade show" role="alert">
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                <?= $language::get('login_message_5') ?>
                            </div>
                            <?php endif; ?>
                            <form action="./login" method="POST" data-parsley-validate="">
                                <div class="card">
                                    <div class="card-body p-4">
                                        <input type="hidden" name="referrer" value="<?= $referrer ?>" />
                                        <div class="form-group mb-3" id="username_group">
                                            <label for="username"><?= $language::get('username') ?></label>
                                            <input class="form-control" autocomplete="off" type="text" id="username" name="username" required data-parsley-trigger="change" placeholder="">
                                        </div>
                                        <div class="form-group mb-3">
                                            <label for="password"><?= $language::get('password') ?></label>
                                            <input class="form-control" autocomplete="off" type="password" required data-parsley-trigger="change" id="password" name="password" placeholder="">
                                        </div>
                                        <?php if ($rSettings['recaptcha_enable']): ?>
                                        <h5 class="auth-title text-center" style="margin-bottom:0;">
                                            <div class="g-recaptcha" data-callback="recaptchaCallback" id="verification" data-sitekey="<?= $rSettings['recaptcha_v2_site_key'] ?>"></div>
                                        </h5>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="form-group mb-0 text-center">
                                    <button style="border:0" class="btn btn-info <?php if (isset($_COOKIE['hue']) && strlen($_COOKIE['hue']) > 0 && in_array($_COOKIE['hue'], array_keys($rHues))): ?>bg-animate-<?= $_COOKIE['hue'] ?><?php else: ?>bg-animate-info<?php endif; ?> btn-block" type="submit" id="login_button" name="login"<?php if ($rSettings['recaptcha_enable']): ?> disabled<?php endif; ?>><?= $language::get('login') ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script src="assets/js/vendor.min.js"></script>
        <script src="assets/libs/parsleyjs/parsley.min.js"></script>
        <script src="assets/js/app.min.js"></script>
        <?php if ($rSettings['recaptcha_enable']): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
        <?php endif; ?>
        <script>
        function recaptchaCallback() {
            $('#login_button').removeAttr('disabled');
        };
        </script>
    </body>
</html>
