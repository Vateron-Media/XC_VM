<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['id']) || ($rPackage = getPackage(CoreUtilities::$rRequest['id']))) {
} else {
    goHome();
}
$rCountries = array(array('id' => '', 'name' => 'Off'), array('id' => 'A1', 'name' => 'Anonymous Proxy'), array('id' => 'A2', 'name' => 'Satellite Provider'), array('id' => 'O1', 'name' => 'Other Country'), array('id' => 'AF', 'name' => 'Afghanistan'), array('id' => 'AX', 'name' => 'Aland Islands'), array('id' => 'AL', 'name' => 'Albania'), array('id' => 'DZ', 'name' => 'Algeria'), array('id' => 'AS', 'name' => 'American Samoa'), array('id' => 'AD', 'name' => 'Andorra'), array('id' => 'AO', 'name' => 'Angola'), array('id' => 'AI', 'name' => 'Anguilla'), array('id' => 'AQ', 'name' => 'Antarctica'), array('id' => 'AG', 'name' => 'Antigua And Barbuda'), array('id' => 'AR', 'name' => 'Argentina'), array('id' => 'AM', 'name' => 'Armenia'), array('id' => 'AW', 'name' => 'Aruba'), array('id' => 'AU', 'name' => 'Australia'), array('id' => 'AT', 'name' => 'Austria'), array('id' => 'AZ', 'name' => 'Azerbaijan'), array('id' => 'BS', 'name' => 'Bahamas'), array('id' => 'BH', 'name' => 'Bahrain'), array('id' => 'BD', 'name' => 'Bangladesh'), array('id' => 'BB', 'name' => 'Barbados'), array('id' => 'BY', 'name' => 'Belarus'), array('id' => 'BE', 'name' => 'Belgium'), array('id' => 'BZ', 'name' => 'Belize'), array('id' => 'BJ', 'name' => 'Benin'), array('id' => 'BM', 'name' => 'Bermuda'), array('id' => 'BT', 'name' => 'Bhutan'), array('id' => 'BO', 'name' => 'Bolivia'), array('id' => 'BA', 'name' => 'Bosnia And Herzegovina'), array('id' => 'BW', 'name' => 'Botswana'), array('id' => 'BV', 'name' => 'Bouvet Island'), array('id' => 'BR', 'name' => 'Brazil'), array('id' => 'IO', 'name' => 'British Indian Ocean Territory'), array('id' => 'BN', 'name' => 'Brunei Darussalam'), array('id' => 'BG', 'name' => 'Bulgaria'), array('id' => 'BF', 'name' => 'Burkina Faso'), array('id' => 'BI', 'name' => 'Burundi'), array('id' => 'KH', 'name' => 'Cambodia'), array('id' => 'CM', 'name' => 'Cameroon'), array('id' => 'CA', 'name' => 'Canada'), array('id' => 'CV', 'name' => 'Cape Verde'), array('id' => 'KY', 'name' => 'Cayman Islands'), array('id' => 'CF', 'name' => 'Central African Republic'), array('id' => 'TD', 'name' => 'Chad'), array('id' => 'CL', 'name' => 'Chile'), array('id' => 'CN', 'name' => 'China'), array('id' => 'CX', 'name' => 'Christmas Island'), array('id' => 'CC', 'name' => 'Cocos (Keeling) Islands'), array('id' => 'CO', 'name' => 'Colombia'), array('id' => 'KM', 'name' => 'Comoros'), array('id' => 'CG', 'name' => 'Congo'), array('id' => 'CD', 'name' => 'Congo, Democratic Republic'), array('id' => 'CK', 'name' => 'Cook Islands'), array('id' => 'CR', 'name' => 'Costa Rica'), array('id' => 'CI', 'name' => "Cote D'Ivoire"), array('id' => 'HR', 'name' => 'Croatia'), array('id' => 'CU', 'name' => 'Cuba'), array('id' => 'CY', 'name' => 'Cyprus'), array('id' => 'CZ', 'name' => 'Czech Republic'), array('id' => 'DK', 'name' => 'Denmark'), array('id' => 'DJ', 'name' => 'Djibouti'), array('id' => 'DM', 'name' => 'Dominica'), array('id' => 'DO', 'name' => 'Dominican Republic'), array('id' => 'EC', 'name' => 'Ecuador'), array('id' => 'EG', 'name' => 'Egypt'), array('id' => 'SV', 'name' => 'El Salvador'), array('id' => 'GQ', 'name' => 'Equatorial Guinea'), array('id' => 'ER', 'name' => 'Eritrea'), array('id' => 'EE', 'name' => 'Estonia'), array('id' => 'ET', 'name' => 'Ethiopia'), array('id' => 'FK', 'name' => 'Falkland Islands (Malvinas)'), array('id' => 'FO', 'name' => 'Faroe Islands'), array('id' => 'FJ', 'name' => 'Fiji'), array('id' => 'FI', 'name' => 'Finland'), array('id' => 'FR', 'name' => 'France'), array('id' => 'GF', 'name' => 'French Guiana'), array('id' => 'PF', 'name' => 'French Polynesia'), array('id' => 'TF', 'name' => 'French Southern Territories'), array('id' => 'MK', 'name' => 'Fyrom'), array('id' => 'GA', 'name' => 'Gabon'), array('id' => 'GM', 'name' => 'Gambia'), array('id' => 'GE', 'name' => 'Georgia'), array('id' => 'DE', 'name' => 'Germany'), array('id' => 'GH', 'name' => 'Ghana'), array('id' => 'GI', 'name' => 'Gibraltar'), array('id' => 'GR', 'name' => 'Greece'), array('id' => 'GL', 'name' => 'Greenland'), array('id' => 'GD', 'name' => 'Grenada'), array('id' => 'GP', 'name' => 'Guadeloupe'), array('id' => 'GU', 'name' => 'Guam'), array('id' => 'GT', 'name' => 'Guatemala'), array('id' => 'GG', 'name' => 'Guernsey'), array('id' => 'GN', 'name' => 'Guinea'), array('id' => 'GW', 'name' => 'Guinea-Bissau'), array('id' => 'GY', 'name' => 'Guyana'), array('id' => 'HT', 'name' => 'Haiti'), array('id' => 'HM', 'name' => 'Heard Island & Mcdonald Islands'), array('id' => 'VA', 'name' => 'Holy See (Vatican City State)'), array('id' => 'HN', 'name' => 'Honduras'), array('id' => 'HK', 'name' => 'Hong Kong'), array('id' => 'HU', 'name' => 'Hungary'), array('id' => 'IS', 'name' => 'Iceland'), array('id' => 'IN', 'name' => 'India'), array('id' => 'ID', 'name' => 'Indonesia'), array('id' => 'IR', 'name' => 'Iran, Islamic Republic Of'), array('id' => 'IQ', 'name' => 'Iraq'), array('id' => 'IE', 'name' => 'Ireland'), array('id' => 'IM', 'name' => 'Isle Of Man'), array('id' => 'IL', 'name' => 'Israel'), array('id' => 'IT', 'name' => 'Italy'), array('id' => 'JM', 'name' => 'Jamaica'), array('id' => 'JP', 'name' => 'Japan'), array('id' => 'JE', 'name' => 'Jersey'), array('id' => 'JO', 'name' => 'Jordan'), array('id' => 'KZ', 'name' => 'Kazakhstan'), array('id' => 'KE', 'name' => 'Kenya'), array('id' => 'KI', 'name' => 'Kiribati'), array('id' => 'KR', 'name' => 'Korea'), array('id' => 'KW', 'name' => 'Kuwait'), array('id' => 'KG', 'name' => 'Kyrgyzstan'), array('id' => 'LA', 'name' => "Lao People's Democratic Republic"), array('id' => 'LV', 'name' => 'Latvia'), array('id' => 'LB', 'name' => 'Lebanon'), array('id' => 'LS', 'name' => 'Lesotho'), array('id' => 'LR', 'name' => 'Liberia'), array('id' => 'LY', 'name' => 'Libyan Arab Jamahiriya'), array('id' => 'LI', 'name' => 'Liechtenstein'), array('id' => 'LT', 'name' => 'Lithuania'), array('id' => 'LU', 'name' => 'Luxembourg'), array('id' => 'MO', 'name' => 'Macao'), array('id' => 'MG', 'name' => 'Madagascar'), array('id' => 'MW', 'name' => 'Malawi'), array('id' => 'MY', 'name' => 'Malaysia'), array('id' => 'MV', 'name' => 'Maldives'), array('id' => 'ML', 'name' => 'Mali'), array('id' => 'MT', 'name' => 'Malta'), array('id' => 'MH', 'name' => 'Marshall Islands'), array('id' => 'MQ', 'name' => 'Martinique'), array('id' => 'MR', 'name' => 'Mauritania'), array('id' => 'MU', 'name' => 'Mauritius'), array('id' => 'YT', 'name' => 'Mayotte'), array('id' => 'MX', 'name' => 'Mexico'), array('id' => 'FM', 'name' => 'Micronesia, Federated States Of'), array('id' => 'MD', 'name' => 'Moldova'), array('id' => 'MC', 'name' => 'Monaco'), array('id' => 'MN', 'name' => 'Mongolia'), array('id' => 'ME', 'name' => 'Montenegro'), array('id' => 'MS', 'name' => 'Montserrat'), array('id' => 'MA', 'name' => 'Morocco'), array('id' => 'MZ', 'name' => 'Mozambique'), array('id' => 'MM', 'name' => 'Myanmar'), array('id' => 'NA', 'name' => 'Namibia'), array('id' => 'NR', 'name' => 'Nauru'), array('id' => 'NP', 'name' => 'Nepal'), array('id' => 'NL', 'name' => 'Netherlands'), array('id' => 'AN', 'name' => 'Netherlands Antilles'), array('id' => 'NC', 'name' => 'New Caledonia'), array('id' => 'NZ', 'name' => 'New Zealand'), array('id' => 'NI', 'name' => 'Nicaragua'), array('id' => 'NE', 'name' => 'Niger'), array('id' => 'NG', 'name' => 'Nigeria'), array('id' => 'NU', 'name' => 'Niue'), array('id' => 'NF', 'name' => 'Norfolk Island'), array('id' => 'MP', 'name' => 'Northern Mariana Islands'), array('id' => 'NO', 'name' => 'Norway'), array('id' => 'OM', 'name' => 'Oman'), array('id' => 'PK', 'name' => 'Pakistan'), array('id' => 'PW', 'name' => 'Palau'), array('id' => 'PS', 'name' => 'Palestinian Territory, Occupied'), array('id' => 'PA', 'name' => 'Panama'), array('id' => 'PG', 'name' => 'Papua New Guinea'), array('id' => 'PY', 'name' => 'Paraguay'), array('id' => 'PE', 'name' => 'Peru'), array('id' => 'PH', 'name' => 'Philippines'), array('id' => 'PN', 'name' => 'Pitcairn'), array('id' => 'PL', 'name' => 'Poland'), array('id' => 'PT', 'name' => 'Portugal'), array('id' => 'PR', 'name' => 'Puerto Rico'), array('id' => 'QA', 'name' => 'Qatar'), array('id' => 'RE', 'name' => 'Reunion'), array('id' => 'RO', 'name' => 'Romania'), array('id' => 'RU', 'name' => 'Russian Federation'), array('id' => 'RW', 'name' => 'Rwanda'), array('id' => 'BL', 'name' => 'Saint Barthelemy'), array('id' => 'SH', 'name' => 'Saint Helena'), array('id' => 'KN', 'name' => 'Saint Kitts And Nevis'), array('id' => 'LC', 'name' => 'Saint Lucia'), array('id' => 'MF', 'name' => 'Saint Martin'), array('id' => 'PM', 'name' => 'Saint Pierre And Miquelon'), array('id' => 'VC', 'name' => 'Saint Vincent And Grenadines'), array('id' => 'WS', 'name' => 'Samoa'), array('id' => 'SM', 'name' => 'San Marino'), array('id' => 'ST', 'name' => 'Sao Tome And Principe'), array('id' => 'SA', 'name' => 'Saudi Arabia'), array('id' => 'SN', 'name' => 'Senegal'), array('id' => 'RS', 'name' => 'Serbia'), array('id' => 'SC', 'name' => 'Seychelles'), array('id' => 'SL', 'name' => 'Sierra Leone'), array('id' => 'SG', 'name' => 'Singapore'), array('id' => 'SK', 'name' => 'Slovakia'), array('id' => 'SI', 'name' => 'Slovenia'), array('id' => 'SB', 'name' => 'Solomon Islands'), array('id' => 'SO', 'name' => 'Somalia'), array('id' => 'ZA', 'name' => 'South Africa'), array('id' => 'GS', 'name' => 'South Georgia And Sandwich Isl.'), array('id' => 'ES', 'name' => 'Spain'), array('id' => 'LK', 'name' => 'Sri Lanka'), array('id' => 'SD', 'name' => 'Sudan'), array('id' => 'SR', 'name' => 'Suriname'), array('id' => 'SJ', 'name' => 'Svalbard And Jan Mayen'), array('id' => 'SZ', 'name' => 'Swaziland'), array('id' => 'SE', 'name' => 'Sweden'), array('id' => 'CH', 'name' => 'Switzerland'), array('id' => 'SY', 'name' => 'Syrian Arab Republic'), array('id' => 'TW', 'name' => 'Taiwan'), array('id' => 'TJ', 'name' => 'Tajikistan'), array('id' => 'TZ', 'name' => 'Tanzania'), array('id' => 'TH', 'name' => 'Thailand'), array('id' => 'TL', 'name' => 'Timor-Leste'), array('id' => 'TG', 'name' => 'Togo'), array('id' => 'TK', 'name' => 'Tokelau'), array('id' => 'TO', 'name' => 'Tonga'), array('id' => 'TT', 'name' => 'Trinidad And Tobago'), array('id' => 'TN', 'name' => 'Tunisia'), array('id' => 'TR', 'name' => 'Turkey'), array('id' => 'TM', 'name' => 'Turkmenistan'), array('id' => 'TC', 'name' => 'Turks And Caicos Islands'), array('id' => 'TV', 'name' => 'Tuvalu'), array('id' => 'UG', 'name' => 'Uganda'), array('id' => 'UA', 'name' => 'Ukraine'), array('id' => 'AE', 'name' => 'United Arab Emirates'), array('id' => 'GB', 'name' => 'United Kingdom'), array('id' => 'US', 'name' => 'United States'), array('id' => 'UM', 'name' => 'United States Outlying Islands'), array('id' => 'UY', 'name' => 'Uruguay'), array('id' => 'UZ', 'name' => 'Uzbekistan'), array('id' => 'VU', 'name' => 'Vanuatu'), array('id' => 'VE', 'name' => 'Venezuela'), array('id' => 'VN', 'name' => 'Viet Nam'), array('id' => 'VG', 'name' => 'Virgin Islands, British'), array('id' => 'VI', 'name' => 'Virgin Islands, U.S.'), array('id' => 'WF', 'name' => 'Wallis And Futuna'), array('id' => 'EH', 'name' => 'Western Sahara'), array('id' => 'YE', 'name' => 'Yemen'), array('id' => 'ZM', 'name' => 'Zambia'), array('id' => 'ZW', 'name' => 'Zimbabwe'));
$_TITLE = 'Package';
include 'header.php'; ?>
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
                                                echo $_['edit_package'];
                                            } else {
                                                echo $_['add_package'];
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
                                            <span class="d-none d-sm-inline"><?= $_['details']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Options</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#groups" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-group mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $_['groups']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#bouquets" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-flower-tulip mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $_['bouquets']; ?></span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="package-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="package_name"><?php echo $_['package_name']; ?></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="package_name" name="package_name" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['package_name']) : ''; ?>">
                                                    </div>
                                                </div>
                                                <h4 class="m-t-0 header-title mb-4">Trial Package</h4>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_trial">Enabled</label>
                                                    <div class="col-md-2">
                                                        <input name="is_trial" id="is_trial" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_trial'] == 1) {
                                                                                                                    echo 'checked ';
                                                                                                                } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="trial_credits">Credit Cost</label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="trial_credits" name="trial_credits" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['trial_credits']) : '0'; ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="trial_duration">Duration</label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="trial_duration" name="trial_duration" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['trial_duration']) : '0'; ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <select name="trial_duration_in" id="trial_duration_in" class="form-control select2" data-toggle="select2">
                                                            <?php foreach (array($_['hours'] => 'hours', $_['days'] => 'days') as $rText => $rOption) { ?>
                                                                <option <?php if (isset($rPackage) && $rPackage['trial_duration_in'] == $rOption) {
                                                                            echo 'selected ';
                                                                        } ?>value="<?php echo $rOption; ?>"><?php echo $rText; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <h4 class="m-t-0 header-title mb-4">Standard Package</h4>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_official">Enabled</label>
                                                    <div class="col-md-2">
                                                        <input name="is_official" id="is_official" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_official'] == 1) {
                                                                                                                        echo 'checked ';
                                                                                                                    } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="official_credits">Credit Cost</label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="official_credits" name="official_credits" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['official_credits']) : '0'; ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="official_duration">Duration</label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="official_duration" name="official_duration" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['official_duration']) : '0'; ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <select name="official_duration_in" id="official_duration_in" class="form-control select2" data-toggle="select2">
                                                            <?php foreach (array($_['hours'] => 'hours', $_['days'] => 'days', $_['months'] => 'months', $_['years'] => 'years') as $rText => $rOption) { ?>
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
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['next']; ?></a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="options">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_mag">MAG Device</label>
                                                    <div class="col-md-2">
                                                        <input name="is_mag" id="is_mag" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_mag'] == 1) {
                                                                                                                echo 'checked ';
                                                                                                            } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="is_e2">Enigma Device</label>
                                                    <div class="col-md-2">
                                                        <input name="is_e2" id="is_e2" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_e2'] == 1) {
                                                                                                            echo 'checked ';
                                                                                                        } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_line">Standard Line</label>
                                                    <div class="col-md-2">
                                                        <input name="is_line" id="is_line" type="checkbox" <?php if (isset($rPackage) ? $rPackage['is_line'] == 1 : true) {
                                                                                                                echo 'checked ';
                                                                                                            } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="is_isplock">Lock to ISP</label>
                                                    <div class="col-md-2">
                                                        <input name="is_isplock" id="is_isplock" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_isplock'] == 1) {
                                                                                                                        echo 'checked ';
                                                                                                                    } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_restreamer">Restreamer</label>
                                                    <div class="col-md-2">
                                                        <input name="is_restreamer" id="is_restreamer" type="checkbox" <?php if (isset($rPackage) && $rPackage['is_restreamer'] == 1) {
                                                                                                                            echo 'checked ';
                                                                                                                        } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="check_compatible">Verify Compatibility</label>
                                                    <div class="col-md-2">
                                                        <input name="check_compatible" id="check_compatible" type="checkbox" <?php if (isset($rPackage) ? $rPackage['check_compatible'] == 1 : true) {
                                                                                                                                    echo 'checked ';
                                                                                                                                } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="force_server_id">Forced Connection</label>
                                                    <div class="col-md-8">
                                                        <select name="force_server_id" id="force_server_id" class="form-control select2" data-toggle="select2">
                                                            <option <?php if (!isset($rPackage) || intval($rPackage['force_server_id']) == 0) {
                                                                        echo 'selected ';
                                                                    } ?>value="0">Disabled</option>
                                                            <?php foreach ($rServers as $rServer) { ?>
                                                                <option <?php if (isset($rPackage) && intval($rPackage['force_server_id']) == intval($rServer['id'])) {
                                                                            echo 'selected ';
                                                                        } ?>value="<?php echo $rServer['id']; ?>"><?php echo htmlspecialchars($rServer['server_name']); ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="max_connections"><?php echo $_['max_connections']; ?></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="max_connections" name="max_connections" value="<?php echo isset($rPackage) ? htmlspecialchars($rPackage['max_connections']) : '1'; ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="output_formats"><?php echo $_['access_output']; ?></label>
                                                    <div class="col-md-8">
                                                        <?php foreach (getOutputs() as $rOutput) { ?>
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
                                                    <label class="col-md-4 col-form-label" for="forced_country">Forced Country</label>
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
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['prev']; ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['next']; ?></a>
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
                                                                <th class="text-center"><?php echo $_['id']; ?></th>
                                                                <th><?php echo $_['group_name']; ?></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach (getMemberGroups() as $rGroup) {
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
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['prev']; ?></a>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <a href="javascript: void(0);" onClick="toggleGroups()" class="btn btn-info"><?php echo $_['toggle_groups']; ?></a>
                                                <a href="javascript: void(0);" class="btn btn-secondary nextb"><?php echo $_['next']; ?></a>
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
                                                                <th class="text-center"><?php echo $_['id']; ?></th>
                                                                <th><?php echo $_['bouquet_name']; ?></th>
                                                                <th class="text-center"><?php echo $_['streams']; ?></th>
                                                                <th class="text-center"><?php echo $_['movies']; ?></th>
                                                                <th class="text-center"><?php echo $_['series']; ?></th>
                                                                <th class="text-center"><?php echo $_['stations']; ?></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach (getBouquets() as $rBouquet) { ?>
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
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['prev']; ?></a>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <a href="javascript: void(0);" onClick="toggleBouquets()" class="btn btn-info"><?php echo $_['toggle_bouquets']; ?></a>
                                                <input name="submit_package" type="submit" class="btn btn-primary" value="<?php echo isset($rPackage) ? $_['edit'] : $_['add']; ?>" />
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

    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });

    <?php endif;

    if (isset($rPackage)): ?>
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
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>