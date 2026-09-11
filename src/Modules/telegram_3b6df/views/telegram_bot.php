<?php

/**
 * Telegram Bot Multi-step Wizard View (Admin).
 *
 * Provides a 4-step wizard for configuring a Telegram bot:
 * 1. Bot Credentials & Token Verification
 * 2. Target Channel / Chat ID & Permission Test
 * 3. Content Rules & Category Scoping
 * 4. Visual Formatting, Media Type & Live Telegram Mockup Preview
 */

$language = (!empty($language) && class_exists($language)) ? $language : \XcVm\Core\Localization\Translator::class;
$rBot = $bot ?? null;
$rIsEdit = !empty($isEdit);
$rCategories = $categories ?? ['movies' => [], 'series' => [], 'live' => []];

$botId = $rBot['id'] ?? 0;
$name = $rBot['name'] ?? '';
$token = $rBot['bot_token'] ?? '';
$username = $rBot['bot_username'] ?? '';
$chatId = $rBot['chat_id'] ?? '';
$types = $rBot['content_types_array'] ?? ['movies'];
$cats = $rBot['categories_array'] ?? [];
$imageType = $rBot['image_type'] ?? 'poster';
$notifyMovie = isset($rBot['notify_on_movie_complete']) ? (int)$rBot['notify_on_movie_complete'] : 1;
$notifyEpisode = isset($rBot['notify_on_episode']) ? (int)$rBot['notify_on_episode'] : 0;
$notifyLive = isset($rBot['notify_on_live']) ? (int)$rBot['notify_on_live'] : 0;
$customTemplate = $rBot['custom_template'] ?? '';
$silent = !empty($rBot['silent_notification']);
$includeButton = !empty($rBot['include_button']);
$buttonText = $rBot['button_text'] ?? '🎬 Watch Now';
$buttonUrl = $rBot['button_url'] ?? '';
$status = isset($rBot['status']) ? (int)$rBot['status'] : 1;
$hasCustomCats = !empty($cats);
?>

<div class="container-xxl flex-grow-1 container-p-y">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="icon-base ti tabler-brand-telegram text-primary fs-2"></i>
                <span><?= $rIsEdit ? 'Edit Telegram Bot: ' . htmlspecialchars($name) : 'Add New Telegram Bot'; ?></span>
            </h4>
            <p class="text-muted mb-0">Follow the 4-step wizard to setup credentials, test channel permissions, select content, and style broadcasts.</p>
        </div>
        <a href="telegram_bots" class="btn btn-outline-secondary d-flex align-items-center gap-1 shadow-sm">
            <i class="icon-base ti tabler-arrow-left"></i>
            <span>Back to Bots</span>
        </a>
    </div>

    <!-- Wizard Stepper Navigation -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="stepper-nav d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="step-indicator active d-flex align-items-center gap-2" data-step="1">
                    <span class="step-number badge rounded-pill bg-primary fs-7">1</span>
                    <div class="step-label">
                        <span class="fw-bold d-block fs-7">Bot Profile</span>
                        <span class="text-muted fs-8">Credentials & Token</span>
                    </div>
                </div>
                <div class="step-separator d-none d-md-block flex-grow-1 mx-3 border-top"></div>

                <div class="step-indicator d-flex align-items-center gap-2 text-muted" data-step="2">
                    <span class="step-number badge rounded-pill bg-label-secondary fs-7">2</span>
                    <div class="step-label">
                        <span class="fw-bold d-block fs-7">Destination</span>
                        <span class="text-muted fs-8">Target Channel / Chat</span>
                    </div>
                </div>
                <div class="step-separator d-none d-md-block flex-grow-1 mx-3 border-top"></div>

                <div class="step-indicator d-flex align-items-center gap-2 text-muted" data-step="3">
                    <span class="step-number badge rounded-pill bg-label-secondary fs-7">3</span>
                    <div class="step-label">
                        <span class="fw-bold d-block fs-7">Content & Rules</span>
                        <span class="text-muted fs-8">Movies, Episodes & Filters</span>
                    </div>
                </div>
                <div class="step-separator d-none d-md-block flex-grow-1 mx-3 border-top"></div>

                <div class="step-indicator d-flex align-items-center gap-2 text-muted" data-step="4">
                    <span class="step-number badge rounded-pill bg-label-secondary fs-7">4</span>
                    <div class="step-label">
                        <span class="fw-bold d-block fs-7">Visual Preview</span>
                        <span class="text-muted fs-8">Styling & Mockup</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Wizard Form -->
    <form id="botWizardForm">
        <input type="hidden" name="id" value="<?= (int)$botId; ?>">

        <!-- STEP 1: Bot Credentials -->
        <div class="wizard-step-pane" id="stepPane1">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="icon-base ti tabler-robot text-primary"></i>
                        <span>Step 1: Bot Profile & Credentials</span>
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="bot_name">Bot Friendly Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="bot_name" name="name" placeholder="e.g. Main Channel Movie Releases" value="<?= htmlspecialchars($name); ?>" required>
                            <small class="text-muted">A clear, descriptive name for identifying this bot in your panel.</small>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="bot_status">Bot Status</label>
                            <select class="form-select" id="bot_status" name="status">
                                <option value="1" <?= $status === 1 ? 'selected' : ''; ?>>Active (Broadcasting Enabled)</option>
                                <option value="0" <?= $status === 0 ? 'selected' : ''; ?>>Paused (Broadcasting Disabled)</option>
                            </select>
                            <small class="text-muted">You can pause or activate broadcasting anytime.</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold" for="bot_token">Telegram Bot Token <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="icon-base ti tabler-key"></i></span>
                                <input type="text" class="form-control" id="bot_token" name="bot_token" placeholder="e.g. 123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ" value="<?= htmlspecialchars($token); ?>" required>
                                <button type="button" class="btn btn-outline-primary" id="btnVerifyToken">
                                    <i class="icon-base ti tabler-check me-1"></i> Verify Token
                                </button>
                            </div>
                            <small class="text-muted d-block mt-1">
                                Obtained from <a href="https://t.me/BotFather" target="_blank" class="fw-semibold">@BotFather</a> on Telegram by running <code>/newbot</code>.
                            </small>

                            <!-- Live Verified Card -->
                            <div id="tokenVerifyResult" class="mt-3 <?= !empty($username) ? '' : 'd-none'; ?>">
                                <div class="alert alert-success d-flex align-items-center gap-3 p-3 mb-0">
                                    <div class="avatar avatar-sm bg-success text-white rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center">
                                        <i class="icon-base ti tabler-check"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <span class="fw-bold d-block" id="verifiedBotName"><?= htmlspecialchars($name ?: 'Verified Bot'); ?></span>
                                        <span class="fs-7 text-muted" id="verifiedBotUsername">@<?= htmlspecialchars($username); ?></span>
                                    </div>
                                    <span class="badge bg-success rounded-pill">Token Verified</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top d-flex justify-content-end p-3">
                    <button type="button" class="btn btn-primary js-next-step" data-target="2">
                        <span>Next: Target Channel</span>
                        <i class="icon-base ti tabler-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- STEP 2: Destination Channel / Chat -->
        <div class="wizard-step-pane d-none" id="stepPane2">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="icon-base ti tabler-broadcast text-primary"></i>
                        <span>Step 2: Target Channel or Group</span>
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-12 col-md-7">
                            <label class="form-label fw-semibold" for="chat_id">Target Channel or Chat ID <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="icon-base ti tabler-at"></i></span>
                                <input type="text" class="form-control" id="chat_id" name="chat_id" placeholder="e.g. @MyMoviesChannel or -1001928374650" value="<?= htmlspecialchars($chatId); ?>" required>
                                <button type="button" class="btn btn-outline-success" id="btnTestChat">
                                    <i class="icon-base ti tabler-send me-1"></i> Send Test Message
                                </button>
                            </div>
                            <small class="text-muted d-block mt-1">
                                Public channels use <code>@channelusername</code>. Private channels use numerical IDs starting with <code>-100...</code>.
                            </small>

                            <!-- Test Chat Result alert -->
                            <div id="chatTestAlert" class="mt-3 d-none"></div>

                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="silent_notification" name="silent_notification" value="1" <?= $silent ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="silent_notification">
                                    Silent Notifications
                                </label>
                                <small class="text-muted d-block">Subscribers will receive the notification without sound alerts.</small>
                            </div>
                        </div>

                        <!-- Channel Setup Instructions Card -->
                        <div class="col-12 col-md-5">
                            <div class="border rounded-3 p-3 bg-light-subtle h-100">
                                <h6 class="fw-bold mb-2 d-flex align-items-center gap-1 text-primary">
                                    <i class="icon-base ti tabler-info-circle"></i> Quick Setup Guide
                                </h6>
                                <ol class="ps-3 mb-0 fs-7 text-muted lh-lg">
                                    <li>Open your target Telegram Channel or Group.</li>
                                    <li>Go to <b>Channel Settings ➜ Administrators</b>.</li>
                                    <li>Click <b>Add Administrator</b> and search for your bot.</li>
                                    <li>Grant the <b>"Post Messages"</b> permission.</li>
                                    <li>Type your channel's public <code>@username</code> or private channel ID and click <b>"Send Test Message"</b> above!</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-outline-secondary js-prev-step" data-target="1">
                        <i class="icon-base ti tabler-arrow-left me-1"></i>
                        <span>Back</span>
                    </button>
                    <button type="button" class="btn btn-primary js-next-step" data-target="3">
                        <span>Next: Content & Rules</span>
                        <i class="icon-base ti tabler-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- STEP 3: Content Types & Categories -->
        <div class="wizard-step-pane d-none" id="stepPane3">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="icon-base ti tabler-category text-primary"></i>
                        <span>Step 3: Content Types & Category Filtering</span>
                    </h5>
                </div>
                <div class="card-body p-4">
                    <!-- Content Types Selection -->
                    <label class="form-label fw-bold mb-3 d-block">1. Select Content Types to Broadcast:</label>
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-4">
                            <div class="card border p-3 h-100 content-type-card cursor-pointer">
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" id="type_movies" name="type_movies" value="1" <?= in_array('movies', $types, true) || $notifyMovie ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold d-block ms-2" for="type_movies">
                                        🎬 Movies (VOD)
                                    </label>
                                </div>
                                <p class="text-muted fs-7 mt-2 mb-0 ms-4">
                                    Broadcast automatically when a movie has finished downloading and is verified by the system.
                                </p>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="card border p-3 h-100 content-type-card cursor-pointer">
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" id="type_episodes" name="type_episodes" value="1" <?= in_array('episodes', $types, true) || $notifyEpisode ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold d-block ms-2" for="type_episodes">
                                        📺 TV Series Episodes
                                    </label>
                                </div>
                                <p class="text-muted fs-7 mt-2 mb-0 ms-4">
                                    Broadcast automatically whenever new TV series episodes are encoded or imported.
                                </p>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="card border p-3 h-100 content-type-card cursor-pointer">
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" id="type_live" name="type_live" value="1" <?= in_array('live', $types, true) || $notifyLive ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold d-block ms-2" for="type_live">
                                        📡 Live Streams
                                    </label>
                                </div>
                                <p class="text-muted fs-7 mt-2 mb-0 ms-4">
                                    Broadcast when new live TV channels or event streams are created and launched.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Category Scope -->
                    <label class="form-label fw-bold mb-3 d-block">2. Category Filtering Scope:</label>
                    <div class="d-flex gap-4 mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="categories_mode" id="catModeAll" value="all" <?= !$hasCustomCats ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-semibold" for="catModeAll">
                                Broadcast All Categories (No Restrictions)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="categories_mode" id="catModeCustom" value="custom" <?= $hasCustomCats ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-semibold" for="catModeCustom">
                                Specific Categories Only
                            </label>
                        </div>
                    </div>

                    <!-- Custom Category Selection Box -->
                    <div id="categorySelectionContainer" class="border rounded-3 p-3 bg-light-subtle <?= $hasCustomCats ? '' : 'd-none'; ?>">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fs-7 fw-bold text-muted">Select permitted categories:</span>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-xs btn-outline-primary" id="btnSelectAllCats">Select All</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary" id="btnDeselectAllCats">Clear Selection</button>
                            </div>
                        </div>

                        <!-- Movies Categories -->
                        <?php if (!empty($rCategories['movies'])): ?>
                            <div class="mb-3">
                                <span class="fw-bold fs-7 d-block mb-2 text-primary">🎬 Movies Categories</span>
                                <div class="row g-2">
                                    <?php foreach ($rCategories['movies'] as $cat): ?>
                                        <div class="col-6 col-md-4 col-lg-3">
                                            <div class="form-check form-check-inline fs-7 m-0">
                                                <input class="form-check-input js-cat-checkbox" type="checkbox" name="categories[]" id="cat_<?= (int)$cat['id']; ?>" value="<?= (int)$cat['id']; ?>" <?= in_array((int)$cat['id'], $cats, true) ? 'checked' : ''; ?>>
                                                <label class="form-check-label text-truncate" for="cat_<?= (int)$cat['id']; ?>" title="<?= htmlspecialchars((string)$cat['category_name']); ?>">
                                                    <?= htmlspecialchars((string)$cat['category_name']); ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Series Categories -->
                        <?php if (!empty($rCategories['series'])): ?>
                            <div class="mb-3">
                                <span class="fw-bold fs-7 d-block mb-2 text-info">📺 Series Categories</span>
                                <div class="row g-2">
                                    <?php foreach ($rCategories['series'] as $cat): ?>
                                        <div class="col-6 col-md-4 col-lg-3">
                                            <div class="form-check form-check-inline fs-7 m-0">
                                                <input class="form-check-input js-cat-checkbox" type="checkbox" name="categories[]" id="cat_<?= (int)$cat['id']; ?>" value="<?= (int)$cat['id']; ?>" <?= in_array((int)$cat['id'], $cats, true) ? 'checked' : ''; ?>>
                                                <label class="form-check-label text-truncate" for="cat_<?= (int)$cat['id']; ?>" title="<?= htmlspecialchars((string)$cat['category_name']); ?>">
                                                    <?= htmlspecialchars((string)$cat['category_name']); ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Live Categories -->
                        <?php if (!empty($rCategories['live'])): ?>
                            <div>
                                <span class="fw-bold fs-7 d-block mb-2 text-success">📡 Live TV Categories</span>
                                <div class="row g-2">
                                    <?php foreach ($rCategories['live'] as $cat): ?>
                                        <div class="col-6 col-md-4 col-lg-3">
                                            <div class="form-check form-check-inline fs-7 m-0">
                                                <input class="form-check-input js-cat-checkbox" type="checkbox" name="categories[]" id="cat_<?= (int)$cat['id']; ?>" value="<?= (int)$cat['id']; ?>" <?= in_array((int)$cat['id'], $cats, true) ? 'checked' : ''; ?>>
                                                <label class="form-check-label text-truncate" for="cat_<?= (int)$cat['id']; ?>" title="<?= htmlspecialchars((string)$cat['category_name']); ?>">
                                                    <?= htmlspecialchars((string)$cat['category_name']); ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-outline-secondary js-prev-step" data-target="2">
                        <i class="icon-base ti tabler-arrow-left me-1"></i>
                        <span>Back</span>
                    </button>
                    <button type="button" class="btn btn-primary js-next-step" data-target="4">
                        <span>Next: Visual Styling & Preview</span>
                        <i class="icon-base ti tabler-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- STEP 4: Visual Formatting & Live Mockup Preview -->
        <div class="wizard-step-pane d-none" id="stepPane4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
                        <i class="icon-base ti tabler-palette text-primary"></i>
                        <span>Step 4: Visual Formatting & Telegram Live Mockup</span>
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <!-- Left column: Settings -->
                        <div class="col-12 col-lg-7">
                            <!-- Image Attachment Choice -->
                            <label class="form-label fw-bold mb-2">Media Attachment:</label>
                            <div class="row g-3 mb-4">
                                <div class="col-4">
                                    <label class="card border p-3 text-center cursor-pointer media-choice-card <?= $imageType === 'poster' ? 'border-primary bg-label-primary' : ''; ?>" for="img_poster">
                                        <input class="form-check-input d-none" type="radio" name="image_type" id="img_poster" value="poster" <?= $imageType === 'poster' ? 'checked' : ''; ?>>
                                        <i class="icon-base ti tabler-photo fs-2 d-block mb-1"></i>
                                        <span class="fw-bold fs-7 d-block">Poster</span>
                                        <small class="text-muted fs-8">Portrait</small>
                                    </label>
                                </div>
                                <div class="col-4">
                                    <label class="card border p-3 text-center cursor-pointer media-choice-card <?= $imageType === 'backdrop' ? 'border-primary bg-label-primary' : ''; ?>" for="img_backdrop">
                                        <input class="form-check-input d-none" type="radio" name="image_type" id="img_backdrop" value="backdrop" <?= $imageType === 'backdrop' ? 'checked' : ''; ?>>
                                        <i class="icon-base ti tabler-wallpaper fs-2 d-block mb-1"></i>
                                        <span class="fw-bold fs-7 d-block">Backdrop</span>
                                        <small class="text-muted fs-8">Landscape</small>
                                    </label>
                                </div>
                                <div class="col-4">
                                    <label class="card border p-3 text-center cursor-pointer media-choice-card <?= $imageType === 'none' ? 'border-primary bg-label-primary' : ''; ?>" for="img_none">
                                        <input class="form-check-input d-none" type="radio" name="image_type" id="img_none" value="none" <?= $imageType === 'none' ? 'checked' : ''; ?>>
                                        <i class="icon-base ti tabler-file-text fs-2 d-block mb-1"></i>
                                        <span class="fw-bold fs-7 d-block">Text Only</span>
                                        <small class="text-muted fs-8">No Media</small>
                                    </label>
                                </div>
                            </div>

                            <!-- Inline Action Button -->
                            <div class="card border p-3 mb-4">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="include_button" name="include_button" value="1" <?= $includeButton ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold ms-2" for="include_button">
                                        Include Inline Telegram Button
                                    </label>
                                </div>
                                <div id="buttonOptionsContainer" class="<?= $includeButton ? '' : 'd-none'; ?>">
                                    <div class="row g-2">
                                        <div class="col-12 col-md-5">
                                            <label class="form-label fs-7">Button Text</label>
                                            <input type="text" class="form-control form-control-sm" id="button_text" name="button_text" placeholder="🎬 Watch Now" value="<?= htmlspecialchars($buttonText); ?>">
                                        </div>
                                        <div class="col-12 col-md-7">
                                            <label class="form-label fs-7">Button URL (supports <code>{stream_id}</code>)</label>
                                            <input type="text" class="form-control form-control-sm" id="button_url" name="button_url" placeholder="https://myportal.com/play?id={stream_id}" value="<?= htmlspecialchars($buttonUrl); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Custom Template Option -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label fw-bold m-0" for="custom_template">Message Caption Template (Optional):</label>
                                    <button type="button" class="btn btn-xs btn-outline-secondary" id="btnResetTemplate">Use Standard Template</button>
                                </div>
                                <textarea class="form-control font-monospace fs-7" id="custom_template" name="custom_template" rows="5" placeholder="Leave blank to use the built-in rich template, or write custom HTML with emojis..."><?= htmlspecialchars($customTemplate); ?></textarea>
                                <div class="mt-2 d-flex flex-wrap gap-1">
                                    <span class="fs-8 text-muted align-self-center me-1">Available Tags:</span>
                                    <button type="button" class="badge bg-label-secondary border-0 js-tag-chip" data-tag="{title}">{title}</button>
                                    <button type="button" class="badge bg-label-secondary border-0 js-tag-chip" data-tag="{year}">{year}</button>
                                    <button type="button" class="badge bg-label-secondary border-0 js-tag-chip" data-tag="{rating}">{rating}</button>
                                    <button type="button" class="badge bg-label-secondary border-0 js-tag-chip" data-tag="{genre}">{genre}</button>
                                    <button type="button" class="badge bg-label-secondary border-0 js-tag-chip" data-tag="{duration}">{duration}</button>
                                    <button type="button" class="badge bg-label-secondary border-0 js-tag-chip" data-tag="{quality}">{quality}</button>
                                    <button type="button" class="badge bg-label-secondary border-0 js-tag-chip" data-tag="{video}">{video}</button>
                                    <button type="button" class="badge bg-label-secondary border-0 js-tag-chip" data-tag="{audio}">{audio}</button>
                                    <button type="button" class="badge bg-label-secondary border-0 js-tag-chip" data-tag="{category}">{category}</button>
                                    <button type="button" class="badge bg-label-secondary border-0 js-tag-chip" data-tag="{plot}">{plot}</button>
                                </div>
                            </div>
                        </div>

                        <!-- Right column: Live Telegram Smartphone Mockup -->
                        <div class="col-12 col-lg-5">
                            <label class="form-label fw-bold mb-2">Live Broadcast Preview:</label>
                            <div class="telegram-phone-mockup border rounded-4 shadow-sm overflow-hidden bg-body-tertiary">
                                <!-- Telegram Header Bar -->
                                <div class="bg-primary text-white p-3 d-flex align-items-center gap-3">
                                    <i class="icon-base ti tabler-arrow-left fs-5"></i>
                                    <div class="avatar avatar-sm bg-white text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold">
                                        <i class="icon-base ti tabler-brand-telegram"></i>
                                    </div>
                                    <div>
                                        <span class="fw-bold d-block fs-7" id="mockupChannelName"><?= htmlspecialchars($chatId ?: '@MyMoviesChannel'); ?></span>
                                        <span class="fs-8 opacity-75">channel • 1,420 subscribers</span>
                                    </div>
                                </div>

                                <!-- Telegram Chat Area -->
                                <div class="p-3" style="background: url('assets/img/telegram-chat-pattern.png') #eef2f5; min-height: 420px;">
                                    <div class="telegram-bubble bg-white rounded-3 shadow-sm overflow-hidden" style="max-width: 320px;">
                                        <!-- Poster Image in Mockup -->
                                        <div id="mockupImageHolder" class="text-center bg-dark">
                                            <img id="mockupImage" src="https://image.tmdb.org/t/p/w600_and_h900_bestv2/oYuLEt3zVCKq57qu2F8dT7NIa6f.jpg" alt="Preview Poster" class="img-fluid" style="max-height: 240px; width: 100%; object-fit: cover;">
                                        </div>

                                        <!-- Caption Text -->
                                        <div class="p-3 fs-8 lh-base text-dark" id="mockupCaption">
                                            🎬 <b>Inception (2010)</b><br>
                                            ━━━━━━━━━━━━━━━━━━<br>
                                            ⭐ <b>Rating:</b> 8.8 / 10<br>
                                            🎭 <b>Genre:</b> Action, Sci-Fi<br>
                                            ⏱ <b>Duration:</b> 2h 28m<br>
                                            📺 <b>Quality:</b> FHD (1080p) | AVC • AAC<br>
                                            📁 <b>Category:</b> Top Rated Sci-Fi<br><br>
                                            📝 <b>Overview:</b><br>
                                            A thief who steals corporate secrets through the use of dream-sharing technology...<br><br>
                                            ✨ <i>Now downloaded & ready to stream in highest quality!</i>
                                        </div>

                                        <!-- Mockup Inline Button -->
                                        <div id="mockupButtonHolder" class="p-2 border-top text-center bg-light <?= $includeButton ? '' : 'd-none'; ?>">
                                            <span class="btn btn-sm btn-primary w-100 py-1 fs-8" id="mockupButtonText">
                                                <?= htmlspecialchars($buttonText); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-outline-secondary js-prev-step" data-target="3">
                        <i class="icon-base ti tabler-arrow-left me-1"></i>
                        <span>Back</span>
                    </button>
                    <button type="submit" class="btn btn-success d-flex align-items-center gap-1 shadow-sm" id="btnSaveBot">
                        <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                        <i class="icon-base ti tabler-device-floppy"></i>
                        <span><?= $rIsEdit ? 'Update Telegram Bot' : 'Save & Activate Bot'; ?></span>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

