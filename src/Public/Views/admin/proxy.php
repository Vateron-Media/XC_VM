<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo 'style="display: none;"' ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= $language::get('edit_proxy') ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <input type="hidden" name="edit" value="<?= $rServerArr['id']; ?>" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#server-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('details') ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#additional_ips" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-web"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('domains_and_ips') ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#advanced-options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('advanced') ?></span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="server-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="server_name"><?= $language::get('server_name') ?></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="server_name" name="server_name" value="<?= htmlspecialchars($rServerArr['server_name']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="server_ip">Server IP <i title="<?= $language::get('this_ip_will_be_used_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="server_ip" name="server_ip" value="<?= htmlspecialchars($rServerArr['server_ip']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="total_clients">Max Clients <i title="<?= $language::get('maximum_number_of_simultaneous_connections_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="total_clients" name="total_clients" value="<?= htmlspecialchars($rServerArr['total_clients']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="enabled">Enabled <i title="<?= $language::get('utilise_this_server_for_connections_and_streams') ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="enabled" id="enabled" type="checkbox" <?php if ($rServerArr['enabled'] == 1) echo 'checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('next') ?></a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="additional_ips">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="alert alert-info alert-dismissible fade show" role="alert">
                                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                                        <span aria-hidden="true">&times;</span>
                                                    </button>
                                                    By default, clients will be directed to the Server IP on the Details tab. You can add IP's or Domain Names here to force clients to be directed to those instead. If random IP / domain is selected, each client will be directed to a random entry in the list, otherwise the first entry in the list will be used to serve content.
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="ip_field"><?= $language::get('domains_and_ips') ?></label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" id="ip_field" class="form-control" value="">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" id="add_ip" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="domain_name">&nbsp;</label>
                                                    <div class="col-md-8">
                                                        <select id="domain_name" name="domain_name[]" size=6 class="form-control" multiple="multiple">
                                                            <?php
                                                            foreach (explode(',', $rServerArr['domain_name']) as $rIP):
                                                                if (strlen($rIP) > 0):
                                                            ?>
                                                                    <option value="<?= $rIP ?>"><?= $rIP ?></option>
                                                            <?php
                                                                endif;
                                                            endforeach;
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="random_ip"><?= $language::get('serve_random_ip_domain') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="random_ip" id="random_ip" type="checkbox" <?php if ($rServerArr['random_ip'] == 1) echo 'checked '; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="col-md-6" align="right">
                                                        <ul class="list-inline wizard mb-0">
                                                            <li class="list-inline-item">
                                                                <a href="javascript: void(0);" onClick="MoveUp()" class="btn btn-secondary"><i class="mdi mdi-chevron-up"></i></a>
                                                                <a href="javascript: void(0);" onClick="MoveDown()" class="btn btn-secondary"><i class="mdi mdi-chevron-down"></i></a>
                                                                <a href="javascript: void(0)" id="remove_ip" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('prev') ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('next') ?></a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="advanced-options">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="http_broadcast_port">HTTP Port <i title="<?= $language::get('modifying_this_will_not_change_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="http_broadcast_port" name="http_broadcast_port" value="<?= htmlspecialchars($rServerArr['http_broadcast_port']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="https_broadcast_port">HTTPS Ports <i title="<?= $language::get('modifying_this_will_not_change_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="https_broadcast_port" name="https_broadcast_port" value="<?= htmlspecialchars($rServerArr['https_broadcast_port']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="network_guaranteed_speed">Network Speed - Mbps <i title="<?= $language::get('port_speed_to_consider_when_connecting_clients') ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="network_guaranteed_speed" name="network_guaranteed_speed" value="<?= htmlspecialchars($rServerArr['network_guaranteed_speed']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="enable_https">Enable SSL <i title="<?= $language::get('allow_https_connections_you_will_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="enable_https" id="enable_https" type="checkbox" <?php if ($rServerArr['enable_https'] == 1) echo 'checked '; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="enable_geoip">GeoIP Load Balancing <i title="<?= $language::get('route_connections_to_the_nearest_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="enable_geoip" id="enable_geoip" type="checkbox" <?php if ($rServerArr['enable_geoip'] == 1) echo 'checked '; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="col-md-6">
                                                        <select name="geoip_type" id="geoip_type" class="form-control select2" data-toggle="select2">
                                                            <?php foreach (array('high_priority' => 'High Priority', 'low_priority' => 'Low Priority', 'strict' => 'Strict') as $rType => $rText): ?>
                                                                <option <?php if ($rServerArr['geoip_type'] == $rType) echo 'selected '; ?> value="<?= $rType ?>"><?= $rText ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="geoip_countries">GeoIP Countries <i title="<?= $language::get('select_which_countries_should_be_prioritised_to_this_server') ?>" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="geoip_countries[]" id="geoip_countries" class="form-control select2 select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder') ?>">
                                                            <?php
                                                            $selectedCountries = json_decode($rServerArr['geoip_countries'] ?? '[]', true);
                                                            foreach ($rCountries as $country): ?>
                                                                <option <?= in_array($country['id'], $selectedCountries) ? 'selected' : '' ?> value="<?= htmlspecialchars($country['id']) ?>"><?= htmlspecialchars($country['name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('prev') ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <input name="submit_server" id="submit_button" type="submit" class="btn btn-primary" value="Save" />
                                            </li>
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

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
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

    function MoveUp() {
        var rSelected = $('#domain_name option:selected');
        if (rSelected.length) {
            var rPrevious = rSelected.first().prev()[0];
            if ($(rPrevious).html() != '') {
                rSelected.first().prev().before(rSelected);
            }
        }
    }

    function MoveDown() {
        var rSelected = $('#domain_name option:selected');
        if (rSelected.length) {
            rSelected.last().next().after(rSelected);
        }
    }
    $(document).ready(function() {
        $('.select2').select2({
            width: '100%'
        })
        $("#add_ip").click(function() {
            if (($("#ip_field").val()) && ((isValidIP($("#ip_field").val())) || (isValidDomain($("#ip_field").val())))) {
                var o = new Option($("#ip_field").val(), $("#ip_field").val());
                $("#domain_name").append(o);
                $("#ip_field").val("");
            } else {
                $.toast("Please enter a valid IP address or domain name.");
            }
        });
        $("#remove_ip").click(function() {
            $('#domain_name option:selected').remove();
        });
        $("#total_clients").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#http_broadcast_port").inputFilter(function(value) {
            return /^\d*$/.test(value) && (value === "" || parseInt(value) <= 65535);
        });
        $("#https_broadcast_port").inputFilter(function(value) {
            return /^\d*$/.test(value) && (value === "" || parseInt(value) <= 65535);
        });
        $("#network_guaranteed_speed").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("form").submit(function(e) {
            e.preventDefault();
            $("#domain_name option").prop('selected', true);
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("form")[0]));
        });
    });
    <?php if (SettingsManager::getAll()['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
