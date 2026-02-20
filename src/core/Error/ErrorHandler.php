<?php

/**
 * XC_VM — Обработка ошибок
 *
 * Функции generateError() и generate404() для отображения ошибок.
 *
 * generateError() — показывает ошибку с кодом и описанием (в debug-режиме)
 *                    или стандартную 404 страницу (в production).
 *
 * generate404()   — стандартная страница «404 Not Found» под видом nginx.
 *
 * Зависимости:
 *   $rErrorCodes (глобальный массив из ErrorCodes.php)
 *   $rSettings   (глобальный массив настроек, загружается позже)
 */

/**
 * Генерация страницы ошибки.
 *
 * В debug-режиме ($rSettings['debug_show_errors'] === true) отображает
 * стилизованную страницу с кодом ошибки и описанием.
 * В production — вызывает generate404().
 *
 * @param string    $rError Код ошибки (ключ из $rErrorCodes)
 * @param bool      $rKill  Завершить выполнение после вывода (default: true)
 * @param int|null  $rCode  HTTP-код ответа (null = 404)
 */
function generateError($rError, $rKill = true, $rCode = null) {
    global $rErrorCodes;
    global $rSettings;

    if (isset($rSettings['debug_show_errors']) && $rSettings['debug_show_errors']) {
        $rErrorDescription = (isset($rErrorCodes[$rError]) ? $rErrorCodes[$rError] : '');
        $rStyle = '*{-webkit-box-sizing:border-box;box-sizing:border-box}body{padding:0;margin:0}#notfound{position:relative;height:100vh}#notfound .notfound{position:absolute;left:50%;top:50%;-webkit-transform:translate(-50%,-50%);-ms-transform:translate(-50%,-50%);transform:translate(-50%,-50%)}.notfound{max-width:520px;width:100%;line-height:1.4;text-align:center}.notfound .notfound-404{position:relative;height:200px;margin:0 auto 20px;z-index:-1}.notfound .notfound-404 h1{font-family:Montserrat,sans-serif;font-size:236px;font-weight:200;margin:0;color:#211b19;text-transform:uppercase;position:absolute;left:50%;top:50%;-webkit-transform:translate(-50%,-50%);-ms-transform:translate(-50%,-50%);transform:translate(-50%,-50%)}.notfound .notfound-404 h2{font-family:Montserrat,sans-serif;font-size:28px;font-weight:400;text-transform:uppercase;color:#211b19;background:#fff;padding:10px 5px;margin:auto;display:inline-block;position:absolute;bottom:0;left:0;right:0}.notfound p{font-family:Montserrat,sans-serif;font-size:14px;font-weight:300;text-transform:uppercase}@media only screen and (max-width:767px){.notfound .notfound-404 h1{font-size:148px}}@media only screen and (max-width:480px){.notfound .notfound-404{height:148px;margin:0 auto 10px}.notfound .notfound-404 h1{font-size:86px}.notfound .notfound-404 h2{font-size:16px}}';
        echo '<html><head><title>XC_VM - Debug Mode</title><link href="https://fonts.googleapis.com/css?family=Montserrat:200,400,700" rel="stylesheet"><style>' . $rStyle . '</style></head><body><div id="notfound"><div class="notfound"><div class="notfound-404"><h1>XC_VM</h1><h2>' . $rError . '</h2><br/></div><p>' . $rErrorDescription . '</p></div></div></body></html>';

        if ($rKill) {
            exit();
        }
    } else {
        if ($rKill) {
            if (!$rCode) {
                generate404();
            } else {
                http_response_code($rCode);
                exit();
            }
        }
    }
}

/**
 * Генерация стандартной страницы 404 (имитация nginx).
 *
 * @param bool $rKill Завершить выполнение после вывода (default: true)
 */
function generate404($rKill = true) {
    echo '<html>' . "\r\n" . '<head><title>404 Not Found</title></head>' . "\r\n" . '<body>' . "\r\n" . '<center><h1>404 Not Found</h1></center>' . "\r\n" . '<hr><center>nginx</center>' . "\r\n" . '</body>' . "\r\n" . '</html>' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->';
    http_response_code(404);

    if ($rKill) {
        exit();
    }
}
