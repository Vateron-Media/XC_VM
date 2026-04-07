<section class="section section--first">
	<div class="details__bg" data-bg="<?= $rCover ?>"></div>
	<div class="container">
		<div class="row">
			<div class="col-12">
				<div class="section__wrap">
					<h2 class="section__title"><?= $rSearchBy ? strtoupper(htmlspecialchars($rSearchBy)) : ($rPopular ? 'POPULAR MOVIES' : 'MOVIES') ?></h2>
					<?php if ($rSearchBy): ?>
					<button class="clear__btn wide" type="button">CLEAR</button>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</section>
<?php if (!$rPopular && !$rSearchBy): ?>
<div class="filter">
	<div class="container">
		<div class="row">
			<div class="col-12">
				<div class="filter__content">
					<div class="filter__items">
						<div class="filter__item" id="filter__genre">
							<span class="filter__item-label">GENRE:</span>
							<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-genre" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<input type="button" value="<?= isset($rCategoryID) ? CategoryService::getFromDatabase()[$rCategoryID]['category_name'] : 'All Genres' ?>">
								<span></span>
							</div>
							<ul class="filter__item-menu dropdown-menu scrollbar-dropdown" aria-labelledby="filter-genre">
								<?php foreach (getOrderedCategories($rUserInfo['category_ids']) as $rCategory): ?>
								<li><?= $rCategory['title'] ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
						<div class="filter__item" id="filter__rate">
							<span class="filter__item-label">RATING:</span>
							<div class="filter__item-btn dropdown-toggle" role="button" id="filter-rate" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<div class="filter__range">
									<div id="filter__rating-start"></div>
									<div id="filter__rating-end"></div>
								</div>
								<span></span>
							</div>
							<div class="filter__item-menu filter__item-menu--range dropdown-menu" aria-labelledby="filter-rate">
								<div id="filter__rating"></div>
							</div>
						</div>
						<div class="filter__item" id="filter__year">
							<span class="filter__item-label">YEAR:</span>
							<div class="filter__item-btn dropdown-toggle" role="button" id="filter-year" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<div class="filter__range">
									<div id="filter__years-start"></div>
									<div id="filter__years-end"></div>
								</div>
								<span></span>
							</div>
							<div class="filter__item-menu filter__item-menu--range dropdown-menu" aria-labelledby="filter-year">
								<div id="filter__years"></div>
							</div>
						</div>
						<div class="filter__item" id="filter__sort">
							<span class="filter__item-label">SORT:</span>
							<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-quality" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<input type="button" value="<?= isset($rSortBy) ? $rSortArray[$rSortBy] : 'Date Added' ?>">
								<span></span>
							</div>
							<ul class="filter__item-menu dropdown-menu scrollbar-dropdown" aria-labelledby="filter-quality">
								<?php foreach ($rSortArray as $rKey => $rValue): ?>
								<li><?= $rValue ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
					<div>
						<button class="filter__btn" type="button">filter</button>
						<button class="clear__btn" type="button">X</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>
<div class="catalog details<?= ($rPopular || $rSearchBy) ? ' top-margin-med' : '' ?>">
	<div class="container">
		<div class="row">
			<?php foreach ($rStreams['streams'] as $rStreamID => $rStream): $rProperties = json_decode($rStream['movie_properties'], true); ?>
			<div class="col-6 col-sm-4 col-lg-3 col-xl-3">
				<div class="card">
					<div class="card__cover">
						<img loading="lazy" src="resize.php?url=<?= urlencode(ImageUtils::validateURL($rProperties['movie_image']) ?: '') ?>&w=267&h=400" alt="">
						<a href="movie.php?id=<?= $rStream['id'] ?>" class="card__play">
							<i class="icon ion-ios-play"></i>
						</a>
					</div>
					<div class="card__content">
						<h3 class="card__title"><a href="movie.php?id=<?= $rStream['id'] ?>"><?= htmlspecialchars($rStream['title'] ?? $rStream['stream_display_name']) ?></a></h3>
						<span class="card__rate"><?= $rStream['year'] ? intval($rStream['year']) . ' &nbsp; ' : '' ?><i class="icon ion-ios-star"></i><?= $rProperties['rating'] ? number_format($rProperties['rating'], 1) : 'N/A' ?></span>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
			<?php if (!$rPopular): ?>
			<div class="col-12">
				<ul class="paginator">
					<?php if ($rPage > 1): ?>
					<li class="paginator__item paginator__item--prev">
						<a href="movies.php?page=<?= $rPage - 1 ?>"><i class="icon ion-ios-arrow-back"></i></a>
					</li>
					<?php endif; ?>
					<?php if ($rPagination[0] > 1): ?>
					<li class="paginator__item<?= $rPage == 1 ? ' paginator__item--active' : '' ?>"><a href="movies.php?page=1">1</a></li>
					<?php if (count($rPagination) > 1): ?>
					<li class="paginator__item"><a href="javascript: void(0);">...</a></li>
					<?php endif; ?>
					<?php endif; ?>
					<?php foreach ($rPagination as $i): ?>
					<li class="paginator__item<?= $rPage == $i ? ' paginator__item--active' : '' ?>"><a href="movies.php?page=<?= $i ?>"><?= $i ?></a></li>
					<?php endforeach; ?>
					<?php if ($rPagination[count($rPagination) - 1] < $rPages): ?>
					<?php if (count($rPagination) > 1): ?>
					<li class="paginator__item"><a href="javascript: void(0);">...</a></li>
					<?php endif; ?>
					<li class="paginator__item<?= $rPage == $rPages ? ' paginator__item--active' : '' ?>"><a href="movies.php?page=<?= $rPages ?>"><?= $rPages ?></a></li>
					<?php endif; ?>
					<?php if ($rPage < $rPages): ?>
					<li class="paginator__item paginator__item--next">
						<a href="movies.php?page=<?= $rPage + 1 ?>"><i class="icon ion-ios-arrow-forward"></i></a>
					</li>
					<?php endif; ?>
				</ul>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php
if (!$rPopular):
	$rPopular = (igbinary_unserialize(file_get_contents(CONTENT_PATH . 'tmdb_popular'))['movies'] ?: array());
	if (count($rPopular) > 0 && count($rUserInfo['vod_ids']) > 0):
		$db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` FROM `streams` WHERE `id` IN (' . implode(',', $rPopular) . ') AND `id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular) . ') ASC LIMIT 6;');
		$rStreams = $db->get_rows();
		$rShuffle = $rStreams;
		shuffle($rShuffle);
		foreach ($rShuffle as $rStream) {
			$rProperties = json_decode($rStream['movie_properties'], true);
			if (!empty($rProperties['backdrop_path'][0])) {
				$rCover = ImageUtils::validateURL($rProperties['backdrop_path'][0]);
				break;
			}
		}
?>
<section class="section">
	<div class="details__bg" data-bg="<?= $rCover ?>"></div>
	<div class="container">
		<div class="row">
			<div class="col-12">
				<h1 class="home__title bottom-margin-sml">POPULAR <b>THIS WEEK</b></h1>
			</div>
			<?php foreach ($rStreams as $rStream): $rProperties = json_decode($rStream['movie_properties'], true); ?>
			<div class="col-6 col-sm-4 col-lg-3 col-xl-2">
				<div class="card">
					<div class="card__cover">
						<img loading="lazy" src="resize.php?url=<?= urlencode(ImageUtils::validateURL($rProperties['movie_image']) ?: '') ?>&w=267&h=400" alt="">
						<a href="movie.php?id=<?= $rStream['id'] ?>" class="card__play">
							<i class="icon ion-ios-play"></i>
						</a>
					</div>
					<div class="card__content">
						<h3 class="card__title"><a href="movie.php?id=<?= $rStream['id'] ?>"><?= htmlspecialchars($rStream['title'] ?: $rStream['stream_display_name']) ?></a></h3>
						<span class="card__rate"><?= $rStream['year'] ? intval($rStream['year']) . ' &nbsp; ' : '' ?><i class="icon ion-ios-star"></i><?= $rProperties['rating'] ? number_format($rProperties['rating'], 1) : 'N/A' ?></span>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
			<div class="col-12">
				<a href="movies.php?sort=popular" class="section__btn">Show more</a>
			</div>
		</div>
	</div>
</section>
<?php
	endif;
endif;
?>
