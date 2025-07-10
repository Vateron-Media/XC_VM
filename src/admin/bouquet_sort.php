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