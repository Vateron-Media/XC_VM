<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()) {
    goHome();
} ?>

<?php
if (!isset(CoreUtilities::$rRequest['id']) || !($rBouquet = getBouquet(CoreUtilities::$rRequest['id']))) {
    goHome();
}

$rListings = ['stream' => [], 'movie' => [], 'radio' => [], 'series' => []];
$rOrdered = ['stream' => [], 'movie' => [], 'radio' => [], 'series' => []];
$rChannels = array_map('intval', json_decode($rBouquet['bouquet_channels'], true));
$rMovies = array_map('intval', json_decode($rBouquet['bouquet_movies'], true));
$rSeries = array_map('intval', json_decode($rBouquet['bouquet_series'], true));
$rRadios = array_map('intval', json_decode($rBouquet['bouquet_radios'], true));

populateListings($db, $rChannels, $rMovies, $rRadios, $rSeries, $rListings);
orderListings($rChannels, $rMovies, $rRadios, $rSeries, $rListings, $rOrdered);
?>

<?php include 'header.php'; ?>

<div class="wrapper boxed-layout">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= htmlspecialchars($rBouquet['bouquet_name']); ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <?= $_['bouquet_order_success']; ?>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" id="stream_order_array" name="stream_order_array" value="" />
                            <input type="hidden" name="reorder" value="<?= intval(CoreUtilities::$rRequest['id']); ?>" />
                            <div id="basicwizard">
                                <?php include 'tabs.php'; ?>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <?php foreach (['stream', 'movie', 'series', 'radio'] as $type) : ?>
                                        <?php include 'tab_content.php'; ?>
                                    <?php endforeach; ?>
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
	<?php
		echo '        ' . "\r\n\t\t" . 'function AtoZ(rType) {' . "\r\n\t\t\t" . '$("#sort_" + rType + "_l").append($("#sort_" + rType + "_l option").remove().sort(function(a, b) {' . "\r\n\t\t\t\t" . 'var at = $(a).text().toUpperCase(), bt = $(b).text().toUpperCase();' . "\r\n\t\t\t\t" . 'return (at > bt) ? 1 : ((at < bt) ? -1 : 0);' . "\r\n\t\t\t" . '}));' . "\r\n" . '            $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());' . "\r\n" . "            \$('#sort_' + rType + '_l option:selected').each(function() {" . "\r\n" . '                $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").prop("selected", true);' . "\r\n" . '            });' . "\r\n" . "            \$('#sort_' + rType + '_r').scrollTop(\$('#sort_' + rType + '_l').scrollTop());" . "\r\n\t\t" . '}' . "\r\n" . '        function MoveLeft(rType) {' . "\r\n" . "            if ((\$('#sort_' + rType + '_l option:selected')) && (\$('#sort_' + rType + '_r option:selected').length > 0)) {" . "\r\n" . "                var rSelected = \$('#sort_' + rType + '_r option:selected');" . "\r\n" . "                var rPosition = \$('#sort_' + rType + '_l option:selected')[\$('#sort_' + rType + '_l option:selected').length-1].value;" . "\r\n" . '                var rIncludePos = true;' . "\r\n" . '                var rIDs = [];' . "\r\n" . '                $(rSelected).each(function() {' . "\r\n" . '                    rIDs.push($(this).val());' . "\r\n" . '                    if ($(this).val() !== rPosition) {' . "\r\n" . '                        $("#sort_" + rType + "_l option[value=" + $(this).val() + "]").remove();' . "\r\n" . '                    } else {' . "\r\n" . '                       rIncludePos = false; ' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '                $(rSelected).each(function() {' . "\r\n" . '                    if (($(this).val() !== rPosition) || (rIncludePos)) {' . "\r\n" . '                        $("#sort_" + rType + "_l option[value=" + rPosition + "]").before(new Option($(this).text(), $(this).val()));' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . "                \$('#sort_' + rType + '_l option:selected').prop(\"selected\", false);" . "\r\n" . '                $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());' . "\r\n" . '                for (let i = 0; i < rIDs.length; ++i) {' . "\r\n" . '                    $("#sort_" + rType + "_l option[value=" + rIDs[i] + "]").prop("selected", true);' . "\r\n" . '                    $("#sort_" + rType + "_r option[value=" + rIDs[i] + "]").prop("selected", true);' . "\r\n" . '                }' . "\r\n" . '                $("#sort_" + rType + "_r option:selected")[0].scrollIntoView();' . "\r\n" . '            } else {' . "\r\n" . '                $.toast("Please select both a source and destination.");' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        function MoveRight(rType) {' . "\r\n" . "            if ((\$('#sort_' + rType + '_l option:selected')) && (\$('#sort_' + rType + '_r option:selected').length > 0)) {" . "\r\n" . "                var rSelected = \$('#sort_' + rType + '_l option:selected');" . "\r\n" . "                var rPosition = \$('#sort_' + rType + '_r option:selected')[\$('#sort_' + rType + '_r option:selected').length-1].value;" . "\r\n" . '                var rIncludePos = true;' . "\r\n" . '                var rIDs = [];' . "\r\n" . '                $(rSelected).each(function() {' . "\r\n" . '                    rIDs.push($(this).val());' . "\r\n" . '                    if ($(this).val() !== rPosition) {' . "\r\n" . '                        $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").remove();' . "\r\n" . '                    } else {' . "\r\n" . '                       rIncludePos = false; ' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '                $(rSelected).each(function() {' . "\r\n" . '                    if (($(this).val() !== rPosition) || (rIncludePos)) {' . "\r\n" . '                        $("#sort_" + rType + "_r option[value=" + rPosition + "]").before(new Option($(this).text(), $(this).val()));' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . "                \$('#sort_' + rType + '_r option:selected').prop(\"selected\", false);" . "\r\n" . '                $("#sort_" + rType + "_l").html($("#sort_" + rType + "_r").html());' . "\r\n" . '                for (let i = 0; i < rIDs.length; ++i) {' . "\r\n" . '                    $("#sort_" + rType + "_l option[value=" + rIDs[i] + "]").prop("selected", true);' . "\r\n" . '                    $("#sort_" + rType + "_r option[value=" + rIDs[i] + "]").prop("selected", true);' . "\r\n" . '                }' . "\r\n" . '                $("#sort_" + rType + "_l option:selected")[0].scrollIntoView();' . "\r\n" . '            } else {' . "\r\n" . '                $.toast("Please select both a source and destination.");' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function MoveUp(rType) {' . "\r\n\t\t\t" . "var rSelected = \$('#sort_' + rType + '_l option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . 'var rPrevious = rSelected.first().prev()[0];' . "\r\n\t\t\t\t" . "if (\$(rPrevious).html() != '') {" . "\r\n\t\t\t\t\t" . 'rSelected.first().prev().before(rSelected);' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());' . "\r\n" . "            \$('#sort_' + rType + '_l option:selected').each(function() {" . "\r\n" . '                $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").prop("selected", true);' . "\r\n" . '            });' . "\r\n" . "            \$('#sort_' + rType + '_r').scrollTop(\$('#sort_' + rType + '_l').scrollTop());" . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function MoveDown(rType) {' . "\r\n\t\t\t" . "var rSelected = \$('#sort_' + rType + '_l option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . 'rSelected.last().next().after(rSelected);' . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());' . "\r\n" . "            \$('#sort_' + rType + '_l option:selected').each(function() {" . "\r\n" . '                $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").prop("selected", true);' . "\r\n" . '            });' . "\r\n" . "            \$('#sort_' + rType + '_r').scrollTop(\$('#sort_' + rType + '_l').scrollTop());" . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function MoveTop(rType) {' . "\r\n\t\t\t" . "var rSelected = \$('#sort_' + rType + '_l option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . "rSelected.prependTo(\$('#sort_' + rType + '_l'));" . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());' . "\r\n" . "            \$('#sort_' + rType + '_l option:selected').each(function() {" . "\r\n" . '                $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").prop("selected", true);' . "\r\n" . '            });' . "\r\n" . "            \$('#sort_' + rType + '_r').scrollTop(\$('#sort_' + rType + '_l').scrollTop());" . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function MoveBottom(rType) {' . "\r\n\t\t\t" . "var rSelected = \$('#sort_' + rType + '_l option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . "rSelected.appendTo(\$('#sort_' + rType + '_l'));" . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());' . "\r\n" . "            \$('#sort_' + rType + '_l option:selected').each(function() {" . "\r\n" . '                $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").prop("selected", true);' . "\r\n" . '            });' . "\r\n" . "            \$('#sort_' + rType + '_r').scrollTop(\$('#sort_' + rType + '_l').scrollTop());" . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n\t\t\t\t" . 'var rOrder = {"stream": [], "movie": [], "radio": [], "series": []};' . "\r\n\t\t\t\t" . "\$('#sort_stream_l option').each(function() {" . "\r\n\t\t\t\t\t" . 'rOrder["stream"].push($(this).val());' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . "\$('#sort_movie_l option').each(function() {" . "\r\n\t\t\t\t\t" . 'rOrder["movie"].push($(this).val());' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . "\$('#sort_radio_l option').each(function() {" . "\r\n\t\t\t\t\t" . 'rOrder["radio"].push($(this).val());' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . "\$('#sort_series_l option').each(function() {" . "\r\n\t\t\t\t\t" . 'rOrder["series"].push($(this).val());' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$("#stream_order_array").val(JSON.stringify(rOrder));' . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '            });' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>