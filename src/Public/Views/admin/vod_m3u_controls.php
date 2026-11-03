<?php
/** Shared controls inside the movie/series import form. */
?>
<div class="mt-3">
	<label class="form-label" for="m3u_url"><?= $language::get('vod_m3u_url'); ?></label>
	<input type="url" class="form-control" id="m3u_url" name="m3u_url" placeholder="https://" autocomplete="off">
	<div class="form-text"><?= $language::get('vod_m3u_source_help'); ?></div>
</div>
<div class="form-check form-switch mt-4">
	<input class="form-check-input" type="checkbox" id="m3u_download" name="m3u_download" value="1">
	<label class="form-check-label" for="m3u_download"><?= $language::get('vod_m3u_download'); ?></label>
</div>
<div id="vod_download_controls" class="mt-3" hidden>
	<label class="form-label" for="vod_download_path"><?= $language::get('vod_download_path'); ?></label>
	<div class="input-group">
		<input type="text" class="form-control" id="vod_download_path" name="vod_download_path" placeholder="s:1:/media/vod/">
		<button type="button" class="btn btn-label-primary vod-folder-browser" data-target="vod_download_path"><i class="icon-base ti tabler-folder"></i></button>
	</div>
	<div class="form-text"><?= $language::get('vod_download_help'); ?></div>
</div>
<input type="hidden" id="m3u_selection" name="m3u_selection" value="">
<button type="button" class="btn btn-label-primary mt-3" id="vod_m3u_preview" data-error="<?= htmlspecialchars($language::get('vod_m3u_invalid'), ENT_QUOTES); ?>"><?= $language::get('vod_m3u_preview'); ?></button>
<div id="vod_m3u_entries" class="mt-3" hidden>
	<label class="form-check"><input type="checkbox" class="form-check-input" id="vod_m3u_all" checked><span class="form-check-label"><?= $language::get('select_all'); ?></span></label>
	<div id="vod_m3u_list" class="list-group overflow-auto" style="max-height: 18rem"></div>
</div>
