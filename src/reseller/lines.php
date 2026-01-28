<?php

include 'session.php';
include 'functions.php';

if (checkResellerPermissions()) {
} else {
	goHome();
}

$_TITLE = 'Lines';
include 'header.php';
echo '<div class="wrapper">' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">Lines</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n" . '                ';

if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
} else {
	echo '                <div class="alert alert-success alert-dismissible fade show" role="alert">' . "\n" . '                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\n" . '                        <span aria-hidden="true">&times;</span>' . "\n" . '                    </button>' . "\n" . '                    Line has been added / modified.' . "\n" . '                </div>' . "\n" . '                ';
}

echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\n" . '                        <div id="collapse_filters" class="';

if (!$rMobile) {
} else {
	echo 'collapse';
}

echo ' form-group row mb-4">' . "\n" . '                            <div class="col-md-3">' . "\n" . '                                <input type="text" class="form-control" id="user_search" value="';

if (!isset(CoreUtilities::$rRequest['search'])) {
} else {
	echo htmlspecialchars(CoreUtilities::$rRequest['search']);
}

echo '" placeholder="Search Lines...">' . "\n" . '                            </div>' . "\n" . '                            <label class="col-md-2 col-form-label text-center" for="user_reseller">Filter Results</label>' . "\n" . '                            <div class="col-md-3">' . "\n" . '                                <select id="user_reseller" class="form-control" data-toggle="select2">' . "\n" . '                                    <optgroup label="Global">' . "\n" . '                                        <option value=""';

if (isset(CoreUtilities::$rRequest['owner'])) {
} else {
	echo ' selected';
}

echo '>All Owners</option>' . "\n" . '                                        <option value="';
echo $rUserInfo['id'];
echo '"';

if (!(isset(CoreUtilities::$rRequest['owner']) && CoreUtilities::$rRequest['owner'] == $rUserInfo['id'])) {
} else {
	echo ' selected';
}

echo '>My Lines</option>' . "\n" . '                                    </optgroup>' . "\n" . '                                    ';

if (0 >= count($rPermissions['direct_reports'])) {
} else {
	echo '                                    <optgroup label="Direct Reports">' . "\n" . '                                        ';

	foreach ($rPermissions['direct_reports'] as $rUserID) {
		$rRegisteredUser = $rPermissions['users'][$rUserID];
		echo '                                        <option value="';
		echo $rUserID;
		echo '"';

		if (!(isset(CoreUtilities::$rRequest['owner']) && CoreUtilities::$rRequest['owner'] == $rUserID)) {
		} else {
			echo ' selected';
		}

		echo '>';
		echo $rRegisteredUser['username'];
		echo '</option>' . "\n" . '                                        ';
	}
	echo '                                    </optgroup>' . "\n" . '                                    ';
}

if (count($rPermissions['direct_reports']) >= count($rPermissions['all_reports'])) {
} else {
	echo '                                    <optgroup label="Indirect Reports">' . "\n" . '                                        ';

	foreach ($rPermissions['all_reports'] as $rUserID) {
		if (in_array($rUserID, $rPermissions['direct_reports'])) {
		} else {
			$rRegisteredUser = $rPermissions['users'][$rUserID];
			echo '                                            <option value="';
			echo $rUserID;
			echo '"';

			if (!(isset(CoreUtilities::$rRequest['owner']) && CoreUtilities::$rRequest['owner'] == $rUserID)) {
			} else {
				echo ' selected';
			}

			echo '>';
			echo $rRegisteredUser['username'];
			echo '</option>' . "\n" . '                                            ';
		}
	}
	echo '                                    </optgroup>' . "\n" . '                                    ';
}

echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="user_filter" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['filter'])) {
} else {
	echo ' selected';
}

echo '>No Filter</option>' . "\n" . '                                    <option value="1"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1)) {
} else {
	echo ' selected';
}

echo '>Active</option>' . "\n" . '                                    <option value="2"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2)) {
} else {
	echo ' selected';
}

echo '>Disabled</option>' . "\n" . '                                    <option value="3"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3)) {
} else {
	echo ' selected';
}

echo '>Banned</option>' . "\n" . '                                    <option value="4"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4)) {
} else {
	echo ' selected';
}

echo '>Expired</option>' . "\n" . '                                    <option value="5"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5)) {
} else {
	echo ' selected';
}

echo '>Trial</option>' . "\n" . '                                    ' . "\n" . '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <label class="col-md-1 col-form-label text-center" for="user_show_entries">Show</label>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="user_show_entries" class="form-control" data-toggle="select2">' . "\n" . '                                    ';

foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) {
	echo '                                    <option';

	if (isset(CoreUtilities::$rRequest['entries'])) {
		if (CoreUtilities::$rRequest['entries'] != $rShow) {
		} else {
			echo ' selected';
		}
	} else {
		if ($rSettings['default_entries'] != $rShow) {
		} else {
			echo ' selected';
		}
	}

	echo ' value="';
	echo $rShow;
	echo '">';
	echo $rShow;
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n\t\t\t\t\t\t" . '<table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">' . "\n\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Username</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Password</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Owner</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Status</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Online</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Trial</th>' . "\n" . '                                    <th class="text-center">Active</th>' . "\n" . '                                    <th class="text-center">Connections</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Expiration</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Last Connection</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Actions</th>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
?>
<!-- WhatsApp Renewal Modal -->
<div class="modal fade" id="whatsappModal" tabindex="-1" role="dialog" aria-labelledby="whatsappModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="whatsappModalLabel"><i class="mdi mdi-whatsapp text-success"></i> WhatsApp Renewal Reminder</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="wa_language">Select Language / Sprache wählen / Dil Seçin</label>
                    <select id="wa_language" class="form-control">
                        <option value="de">🇩🇪 Deutsch</option>
                        <option value="en">🇬🇧 English</option>
                        <option value="tr">🇹🇷 Türkçe</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Preview / Vorschau / Önizleme</label>
                    <textarea id="wa_message_preview" class="form-control" rows="5" readonly></textarea>
                </div>
                <input type="hidden" id="wa_phone" value="">
                <input type="hidden" id="wa_username" value="">
                <input type="hidden" id="wa_expdate" value="">
                <input type="hidden" id="wa_daysremaining" value="">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <a id="wa_send_btn" href="#" target="_blank" class="btn btn-success"><i class="mdi mdi-whatsapp"></i> Send via WhatsApp</a>
            </div>
        </div>
    </div>
</div>
<script>
// WhatsApp Renewal Messages
var waMessages = {
    de: "Hallo Lieber {USERNAME},\n\nIhr IPTV Abonnement endet am {EXPDATE} und es sind noch {DAYS} Tage übrig.\n\nMöchten Sie Ihr IPTV Abonnement verlängern?\n\nMit freundlichen Grüßen",
    en: "Hello Dear {USERNAME},\n\nYour IPTV subscription expires on {EXPDATE} and there are {DAYS} days remaining.\n\nWould you like to renew your IPTV subscription?\n\nBest regards",
    tr: "Merhaba Sayın {USERNAME},\n\nIPTV aboneliğiniz {EXPDATE} tarihinde sona eriyor ve {DAYS} gün kaldı.\n\nIPTV aboneliğinizi yenilemek ister misiniz?\n\nSaygılarımızla"
};

function updateWaPreview() {
    var lang = $("#wa_language").val();
    var username = $("#wa_username").val();
    var expdate = $("#wa_expdate").val();
    var days = $("#wa_daysremaining").val();
    
    var message = waMessages[lang]
        .replace("{USERNAME}", username)
        .replace("{EXPDATE}", expdate)
        .replace("{DAYS}", days);
    
    $("#wa_message_preview").val(message);
    
    var phone = $("#wa_phone").val().replace(/[^0-9]/g, '');
    var encodedMessage = encodeURIComponent(message);
    $("#wa_send_btn").attr("href", "https://wa.me/" + phone + "?text=" + encodedMessage);
}

function openWhatsApp(username, contact, expTimestamp) {
    if (!contact) {
        $.toast({
            heading: 'No WhatsApp Number',
            text: 'This line has no WhatsApp number set.',
            icon: 'warning',
            position: 'top-right'
        });
        return;
    }
    
    var expDate = expTimestamp ? new Date(expTimestamp * 1000) : null;
    var expDateStr = expDate ? expDate.toLocaleDateString('de-DE') : 'Never';
    var daysRemaining = 0;
    
    if (expDate) {
        var today = new Date();
        var diffTime = expDate - today;
        daysRemaining = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        if (daysRemaining < 0) daysRemaining = 0;
    }
    
    $("#wa_phone").val(contact);
    $("#wa_username").val(username);
    $("#wa_expdate").val(expDateStr);
    $("#wa_daysremaining").val(daysRemaining);
    
    updateWaPreview();
    $("#whatsappModal").modal("show");
}

$(document).ready(function() {
    $("#wa_language").change(function() {
        updateWaPreview();
    });
});
</script>
<?php
include 'footer.php';
