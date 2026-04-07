<section class="section section--first">
	<div class="details__bg" data-bg="<?= $rCover ?>"></div>
	<div class="container">
		<div class="row">
			<div class="col-12">
				<div class="section__wrap">
					<h2 class="section__title" id="now__playing__title"><?= strtoupper(htmlspecialchars($rSearchBy)) ?: 'LIVE TV' ?></h2>
					<button onClick="closeChannel();" class="close__btn" type="button" style="display: none;">CLOSE</button>
				</div>
				<span id="now__playing__box" style="display: none;">
					<h3 class="card__title" id="now__playing__epg"></h3>
					<span class="card__rate" id="now__playing__text"></span>
					<video id="now__playing__player" class="video-js vjs-fantasy" controls preload="auto"></video>
				</span>
			</div>
		</div>
	</div>
</section>
<?php if (!$rSearchBy): ?>
<div class="filter">
	<div class="container">
		<div class="row">
			<div class="col-12">
				<div class="filter__content">
					<div class="filter__items">
						<div class="filter__item" id="filter__genre">
							<span class="filter__item-label">CATEGORY:</span>
							<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-genre" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<input type="button" value="<?= !empty($rCategoryID) ? CategoryService::getFromDatabase()[$rCategoryID]['category_name'] : $rCategories[0]['title'] ?>">
								<span></span>
							</div>
							<ul class="filter__item-menu dropdown-menu scrollbar-dropdown" aria-labelledby="filter-genre">
								<?php foreach ($rCategories as $rCategory): ?>
								<li><?= $rCategory['title'] ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
						<div class="filter__item" id="filter__filter">
							<span class="filter__item-label">FILTER:</span>
							<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-archive" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<input type="button" value="<?= isset($rFilterBy) ? $rFilterArray[$rFilterBy] : 'All Channels' ?>">
								<span></span>
							</div>
							<ul class="filter__item-menu dropdown-menu scrollbar-dropdown" aria-labelledby="filter-archive">
								<?php foreach ($rFilterArray as $rKey => $rValue): ?>
								<li><?= $rValue ?></li>
								<?php endforeach; ?>
							</ul>
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
<div class="catalog details<?= $rSearchBy ? ' top-margin-med' : '' ?>">
	<div class="container">
		<div class="row">
			<div class="col-12">
				<?php if (count($rStreamIDs) > 0): ?>
				<div class="listings-grid-container">
					<a href="#" class="listings-direction-link left day-nav-arrow js-day-nav-arrow" data-direction="prev"><span class="isvg isvg-left-dir"></span></a>
					<a href="#" class="listings-direction-link right day-nav-arrow js-day-nav-arrow" data-direction="next"><span class="isvg isvg-right-dir"></span></a>
					<div class="listings-day-slider-wrapper">
						<div class="listings-day-slider js-listings-day-slider">
							<div class="js-listings-day-nav-inner"></div>
						</div>
					</div>
					<div class="js-billboard-fix-point"></div>
					<div class="listings-grid-inner">
						<div class="time-nav-bar cf js-time-nav-bar">
							<div class="listings-mobile-nav">
								<a class="listings-now-btn js-now-btn" href="#">NOW</a>
							</div>
							<div class="listings-times-wrapper">
								<a href="#" class="listings-direction-link left js-time-nav-arrow" data-direction="prev"><span class="isvg isvg-left-dir"></span></a>
								<a href="#" class="listings-direction-link right js-time-nav-arrow" data-direction="next"><span class="isvg isvg-right-dir"></span></a>
								<div class="times-slider js-times-slider"></div>
							</div>
							<div class="listings-loader js-listings-loader"><span class="isvg isvg-loader animate-spin"></span></div>
						</div>
						<div class="listings-wrapper cf js-listings-wrapper">
							<div class="listings-timeline js-listings-timeline"></div>
							<div class="js-listings-container"></div>
						</div>
					</div>
				</div>
				<?php else: ?>
				<div class="results_form">
					<div class="row">
						<div class="col-12">
							<h4 class="results__error">No Live Channels or Programmes have been found matching your search terms.</h4>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
