<section class="section details">
	<div class="details__bg" data-bg="<?= $rCover ?>"></div>
	<div class="container top-margin">
		<div class="row">
			<div class="col-12">
				<h1 class="details__title"><?= $rStream['stream_display_name'] ?><br/>
					<ul class="card__list">
						<?php foreach (json_decode($rStream['category_id'], true) as $rCategoryID): ?>
						<li><?= CategoryService::getFromDatabase()[$rCategoryID]['category_name'] ?></li>
						<?php endforeach; ?>
					</ul>
				</h1>
			</div>
			<div class="col-12 col-xl-12">
				<div class="card card--details">
					<div class="row">
						<div class="col-12 col-sm-3 col-md-3 col-lg-3 col-xl-3">
							<div class="card__cover">
								<img src="<?= $rPoster ?>" alt="">
							</div>
						</div>
						<div class="col-12 col-sm-9 col-md-9 col-lg-9 col-xl-9">
							<div class="card__content">
								<div class="card__wrap">
									<span class="card__rate"><?= $rStream['year'] ? $rStream['year'] . ' &nbsp; ' : '' ?><i class="icon ion-ios-star"></i><?= $rProperties['rating'] ?: 'N/A' ?></span>
								</div>
								<ul class="card__meta">
									<li><span><strong>Duration:</strong></span> <?= intval($rProperties['duration_secs'] / 60) ?> min</li>
									<li><span><strong>Country:</strong></span> <a href="#"><?= $rProperties['country'] ?></a></li>
									<li>
										<span><strong>Cast:</strong></span>
										<?= implode(', ', array_slice(explode(',', $rProperties['cast']), 0, 5)) ?>
									</li>
								</ul>
								<div class="card__description card__description--details">
									<?= $rProperties['description'] ?>
								</div>
							</div>
						</div>
					</div>
					<div class="row top-margin-sml">
						<div class="col-12">
							<div class="alert alert-danger" id="player__error" style="display: none;"></div>
							<div id="player_row">
								<?php if ($rLegacy): ?>
								<video controls width="100%" autoplay>
									<source src="<?= $rURLs[0] ?>" type="video/mp4" />
									<?php foreach ($rSubtitles[0] as $rSubtitle): ?>
									<track label="<?= $rSubtitle['label'] ?>" kind="subtitles" src="proxy.php?url=<?= Encryption::encrypt($rSubtitle['file'], SettingsManager::getAll()['live_streaming_pass'], 'd8de497ebccf4f4697a1da20219c7c33') ?>">
									<?php endforeach; ?>
								</video>
								<?php else: ?>
								<video id="now__playing__player" class="video-js vjs-fantasy" controls preload="auto"></video>
								<?php endif; ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>
<?php if (count($rSimilar) > 0): ?>
<section class="content">
	<div class="container" style="margin-top: 30px;">
		<div class="row">
			<div class="col-12 col-lg-12 col-xl-12">
				<div class="row">
					<div class="col-12">
						<h2 class="section__title section__title--sidebar">Users Also Watched</h2>
					</div>
					<?php foreach (array_slice($rSimilar, 0, 6) as $rItem): ?>
					<div class="col-4 col-sm-4 col-lg-2">
						<div class="card">
							<div class="card__cover">
								<img loading="lazy" src="resize.php?url=<?= urlencode($rItem['cover']) ?>&w=267&h=400" alt="">
								<a href="movie.php?id=<?= $rItem['id'] ?>" class="card__play">
									<i class="icon ion-ios-play"></i>
								</a>
							</div>
							<div class="card__content">
								<h3 class="card__title"><a href="movie.php?id=<?= $rItem['id'] ?>"><?= htmlspecialchars($rItem['title']) ?></a></h3>
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
