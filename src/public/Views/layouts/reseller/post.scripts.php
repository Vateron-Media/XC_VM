<?php

/**
 * post.scripts.php — JS-функции submitForm() / callbackForm()
 *
 * Подключается footer'ом (include).
 * Выводит <script> блок с формой отправки и обработкой ошибок
 * для каждой reseller-страницы (edit_profile, line, mag, enigma, ticket, user).
 *
 * Зависимости (доступны через bootstrap):
 *   - getPageName()              → текущая страница
 *   - get_defined_constants()    → STATUS_* коды ошибок
 *   - SettingsManager::getAll()  → настройки (pass_length)
 *   - $rPermissions              → минимальные длины логина/пароля
 *
 * @see src/reseller/post.php  (POST handler — вызывается напрямую через nginx)
 */

$_PAGE = getPageName();
$_ERRORS = array();

foreach (get_defined_constants(true)['user'] as $rKey => $rValue) {
    if (substr($rKey, 0, 7) != 'STATUS_') {
    } else {
        $_ERRORS[intval($rValue)] = $rKey;
    }
}
?>
<script>
var rCurrentPage = "<?php echo $_PAGE; ?>";
var rErrors = <?php echo json_encode($_ERRORS); ?>;
function submitForm(rType, rData, rCallback=callbackForm) {
    $.ajax({
        type: "POST",
        url: "post.php?action=" + encodeURIComponent(rType),
        data: rData,
        processData: false,
        contentType: false,
        success: function(rReturn) {
            try {
                var rJSON = $.parseJSON(rReturn);
            } catch (e) {
                var rJSON = {"status": 0, "result": false};
            }
            rCallback(rJSON);
        }
    });
}
function callbackForm(rData) {
    if (rData.location) {
        if (rData.reload) {
            window.location.href = rData.location;
        } else {
            navigate(rData.location);
        }
    } else {
        $(':input[type="submit"]').prop('disabled', false);

        switch (window.rCurrentPage) {
            case "edit_profile":
                switch (window.rErrors[rData.status]) {
                    case "STATUS_INVALID_EMAIL":
                        showError("Please enter a valid email address.");
                        break;

                    case "STATUS_INVALID_PASSWORD":
                        showError("Your password must be at least <?php echo SettingsManager::getAll()['pass_length']; ?> characters long.");
                        break;

                    default:
                        showError("An error occured while processing your request.");
                        break;
                }
                break;

            case "mag":
            case "enigma":
                switch (window.rErrors[rData.status]) {
                    case "STATUS_INVALID_TYPE":
                        showError("This package is not supported.");
                        break;

                    case "STATUS_NO_TRIALS":
                        showError("You cannot generate trials at this time.");
                        break;

                    case "STATUS_INSUFFICIENT_CREDITS":
                        showError("You do not have enough credits to make this purchase.");
                        break;

                    case "STATUS_INVALID_PACKAGE":
                        showError("Please select a valid package.");
                        break;

                    case "STATUS_INVALID_MAC":
                        showError("Please enter a valid MAC address.");
                        break;

                    case "STATUS_EXISTS_MAC":
                        showError("The MAC address you entered is already in use.");
                        break;

                    default:
                        showError("An error occured while processing your request.");
                        break;
                }
                break;

            case "ticket":
                switch (window.rErrors[rData.status]) {
                    case "STATUS_INVALID_DATA":
                        showError("Please ensure you enter both a title and message.");
                        break;

                    default:
                        showError("An error occured while processing your request.");
                        break;
                }
                break;

            case "line":
                switch (window.rErrors[rData.status]) {
                    case "STATUS_INVALID_TYPE":
                        showError("This package is not supported.");
                        break;

                    case "STATUS_NO_TRIALS":
                        showError("You cannot generate trials at this time.");
                        break;

                    case "STATUS_INSUFFICIENT_CREDITS":
                        showError("You do not have enough credits to make this purchase.");
                        break;

                    case "STATUS_INVALID_PACKAGE":
                        showError("Please select a valid package.");
                        break;

                    case "STATUS_INVALID_USERNAME":
                        showError("Username is too short! It must be at least <?php echo $rPermissions['minimum_username_length']; ?> characters long.");
                        break;

                    case "STATUS_INVALID_PASSWORD":
                        showError("Password is too short! It must be at least <?php echo $rPermissions['minimum_password_length']; ?> characters long.");
                        break;

                    case "STATUS_EXISTS_USERNAME":
                        showError("The username you selected already exists. Please use another.");
                        break;

                    default:
                        showError("An error occured while processing your request.");
                        break;
                }
                break;

            case "user":
                switch (window.rErrors[rData.status]) {
                    case "STATUS_INVALID_PASSWORD":
                        showError("Password is too short! It must be at least <?php echo $rPermissions['minimum_password_length']; ?> characters long.");
                        break;

                    case "STATUS_INVALID_USERNAME":
                        showError("Username is too short! It must be at least <?php echo $rPermissions['minimum_username_length']; ?> characters long.");
                        break;

                    case "STATUS_INSUFFICIENT_CREDITS":
                        showError("You do not have enough credits to make this purchase.");
                        break;

                    case "STATUS_INVALID_SUBRESELLER":
                        showError("You are not set up to create subresellers. Please open a ticket.");
                        break;

                    case "STATUS_EXISTS_USERNAME":
                        showError("The username you selected already exists. Please use another.");
                        break;

                    default:
                        showError("An error occured while processing your request.");
                        break;
                }
                break;

            default:
                showError("An error occured while processing your request.");
                break;
        }
    }
}
</script>
