<div class="wrapper boxed-layout-ext" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                        } else {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php if (isset($rPackage)) {
                                                echo $language::get('edit_package');
                                            } else {
                                                echo $language::get('add_package');
                                            } ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (!isset($rPackage)) {
                            } else { ?>
                                <input type="hidden" name="edit" value="<?= $rPackage['id']; ?>" />
                            <?php } ?>
                            <input type="hidden" name="bouquets_selected" id="bouquets_selected" value="" />
                            <input type="hidden" name="groups_selected" id="groups_selected" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#package-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('details'); ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('options') ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#groups" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-group mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('groups'); ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#bouquets" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-flower-tulip mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('bouquets'); ?></span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="package-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="package_name"><?php echo $language::get('package_name'); ?></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="package_name" name="package_name" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['package_name']) : ''; ?>">
                                                    </div>
                                                </div>
                                                <h4 class="m-t-0 header-title mb-4">Trial Package</h4>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_trial"><?= $language::get('enabled') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="is_trial" id="is_trial" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_trial'] == 1) {
                                                                                                                    echo 'checked ';
                                                                                                                } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="trial_credits"><?= $language::get('credit_cost') ?></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="trial_credits" name="trial_credits" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['trial_credits']) : '0'; ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="trial_duration"><?= $language::get('duration') ?></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="trial_duration" name="trial_duration" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['trial_duration']) : '0'; ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <select name="trial_duration_in" id="trial_duration_in" class="form-control select2" data-toggle="select2">
                                                            <?php foreach (array($language::get('hours') => 'hours', $language::get('days') => 'days') as $rText => $rOption) { ?>
                                                                <option <?php if (isset($rPackage) && $rPackage['trial_duration_in'] == $rOption) {
                                                                            echo 'selected ';
                                                                        } ?>value="<?php echo $rOption; ?>"><?php echo $rText; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <h4 class="m-t-0 header-title mb-4">Standard Package</h4>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_official"><?= $language::get('enabled') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="is_official" id="is_official" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_official'] == 1) {
                                                                                                                        echo 'checked ';
                                                                                                                    } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="official_credits"><?= $language::get('credit_cost') ?></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="official_credits" name="official_credits" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['official_credits']) : '0'; ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="official_duration"><?= $language::get('duration') ?></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="official_duration" name="official_duration" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['official_duration']) : '0'; ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <select name="official_duration_in" id="official_duration_in" class="form-control select2" data-toggle="select2">
                                                            <?php foreach (array($language::get('hours') => 'hours', $language::get('days') => 'days', $language::get('months') => 'months', $language::get('years') => 'years') as $rText => $rOption) { ?>
                                                                <option <?php if (isset($rPackage) && $rPackage['official_duration_in'] == $rOption) {
                                                                            echo 'selected ';
                                                                        } ?>value="<?php echo $rOption; ?>"><?php echo $rText; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $language::get('next'); ?></a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="options">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_mag"><?= $language::get('mag_device') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="is_mag" id="is_mag" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_mag'] == 1) {
                                                                                                                echo 'checked ';
                                                                                                            } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="is_e2"><?= $language::get('enigma_device') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="is_e2" id="is_e2" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_e2'] == 1) {
                                                                                                            echo 'checked ';
                                                                                                        } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_line"><?= $language::get('standard_line') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="is_line" id="is_line" type="checkbox" <?php if (isset($rPackage) ? $rPackage['is_line'] == 1 : true) {
                                                                                                                echo 'checked ';
                                                                                                            } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="is_isplock"><?= $language::get('lock_to_isp') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="is_isplock" id="is_isplock" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_isplock'] == 1) {
                                                                                                                        echo 'checked ';
                                                                                                                    } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_restreamer"><?= $language::get('restreamer') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="is_restreamer" id="is_restreamer" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_restreamer'] == 1) {
                                                                                                                            echo 'checked ';
                                                                                                                        } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="check_compatible"><?= $language::get('verify_compatibility') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="check_compatible" id="check_compatible" type="checkbox" <?php if (isset($rPackage) ? $rPackage['check_compatible'] == 1 : true) {
                                                                                                                                    echo 'checked ';
                                                                                                                                } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="force_server_id"><?= $language::get('forced_connection') ?></label>
                                                    <div class="col-md-8">
                                                        <select name="force_server_id" id="force_server_id" class="form-control select2" data-toggle="select2">
                                                            <option <?php if (!isset($rPackage) || intval($rPackage['force_server_id']) == 0) {
                                                                        echo 'selected ';
                                                                    } ?><?= $language::get('value0disabled') ?></option>
                                                            <?php foreach ($rServers as $rServer) { ?>
                                                                <option <?php if (isset($rPackage) && intval($rPackage['force_server_id']) == intval($rServer['id'])) {
                                                                            echo 'selected ';
                                                                        } ?>value="<?php echo $rServer['id']; ?>"><?php echo htmlspecialchars($rServer['server_name']); ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="max_connections"><?php echo $language::get('max_connections'); ?></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="max_connections" name="max_connections" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['max_connections']) : '1'; ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="output_formats"><?php echo $language::get('access_output'); ?></label>
                                                    <div class="col-md-8">
                                                        <?php foreach (LineRepository::getOutputFormats() as $rOutput) { ?>
                                                            <div class="checkbox form-check-inline">
                                                                <input data-size="large" type="checkbox"
                                                                    id="output_formats_<?php echo $rOutput['access_output_id']; ?>"
                                                                    name="output_formats[]"
                                                                    value="<?php echo $rOutput['access_output_id']; ?>" <?php if (isset($rPackage) && in_array($rOutput['access_output_id'], json_decode($rPackage['output_formats'] ?? '[]', true) ?? [])) echo ' checked'; ?> />
                                                                <label for="output_formats_<?php echo $rOutput['access_output_id']; ?>"> <?php echo $rOutput['output_name']; ?> </label>
                                                            </div>
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="forced_country"><?= $language::get('forced_country') ?></label>
                                                    <div class="col-md-8">
                                                        <select name="forced_country" id="forced_country" class="form-control select2" data-toggle="select2">
                                                            <?php foreach ($rCountries as $rCountry) { ?>
                                                                <option <?php if (isset($rPackage) && $rPackage['forced_country'] == $rCountry['id']) {
                                                                            echo 'selected ';
                                                                        } ?>value="<?php echo $rCountry['id']; ?>"><?php echo $rCountry['name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $language::get('prev'); ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $language::get('next'); ?></a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="groups">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <table id="datatable-groups" class="table table-striped table-borderless mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th class="text-center"><?php echo $language::get('id'); ?></th>
                                                                <th><?php echo $language::get('group_name'); ?></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach (GroupService::getAll() as $rGroup) {
                                                                if ($rGroup['is_reseller']) { ?>
                                                                    <tr<?php if (isset($rPackage) && in_array($rGroup['group_id'], json_decode($rPackage['groups'], true))) {
                                                                            echo " class='selected selectedfilter ui-selected'";
                                                                        } ?>>
                                                                        <td class="text-center"><?php echo $rGroup['group_id']; ?></td>
                                                                        <td><?php echo $rGroup['group_name']; ?></td>
                                                                        </tr>
                                                                <?php }
                                                            } ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $language::get('prev'); ?></a>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <a href="javascript: void(0);" onClick="toggleGroups()" class="btn btn-info"><?php echo $language::get('toggle_groups'); ?></a>
                                                <a href="javascript: void(0);" class="btn btn-secondary nextb"><?php echo $language::get('next'); ?></a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="bouquets">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <table id="datatable-bouquets" class="table table-striped table-borderless mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th class="text-center"><?php echo $language::get('id'); ?></th>
                                                                <th><?php echo $language::get('bouquet_name'); ?></th>
                                                                <th class="text-center"><?php echo $language::get('streams'); ?></th>
                                                                <th class="text-center"><?php echo $language::get('movies'); ?></th>
                                                                <th class="text-center"><?php echo $language::get('series'); ?></th>
                                                                <th class="text-center"><?php echo $language::get('stations'); ?></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach (BouquetService::getAllSimple() as $rBouquet) { ?>
                                                                <tr<?php if (isset($rPackage) && in_array($rBouquet['id'], json_decode($rPackage['bouquets'], true))) {
                                                                        echo " class='selected selectedfilter ui-selected'";
                                                                    } ?>>
                                                                    <td class="text-center"><?php echo $rBouquet['id']; ?></td>
                                                                    <td><?php echo $rBouquet['bouquet_name']; ?></td>
                                                                    <td class="text-center"><?php echo count(json_decode($rBouquet['bouquet_channels'], true)); ?></td>
                                                                    <td class="text-center"><?php echo count(json_decode($rBouquet['bouquet_movies'], true)); ?></td>
                                                                    <td class="text-center"><?php echo count(json_decode($rBouquet['bouquet_series'], true)); ?></td>
                                                                    <td class="text-center"><?php echo count(json_decode($rBouquet['bouquet_radios'], true)); ?></td>
                                                                    </tr>
                                                                <?php } ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $language::get('prev'); ?></a>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <a href="javascript: void(0);" onClick="toggleBouquets()" class="btn btn-info"><?php echo $language::get('toggle_bouquets'); ?></a>
                                                <input name="submit_package" type="submit" class="btn btn-primary" value="<?php echo isset($rPackage) ? $language::get('edit') : $language::get('add'); ?>" />
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

    <?php if (isset($rPackage)): ?>
        var rBouquets = [<?php echo implode(',', array_map('intval', is_array($addons = json_decode($rPackage['bouquets'] ?? '[]', true)) ? $addons : [])); ?>];
        var rGroups = [<?php echo implode(',', array_map('intval', is_array($addons = json_decode($rPackage['groups'] ?? '[]', true)) ? $addons : [])); ?>];
        var rAddons = [<?php echo implode(',', array_map('intval', is_array($addons = json_decode($rPackage['addon_packages'] ?? '[]', true)) ? $addons : [])); ?>];
    <?php else: ?>
        var rBouquets = [];
        var rGroups = [];
        var rAddons = [];
    <?php endif; ?>

    function toggleBouquets() {
        $("#datatable-bouquets tr").each(function() {
            if ($(this).hasClass('selected')) {
                $(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
                if ($(this).find("td:eq(0)").text()) {
                    window.rBouquets.splice(parseInt($.inArray($(this).find("td:eq(0)").text()), window.rBouquets), 1);
                }
            } else {
                $(this).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                if ($(this).find("td:eq(0)").text()) {
                    window.rBouquets.push(parseInt($(this).find("td:eq(0)").text()));
                }
            }
        });
    }

    function toggleGroups() {
        $("#datatable-groups tr").each(function() {
            if ($(this).hasClass('selected')) {
                $(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
                if ($(this).find("td:eq(0)").text()) {
                    window.rGroups.splice(parseInt($.inArray($(this).find("td:eq(0)").text()), window.rGroups), 1);
                }
            } else {
                $(this).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                if ($(this).find("td:eq(0)").text()) {
                    window.rGroups.push(parseInt($(this).find("td:eq(0)").text()));
                }
            }
        });
    }

    function toggleAddons() {
        $("#datatable-addon tr").each(function() {
            if ($(this).hasClass('selected')) {
                $(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
                if ($(this).find("td:eq(0)").text()) {
                    window.rAddons.splice(parseInt($.inArray($(this).find("td:eq(0)").text()), window.rAddons), 1);
                }
            } else {
                $(this).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                if ($(this).find("td:eq(0)").text()) {
                    window.rAddons.push(parseInt($(this).find("td:eq(0)").text()));
                }
            }
        });
    }
    $(document).ready(function() {
        $('select.select2').select2({
            width: '100%'
        });
        $("#datatable-bouquets").DataTable({
            columnDefs: [{
                "className": "dt-center",
                "targets": [0, 2, 3, 4, 5]
            }],
            drawCallback: function() {
                bindHref();
                refreshTooltips();
            },
            "rowCallback": function(row, data) {
                if ($.inArray(data[0], window.rBouquets) !== -1) {
                    $(row).addClass("selected");
                }
            },
            paging: false,
            bInfo: false,
            searching: false
        });
        $("#datatable-bouquets").selectable({
            filter: 'tr',
            selected: function(event, ui) {
                if ($(ui.selected).hasClass('selectedfilter')) {
                    $(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
                    window.rBouquets.splice(parseInt($.inArray($(ui.selected).find("td:eq(0)").text()), window.rBouquets), 1);
                } else {
                    $(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                    window.rBouquets.push(parseInt($(ui.selected).find("td:eq(0)").text()));
                }
            }
        });
        $("#datatable-addon").DataTable({
            columnDefs: [{
                "className": "dt-center",
                "targets": [0, 2, 3]
            }],
            drawCallback: function() {
                bindHref();
                refreshTooltips();
            },
            "rowCallback": function(row, data) {
                if ($.inArray(data[0], window.rAddons) !== -1) {
                    $(row).addClass("selected");
                }
            },
            paging: false,
            bInfo: false,
            searching: false
        });
        $("#datatable-addon").selectable({
            filter: 'tr',
            selected: function(event, ui) {
                if ($(ui.selected).hasClass('selectedfilter')) {
                    $(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
                    window.rAddons.splice(parseInt($.inArray($(ui.selected).find("td:eq(0)").text()), window.rAddons), 1);
                } else {
                    $(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                    window.rAddons.push(parseInt($(ui.selected).find("td:eq(0)").text()));
                }
            }
        });
        $("#datatable-groups").DataTable({
            columnDefs: [{
                "className": "dt-center",
                "targets": [0]
            }],
            drawCallback: function() {
                bindHref();
                refreshTooltips();
            },
            "rowCallback": function(row, data) {
                if ($.inArray(data[0], window.rGroups) !== -1) {
                    $(row).addClass("selected");
                }
            },
            paging: false,
            bInfo: false,
            searching: false
        });
        $("#datatable-groups").selectable({
            filter: 'tr',
            selected: function(event, ui) {
                if ($(ui.selected).hasClass('selectedfilter')) {
                    $(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
                    window.rGroups.splice(parseInt($.inArray($(ui.selected).find("td:eq(0)").text()), window.rGroups), 1);
                } else {
                    $(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                    window.rGroups.push(parseInt($(ui.selected).find("td:eq(0)").text()));
                }
            }
        });
        $("#max_connections").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#trial_duration").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#official_duration").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#trial_credits").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#official_credits").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("form").submit(function(e) {
            e.preventDefault();
            var rBouquets = [];
            $("#datatable-bouquets tr.selected").each(function() {
                rBouquets.push($(this).find("td:eq(0)").text());
            });
            $("#bouquets_selected").val(JSON.stringify(rBouquets));
            var rGroups = [];
            $("#datatable-groups tr.selected").each(function() {
                rGroups.push($(this).find("td:eq(0)").text());
            });
            $("#groups_selected").val(JSON.stringify(rGroups));
            var rAddons = [];
            $("#datatable-addon tr.selected").each(function() {
                rAddons.push($(this).find("td:eq(0)").text());
            });
            $("#addons_selected").val(JSON.stringify(rAddons));
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
