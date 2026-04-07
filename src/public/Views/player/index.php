<?php if (count($rPopularNow) > 0): ?>
<section class="home">
	<div class="owl-carousel home__bg">
		<?php foreach ($rPopularNow as $rItem): ?>
		<div class="item home__cover" data-bg="<?= $rItem['backdrop'] ?>"></div>
		<?php endforeach; ?>
	</div>
	<div class="container">
		<div class="row">
			<div class="col-12">
				<h1 class="home__title">POPULAR <b>NOW</b></h1>
				<button class="home__nav home__nav--prev" type="button">
					<i class="icon ion-ios-arrow-round-back"></i>
				</button>
				<button class="home__nav home__nav--next" type="button">
					<i class="icon ion-ios-arrow-round-forward"></i>
				</button>
			</div>
			<div class="col-12">
				<div class="owl-carousel home__carousel">
					<?php foreach ($rPopularNow as $rItem): ?>
					<div class="item">
						<div class="card card--big">
							<div class="card__cover">
								<img loading="lazy" src="resize.php?url=<?= urlencode($rItem['cover']) ?>&w=267&h=400" alt="">
								<a href="<?= $rItem['type'] ?>.php?id=<?= $rItem['id'] ?>" class="card__play">
									<i class="icon ion-ios-play"></i>
								</a>
							</div>
							<div class="card__content">
								<h3 class="card__title"><a href="<?= $rItem['type'] ?>.php?id=<?= $rItem['id'] ?>"><?= htmlspecialchars($rItem['title']) ?></a></h3>
								<span class="card__rate"><?= $rItem['year'] ? intval($rItem['year']) . ' &nbsp; ' : '' ?><i class="icon ion-ios-star"></i><?= $rItem['rating'] ? number_format($rItem['rating'], 1) : 'N/A' ?></span>
							</div>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
</section>
<?php endif; ?>
<section class="content"<?= count($rPopularNow) == 0 ? ' style="margin-top: 10px;"' : '' ?>>
	<div class="content__head">
		<div class="container">
			<div class="row">
				<div class="col-12">
					<h1 class="home__title" style="margin-top:30px;">NEWLY <b>ADDED</b></h1>
					<ul class="nav nav-tabs content__tabs" id="content__tabs" role="tablist">
						<li class="nav-item">
							<a class="nav-link active" data-toggle="tab" href="#movies" role="tab" aria-controls="movies" aria-selected="true">MOVIES</a>
						</li>
						<li class="nav-item">
							<a class="nav-link" data-toggle="tab" href="#series" role="tab" aria-controls="series" aria-selected="false">TV SERIES</a>
						</li>
					</ul>
					<div class="content__mobile-tabs" id="content__mobile-tabs">
						<div class="content__mobile-tabs-btn dropdown-toggle" role="navigation" id="mobile-tabs" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
							<input type="button" value="Movies">
							<span></span>
						</div>
						<div class="content__mobile-tabs-menu dropdown-menu" aria-labelledby="mobile-tabs">
							<ul class="nav nav-tabs" role="tablist">
								<li class="nav-item"><a class="nav-link active" id="movies-tab" data-toggle="tab" href="#movies" role="tab" aria-controls="movies" aria-selected="true">MOVIES</a></li>
								<li class="nav-item"><a class="nav-link" id="series-tab" data-toggle="tab" href="#series" role="tab" aria-controls="series" aria-selected="false">TV SERIES</a></li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="container">
		<div class="tab-content">
			<div class="tab-pane fade show active" id="movies" role="tabpanel" aria-labelledby="movies-tab">
				<div class="row">
					<?php foreach ($rMovies['streams'] as $rStreamID => $rStream): $rProperties = json_decode($rStream['movie_properties'], true); ?>
					<div class="col-6 col-sm-4 col-lg-3 col-xl-3">
						<div class="card">
							<div class="card__cover">
								<img loading="lazy" src="resize.php?url=<?= urlencode(ImageUtils::validateURL($rProperties['movie_image']) ?: '') ?>&w=267&h=400" alt="">
								<a href="movie.php?id=<?= $rStream['id'] ?>" class="card__play">
									<i class="icon ion-ios-play"></i>
								</a>
							</div>
							<div class="card__content">
								<h3 class="card__title"><a href="movie.php?id=<?= $rStream['id'] ?>"><?= htmlspecialchars($rStream['stream_display_name']) ?></a></h3>
								<span class="card__rate"><?= $rStream['year'] ? intval($rStream['year']) . ' &nbsp; ' : '' ?><i class="icon ion-ios-star"></i><?= $rProperties['rating'] ? number_format($rProperties['rating'], 1) : 'N/A' ?></span>
							</div>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="tab-pane fade" id="series" role="tabpanel" aria-labelledby="series-tab">
				<div class="row">
					<?php foreach ($rSeries['streams'] as $rStreamID => $rStream): ?>
					<div class="col-6 col-sm-4 col-lg-3 col-xl-3">
						<div class="card">
							<div class="card__cover">
								<img loading="lazy" src="resize.php?url=<?= urlencode(ImageUtils::validateURL($rStream['cover']) ?: '') ?>&w=267&h=400" alt="">
								<a href="episodes.php?id=<?= $rStream['id'] ?>" class="card__play">
									<i class="icon ion-ios-play"></i>
								</a>
							</div>
							<div class="card__content">
								<h3 class="card__title"><a href="episodes.php?id=<?= $rStream['id'] ?>"><?= htmlspecialchars($rStream['title']) ?></a></h3>
								<span class="card__rate"><?= $rStream['year'] ? intval($rStream['year']) . ' &nbsp; ' : ($rStream['releaseDate'] ? intval(substr($rStream['releaseDate'], 0, 4)) . ' &nbsp; ' : '') ?><i class="icon ion-ios-star"></i><?= $rStream['rating'] ? number_format($rStream['rating'], 0) : 'N/A' ?></span>
							</div>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
</section>
