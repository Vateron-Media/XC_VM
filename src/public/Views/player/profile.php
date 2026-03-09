<section class="section section--first section--bg" data-bg="images/pattern.png">
	<div class="container">
		<div class="row">
			<div class="col-12">
				<div class="section__wrap">
					<h2 class="section__title">PROFILE</h2>
				</div>
			</div>
		</div>
	</div>
</section>
<div class="content">
	<div class="profile">
		<div class="container">
			<div class="row">
				<div class="col-12">
					<div class="profile__content">
						<div class="profile__user">
							<div class="profile__meta">
								<h3>Username</h3>
								<span><?= htmlspecialchars($rUserInfo['username']) ?></span>
							</div>
						</div>
						<ul class="nav nav-tabs content__tabs content__tabs--profile" id="content__tabs" role="tablist">
							<li class="nav-item">
								<a class="nav-link active" data-toggle="tab" href="#tab-profile" role="tab" aria-controls="tab-profile" aria-selected="true">Profile</a>
							</li>
							<?php if (SettingsManager::getAll()['player_allow_bouquet']): ?>
							<li class="nav-item">
								<a class="nav-link" data-toggle="tab" href="#tab-bouquets" role="tab" aria-controls="tab-bouquets" aria-selected="false">Bouquets</a>
							</li>
							<?php endif; ?>
						</ul>
						<div class="content__mobile-tabs content__mobile-tabs--profile" id="content__mobile-tabs">
							<div class="content__mobile-tabs-btn dropdown-toggle" role="navigation" id="mobile-tabs" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<input type="button" value="Profile">
								<span></span>
							</div>
							<div class="content__mobile-tabs-menu dropdown-menu" aria-labelledby="mobile-tabs">
								<ul class="nav nav-tabs" role="tablist">
									<li class="nav-item"><a class="nav-link active" id="profile-tab" data-toggle="tab" href="#tab-profile" role="tab" aria-controls="tab-profile" aria-selected="true">Profile</a></li>
									<?php if (SettingsManager::getAll()['player_allow_bouquet']): ?>
									<li class="nav-item"><a class="nav-link" id="bouquets-tab" data-toggle="tab" href="#tab-bouquets" role="tab" aria-controls="tab-bouquets" aria-selected="false">Bouquets</a></li>
									<?php endif; ?>
								</ul>
							</div>
						</div>
						<button class="profile__logout" type="button" onClick="doLogout()">
							<span>Logout</span>
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="container">
		<div class="tab-content">
			<div class="tab-pane fade show active" id="tab-profile" role="tabpanel" aria-labelledby="profile-tab">
				<div class="row">
					<div class="col-12<?= SettingsManager::getAll()['player_allow_playlist'] ? ' col-lg-6' : '' ?>">
						<form action="#" class="profile__form">
							<div class="row">
								<div class="col-12">
									<h4 class="profile__title">Line Details</h4>
								</div>
								<div class="col-12 col-md-6 col-lg-12 col-xl-6">
									<div class="profile__group">
										<label class="profile__label" for="username">Username</label>
										<input id="username" type="text" name="username" class="profile__input" value="<?= htmlentities($rUserInfo['username']) ?>" readonly>
									</div>
								</div>
								<div class="col-12 col-md-6 col-lg-12 col-xl-6">
									<div class="profile__group">
										<label class="profile__label" for="password">Password</label>
										<input id="password" type="text" name="password" class="profile__input" value="<?= htmlentities($rUserInfo['password']) ?>" readonly>
									</div>
								</div>
								<div class="col-12 col-md-12 col-lg-12 col-xl-12">
									<div class="profile__group">
										<label class="profile__label" for="expiry">Expiry Date</label>
										<input id="expiry" type="text" name="expiry" class="profile__input" value="<?= $rUserInfo['exp_date'] ? date('l jS F Y h:i A', $rUserInfo['exp_date']) : 'Never' ?>" readonly>
									</div>
								</div>
							</div>
						</form>
					</div>
					<?php if (SettingsManager::getAll()['player_allow_playlist']): ?>
					<div class="col-12 col-lg-6">
						<form action="#" class="profile__form">
							<div class="row">
								<div class="col-12">
									<h4 class="profile__title">Playlist</h4>
								</div>
								<div class="col-12 col-md-12 col-lg-12 col-xl-12">
									<div class="profile__group">
										<label class="profile__label" for="download_type">Format</label>
										<select id="download_type" class="profile__input" data-toggle="select2">
											<?php $db->query('SELECT * FROM `output_devices` WHERE `copy_text` IS NULL ORDER BY `device_id` ASC;'); ?>
											<?php foreach ($db->get_rows() as $rRow): ?>
											<optgroup label="<?= $rRow['device_name'] ?>">
												<option value="<?= $rRow['device_key'] ?>?output=hls"><?= $rRow['device_name'] ?> - HLS</option>
												<option value="<?= $rRow['device_key'] ?>"><?= $rRow['device_name'] ?> - MPEGTS</option>
											</optgroup>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
								<div class="col-12 col-md-12 col-lg-12 col-xl-12">
									<div class="profile__group">
										<label class="profile__label" for="output_type">Output</label>
										<select id="output_type" class="profile__input" data-toggle="select2">
											<option value="" selected>Everything</option>
											<option value="live">Live Streams</option>
											<option value="movie">Movies</option>
											<option value="created_live">Created Channels</option>
											<option value="radio_streams">Radio Stations</option>
											<option value="series">TV Series</option>
										</select>
									</div>
								</div>
							</div>
						</form>
					</div>
					<div class="col-12 col-lg-12">
						<form action="#" class="profile__form">
							<div class="row">
								<div class="col-12">
									<h4 class="profile__title">Download URL</h4>
								</div>
								<div class="col-12 col-md-12 col-lg-12 col-xl-12">
									<div class="profile__group">
										<input type="text" class="profile__input" id="download_url" value="" readonly>
									</div>
								</div>
							</div>
						</form>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<?php if (SettingsManager::getAll()['player_allow_bouquet']): ?>
			<div class="tab-pane fade hide" id="tab-bouquets" role="tabpanel" aria-labelledby="bouquets-tab">
				<div class="row">
					<div class="col-12 col-lg-12">
						<form action="profile" class="profile__form" id="bouquet__form" method="POST">
							<input type="hidden" id="bouquet_order_array" name="bouquet_order" value="">
							<div class="row">
								<div class="col-12">
									<h4 class="profile__title">Bouquet Order</h4>
								</div>
								<div class="col-12 col-md-12 col-lg-12 col-xl-12">
									<div class="profile__group">
										<select multiple="" id="sort_bouquet" class="profile__input" style="min-height:250px;">
											<?php foreach ($rUserInfo['bouquet'] as $rBouquet): ?>
							<?php if (isset($rBouquetNames[$rBouquet])): ?>
							<option value="<?= intval($rBouquet) ?>"><?= htmlentities($rBouquetNames[$rBouquet]) ?></option>
							<?php endif; ?>
											<?php endforeach; ?>
										</select>
										<ul>
											<li class="move__buttons">
												<button type="button" onClick="MoveUp()"><i class="icon ion-md-arrow-dropup"></i></button>
												<button type="button" onClick="MoveDown()"><i class="icon ion-md-arrow-dropdown"></i></button>
												<button type="button" onClick="AtoZ()">A to Z</button>
												<button type="submit" class="save__button">Save Changes</button>
											</li>
										</ul>
									</div>
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>
