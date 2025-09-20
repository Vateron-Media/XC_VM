<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

if (isset(CoreUtilities::$rRequest['id']) && ($rServerArr = $rProxyServers[CoreUtilities::$rRequest['id']] || $rServerArr['server_type'] != 1)) {
} else {
    goHome();
}

$rCountries = array(array('id' => 'ALL', 'name' => 'All Countries'), array('id' => 'A1', 'name' => 'Anonymous Proxy'), array('id' => 'A2', 'name' => 'Satellite Provider'), array('id' => 'O1', 'name' => 'Other Country'), array('id' => 'AF', 'name' => 'Afghanistan'), array('id' => 'AX', 'name' => 'Aland Islands'), array('id' => 'AL', 'name' => 'Albania'), array('id' => 'DZ', 'name' => 'Algeria'), array('id' => 'AS', 'name' => 'American Samoa'), array('id' => 'AD', 'name' => 'Andorra'), array('id' => 'AO', 'name' => 'Angola'), array('id' => 'AI', 'name' => 'Anguilla'), array('id' => 'AQ', 'name' => 'Antarctica'), array('id' => 'AG', 'name' => 'Antigua And Barbuda'), array('id' => 'AR', 'name' => 'Argentina'), array('id' => 'AM', 'name' => 'Armenia'), array('id' => 'AW', 'name' => 'Aruba'), array('id' => 'AU', 'name' => 'Australia'), array('id' => 'AT', 'name' => 'Austria'), array('id' => 'AZ', 'name' => 'Azerbaijan'), array('id' => 'BS', 'name' => 'Bahamas'), array('id' => 'BH', 'name' => 'Bahrain'), array('id' => 'BD', 'name' => 'Bangladesh'), array('id' => 'BB', 'name' => 'Barbados'), array('id' => 'BY', 'name' => 'Belarus'), array('id' => 'BE', 'name' => 'Belgium'), array('id' => 'BZ', 'name' => 'Belize'), array('id' => 'BJ', 'name' => 'Benin'), array('id' => 'BM', 'name' => 'Bermuda'), array('id' => 'BT', 'name' => 'Bhutan'), array('id' => 'BO', 'name' => 'Bolivia'), array('id' => 'BA', 'name' => 'Bosnia And Herzegovina'), array('id' => 'BW', 'name' => 'Botswana'), array('id' => 'BV', 'name' => 'Bouvet Island'), array('id' => 'BR', 'name' => 'Brazil'), array('id' => 'IO', 'name' => 'British Indian Ocean Territory'), array('id' => 'BN', 'name' => 'Brunei Darussalam'), array('id' => 'BG', 'name' => 'Bulgaria'), array('id' => 'BF', 'name' => 'Burkina Faso'), array('id' => 'BI', 'name' => 'Burundi'), array('id' => 'KH', 'name' => 'Cambodia'), array('id' => 'CM', 'name' => 'Cameroon'), array('id' => 'CA', 'name' => 'Canada'), array('id' => 'CV', 'name' => 'Cape Verde'), array('id' => 'KY', 'name' => 'Cayman Islands'), array('id' => 'CF', 'name' => 'Central African Republic'), array('id' => 'TD', 'name' => 'Chad'), array('id' => 'CL', 'name' => 'Chile'), array('id' => 'CN', 'name' => 'China'), array('id' => 'CX', 'name' => 'Christmas Island'), array('id' => 'CC', 'name' => 'Cocos (Keeling) Islands'), array('id' => 'CO', 'name' => 'Colombia'), array('id' => 'KM', 'name' => 'Comoros'), array('id' => 'CG', 'name' => 'Congo'), array('id' => 'CD', 'name' => 'Congo, Democratic Republic'), array('id' => 'CK', 'name' => 'Cook Islands'), array('id' => 'CR', 'name' => 'Costa Rica'), array('id' => 'CI', 'name' => "Cote D'Ivoire"), array('id' => 'HR', 'name' => 'Croatia'), array('id' => 'CU', 'name' => 'Cuba'), array('id' => 'CY', 'name' => 'Cyprus'), array('id' => 'CZ', 'name' => 'Czech Republic'), array('id' => 'DK', 'name' => 'Denmark'), array('id' => 'DJ', 'name' => 'Djibouti'), array('id' => 'DM', 'name' => 'Dominica'), array('id' => 'DO', 'name' => 'Dominican Republic'), array('id' => 'EC', 'name' => 'Ecuador'), array('id' => 'EG', 'name' => 'Egypt'), array('id' => 'SV', 'name' => 'El Salvador'), array('id' => 'GQ', 'name' => 'Equatorial Guinea'), array('id' => 'ER', 'name' => 'Eritrea'), array('id' => 'EE', 'name' => 'Estonia'), array('id' => 'ET', 'name' => 'Ethiopia'), array('id' => 'FK', 'name' => 'Falkland Islands (Malvinas)'), array('id' => 'FO', 'name' => 'Faroe Islands'), array('id' => 'FJ', 'name' => 'Fiji'), array('id' => 'FI', 'name' => 'Finland'), array('id' => 'FR', 'name' => 'France'), array('id' => 'GF', 'name' => 'French Guiana'), array('id' => 'PF', 'name' => 'French Polynesia'), array('id' => 'TF', 'name' => 'French Southern Territories'), array('id' => 'MK', 'name' => 'Fyrom'), array('id' => 'GA', 'name' => 'Gabon'), array('id' => 'GM', 'name' => 'Gambia'), array('id' => 'GE', 'name' => 'Georgia'), array('id' => 'DE', 'name' => 'Germany'), array('id' => 'GH', 'name' => 'Ghana'), array('id' => 'GI', 'name' => 'Gibraltar'), array('id' => 'GR', 'name' => 'Greece'), array('id' => 'GL', 'name' => 'Greenland'), array('id' => 'GD', 'name' => 'Grenada'), array('id' => 'GP', 'name' => 'Guadeloupe'), array('id' => 'GU', 'name' => 'Guam'), array('id' => 'GT', 'name' => 'Guatemala'), array('id' => 'GG', 'name' => 'Guernsey'), array('id' => 'GN', 'name' => 'Guinea'), array('id' => 'GW', 'name' => 'Guinea-Bissau'), array('id' => 'GY', 'name' => 'Guyana'), array('id' => 'HT', 'name' => 'Haiti'), array('id' => 'HM', 'name' => 'Heard Island & Mcdonald Islands'), array('id' => 'VA', 'name' => 'Holy See (Vatican City State)'), array('id' => 'HN', 'name' => 'Honduras'), array('id' => 'HK', 'name' => 'Hong Kong'), array('id' => 'HU', 'name' => 'Hungary'), array('id' => 'IS', 'name' => 'Iceland'), array('id' => 'IN', 'name' => 'India'), array('id' => 'ID', 'name' => 'Indonesia'), array('id' => 'IR', 'name' => 'Iran, Islamic Republic Of'), array('id' => 'IQ', 'name' => 'Iraq'), array('id' => 'IE', 'name' => 'Ireland'), array('id' => 'IM', 'name' => 'Isle Of Man'), array('id' => 'IL', 'name' => 'Israel'), array('id' => 'IT', 'name' => 'Italy'), array('id' => 'JM', 'name' => 'Jamaica'), array('id' => 'JP', 'name' => 'Japan'), array('id' => 'JE', 'name' => 'Jersey'), array('id' => 'JO', 'name' => 'Jordan'), array('id' => 'KZ', 'name' => 'Kazakhstan'), array('id' => 'KE', 'name' => 'Kenya'), array('id' => 'KI', 'name' => 'Kiribati'), array('id' => 'KR', 'name' => 'Korea'), array('id' => 'KW', 'name' => 'Kuwait'), array('id' => 'KG', 'name' => 'Kyrgyzstan'), array('id' => 'LA', 'name' => "Lao People's Democratic Republic"), array('id' => 'LV', 'name' => 'Latvia'), array('id' => 'LB', 'name' => 'Lebanon'), array('id' => 'LS', 'name' => 'Lesotho'), array('id' => 'LR', 'name' => 'Liberia'), array('id' => 'LY', 'name' => 'Libyan Arab Jamahiriya'), array('id' => 'LI', 'name' => 'Liechtenstein'), array('id' => 'LT', 'name' => 'Lithuania'), array('id' => 'LU', 'name' => 'Luxembourg'), array('id' => 'MO', 'name' => 'Macao'), array('id' => 'MG', 'name' => 'Madagascar'), array('id' => 'MW', 'name' => 'Malawi'), array('id' => 'MY', 'name' => 'Malaysia'), array('id' => 'MV', 'name' => 'Maldives'), array('id' => 'ML', 'name' => 'Mali'), array('id' => 'MT', 'name' => 'Malta'), array('id' => 'MH', 'name' => 'Marshall Islands'), array('id' => 'MQ', 'name' => 'Martinique'), array('id' => 'MR', 'name' => 'Mauritania'), array('id' => 'MU', 'name' => 'Mauritius'), array('id' => 'YT', 'name' => 'Mayotte'), array('id' => 'MX', 'name' => 'Mexico'), array('id' => 'FM', 'name' => 'Micronesia, Federated States Of'), array('id' => 'MD', 'name' => 'Moldova'), array('id' => 'MC', 'name' => 'Monaco'), array('id' => 'MN', 'name' => 'Mongolia'), array('id' => 'ME', 'name' => 'Montenegro'), array('id' => 'MS', 'name' => 'Montserrat'), array('id' => 'MA', 'name' => 'Morocco'), array('id' => 'MZ', 'name' => 'Mozambique'), array('id' => 'MM', 'name' => 'Myanmar'), array('id' => 'NA', 'name' => 'Namibia'), array('id' => 'NR', 'name' => 'Nauru'), array('id' => 'NP', 'name' => 'Nepal'), array('id' => 'NL', 'name' => 'Netherlands'), array('id' => 'AN', 'name' => 'Netherlands Antilles'), array('id' => 'NC', 'name' => 'New Caledonia'), array('id' => 'NZ', 'name' => 'New Zealand'), array('id' => 'NI', 'name' => 'Nicaragua'), array('id' => 'NE', 'name' => 'Niger'), array('id' => 'NG', 'name' => 'Nigeria'), array('id' => 'NU', 'name' => 'Niue'), array('id' => 'NF', 'name' => 'Norfolk Island'), array('id' => 'MP', 'name' => 'Northern Mariana Islands'), array('id' => 'NO', 'name' => 'Norway'), array('id' => 'OM', 'name' => 'Oman'), array('id' => 'PK', 'name' => 'Pakistan'), array('id' => 'PW', 'name' => 'Palau'), array('id' => 'PS', 'name' => 'Palestinian Territory, Occupied'), array('id' => 'PA', 'name' => 'Panama'), array('id' => 'PG', 'name' => 'Papua New Guinea'), array('id' => 'PY', 'name' => 'Paraguay'), array('id' => 'PE', 'name' => 'Peru'), array('id' => 'PH', 'name' => 'Philippines'), array('id' => 'PN', 'name' => 'Pitcairn'), array('id' => 'PL', 'name' => 'Poland'), array('id' => 'PT', 'name' => 'Portugal'), array('id' => 'PR', 'name' => 'Puerto Rico'), array('id' => 'QA', 'name' => 'Qatar'), array('id' => 'RE', 'name' => 'Reunion'), array('id' => 'RO', 'name' => 'Romania'), array('id' => 'RU', 'name' => 'Russian Federation'), array('id' => 'RW', 'name' => 'Rwanda'), array('id' => 'BL', 'name' => 'Saint Barthelemy'), array('id' => 'SH', 'name' => 'Saint Helena'), array('id' => 'KN', 'name' => 'Saint Kitts And Nevis'), array('id' => 'LC', 'name' => 'Saint Lucia'), array('id' => 'MF', 'name' => 'Saint Martin'), array('id' => 'PM', 'name' => 'Saint Pierre And Miquelon'), array('id' => 'VC', 'name' => 'Saint Vincent And Grenadines'), array('id' => 'WS', 'name' => 'Samoa'), array('id' => 'SM', 'name' => 'San Marino'), array('id' => 'ST', 'name' => 'Sao Tome And Principe'), array('id' => 'SA', 'name' => 'Saudi Arabia'), array('id' => 'SN', 'name' => 'Senegal'), array('id' => 'RS', 'name' => 'Serbia'), array('id' => 'SC', 'name' => 'Seychelles'), array('id' => 'SL', 'name' => 'Sierra Leone'), array('id' => 'SG', 'name' => 'Singapore'), array('id' => 'SK', 'name' => 'Slovakia'), array('id' => 'SI', 'name' => 'Slovenia'), array('id' => 'SB', 'name' => 'Solomon Islands'), array('id' => 'SO', 'name' => 'Somalia'), array('id' => 'ZA', 'name' => 'South Africa'), array('id' => 'GS', 'name' => 'South Georgia And Sandwich Isl.'), array('id' => 'ES', 'name' => 'Spain'), array('id' => 'LK', 'name' => 'Sri Lanka'), array('id' => 'SD', 'name' => 'Sudan'), array('id' => 'SR', 'name' => 'Suriname'), array('id' => 'SJ', 'name' => 'Svalbard And Jan Mayen'), array('id' => 'SZ', 'name' => 'Swaziland'), array('id' => 'SE', 'name' => 'Sweden'), array('id' => 'CH', 'name' => 'Switzerland'), array('id' => 'SY', 'name' => 'Syrian Arab Republic'), array('id' => 'TW', 'name' => 'Taiwan'), array('id' => 'TJ', 'name' => 'Tajikistan'), array('id' => 'TZ', 'name' => 'Tanzania'), array('id' => 'TH', 'name' => 'Thailand'), array('id' => 'TL', 'name' => 'Timor-Leste'), array('id' => 'TG', 'name' => 'Togo'), array('id' => 'TK', 'name' => 'Tokelau'), array('id' => 'TO', 'name' => 'Tonga'), array('id' => 'TT', 'name' => 'Trinidad And Tobago'), array('id' => 'TN', 'name' => 'Tunisia'), array('id' => 'TR', 'name' => 'Turkey'), array('id' => 'TM', 'name' => 'Turkmenistan'), array('id' => 'TC', 'name' => 'Turks And Caicos Islands'), array('id' => 'TV', 'name' => 'Tuvalu'), array('id' => 'UG', 'name' => 'Uganda'), array('id' => 'UA', 'name' => 'Ukraine'), array('id' => 'AE', 'name' => 'United Arab Emirates'), array('id' => 'GB', 'name' => 'United Kingdom'), array('id' => 'US', 'name' => 'United States'), array('id' => 'UM', 'name' => 'United States Outlying Islands'), array('id' => 'UY', 'name' => 'Uruguay'), array('id' => 'UZ', 'name' => 'Uzbekistan'), array('id' => 'VU', 'name' => 'Vanuatu'), array('id' => 'VE', 'name' => 'Venezuela'), array('id' => 'VN', 'name' => 'Viet Nam'), array('id' => 'VG', 'name' => 'Virgin Islands, British'), array('id' => 'VI', 'name' => 'Virgin Islands, U.S.'), array('id' => 'WF', 'name' => 'Wallis And Futuna'), array('id' => 'EH', 'name' => 'Western Sahara'), array('id' => 'YE', 'name' => 'Yemen'), array('id' => 'ZM', 'name' => 'Zambia'), array('id' => 'ZW', 'name' => 'Zimbabwe'));
$_TITLE = 'Edit Proxy';
include 'header.php'; ?>
<div class="wrapper boxed-layout" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Edit Proxy</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <input type="hidden" name="edit" value="<?php echo $rServerArr['id']; ?>" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#server-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#additional_ips" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-web"></i>
                                            <span class="d-none d-sm-inline">Domains & IP's</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#advanced-options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Advanced</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="server-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="server_name">Server Name</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="server_name" name="server_name" value="<?php echo htmlspecialchars($rServerArr['server_name']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="server_ip">Server IP <i title="This IP will be used for internal connections as well as broadcast if no domains are allocated." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="server_ip" name="server_ip" value="<?php echo htmlspecialchars($rServerArr['server_ip']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="total_clients">Max Clients <i title="Maximum number of simultaneous connections to allow on this server." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="total_clients" name="total_clients" value="<?php echo htmlspecialchars($rServerArr['total_clients']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="enabled">Enabled <i title="Utilise this server for connections and streams." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="enabled" id="enabled" type="checkbox" <?php if ($rServerArr['enabled'] == 1) {
                                                                                                                echo 'checked ';
                                                                                                            } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
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
                                                    <label class="col-md-4 col-form-label" for="ip_field">Domains & IP's</label>
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
                                                            <?php foreach (explode(',', $rServerArr['domain_name']) as $rIP) {
                                                                if (strlen($rIP) > 0) { ?>
                                                                    <option value="<?= $rIP; ?>"><?= $rIP; ?></option>
                                                            <?php }
                                                            } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="random_ip">Serve Random IP / Domain</label>
                                                    <div class="col-md-2">
                                                        <input name="random_ip" id="random_ip" type="checkbox" <?php if ($rServerArr['random_ip'] == 1) {
                                                                                                                    echo 'checked ';
                                                                                                                } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
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
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="advanced-options">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="http_broadcast_port">HTTP Port <i title="Modifying this will not change the port on the proxy end, you would need to do this manually." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="http_broadcast_port" name="http_broadcast_port" value="<?= htmlspecialchars($rServerArr['http_broadcast_port']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="https_broadcast_port">HTTPS Ports <i title="Modifying this will not change the port on the proxy end, you would need to do this manually." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="https_broadcast_port" name="https_broadcast_port" value="<?= htmlspecialchars($rServerArr['https_broadcast_port']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="network_guaranteed_speed">Network Speed - Mbps <i title="Port speed to consider when connecting clients." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="network_guaranteed_speed" name="network_guaranteed_speed" value="<?= htmlspecialchars($rServerArr['network_guaranteed_speed']); ?>" required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="enable_https">Enable SSL <i title="Allow HTTPS connections. You will have to configure this on the server manually." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="enable_https" id="enable_https" type="checkbox" <?php if ($rServerArr['enable_https'] == 1) {
                                                                                                                            echo 'checked ';
                                                                                                                        } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="enable_geoip">GeoIP Load Balancing <i title="Route connections to the nearest server based on the location of the client." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="enable_geoip" id="enable_geoip" type="checkbox" <?php if ($rServerArr['enable_geoip'] == 1) {
                                                                                                                            echo 'checked ';
                                                                                                                        } ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="col-md-6">
                                                        <select name="geoip_type" id="geoip_type" class="form-control select2" data-toggle="select2">
                                                            <?php foreach (array('high_priority' => 'High Priority', 'low_priority' => 'Low Priority', 'strict' => 'Strict') as $rType => $rText) { ?>
                                                                <option value="<?= $rType; ?>" <?php if ($rServerArr['geoip_type'] == $rType) {
                                                                                                    echo 'selected';
                                                                                                } ?>><?= $rText; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="geoip_countries">GeoIP Countries <i title="Select which countries should be prioritised to this server." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="geoip_countries[]" id="geoip_countries" class="form-control select2 select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php $rSelected = json_decode($rServerArr['geoip_countries'], true);
                                                            foreach ($rCountries as $rCountry) { ?>
                                                                <option value="<?= $rCountry['id']; ?>" <?php if (in_array($rCountry['id'], $rSelected)) {
                                                                                                            echo 'selected';
                                                                                                        } ?>><?= $rCountry['name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"></a>
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
        <?php if (!$rMobile || $rSettings['header_stats']): ?>
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

    <?php endif; ?>

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
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>