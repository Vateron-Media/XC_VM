<?php include 'session.php';
include 'functions.php';
if (checkPermissions()) {
} else {
    goHome();
}
if (isset(CoreUtilities::$rRequest['id']) && !($rCode = getCode(CoreUtilities::$rRequest['id']))) {
    exit();
}
$_TITLE = 'Access Code';
include 'header.php'; ?>
<div class="wrapper boxed-layout"
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    } else { ?>
    style="display: none;" <?php } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php if (isset($rCode)) {
                                                echo 'Edit Code';
                                            } else {
                                                echo 'Add Code';
                                            } ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (!(isset($rCode) && getCurrentCode() == $rCode['code'])) {
                } else { ?>
                    <div class="alert alert-warning" role="alert">
                        You are editing the Access Code you're currently using to access the system. Ensure you have set up
                        another access code before disabling or modifying the access rights of this group.
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (!isset($rCode)) {
                            } else { ?>
                                <input type="hidden" name="edit" value="<?php echo $rCode['id']; ?>" />
                            <?php } ?>
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#code-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#groups" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-group mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['groups']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#restrictions" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-hazard-lights mr-1"></i>
                                            <span class="d-none d-sm-inline">Restrictions</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="code-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label" for="code">Access Code <i
                                                            title="Enter a secure access code that cannot be guessed. It needs to be at least 8 characters and should be random."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-9 input-group">
                                                        <input type="text" maxlength="16" class="form-control" id="code"
                                                            name="code"
                                                            value="<?php if (isset($rCode)) {
                                                                        echo htmlspecialchars($rCode['code']);
                                                                    } ?>"
                                                            required data-parsley-trigger="change">
                                                        <div class="input-group-append">
                                                            <button class="btn btn-info waves-effect waves-light"
                                                                onClick="generateCode();" type="button"><i
                                                                    class="mdi mdi-refresh"></i></button>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label" for="type">Access Type <i
                                                            title="Select what access to grant."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-4">
                                                        <select id="type" name="type" class="form-control select2">
                                                            <?php foreach (array('Admin', 'Reseller', 'Ministra', 'Admin API', 'Reseller API', 6 => 'Web Player') as $rID => $rValue) { ?>
                                                                <option
                                                                    <?php if (isset($rCode) && $rCode['type'] == $rID) {
                                                                        echo 'selected';
                                                                    } ?>
                                                                    value="<?php echo $rID; ?>">
                                                                    <?php echo $rValue; ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                    <label class="col-md- 3col-form-label"
                                                        for="enabled">Enabled</label>
                                                    <div class="col-md-2">
                                                        <input name="enabled" id="enabled" type="checkbox"
                                                            <?php if (isset($rCode) && $rCode['enabled'] == 1) {
                                                                echo 'checked';
                                                            } else if (!isset($rCode)) {
                                                                echo 'checked';
                                                            } ?>
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);"
                                                    class="btn btn-secondary"><?php echo $_['next']; ?></a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="groups">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <?php foreach (getMemberGroups() as $rGroup) { ?>
                                                        <div class="col-md-6">
                                                            <div class="custom-control custom-checkbox mt-1">
                                                                <input type="checkbox"
                                                                    class="custom-control-input group-checkbox"
                                                                    id="group-<?php echo $rGroup['group_id']; ?>"
                                                                    data-id="<?php echo $rGroup['group_id']; ?>"
                                                                    name="groups[]"
                                                                    value="<?php echo $rGroup['group_id']; ?>"
                                                                    <?php if (isset($rCode) && in_array($rGroup['group_id'], json_decode($rCode['groups'], true))) {
                                                                        echo ' checked';
                                                                    } ?>>
                                                                <label class="custom-control-label"
                                                                    for="group-<?php echo $rGroup['group_id']; ?>"><?php echo $rGroup['group_name']; ?></label>
                                                            </div>
                                                        </div>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);"
                                                    class="btn btn-secondary"><?php echo $_['prev']; ?></a>
                                            </li>
                                            <li class="list-inline-item">
                                                <a href="javascript: void(0);" onClick="selectAll()"
                                                    class="btn btn-secondary"><?php echo $_['select_all']; ?></a>
                                                <a href="javascript: void(0);" onClick="selectNone()"
                                                    class="btn btn-secondary"><?php echo $_['deselect_all']; ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="restrictions">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="ip_field">Allowed IP
                                                        Addresses <i
                                                            title="Restrict access to specific IP addresses."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" id="ip_field" class="form-control"
                                                            value="">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" id="add_ip"
                                                                class="btn btn-primary waves-effect waves-light"><i
                                                                    class="mdi mdi-plus"></i></a>
                                                            <a href="javascript:void(0)" id="remove_ip"
                                                                class="btn btn-danger waves-effect waves-light"><i
                                                                    class="mdi mdi-close"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="whitelist">&nbsp;</label>
                                                    <div class="col-md-8">
                                                        <select id="whitelist" name="whitelist[]" size="6"
                                                            class="form-control" multiple="multiple">
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <?php if (isset($rCode)) {
                                            foreach (json_decode($rCode['whitelist'], true) as $rIP) { ?>
                                                <option value="<?php echo $rIP; ?>"><?php echo $rIP; ?></option>
                                        <?php }
                                        } ?>
                                        </select>

                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item"><a href="javascript: void(0);" class="btn btn-secondary">Previous</a></li>
                                            <li class="list-inline-item float-right"><input name="submit" type="submit" class="btn btn-primary" value="Save"></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
<script id="scripts">
    var resizeObserver = new ResizeObserver(entries => $(window).scroll());
    $(document).ready(function() {
        resizeObserver.observe(document.body)
        $("form").attr('autocomplete', 'off');
        $(document).keypress(function(event) {
            if (event.which == 13 && event.target.nodeName != "TEXTAREA") return false;
        });
        $.fn.dataTable.ext.errMode = 'none';
        var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
        elems.forEach(function(html) {
            var switchery = new Switchery(html, {
                'color': '#414d5f'
            });
            window.rSwitches[$(html).attr("id")] = switchery;
        });
        setTimeout(pingSession, 30000);
        <?php if (!$rMobile && $rSettings['header_stats']): ?>
            headerStats();
        <?php endif; ?>
        bindHref();
        refreshTooltips();
        $(window).scroll(function() {
            if ($(this).scrollTop() > 200) {
                if ($(document).height() > $(window).height()) {
                    $('#scrollToBottom').fadeOut();
                }
                $('#scrollToTop').fadeIn();
            } else {
                $('#scrollToTop').fadeOut();
                if ($(document).height() > $(window).height()) {
                    $('#scrollToBottom').fadeIn();
                } else {
                    $('#scrollToBottom').hide();
                }
            }
        });
        $("#scrollToTop").unbind("click");
        $('#scrollToTop').click(function() {
            $('html, body').animate({
                scrollTop: 0
            }, 800);
            return false;
        });
        $("#scrollToBottom").unbind("click");
        $('#scrollToBottom').click(function() {
            $('html, body').animate({
                scrollTop: $(document).height()
            }, 800);
            return false;
        });
        $(window).scroll();
        $(".nextb").unbind("click");
        $(".nextb").click(function() {
            var rPos = 0;
            var rActive = null;
            $(".nav .nav-item").each(function() {
                if ($(this).find(".nav-link").hasClass("active")) {
                    rActive = rPos;
                }
                if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
                    $(this).find(".nav-link").trigger("click");
                    return false;
                }
                rPos += 1;
            });
        });
        $(".prevb").unbind("click");
        $(".prevb").click(function() {
            var rPos = 0;
            var rActive = null;
            $($(".nav .nav-item").get().reverse()).each(function() {
                if ($(this).find(".nav-link").hasClass("active")) {
                    rActive = rPos;
                }
                if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
                    $(this).find(".nav-link").trigger("click");
                    return false;
                }
                rPos += 1;
            });
        });
        (function($) {
            $.fn.inputFilter = function(inputFilter) {
                return this.on("input keydown keyup mousedown mouseup select contextmenu drop", function() {
                    if (inputFilter(this.value)) {
                        this.oldValue = this.value;
                        this.oldSelectionStart = this.selectionStart;
                        this.oldSelectionEnd = this.selectionEnd;
                    } else if (this.hasOwnProperty("oldValue")) {
                        this.value = this.oldValue;
                        this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
                    }
                });
            };
        }(jQuery));
        <?php if ($rSettings['js_navigate']): ?>
            $(".navigation-menu li").mouseenter(function() {
                $(this).find(".submenu").show();
            });
            delParam("status");
            $(window).on("popstate", function() {
                if (window.rRealURL) {
                    if (window.rRealURL.split("/").reverse()[0].split("?")[0].split(".")[0] != window.location.href.split("/").reverse()[0].split("?")[0].split(".")[0]) {
                        navigate(window.location.href.split("/").reverse()[0]);
                    }
                }
            });
        <?php endif; ?>
        $(document).keydown(function(e) {
            if (e.keyCode == 16) {
                window.rShiftHeld = true;
            }
        });
        $(document).keyup(function(e) {
            if (e.keyCode == 16) {
                window.rShiftHeld = false;
            }
        });
        document.onselectstart = function() {
            if (window.rShiftHeld) {
                return false;
            }
        }
    });

    function generateCode() {
        var result = '';
        var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        var charactersLength = characters.length;
        for (var i = 0; i < 8; i++) {
            result += characters.charAt(Math.floor(Math.random() * charactersLength));
        }
        $("#code").val(result);
    }

    function selectAll() {
        $(".group-checkbox").each(function() {
            $(this).prop('checked', true);
        });
    }

    function selectNone() {
        $(".group-checkbox").each(function() {
            $(this).prop('checked', false);
        });
    }
    $(document).ready(function() {
        $('.select2').select2({
            width: '100%'
        });
        $('#code').keydown(function(e) {
            var k = e.which;
            var ok = k >= 65 && k <= 90 || // A-Z
                k >= 96 && k <= 105 || // a-z
                k >= 35 && k <= 40 || // arrows
                k == 8 || // Backspaces
                (!e.shiftKey && k >= 48 && k <= 57); // 0-9

            if (!ok) {
                e.preventDefault();
            }
        });
        $("form").submit(function(e) {
            e.preventDefault();
            $("#whitelist option").prop('selected', true);
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("form")[0]));
        });
        $("#add_ip").click(function() {
            if (($("#ip_field").val()) && (isValidIP($("#ip_field").val()))) {
                var o = new Option($("#ip_field").val(), $("#ip_field").val());
                $("#whitelist").append(o);
                $("#ip_field").val("");
            } else {
                $.toast("Please enter a valid IP address.");
            }
        });
        $("#remove_ip").click(function() {
            $('#whitelist option:selected').remove();
        });

        <?php if (!isset($rCode)) echo 'generateCode();'; ?>
    });
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>