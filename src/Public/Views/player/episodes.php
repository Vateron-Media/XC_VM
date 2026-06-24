<section class="section details">
	<div class="details__bg" data-bg="<?= $rCover ?>"></div>
	<div class="container top-margin">
		<div class="row">
			<div class="col-12">
				<h1 class="details__title"><?= $rSeries['title'] ?><br/>
					<ul class="card__list">
						<?php foreach (json_decode($rSeries['category_id'], true) as $rCategoryID): ?>
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
									<span class="card__rate"><?= $rSeries['year'] ? $rSeries['year'] . ' &nbsp; ' : '' ?><i class="icon ion-ios-star"></i><?= $rSeries['rating'] ?: 'N/A' ?></span>
								</div>
								<ul class="card__meta">
									<li><span><strong>Duration:</strong></span> <?= intval($rSeries['episode_run_time']) ?> min</li>
									<li>
										<span><strong>Cast:</strong></span>
										<?= implode(', ', array_slice(explode(',', $rSeries['cast']), 0, 5)) ?>
									</li>
								</ul>
								<div class="card__description card__description--details">
									<?= $rSeries['plot'] ?>
								</div>
							</div>
						</div>
					</div>
					<?php if ($rLegacy): ?>
					<div class="row top-margin-sml" id="player_row" style="display: none;">
						<div class="col-12">
							<video controls width="100%" preload="none" id="video__player">
								<source src="" type="video/mp4" />
							</video>
						</div>
					</div>
					<?php else: ?>
					<div class="row top-margin-sml">
						<div class="col-12">
							<div id="player_row" style="display: none;">
								<video id="now__playing__player" class="video-js vjs-fantasy" controls preload="auto"></video>
							</div>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</section>
<section class="seasons">
	<?php if (count($rEpisodes) == 0): ?>
	<div class="container">
		<div class="row">
			<div class="col-12">
				<div class="alert alert-danger">
					No episodes are available for this series. Please check back later.
				</div>
			</div>
		</div>
	</div>
	<?php else: ?>
	<div class="owl-carousel seasons__bg">
		<?php foreach ($rEpisodes as $rEpisode): ?>
		<div class="item seasons__cover" data-bg="<?= $rSeasonArray[$rEpisode['episode_num']]['image_cover'] ?>"></div>
		<?php endforeach; ?>
	</div>
	<div class="container">
		<div class="row">
			<div class="col-12">
				<h1 class="seasons__title">
					<select id="season__select">
						<?php foreach ($rSeasons as $i): ?>
						<option<?= $rSeasonNo == $i ? ' selected' : '' ?>>Season <?= $i ?></option>
						<?php endforeach; ?>
					</select>
				</h1>
				<button class="seasons__nav seasons__nav--prev" type="button">
					<i class="icon ion-ios-arrow-round-back"></i>
				</button>
				<button class="seasons__nav seasons__nav--next" type="button">
					<i class="icon ion-ios-arrow-round-forward"></i>
				</button>
			</div>
			<div class="col-12">
				<div class="owl-carousel seasons__carousel">
					<?php
					$i = 0;
					foreach ($rEpisodes as $rRow):
						$i++;
						$rProperties = json_decode($rRow['movie_properties'], true);
						$rEpisodeData = $rSeasonArray[$rRow['episode_num']];
					?>
					<div class="item" id="episode_<?= $rRow['id'] ?>" data-index="<?= $i - 1 ?>">
						<div class="card card--big">
							<div class="card__cover">
								<img loading="lazy" src="<?= $rEpisodeData['image'] ?>" alt="">
								<a href="javascript:void(0)" onClick="openPlayer(<?= $rRow['id'] ?>);" class="card__play">
									<i class="icon ion-ios-play"></i>
								</a>
							</div>
							<div class="card__content">
								<h3 class="card__title"><a href="javascript:void(0);" onClick="openPlayer(<?= $rRow['id'] ?>);"><?= sprintf('%02d', $rRow['episode_num']) ?> - <?= $rEpisodeData['title'] ?></a></h3>
								<span class="card__episode">
									<?= strlen($rEpisodeData['description']) > 500 ? substr($rEpisodeData['description'], 0, 500) . '...' : $rEpisodeData['description'] ?>
								</span>
								<ul class="card__list card__danger" style="display: none;">
									<li>UNAVAILABLE</li>
								</ul>
								<span class="card__rate"><i class="icon ion-ios-star"></i><?= $rEpisodeData['rating'] ? number_format($rEpisodeData['rating'], 1) : ($rSeries['rating'] ? number_format($rSeries['rating'], 1) : 'N/A') ?></span>
							</div>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>
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
								<a href="episodes?id=<?= $rItem['id'] ?>" class="card__play">
									<i class="icon ion-ios-play"></i>
								</a>
							</div>
							<div class="card__content">
								<h3 class="card__title"><a href="episodes?id=<?= $rItem['id'] ?>"><?= htmlspecialchars($rItem['title']) ?></a></h3>
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
